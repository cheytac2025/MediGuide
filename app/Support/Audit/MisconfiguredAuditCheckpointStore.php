<?php

namespace App\Support\Audit;

use RuntimeException;

/**
 * A non-empty checkpoint path that is not an absolute location outside the web root.
 */
class MisconfiguredAuditCheckpointStore implements AuditCheckpointStore
{
    public const MESSAGE = 'Checkpoint protection is misconfigured. The path must be an absolute location outside the web root.';

    public function status(): AuditCheckpointStatus
    {
        return AuditCheckpointStatus::problem('misconfigured', self::MESSAGE);
    }

    public function put(array $checkpoint): void
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
