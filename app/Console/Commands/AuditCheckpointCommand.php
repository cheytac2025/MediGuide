<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditCheckpointStore;
use App\Support\Audit\AuditTrailService;
use App\Support\Audit\InactiveAuditCheckpointStore;
use App\Support\Audit\MisconfiguredAuditCheckpointStore;
use Illuminate\Console\Command;

/**
 * Writes a signed chain-head checkpoint. Dry-run unless --execute is passed.
 *
 * The checkpoint is useful only when the destination is outside the database
 * and copies are kept off this computer. No event context is printed.
 */
class AuditCheckpointCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mediguide:audit-checkpoint
        {--execute : Append a signed checkpoint to the configured off-database store}';

    /**
     * @var string
     */
    protected $description = 'Prepare a signed checkpoint of the audit chain head';

    public function handle(AuditTrailService $audit, AuditCheckpointStore $store): int
    {
        $execute = (bool) $this->option('execute');
        $checkpoint = $audit->checkpoint();
        $protection = match (true) {
            $store instanceof InactiveAuditCheckpointStore => 'inactive',
            $store instanceof MisconfiguredAuditCheckpointStore => 'misconfigured',
            default => 'configured',
        };

        $this->line($execute ? 'Mode: CHECKPOINT' : 'Mode: DRY RUN (nothing written)');
        $this->line('Sequence: '.$checkpoint['sequence']);
        $this->line('Key version: '.$checkpoint['key_version']);
        $this->line('Checkpoint protection: '.$protection);

        if ($protection === 'misconfigured') {
            $this->error(MisconfiguredAuditCheckpointStore::MESSAGE);

            return self::FAILURE;
        }

        if (! $execute) {
            return self::SUCCESS;
        }

        if ($protection === 'inactive') {
            $this->error('Checkpoint storage is inactive. Configure an off-database path before executing.');

            return self::FAILURE;
        }

        $store->put($checkpoint);
        $this->info('Checkpoint appended.');

        return self::SUCCESS;
    }
}
