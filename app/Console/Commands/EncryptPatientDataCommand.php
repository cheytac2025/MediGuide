<?php

namespace App\Console\Commands;

use App\Support\Integrity\CanonicalRecord;
use App\Support\Integrity\IntegrityStatus;
use App\Support\Integrity\RecordIntegrityService;
use App\Support\PatientDataEncryption;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converts legacy plaintext in protected patient columns to ciphertext.
 *
 * Safe to re-run: every value is classified by MAC verification, so already
 * encrypted values are skipped (no double encryption) and an interrupted run
 * resumes where it stopped. Malformed ciphertext is reported by row ID and
 * left untouched. Values are never printed or logged.
 */
class EncryptPatientDataCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'patient-data:encrypt
        {--execute : Write changes. Without this flag the command only reports counts}
        {--backup-confirmed : Confirm a verified database backup exists (required with --execute)}
        {--reverse : Decrypt ciphertext back to plaintext (rollback before a code or schema rollback)}
        {--column=* : Limit to table.column (e.g. appointments.patient_concern)}
        {--limit=0 : Stop after converting this many values per column (0 = no limit)}';

    /**
     * @var string
     */
    protected $description = 'Encrypt (or decrypt with --reverse) legacy patient data in protected columns';

    /**
     * @var array<string, array{table: string, column: string, profile: bool}>
     */
    private const COLUMNS = [
        'appointments.patient_concern' => ['table' => 'appointments', 'column' => 'patient_concern', 'profile' => false],
        'patients.contact_number' => ['table' => 'patients', 'column' => 'contact_number', 'profile' => true],
        'patients.date_of_birth' => ['table' => 'patients', 'column' => 'date_of_birth', 'profile' => true],
    ];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $reverse = (bool) $this->option('reverse');
        $limit = max(0, (int) $this->option('limit'));
        $columns = $this->selectedColumns();

        if ($columns === null) {
            return self::INVALID;
        }

        if ($execute && ! $this->option('backup-confirmed')) {
            $this->error('Refusing to write: take and verify a database backup first, then re-run with --backup-confirmed.');

            return self::FAILURE;
        }

        $this->line($execute ? ($reverse ? 'Mode: DECRYPT (rollback)' : 'Mode: ENCRYPT') : 'Mode: DRY RUN (no changes written)');

        $rows = [];
        $healthy = true;

        foreach ($columns as $name => $definition) {
            $blocked = $execute && ! $reverse ? $this->forwardBlocker($definition) : null;

            if ($blocked !== null) {
                $this->warn("{$name}: skipped. {$blocked}");
                $rows[] = [$name, '-', '-', '-', '-', 0, 'skipped'];

                continue;
            }

            $result = $this->processColumn($definition, $execute, $reverse, $limit);
            $healthy = $healthy && $result['malformed'] === [] && $result['conflicts'] === [];

            $rows[] = [
                $name,
                $result['counts'][PatientDataEncryption::ENCRYPTED],
                $result['counts'][PatientDataEncryption::PLAINTEXT],
                $result['counts'][PatientDataEncryption::EMPTY],
                $result['counts'][PatientDataEncryption::MALFORMED],
                $result['converted'],
                match (true) {
                    ! $execute => 'dry run',
                    $result['remaining'] > 0 => "{$result['remaining']} left (limit reached)",
                    default => 'complete',
                },
            ];

            if ($result['malformed'] !== []) {
                $this->error("{$name}: malformed ciphertext in row IDs ".implode(', ', $result['malformed']).'. Left unchanged.');
            }

            if ($result['conflicts'] !== []) {
                $this->warn("{$name}: rows changed during conversion and were skipped: ".implode(', ', $result['conflicts']).'. Re-run to process them.');
            }
        }

        $this->table(['Column', 'Encrypted', 'Plaintext', 'Empty', 'Malformed', $reverse ? 'Decrypted' : 'Encrypted now', 'Status'], $rows);

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, array{table: string, column: string, profile: bool}>|null
     */
    private function selectedColumns(): ?array
    {
        $requested = (array) $this->option('column');

        if ($requested === []) {
            return self::COLUMNS;
        }

        $unknown = array_diff($requested, array_keys(self::COLUMNS));

        if ($unknown !== []) {
            $this->error('Unknown column: '.implode(', ', $unknown).'. Supported: '.implode(', ', array_keys(self::COLUMNS)));

            return null;
        }

        return array_intersect_key(self::COLUMNS, array_flip($requested));
    }

    /**
     * @param  array{table: string, column: string, profile: bool}  $definition
     */
    private function forwardBlocker(array $definition): ?string
    {
        if (! $definition['profile']) {
            return null;
        }

        $type = strtolower(Schema::getColumnType($definition['table'], $definition['column']));

        if (! str_contains($type, 'text')) {
            return "Column type is {$type}; run the widening migration first so ciphertext is not truncated.";
        }

        if (! config('patient_data.encrypt_profile_fields')) {
            return 'Set PATIENT_DATA_ENCRYPT_PROFILE_FIELDS=true first so new values are also encrypted.';
        }

        return null;
    }

    /**
     * @param  array{table: string, column: string, profile: bool}  $definition
     * @return array{counts: array<string, int>, converted: int, remaining: int, malformed: list<int>, conflicts: list<int>}
     */
    private function processColumn(array $definition, bool $execute, bool $reverse, int $limit): array
    {
        ['table' => $table, 'column' => $column] = $definition;

        $counts = array_fill_keys([
            PatientDataEncryption::ENCRYPTED,
            PatientDataEncryption::PLAINTEXT,
            PatientDataEncryption::EMPTY,
            PatientDataEncryption::MALFORMED,
        ], 0);
        $converted = 0;
        $remaining = 0;
        $malformed = [];
        $conflicts = [];
        $target = $reverse ? PatientDataEncryption::ENCRYPTED : PatientDataEncryption::PLAINTEXT;

        foreach (DB::table($table)->select(['id', $column])->lazyById(100) as $row) {
            $stored = $row->{$column} === null ? null : (string) $row->{$column};
            $state = PatientDataEncryption::classify($stored);
            $counts[$state]++;

            if ($state === PatientDataEncryption::MALFORMED) {
                $malformed[] = (int) $row->id;

                continue;
            }

            if (! $execute || $state !== $target) {
                continue;
            }

            if ($limit > 0 && $converted >= $limit) {
                $remaining++;

                continue;
            }

            $subject = $table === 'appointments' ? CanonicalRecord::APPOINTMENT : CanonicalRecord::PATIENT;
            $integrity = app(RecordIntegrityService::class);
            $before = $integrity->statusFor($subject, (int) $row->id);

            if ($before === IntegrityStatus::Invalid) {
                $conflicts[] = (int) $row->id;
                $integrity->report($subject, (int) $row->id, 'conversion_blocked');

                continue;
            }

            $replacement = $reverse
                ? (string) PatientDataEncryption::read($stored)
                : PatientDataEncryption::encrypt((string) $stored);

            $outcome = $integrity->lockAnd($subject, (int) $row->id, function (?object $locked, IntegrityStatus $status) use ($integrity, $subject, $table, $column, $stored, $replacement, $row): string {
                if ($locked === null || $status === IntegrityStatus::Invalid) {
                    $integrity->report($subject, (int) $row->id, 'conversion_blocked');

                    return 'invalid';
                }

                if ($status === IntegrityStatus::Unsigned && $integrity->strict()) {
                    $integrity->report($subject, (int) $locked->id, 'unsigned');

                    return 'invalid';
                }

                $updated = $this->whereUnchanged(DB::table($table)->where('id', $locked->id), $column, $stored)
                    ->update([$column => $replacement]);

                if ($updated !== 1) {
                    return 'conflict';
                }

                if ($status === IntegrityStatus::Valid) {
                    $integrity->writeSeal($subject, (int) $locked->id);
                }

                return 'converted';
            });

            if ($outcome !== 'converted') {
                $conflicts[] = (int) $row->id;

                continue;
            }

            $converted++;
        }

        return compact('counts', 'converted', 'remaining', 'malformed', 'conflicts');
    }

    /**
     * MySQL/MariaDB text comparison follows the column collation, which is
     * case- and trailing-space-insensitive; compare bytes so a concurrent
     * edit is never overwritten.
     */
    private function whereUnchanged(Builder $query, string $column, ?string $stored): Builder
    {
        if (in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $query->whereRaw('BINARY '.$query->getGrammar()->wrap($column).' = ?', [$stored]);
        }

        return $query->where($column, $stored);
    }
}
