<?php

namespace App\ClinicalKnowledge;

/**
 * Reviewer choices. Relevance is not a safety conclusion.
 */
final class ClinicalKnowledgeReviewRatings
{
    public const string AWAITING = 'awaiting_clinical_review';

    public const string RECEIVED = 'reviews_received';

    public const string REJECTED = 'rejected';

    /**
     * @return list<string>
     */
    public static function relevance(): array
    {
        return [
            'relevant',
            'possibly_relevant',
            'irrelevant',
            'insufficient_information',
            'population_inapplicable',
            'stale_or_unsupported',
            'not_evaluated',
        ];
    }

    /**
     * @return list<string>
     */
    public static function safety(): array
    {
        return [
            'appropriate',
            'needs_revision',
            'unsafe',
            'not_applicable',
            'not_evaluated',
        ];
    }

    /**
     * @return list<string>
     */
    public static function applicability(): array
    {
        return [
            'applicable',
            'limited',
            'not_applicable',
            'not_evaluated',
        ];
    }

    public static function label(string $value): string
    {
        return match ($value) {
            'relevant' => 'Relevant',
            'possibly_relevant' => 'Possibly Relevant',
            'irrelevant' => 'Irrelevant',
            'insufficient_information' => 'Insufficient Information',
            'population_inapplicable' => 'Population Inapplicable',
            'stale_or_unsupported' => 'Stale or Unsupported',
            'not_evaluated' => 'Not Evaluated',
            'appropriate' => 'Appropriate',
            'needs_revision' => 'Needs Revision',
            'unsafe' => 'Unsafe',
            'not_applicable' => 'Not Applicable',
            'applicable' => 'Applicable',
            'limited' => 'Limited',
            default => $value,
        };
    }
}
