<?php

namespace App\Support\Audit;

/**
 * Result of reading a checkpoint store. A ready record is typed, but not yet authenticated.
 */
final class AuditCheckpointStatus
{
    /**
     * @param  array{sequence: int, tag: string, key_version: int, occurred_at: string, checkpoint_tag: string}|null  $checkpoint
     */
    private function __construct(
        public readonly string $state,
        public readonly ?array $checkpoint,
        public readonly string $message,
    ) {}

    public static function inactive(): self
    {
        return new self('inactive', null, 'Checkpoint protection is inactive.');
    }

    /**
     * @param  array{sequence: int, tag: string, key_version: int, occurred_at: string, checkpoint_tag: string}  $checkpoint
     */
    public static function ready(array $checkpoint): self
    {
        return new self('ready', $checkpoint, '');
    }

    public static function problem(string $state, string $message): self
    {
        return new self($state, null, $message);
    }
}
