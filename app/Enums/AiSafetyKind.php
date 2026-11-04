<?php

namespace App\Enums;

/**
 * Internal navigation-safety kind. Both values still use the public
 * guidance type `safety` so the frontend never shows routine booking.
 *
 * This is not a clinically validated triage scale.
 */
enum AiSafetyKind: string
{
    case Emergency = 'emergency';
    case PromptEvaluation = 'prompt_evaluation';
}
