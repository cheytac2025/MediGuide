<?php

namespace App\Support\Audit;

/**
 * In-memory checkpoint used by tests to stand in for protected storage.
 */
class MemoryAuditCheckpointStore implements AuditCheckpointStore
{
    /**
     * @param  list<array{sequence: int, tag: string, key_version: int, occurred_at: string, checkpoint_tag: string}>  $checkpoints
     */
    public function __construct(private array $checkpoints = []) {}

    public function latest(): ?array
    {
        if ($this->checkpoints === []) {
            return null;
        }

        return $this->checkpoints[array_key_last($this->checkpoints)];
    }

    public function put(array $checkpoint): void
    {
        $this->checkpoints[] = $checkpoint;
    }
}
