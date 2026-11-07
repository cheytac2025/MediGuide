<?php

namespace App\Support\Audit;

/**
 * In-memory stand-in for a configured checkpoint store. An empty store is
 * missing, not inactive. The latest record is the only one inspected.
 */
class MemoryAuditCheckpointStore implements AuditCheckpointStore
{
    /**
     * @param  list<array<string, mixed>>  $checkpoints
     */
    public function __construct(private array $checkpoints = []) {}

    public function status(): AuditCheckpointStatus
    {
        if ($this->checkpoints === []) {
            return AuditCheckpointStatus::problem(
                'missing',
                'Checkpoint protection is configured, but the checkpoint file is missing.',
            );
        }

        $inspected = AuditCheckpointRecord::inspect($this->checkpoints[array_key_last($this->checkpoints)]);

        if ($inspected['checkpoint'] === null) {
            return AuditCheckpointStatus::problem($inspected['state'], $inspected['message']);
        }

        return AuditCheckpointStatus::ready($inspected['checkpoint']);
    }

    public function put(array $checkpoint): void
    {
        $this->checkpoints[] = $checkpoint;
    }
}
