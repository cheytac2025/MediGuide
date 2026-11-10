<?php

namespace App\Support;

/**
 * Request-local counters for shadow evidence checks.
 *
 * Stores a status code only. Visitor text, quotations, and model
 * messages are not retained and are not written to the log.
 */
final class AiSafetyEvidenceShadow
{
    private int $evaluations = 0;

    private ?string $lastStatus = null;

    public function record(AiSafetyEvidenceAssessment $assessment): void
    {
        $this->evaluations++;
        $this->lastStatus = $assessment->status->value;
    }

    public function evaluations(): int
    {
        return $this->evaluations;
    }

    public function lastStatus(): ?string
    {
        return $this->lastStatus;
    }
}
