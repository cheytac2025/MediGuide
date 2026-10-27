<?php

namespace App\Support\Audit;

interface AuditCheckpointStore
{
    /**
     * @return array{sequence: int, tag: string, key_version: int, occurred_at: string, checkpoint_tag: string}|null
     */
    public function latest(): ?array;

    /**
     * @param  array{sequence: int, tag: string, key_version: int, occurred_at: string, checkpoint_tag: string}  $checkpoint
     */
    public function put(array $checkpoint): void;
}
