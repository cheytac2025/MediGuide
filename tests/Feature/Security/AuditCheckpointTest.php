<?php

namespace Tests\Feature\Security;

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\RoleName;
use App\Models\User;
use App\Support\Audit\AuditCheckpointStatus;
use App\Support\Audit\AuditCheckpointStore;
use App\Support\Audit\AuditTrailService;
use App\Support\Audit\AuditVerificationService;
use App\Support\Audit\CanonicalAuditEvent;
use App\Support\Audit\FileAuditCheckpointStore;
use App\Support\Audit\InactiveAuditCheckpointStore;
use App\Support\Audit\MemoryAuditCheckpointStore;
use App\Support\Audit\MisconfiguredAuditCheckpointStore;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class AuditCheckpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $directories = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->seed(RoleSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($directory);
        }

        config([
            'audit.checkpoint_path' => '',
            'audit.previous_keys' => '',
        ]);
        $this->app->forgetInstance(AuditCheckpointStore::class);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_checkpoint_checks_use_the_isolated_database_and_make_no_external_calls(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('', (string) env('DB_URL'));
        $this->assertSame('', (string) config('audit.checkpoint_path'));
        $this->assertSame('', (string) env('MEDIGUIDE_AUDIT_CHECKPOINT_PATH'));

        $level = DB::transactionLevel();
        $result = app(AuditVerificationService::class)->verify();

        $this->assertSame($level, DB::transactionLevel());
        $this->assertTrue($result['passed']);
        $this->assertSame('inactive', $result['checkpoint_protection']);
        $this->assertSame(0, $result['head_sequence']);
    }

    public function test_the_checkpoint_is_read_before_the_database_snapshot(): void
    {
        $store = new class implements AuditCheckpointStore
        {
            public int $level = -1;

            public function status(): AuditCheckpointStatus
            {
                $this->level = DB::transactionLevel();

                return AuditCheckpointStatus::inactive();
            }

            public function put(array $checkpoint): void
            {
                throw new RuntimeException('Verification must not write a checkpoint.');
            }
        };
        $outer = DB::transactionLevel();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = app(AuditVerificationService::class)->verify($store);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($result['passed']);
        $this->assertSame($outer, $store->level);
        $this->assertGreaterThan(0, $outer);

        $auditQueries = array_values(array_filter(
            $queries,
            fn (array $query): bool => str_contains($query['query'], 'audit_events') || str_contains($query['query'], 'audit_chain_state'),
        ));

        $this->assertCount(2, $auditQueries);
        $eventQueries = array_values(array_filter($auditQueries, fn (array $query): bool => str_contains($query['query'], 'audit_events')));
        $stateQueries = array_values(array_filter($auditQueries, fn (array $query): bool => str_contains($query['query'], 'audit_chain_state')));
        $this->assertCount(1, $eventQueries);
        $this->assertCount(1, $stateQueries);

        $eventSql = strtolower($eventQueries[0]['query']);
        $stateSql = strtolower($stateQueries[0]['query']);
        $this->assertStringNotContainsString('limit', $eventSql);
        $this->assertStringNotContainsString('offset', $eventSql);
        $this->assertStringNotContainsString('offset', $stateSql);
        $this->assertStringContainsString('limit 1', $stateSql);

        foreach ([$eventSql, $stateSql] as $sql) {
            $this->assertStringNotContainsString('for update', $sql);
            $this->assertStringNotContainsString('lock in share mode', $sql);
        }

        $this->assertFalse(collect($queries)->contains(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'isolation'),
        ));
    }

    public function test_an_open_mysql_or_mariadb_transaction_is_rejected_without_changing_it(): void
    {
        foreach (['mysql', 'mariadb'] as $driver) {
            $this->assertOpenServerTransactionIsRejected($driver);
        }
    }

    public function test_an_empty_path_stays_inactive_and_a_non_empty_invalid_path_does_not(): void
    {
        config(['audit.checkpoint_path' => '']);
        $this->app->forgetInstance(AuditCheckpointStore::class);
        $this->assertInstanceOf(InactiveAuditCheckpointStore::class, app(AuditCheckpointStore::class));

        config(['audit.checkpoint_path' => null]);
        $this->app->forgetInstance(AuditCheckpointStore::class);
        $this->assertInstanceOf(InactiveAuditCheckpointStore::class, app(AuditCheckpointStore::class));

        $inactive = app(AuditVerificationService::class)->verify();
        $this->assertTrue($inactive['passed']);
        $this->assertSame('inactive', $inactive['checkpoint_protection']);

        config(['audit.checkpoint_path' => 'storage\\checkpoint.jsonl']);
        $this->app->forgetInstance(AuditCheckpointStore::class);
        $this->assertInstanceOf(MisconfiguredAuditCheckpointStore::class, app(AuditCheckpointStore::class));

        $misconfigured = app(AuditVerificationService::class)->verify();
        $this->assertFalse($misconfigured['passed']);
        $this->assertSame('checkpoint', $misconfigured['category']);
        $this->assertSame('misconfigured', $misconfigured['checkpoint_protection']);
        $this->assertSame(MisconfiguredAuditCheckpointStore::MESSAGE, $misconfigured['message']);
        $this->assertStringNotContainsString('storage', (string) $misconfigured['message']);

        $this->artisan('mediguide:audit-verify')
            ->assertFailed()
            ->expectsOutputToContain(MisconfiguredAuditCheckpointStore::MESSAGE)
            ->doesntExpectOutputToContain('Checkpoint protection is inactive.');

        $this->artisan('mediguide:audit-checkpoint')
            ->assertFailed()
            ->expectsOutputToContain('Checkpoint protection: misconfigured')
            ->expectsOutputToContain(MisconfiguredAuditCheckpointStore::MESSAGE);
    }

    public function test_a_missing_checkpoint_fails_verification_until_execute_creates_it(): void
    {
        $path = $this->temporaryCheckpointPath();
        $this->recordEvent();
        $events = DB::table('audit_events')->count();
        config(['audit.checkpoint_path' => $path]);
        $this->app->forgetInstance(AuditCheckpointStore::class);

        $missing = app(AuditVerificationService::class)->verify();

        $this->assertFalse($missing['passed']);
        $this->assertSame('missing', $missing['checkpoint_protection']);
        $this->assertSame(FileAuditCheckpointStore::MESSAGES['missing'], $missing['message']);
        $this->assertFileDoesNotExist($path);
        $this->assertSame($events, DB::table('audit_events')->count());

        $this->artisan('mediguide:audit-checkpoint')
            ->assertSuccessful()
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Checkpoint protection: configured');
        $this->assertFileDoesNotExist($path);

        $this->artisan('mediguide:audit-verify')->assertFailed();
        $this->artisan('mediguide:audit-checkpoint', ['--execute' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Checkpoint appended.');

        $this->assertFileExists($path);
        $before = hash_file('sha256', $path);
        $verified = app(AuditVerificationService::class)->verify();

        $this->assertTrue($verified['passed']);
        $this->assertSame('verified', $verified['checkpoint_protection']);
        $this->assertSame(1, $verified['checkpoint_sequence']);
        $this->assertSame(1, $verified['head_sequence']);
        $this->assertSame(0, $verified['uncovered_events']);
        $this->assertSame($before, hash_file('sha256', $path));
        $this->assertSame($events, DB::table('audit_events')->count());
        $this->assertCount(1, file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_the_file_store_appends_and_reads_the_latest_checkpoint(): void
    {
        $path = $this->temporaryCheckpointPath();
        $store = new FileAuditCheckpointStore($path);
        $this->recordEvent();
        $first = app(AuditTrailService::class)->checkpoint();
        $store->put($first);
        $this->recordEvent();
        $second = app(AuditTrailService::class)->checkpoint();
        $store->put($second);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $status = $store->status();

        $this->assertCount(2, $lines);
        $this->assertSame($first['sequence'], json_decode((string) $lines[0], true)['sequence']);
        $this->assertSame('ready', $status->state);
        $this->assertSame($second['sequence'], $status->checkpoint['sequence']);
        $this->assertSame($second['tag'], $status->checkpoint['tag']);
        $this->assertNotSame($first['tag'], $status->checkpoint['tag']);

        $verified = app(AuditVerificationService::class)->verify($store);

        $this->assertTrue($verified['passed']);
        $this->assertSame($second['sequence'], $verified['checkpoint_sequence']);
        $this->assertSame(0, $verified['uncovered_events']);
    }

    public function test_checkpoint_file_problems_fail_without_using_an_older_line(): void
    {
        $path = $this->temporaryCheckpointPath();
        $directory = dirname($path);
        $this->recordEvent();
        $checkpoint = app(AuditTrailService::class)->checkpoint();
        $valid = json_encode($checkpoint, JSON_THROW_ON_ERROR)."\n";
        $cases = [
            'empty' => ['', 'empty', FileAuditCheckpointStore::MESSAGES['empty']],
            'blank lines' => ["\n\n", 'empty', FileAuditCheckpointStore::MESSAGES['empty']],
            'malformed' => ['{', 'malformed', 'latest checkpoint record is malformed.'],
            'incomplete' => [$this->recordJson($this->without($checkpoint, 'checkpoint_tag')), 'incomplete', 'latest checkpoint record is incomplete.'],
            'string sequence' => [$this->recordJson(array_merge($checkpoint, ['sequence' => '1'])), 'invalid', 'invalid field.'],
            'boolean sequence' => ['{"sequence":true,"tag":"'.str_repeat('ab', 32).'","key_version":1,"occurred_at":"2026-10-05T00:00:00.000000Z","checkpoint_tag":"'.str_repeat('cd', 32).'"}'."\n", 'invalid', 'invalid field.'],
            'fractional sequence' => ['{"sequence":1.5,"tag":"'.str_repeat('ab', 32).'","key_version":1,"occurred_at":"2026-10-05T00:00:00.000000Z","checkpoint_tag":"'.str_repeat('cd', 32).'"}'."\n", 'invalid', 'invalid field.'],
            'older line ignored' => [$valid."   \n", 'malformed', 'latest checkpoint record is malformed.'],
        ];

        foreach ($cases as $name => [$contents, $state, $message]) {
            if (! is_dir($directory)) {
                mkdir($directory);
            }

            file_put_contents($path, $contents);
            $store = new FileAuditCheckpointStore($path);
            $status = $store->status();
            $result = app(AuditVerificationService::class)->verify($store);

            $this->assertSame($state, $status->state, $name);
            $this->assertFalse($result['passed'], $name);
            $this->assertSame('checkpoint', $result['category'], $name);
            $this->assertSame($state, $result['checkpoint_protection'], $name);
            $this->assertStringContainsString($message, (string) $result['message'], $name);
            $this->assertNull($result['checkpoint_sequence'], $name);
            $this->assertStringNotContainsString($checkpoint['tag'], (string) $result['message'], $name);
            $this->assertStringNotContainsString($path, (string) $result['message'], $name);
        }

        $unreadable = rtrim(sys_get_temp_dir(), '\\/').DIRECTORY_SEPARATOR.'mediguide-audit-dir-'.bin2hex(random_bytes(4));
        mkdir($unreadable);
        $this->directories[] = $unreadable;
        $this->assertTrue(FileAuditCheckpointStore::pathIsOutsideWebRoot($unreadable));
        config(['audit.checkpoint_path' => $unreadable]);
        $this->app->forgetInstance(AuditCheckpointStore::class);
        $directoryResult = app(AuditVerificationService::class)->verify();

        $this->assertSame('unreadable', $directoryResult['checkpoint_protection']);
        $this->assertFalse($directoryResult['passed']);
        $this->assertSame(FileAuditCheckpointStore::MESSAGES['unreadable'], $directoryResult['message']);
        $this->artisan('mediguide:audit-verify')->assertFailed();
    }

    public function test_invalid_signatures_and_unknown_key_versions_are_rejected(): void
    {
        $path = $this->temporaryCheckpointPath();
        $this->recordEvent();
        $checkpoint = app(AuditTrailService::class)->checkpoint();
        $checkpoint['checkpoint_tag'] = str_repeat($checkpoint['checkpoint_tag'] === str_repeat('ab', 32) ? 'cd' : 'ab', 32);
        $this->writeCheckpoint($path, $checkpoint);

        $invalid = app(AuditVerificationService::class)->verify(new FileAuditCheckpointStore($path));

        $this->assertFalse($invalid['passed']);
        $this->assertSame('checkpoint', $invalid['category']);
        $this->assertSame('failed', $invalid['checkpoint_protection']);
        $this->assertSame('The checkpoint signature is invalid.', $invalid['message']);
        $this->assertSame(1, $invalid['checkpoint_sequence']);
        $this->assertStringNotContainsString($checkpoint['tag'], (string) $invalid['message']);

        $unknown = $checkpoint;
        $unknown['key_version'] = 9;
        $unknown['checkpoint_tag'] = str_repeat('ef', 32);
        $this->writeCheckpoint($path, $unknown);
        $unknownResult = app(AuditVerificationService::class)->verify(new FileAuditCheckpointStore($path));

        $this->assertFalse($unknownResult['passed']);
        $this->assertSame('unknown_key', $unknownResult['category']);
        $this->assertSame('The checkpoint uses an unknown key version.', $unknownResult['message']);
        $this->assertSame(9, json_decode((string) file($path)[0], true)['key_version']);
        $this->assertStringNotContainsString('9', (string) $unknownResult['message']);
    }

    public function test_events_after_a_checkpoint_stay_valid_and_are_reported_as_uncovered(): void
    {
        $this->recordEvent();
        $store = new MemoryAuditCheckpointStore;
        $store->put(app(AuditTrailService::class)->checkpoint());
        $this->recordEvent();
        $this->app->instance(AuditCheckpointStore::class, $store);

        $result = app(AuditVerificationService::class)->verify();

        $this->assertTrue($result['passed']);
        $this->assertSame('verified', $result['checkpoint_protection']);
        $this->assertSame(1, $result['checkpoint_sequence']);
        $this->assertSame(2, $result['head_sequence']);
        $this->assertSame(1, $result['uncovered_events']);
        $this->assertSame(2, $result['valid']);
        $this->assertSame(0, $result['invalid']);

        $this->artisan('mediguide:audit-verify')
            ->assertSuccessful()
            ->expectsOutputToContain('Events after the checkpoint are not covered by it.')
            ->expectsOutputToContain('Deletion confined to that tail may remain undetected.')
            ->doesntExpectOutputToContain('independently protected');
    }

    public function test_a_valid_chain_that_disagrees_with_the_checkpoint_fails(): void
    {
        $this->recordEvent();
        $this->recordEvent();
        $eventTag = (string) DB::table('audit_events')->where('sequence', 1)->value('tag');
        $otherTag = $eventTag === str_repeat('ab', 32) ? str_repeat('cd', 32) : str_repeat('ab', 32);
        $store = new MemoryAuditCheckpointStore;
        $store->put($this->signedCheckpoint(1, $otherTag));

        $result = app(AuditVerificationService::class)->verify($store);

        $this->assertFalse($result['passed']);
        $this->assertSame('checkpoint', $result['category']);
        $this->assertSame(1, $result['first_sequence']);
        $this->assertSame(1, $result['checkpoint_sequence']);
        $this->assertSame(2, $result['head_sequence']);
        $this->assertNull($result['uncovered_events']);
        $this->assertSame('The checkpoint does not match the audit event at its recorded sequence.', $result['message']);
        $this->assertSame(2, $result['valid']);
    }

    public function test_chain_tampering_still_fails_when_a_checkpoint_is_present(): void
    {
        $this->recordEvent();
        $this->recordEvent();
        $store = new MemoryAuditCheckpointStore;
        $store->put(app(AuditTrailService::class)->checkpoint());
        DB::table('audit_events')->where('sequence', 2)->update(['context' => '{"reason":"tampered"}']);

        $result = app(AuditVerificationService::class)->verify($store);

        $this->assertFalse($result['passed']);
        $this->assertSame('mismatch', $result['category']);
        $this->assertSame(2, $result['first_sequence']);
    }

    public function test_database_truncation_below_a_file_checkpoint_fails(): void
    {
        $path = $this->temporaryCheckpointPath();
        $store = new FileAuditCheckpointStore($path);
        $this->recordEvent();
        $this->recordEvent();
        $store->put(app(AuditTrailService::class)->checkpoint());
        DB::table('audit_events')->where('sequence', 2)->delete();
        DB::table('audit_chain_state')->where('id', 1)->update([
            'last_sequence' => 1,
            'last_tag' => DB::table('audit_events')->where('sequence', 1)->value('tag'),
        ]);

        $result = app(AuditVerificationService::class)->verify($store);

        $this->assertFalse($result['passed']);
        $this->assertSame('checkpoint', $result['category']);
        $this->assertSame(2, $result['checkpoint_sequence']);
        $this->assertSame(1, $result['head_sequence']);
        $this->assertSame('The audit chain ends before the checkpoint sequence.', $result['message']);
    }

    public function test_genesis_checkpoints_use_their_key_version_and_allow_later_events(): void
    {
        $audit = app(AuditTrailService::class);
        $store = new MemoryAuditCheckpointStore;
        $store->put($this->signedCheckpoint(0, $audit->genesisTag(1)));

        $empty = app(AuditVerificationService::class)->verify($store);

        $this->assertTrue($empty['passed']);
        $this->assertSame(0, $empty['checkpoint_sequence']);
        $this->assertSame(0, $empty['head_sequence']);
        $this->assertSame(0, $empty['uncovered_events']);

        $this->recordEvent();
        $later = app(AuditVerificationService::class)->verify($store);
        $this->assertTrue($later['passed']);
        $this->assertSame(0, $later['checkpoint_sequence']);
        $this->assertSame(1, $later['head_sequence']);
        $this->assertSame(1, $later['uncovered_events']);

        $wrong = new MemoryAuditCheckpointStore;
        $wrong->put($this->signedCheckpoint(0, str_repeat('ab', 32)));
        $invalidGenesis = app(AuditVerificationService::class)->verify($wrong);
        $this->assertFalse($invalidGenesis['passed']);
        $this->assertSame('The checkpoint genesis tag is invalid.', $invalidGenesis['message']);

        config(['audit.previous_keys' => '2:'.config('audit.key')]);
        $versionTwo = new MemoryAuditCheckpointStore;
        $versionTwo->put($this->signedCheckpoint(0, $audit->genesisTag(2), 2));
        $this->assertTrue(app(AuditVerificationService::class)->verify($versionTwo)['passed']);

        $wrongVersion = new MemoryAuditCheckpointStore;
        $wrongVersion->put($this->signedCheckpoint(0, $audit->genesisTag(1), 2));
        $mismatched = app(AuditVerificationService::class)->verify($wrongVersion);
        $this->assertFalse($mismatched['passed']);
        $this->assertSame('The checkpoint genesis tag is invalid.', $mismatched['message']);
    }

    private function assertOpenServerTransactionIsRejected(string $driver): void
    {
        DB::table('audit_chain_state')->where('id', 1)->update(['key_version' => 77]);
        $level = DB::transactionLevel();
        $this->assertGreaterThan(0, $level);

        $store = new class implements AuditCheckpointStore
        {
            public int $reads = 0;

            public function status(): AuditCheckpointStatus
            {
                $this->reads++;

                throw new RuntimeException('The checkpoint was read.');
            }

            public function put(array $checkpoint): void
            {
                throw new RuntimeException('The checkpoint was written.');
            }
        };
        $inner = DB::connection();
        $mask = new class($inner, $driver) extends SQLiteConnection
        {
            public array $statements = [];

            public int $transactionCalls = 0;

            public function __construct(private \Illuminate\Database\Connection $inner, private string $reportedDriver)
            {
                parent::__construct(
                    $inner->getPdo(),
                    $inner->getDatabaseName(),
                    $inner->getTablePrefix(),
                    $inner->getConfig(),
                );
            }

            public function getDriverName()
            {
                return $this->reportedDriver;
            }

            public function transactionLevel()
            {
                return $this->inner->transactionLevel();
            }

            public function statement($query, $bindings = [])
            {
                $this->statements[] = (string) $query;

                return true;
            }

            public function transaction(\Closure $callback, $attempts = 1)
            {
                $this->transactionCalls++;

                return null;
            }
        };

        $manager = app('db');
        $property = new ReflectionProperty($manager, 'connections');
        $connections = $property->getValue($manager);
        $original = $connections['sqlite'];
        $inner->flushQueryLog();
        $inner->enableQueryLog();
        $connections['sqlite'] = $mask;
        $property->setValue($manager, $connections);

        try {
            $result = app(AuditVerificationService::class)->verify($store);
            $this->artisan('mediguide:audit-verify')
                ->assertFailed()
                ->expectsOutputToContain(AuditVerificationService::OPEN_TRANSACTION_MESSAGE)
                ->expectsOutputToContain('Category: transaction');
        } finally {
            $callerLog = $inner->getQueryLog();
            $inner->disableQueryLog();
            $connections = $property->getValue($manager);
            $connections['sqlite'] = $original;
            $property->setValue($manager, $connections);
        }

        $this->assertFalse($result['passed']);
        $this->assertSame('transaction', $result['category']);
        $this->assertSame('not_checked', $result['checkpoint_protection']);
        $this->assertSame(AuditVerificationService::OPEN_TRANSACTION_MESSAGE, $result['message']);
        $this->assertNull($result['checkpoint_sequence']);
        $this->assertNull($result['uncovered_events']);
        $this->assertSame(0, $store->reads);
        $this->assertSame([], $mask->statements);
        $this->assertSame(0, $mask->transactionCalls);
        $this->assertSame($level, $inner->transactionLevel());
        $this->assertSame(77, (int) DB::table('audit_chain_state')->where('id', 1)->value('key_version'));
        $this->assertSame([], $callerLog);
    }

    private function recordEvent(): void
    {
        $user = User::factory()->role(RoleName::Patient)->create();
        app(AuditTrailService::class)->record(AuditAction::Logout, AuditOutcome::Success, actor: $user);
    }

    /**
     * @return array{sequence: int, tag: string, key_version: int, occurred_at: string, checkpoint_tag: string}
     */
    private function signedCheckpoint(int $sequence, string $tag, int $keyVersion = 1): array
    {
        $body = [
            'sequence' => $sequence,
            'tag' => $tag,
            'key_version' => $keyVersion,
            'occurred_at' => '2026-10-05T00:00:00.000000Z',
        ];
        $body['checkpoint_tag'] = hash_hmac(
            'sha256',
            CanonicalAuditEvent::checkpoint($body),
            app(AuditTrailService::class)->keyForVersion($keyVersion),
        );

        return $body;
    }

    private function temporaryCheckpointPath(): string
    {
        $directory = rtrim(sys_get_temp_dir(), '\\/').DIRECTORY_SEPARATOR.'mediguide-audit-'.bin2hex(random_bytes(6));
        $path = $directory.DIRECTORY_SEPARATOR.'checkpoint.jsonl';
        $this->assertTrue(FileAuditCheckpointStore::pathIsOutsideWebRoot($path));
        $this->directories[] = $directory;

        return $path;
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    private function writeCheckpoint(string $path, array $checkpoint): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory);
        }

        file_put_contents($path, json_encode($checkpoint, JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    private function recordJson(array $checkpoint): string
    {
        return json_encode($checkpoint, JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @return array<string, mixed>
     */
    private function without(array $checkpoint, string $key): array
    {
        unset($checkpoint[$key]);

        return $checkpoint;
    }
}
