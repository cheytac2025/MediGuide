<?php

/**
 * Proves two MySQL connections append audit events without forking the chain.
 * Refuses the live mediguide database and any restore database.
 */

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\RoleName;
use App\Models\User;
use App\Support\Audit\AuditTrailService;
use App\Support\Audit\AuditVerificationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

const AUDIT_TEST_DATABASE = 'mediguide_audit_concurrency';

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (isset($argv[1]) && $argv[1] === 'worker') {
    exit(runWorker((int) ($argv[2] ?? 0)));
}

exit(runParent());

function runParent(): int
{
    $connection = config('database.connections.mysql');
    $host = (string) ($connection['host'] ?? '');
    $live = (string) ($connection['database'] ?? '');

    if (! in_array($host, ['127.0.0.1', 'localhost'], true) || $live !== 'mediguide' || str_contains($live, 'restore')) {
        fwrite(STDERR, "Refused: unexpected database connection.\n");

        return 1;
    }

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s', $connection['host'], $connection['port'] ?? 3306),
        (string) $connection['username'],
        (string) ($connection['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('DROP DATABASE IF EXISTS `'.AUDIT_TEST_DATABASE.'`');
    $pdo->exec('CREATE DATABASE `'.AUDIT_TEST_DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $passed = false;

    try {
        useTestDatabase();
        Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);
        Artisan::call('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
        $first = startWorker(1);
        $second = startWorker(2);
        $left = finishWorker($first);
        $right = finishWorker($second);
        $result = app(AuditVerificationService::class)->verify();
        $sequences = DB::table('audit_events')->orderBy('sequence')->pluck('sequence')->map(fn (mixed $id): int => (int) $id)->all();
        $passed = ($left['result'] ?? '') === 'recorded'
            && ($right['result'] ?? '') === 'recorded'
            && $sequences === [1, 2]
            && $result['passed'] === true;
        fwrite(STDOUT, ($passed ? 'PASS' : 'FAIL').' audit concurrency sequences='.implode(',', $sequences).' verify='.($result['passed'] ? 'valid' : 'invalid')."\n");
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Isolated audit concurrency check failed: '.$exception::class."\n");
    } finally {
        DB::disconnect('mysql');
        $pdo->exec('DROP DATABASE IF EXISTS `'.AUDIT_TEST_DATABASE.'`');
    }

    return $passed ? 0 : 1;
}

function useTestDatabase(): void
{
    config(['database.connections.mysql.database' => AUDIT_TEST_DATABASE]);
    DB::purge('mysql');
    DB::reconnect('mysql');
    $current = (string) DB::select('select database() as name')[0]->name;

    if ($current !== AUDIT_TEST_DATABASE) {
        throw new RuntimeException('Connected to the wrong database.');
    }
}

function startWorker(int $slot): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $slot], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));

    return ['process' => $process, 'pipes' => $pipes];
}

function finishWorker(array $worker): array
{
    if (! is_resource($worker['process'])) {
        return ['result' => 'not-started'];
    }

    $output = stream_get_contents($worker['pipes'][1]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    proc_close($worker['process']);
    $decoded = json_decode((string) $output, true);

    return is_array($decoded) ? array_map(strval(...), $decoded) : ['result' => 'unreadable'];
}

function runWorker(int $slot): int
{
    useTestDatabase();
    $user = User::factory()->role(RoleName::Patient)->create([
        'email' => 'audit-worker-'.$slot.'@example.test',
    ]);

    try {
        app(AuditTrailService::class)->record(AuditAction::Logout, AuditOutcome::Success, actor: $user);
        $result = 'recorded';
    } catch (Throwable) {
        $result = 'failed';
    }

    fwrite(STDOUT, json_encode(['result' => $result], JSON_THROW_ON_ERROR));

    return 0;
}
