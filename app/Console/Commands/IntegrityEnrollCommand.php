<?php

namespace App\Console\Commands;

use App\Support\Integrity\CanonicalRecord;
use App\Support\Integrity\IntegrityStatus;
use App\Support\Integrity\RecordIntegrityService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Seals records that do not yet have integrity metadata.
 *
 * Dry-run unless --execute is passed. Valid seals are left untouched.
 * Invalid seals are reported and never overwritten.
 */
class IntegrityEnrollCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mediguide:integrity-enroll
        {--execute : Write seals. Without this flag the command only reports counts}
        {--type= : Limit to appointment or patient}
        {--limit=0 : Stop after sealing this many unsigned records (0 = no limit)}';

    /**
     * @var string
     */
    protected $description = 'Create HMAC-SHA-256 seals for records that do not have one yet';

    public function handle(RecordIntegrityService $integrity): int
    {
        $types = $this->types();

        if ($types === null) {
            return self::INVALID;
        }

        $execute = (bool) $this->option('execute');
        $limit = max(0, (int) $this->option('limit'));
        $sealed = 0;
        $rows = [];
        $invalidIds = [];

        $this->line($execute ? 'Mode: ENROLL' : 'Mode: DRY RUN (no seals written)');

        foreach ($types as $type) {
            $counts = [
                IntegrityStatus::Valid->value => 0,
                IntegrityStatus::Invalid->value => 0,
                IntegrityStatus::Unsigned->value => 0,
            ];
            $wrote = 0;
            $remaining = 0;

            foreach ($integrity->ids($type) as $id) {
                $status = $integrity->statusFor($type, $id);
                $counts[$status->value]++;

                if ($status === IntegrityStatus::Invalid) {
                    $invalidIds[] = "{$type}:{$id}";

                    continue;
                }

                if ($status !== IntegrityStatus::Unsigned) {
                    continue;
                }

                if (! $execute) {
                    continue;
                }

                if ($limit > 0 && $sealed >= $limit) {
                    $remaining++;

                    continue;
                }

                try {
                    $result = $integrity->enrollOne($type, $id);
                } catch (Throwable) {
                    if ($integrity->statusFor($type, $id) === IntegrityStatus::Valid) {
                        continue;
                    }

                    $invalidIds[] = "{$type}:{$id}";
                    $integrity->report($type, $id, 'enrollment_failed');

                    continue;
                }

                if ($result === 'skipped_invalid') {
                    $invalidIds[] = "{$type}:{$id}";

                    continue;
                }

                if ($result !== 'sealed') {
                    continue;
                }

                $sealed++;
                $wrote++;
            }

            $rows[] = [
                $type,
                $counts['valid'],
                $counts['invalid'],
                $counts['unsigned'],
                $wrote,
                $remaining > 0 ? "{$remaining} left (limit reached)" : ($execute ? 'complete' : 'dry run'),
            ];
        }

        $this->table(['Record type', 'Valid', 'Invalid', 'Unsigned', 'Sealed now', 'Status'], $rows);

        if ($invalidIds !== []) {
            $this->error('Invalid seals were left unchanged: '.implode(', ', $invalidIds));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function types(): ?array
    {
        $type = $this->option('type');

        if (! is_string($type) || $type === '') {
            return [CanonicalRecord::APPOINTMENT, CanonicalRecord::PATIENT];
        }

        if (! in_array($type, [CanonicalRecord::APPOINTMENT, CanonicalRecord::PATIENT], true)) {
            $this->error('Type must be appointment or patient.');

            return null;
        }

        return [$type];
    }
}
