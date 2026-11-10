<?php

namespace App\ClinicalKnowledge;

/**
 * A proposed list of catalog IDs.
 *
 * Free-text medical claims are not accepted. An unexpected field rejects
 * the whole proposal so a partial list cannot be applied.
 */
final readonly class ClinicalKnowledgeProposal
{
    public const string PROPOSED = 'proposed';

    public const string MALFORMED = 'malformed';

    public const string FAILED = 'failed';

    /**
     * @param  list<string>  $sourceIds
     */
    private function __construct(
        public string $status,
        public array $sourceIds,
    ) {}

    public static function failed(): self
    {
        return new self(self::FAILED, []);
    }

    public static function fromModelPayload(mixed $payload): self
    {
        if (! is_array($payload) || array_diff(array_keys($payload), ['source_ids']) !== []) {
            return new self(self::MALFORMED, []);
        }

        $ids = $payload['source_ids'] ?? null;

        if (! is_array($ids) || count($ids) > 3) {
            return new self(self::MALFORMED, []);
        }

        $clean = [];

        foreach ($ids as $id) {
            if (! is_string($id) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id) !== 1 || strlen($id) > 80) {
                return new self(self::MALFORMED, []);
            }

            $clean[] = $id;
        }

        if (count($clean) !== count(array_unique($clean))) {
            return new self(self::MALFORMED, []);
        }

        return new self(self::PROPOSED, $clean);
    }
}
