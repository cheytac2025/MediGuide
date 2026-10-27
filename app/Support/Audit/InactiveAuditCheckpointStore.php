<?php

namespace App\Support\Audit;

use RuntimeException;

/**
 * Used when no off-database checkpoint has been configured.
 *
 * Tail deletion of a still-valid chain is not detected in this mode.
 */
class InactiveAuditCheckpointStore implements AuditCheckpointStore
{
    public function latest(): ?array
    {
        return null;
    }

    public function put(array $checkpoint): void
    {
        throw new RuntimeException('Audit checkpoints are inactive until an off-database path is configured.');
    }
}
