<?php

namespace App\Support\Audit;

use RuntimeException;

/**
 * Used when the checkpoint path is intentionally empty.
 *
 * Verification of this store checks the database chain only and reports
 * checkpoint protection as inactive. Tail deletion of a still-valid chain
 * is not detected in this mode.
 */
class InactiveAuditCheckpointStore implements AuditCheckpointStore
{
    public function status(): AuditCheckpointStatus
    {
        return AuditCheckpointStatus::inactive();
    }

    public function put(array $checkpoint): void
    {
        throw new RuntimeException('Audit checkpoints are inactive until an off-database path is configured.');
    }
}
