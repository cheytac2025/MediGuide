<?php

namespace App\Enums;

/**
 * Shadow-mode result for model safety evidence.
 *
 * None of these values selects a visitor-facing emergency,
 * prompt-evaluation, clarification, or recommendation response.
 */
enum AiSafetyEvidenceStatus: string
{
    case NotSupplied = 'not_supplied';
    case InvalidContract = 'invalid_contract';
    case ModelInferred = 'model_inferred';
    case Unsupported = 'unsupported';
    case Negated = 'negated';
    case UnknownAnswer = 'unknown_answer';
    case AffirmationOnly = 'affirmation_only';
    case QuotedWithoutRule = 'quoted_without_rule';
    case QuotedWithExistingRule = 'quoted_with_existing_rule';
}
