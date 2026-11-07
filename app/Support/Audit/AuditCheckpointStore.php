<?php

namespace App\Support\Audit;

interface AuditCheckpointStore
{
    public function status(): AuditCheckpointStatus;

    /**
     * @param  array{sequence: int, tag: string, key_version: int, occurred_at: string, checkpoint_tag: string}  $checkpoint
     */
    public function put(array $checkpoint): void;
}
