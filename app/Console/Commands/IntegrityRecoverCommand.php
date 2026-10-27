<?php

namespace App\Console\Commands;

use App\Support\Integrity\CanonicalRecord;
use App\Support\Integrity\RecordIntegrityService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-seals a protected row whose HMAC was removed. Dry-run unless --execute is passed.
 *
 * A mismatched HMAC is never replaced. Unsigned legacy rows are not enrolled.
 * Recovery trusts the current row and cannot prove it was unchanged after the seal was deleted.
 */
class IntegrityRecoverCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mediguide:integrity-recover
        {--execute : Replace seals that were removed after protection}
        {--type= : Limit to appointment or patient}';

    /**
     * @var string
     */
    protected $description = 'Recover HMAC seals that were deleted after a record was protected';

    public function handle(RecordIntegrityService $integrity): int
    {
        $types = $this->types();

        if ($types === null) {
            return self::INVALID;
        }

        $execute = (bool) $this->option('execute');
        $rows = [];
        $blocked = [];
        $recovered = 0;

        $this->line($execute ? 'Mode: RECOVER' : 'Mode: DRY RUN (no seals written)');

        foreach ($types as $type) {
            $counts = ['missing_seal' => 0, 'recovered' => 0, 'other_invalid' => 0];

            foreach ($integrity->ids($type) as $id) {
                $category = $integrity->failureCategory($type, $id);

                if ($category === null) {
                    continue;
                }

                if ($category !== 'missing_seal') {
                    $counts['other_invalid']++;
                    $blocked[] = "{$type}:{$id}";

                    continue;
                }

                $counts['missing_seal']++;

                if (! $execute) {
                    continue;
                }

                try {
                    $result = $integrity->recoverOne($type, $id);
                } catch (Throwable) {
                    $blocked[] = "{$type}:{$id}";
                    $integrity->report($type, $id, 'recovery_failed');

                    continue;
                }

                if ($result !== 'recovered') {
                    $blocked[] = "{$type}:{$id}";

                    continue;
                }

                $recovered++;
                $counts['recovered']++;
            }

            $rows[] = [$type, $counts['missing_seal'], $counts['recovered'], $counts['other_invalid']];
        }

        $this->table(['Record type', 'Missing seal', 'Recovered', 'Other invalid'], $rows);

        if ($blocked !== []) {
            $this->error('Records left unchanged: '.implode(', ', $blocked));

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
