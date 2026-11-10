<?php

namespace App\ClinicalKnowledge;

/**
 * Review intake result. It never approves the catalog or reports accuracy.
 */
final readonly class ClinicalKnowledgeReviewBenchmarkResult
{
    /**
     * @param  list<array<string, mixed>>  $agreements
     * @param  list<array<string, mixed>>  $disagreements
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $status,
        public int $submittedRatings,
        public int $notEvaluatedRows,
        public array $agreements,
        public array $disagreements,
        public array $errors,
    ) {}

    public function approvesCatalog(): bool
    {
        return false;
    }

    public function usesEngineeringLabelsAsGroundTruth(): bool
    {
        return false;
    }

    public function assignsClinicalUrgency(): bool
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
