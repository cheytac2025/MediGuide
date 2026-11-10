<?php

namespace App\ClinicalKnowledge;

/**
 * Evaluation-only retrieval result.
 *
 * A match does not assign urgency, name a doctor, or fill a knowledge gap
 * with a claim that the concern is harmless.
 */
final readonly class ClinicalKnowledgeAssessment
{
    /**
     * @param  list<array<string, mixed>>  $currentReferences
     * @param  list<array<string, mixed>>  $staleReferences
     * @param  list<string>  $clarificationDimensions
     * @param  list<array{id: string|null, reason: string}>  $rejected
     * @param  list<array<string, mixed>>  $possiblyRelevantReferences
     * @param  list<array<string, mixed>>  $unassessedReferences
     * @param  list<array<string, mixed>>  $insufficientReferences
     */
    public function __construct(
        public bool $enabled,
        public bool $knowledgeGap,
        public array $currentReferences,
        public array $staleReferences,
        public array $clarificationDimensions,
        public array $rejected = [],
        public ?string $selectorStatus = null,
        public array $possiblyRelevantReferences = [],
        public array $unassessedReferences = [],
        public array $insufficientReferences = [],
    ) {}

    public static function disabled(): self
    {
        return new self(false, false, [], [], []);
    }

    public function assignsClinicalUrgency(): bool
    {
        return false;
    }

    public function harmless(): bool
    {
        return false;
    }

    /**
     * @return list<int>
     */
    public function specialistIds(): array
    {
        return [];
    }
}
