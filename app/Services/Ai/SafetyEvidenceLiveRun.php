<?php

namespace App\Services\Ai;

/**
 * Public summary of one safety-evidence evaluation.
 *
 * This object does not carry model text, quotations, prompts, or credentials.
 */
final readonly class SafetyEvidenceLiveRun
{
    /**
     * @param  list<array<string, mixed>>  $scenarios
     */
    public function __construct(
        public bool $executed,
        public bool $failed,
        public ?string $stopReason,
        public int $providerCalls,
        public int $limit,
        public string $model,
        public int $maxOutputTokens,
        public int $retryOutputTokens,
        public ?int $approximateInputCharacters,
        public ?int $roughInputTokens,
        public array $scenarios,
        public string $message,
        public bool $schemaEnabledGlobally,
        public ?int $httpStatus = null,
        public ?string $errorCategory = null,
    ) {}
}
