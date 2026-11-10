<?php

namespace App\Support;

use App\Enums\AiSafetyEvidenceStatus;

/**
 * Internal observation of model safety evidence.
 *
 * The observed rule, when present, names an existing overlay match.
 * This object never authorizes a visitor-facing urgency decision.
 */
final readonly class AiSafetyEvidenceAssessment
{
    public function __construct(
        public AiSafetyEvidenceStatus $status,
        public ?string $observedExistingRule,
    ) {}

    public function assignsClinicalUrgency(): bool
    {
        return false;
    }
}
