<?php

namespace App\Console\Commands;

use App\Support\Integrity\CanonicalRecord;
use App\Support\Integrity\IntegrityStatus;
use App\Support\Integrity\RecordIntegrityService;
use Illuminate\Console\Command;

/**
 * Read-only HMAC verification. Invalid seals are reported and never repaired.
 */
class IntegrityVerifyCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mediguide:integrity-verify
        {--type= : Limit to appointment or patient}
        {--strict : Fail when any protected record is still unsigned}';

    /**
     * @var string
     */
    protected $description = 'Verify HMAC-SHA-256 seals for patient and appointment records';

    public function handle(RecordIntegrityService $integrity): int
    {
        $types = $this->types();

        if ($types === null) {
            return self::INVALID;
        }

        $rows = [];
        $invalid = 0;
        $unsigned = 0;

        foreach ($types as $type) {
            $counts = [
                IntegrityStatus::Valid->value => 0,
                IntegrityStatus::Invalid->value => 0,
                IntegrityStatus::Unsigned->value => 0,
            ];

            foreach ($integrity->ids($type) as $id) {
                $status = $integrity->statusFor($type, $id);
                $counts[$status->value]++;

                if ($status === IntegrityStatus::Invalid) {
                    $invalid++;
                    $integrity->report($type, $id, $integrity->failureCategory($type, $id) ?? 'mismatch');
                    $this->line("{$type}:{$id} ".$integrity->failureCategory($type, $id));
                }
            }

            $unsigned += $counts['unsigned'];
            $rows[] = [$type, $counts['valid'], $counts['invalid'], $counts['unsigned']];
        }

        $this->table(['Record type', 'Valid', 'Invalid', 'Unsigned'], $rows);

        if ($invalid > 0) {
            $this->error("{$invalid} record(s) failed integrity verification.");

            return self::FAILURE;
        }

        if ((bool) $this->option('strict') && $unsigned > 0) {
            $this->error("{$unsigned} unsigned record(s) remain.");

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
