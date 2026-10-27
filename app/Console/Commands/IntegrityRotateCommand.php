<?php

namespace App\Console\Commands;

use App\Support\Integrity\CanonicalRecord;
use App\Support\Integrity\RecordIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-signs seals that already verify. Dry-run unless --execute is passed.
 *
 * Invalid seals are never replaced. Unsigned rows are not enrolled.
 */
class IntegrityRotateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mediguide:integrity-rotate
        {--execute : Re-sign valid seals with the current key}
        {--type= : Limit to appointment or patient}
        {--limit=0 : Stop after rotating this many seals (0 = no limit)}';

    /**
     * @var string
     */
    protected $description = 'Re-sign valid HMAC seals with the current integrity key';

    public function handle(RecordIntegrityService $integrity): int
    {
        $types = $this->types();

        if ($types === null) {
            return self::INVALID;
        }

        $execute = (bool) $this->option('execute');
        $limit = max(0, (int) $this->option('limit'));
        $rotated = 0;
        $rows = [];
        $blocked = [];

        $this->line($execute ? 'Mode: ROTATE' : 'Mode: DRY RUN (no seals rewritten)');

        foreach ($types as $type) {
            $counts = ['current' => 0, 'ready' => 0, 'unsigned' => 0, 'invalid' => 0, 'rotated' => 0];

            foreach ($integrity->ids($type) as $id) {
                $seal = $this->seal($type, $id);
                $category = $integrity->failureCategory($type, $id);

                if ($seal === null) {
                    if ($category !== null) {
                        $counts['invalid']++;
                        $blocked[] = "{$type}:{$id}";

                        continue;
                    }

                    $counts['unsigned']++;

                    continue;
                }

                if ($category !== null) {
                    $counts['invalid']++;
                    $blocked[] = "{$type}:{$id}";

                    continue;
                }

                $current = (int) $seal->key_version === (int) config('integrity.key_version')
                    && (int) $seal->integrity_version === (int) config('integrity.version');

                if ($current) {
                    $counts['current']++;

                    continue;
                }

                $counts['ready']++;

                if (! $execute || ($limit > 0 && $rotated >= $limit)) {
                    continue;
                }

                try {
                    $result = $integrity->rotateOne($type, $id);
                } catch (Throwable) {
                    $blocked[] = "{$type}:{$id}";
                    $integrity->report($type, $id, 'rotation_failed');

                    continue;
                }

                if ($result === 'invalid' || $result === 'unsigned') {
                    $blocked[] = "{$type}:{$id}";

                    continue;
                }

                if ($result === 'rotated') {
                    $rotated++;
                    $counts['rotated']++;
                }
            }

            $rows[] = [$type, $counts['current'], $counts['ready'], $counts['rotated'], $counts['unsigned'], $counts['invalid']];
        }

        $this->table(['Record type', 'Current', 'Ready', 'Rotated', 'Unsigned', 'Invalid'], $rows);

        if ($blocked !== []) {
            $this->error('Seals left unchanged: '.implode(', ', $blocked));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function seal(string $type, int $id): ?object
    {
        return DB::table(RecordIntegrityService::TABLE)
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->first();
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
