<?php

namespace App\Support;

use App\Enums\AiSafetyKind;

/**
 * Session-only navigation-safety decision.
 *
 * The rule code is for software tests and clinician review. It is not a
 * diagnosis and must not be shown to visitors or stored as a medical record.
 */
final readonly class AiSafetyDecision
{
    private function __construct(
        public ?AiSafetyKind $kind,
        public ?string $rule,
    ) {}

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function emergency(string $rule = 'emergency_warning_sign'): self
    {
        return new self(AiSafetyKind::Emergency, $rule);
    }

    public static function promptEvaluation(string $rule): self
    {
        return new self(AiSafetyKind::PromptEvaluation, $rule);
    }

    public function blocksRoutineNavigation(): bool
    {
        return $this->kind instanceof AiSafetyKind;
    }

    public function isEmergency(): bool
    {
        return $this->kind === AiSafetyKind::Emergency;
    }

    public function isPromptEvaluation(): bool
    {
        return $this->kind === AiSafetyKind::PromptEvaluation;
    }
}
