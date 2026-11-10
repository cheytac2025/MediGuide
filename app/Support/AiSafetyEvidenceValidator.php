<?php

namespace App\Support;

use App\Enums\AiSafetyEvidenceStatus;

/**
 * Checks model safety quotations against server-side visitor statements.
 *
 * A matching quotation is an attribution result, not an urgency decision.
 * The model's proposed level is never accepted as authoritative.
 */
final class AiSafetyEvidenceValidator
{
    public const int MAX_QUOTES = 3;

    public const int MAX_QUOTE_CHARACTERS = 200;

    public const int MAX_MESSAGE_CHARACTERS = 400;

    public function __construct(
        private AiUrgentConcernDetector $urgentConcerns,
        private AiNavigationSafety $navigationSafety,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function evaluate(array $payload, AiConversationState $state, string $latestConcern): AiSafetyEvidenceAssessment
    {
        $evidenceKeys = array_intersect(['basis', 'evidence', 'proposed_level'], array_keys($payload));

        if ($evidenceKeys === []) {
            return $this->assessment(AiSafetyEvidenceStatus::NotSupplied);
        }

        $parsed = $this->parseContract($payload);

        if ($parsed instanceof AiSafetyEvidenceAssessment) {
            return $parsed;
        }

        if ($parsed['basis'] === 'model_inferred') {
            return $this->assessment(AiSafetyEvidenceStatus::ModelInferred);
        }

        if ($parsed['quotes'] === []) {
            return $this->assessment(AiSafetyEvidenceStatus::Unsupported);
        }

        return $this->attributeQuotes($parsed['quotes'], $state, $latestConcern);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{basis: string, quotes: list<string>}|AiSafetyEvidenceAssessment
     */
    private function parseContract(array $payload): array|AiSafetyEvidenceAssessment
    {
        $allowed = ['type', 'message', 'basis', 'evidence', 'proposed_level'];

        if (array_diff(array_keys($payload), $allowed) !== []) {
            return $this->assessment(AiSafetyEvidenceStatus::InvalidContract);
        }

        if (($payload['type'] ?? null) !== 'safety') {
            return $this->assessment(AiSafetyEvidenceStatus::InvalidContract);
        }

        $message = $payload['message'] ?? null;

        if (! is_string($message) || trim($message) === '' || mb_strlen(trim($message)) > self::MAX_MESSAGE_CHARACTERS) {
            return $this->assessment(AiSafetyEvidenceStatus::InvalidContract);
        }

        if (! in_array($payload['basis'] ?? null, ['visitor_reported', 'model_inferred'], true)) {
            return $this->assessment(AiSafetyEvidenceStatus::InvalidContract);
        }

        if (! in_array($payload['proposed_level'] ?? null, ['emergency', 'prompt_evaluation'], true)) {
            return $this->assessment(AiSafetyEvidenceStatus::InvalidContract);
        }

        $quotes = $this->parseQuotes($payload['evidence'] ?? null);

        if ($quotes === null) {
            return $this->assessment(AiSafetyEvidenceStatus::InvalidContract);
        }

        return [
            'basis' => $payload['basis'],
            'quotes' => $quotes,
        ];
    }

    /**
     * @return list<string>|null
     */
    private function parseQuotes(mixed $evidence): ?array
    {
        if (! is_array($evidence) || ! array_is_list($evidence) || count($evidence) > self::MAX_QUOTES) {
            return null;
        }

        $quotes = [];

        foreach ($evidence as $item) {
            if (! is_array($item) || ! array_key_exists('quote', $item) || array_diff(array_keys($item), ['quote']) !== []) {
                return null;
            }

            $quote = $item['quote'];

            if (! is_string($quote) || trim($quote) === '' || mb_strlen(trim($quote)) > self::MAX_QUOTE_CHARACTERS) {
                return null;
            }

            $quotes[] = trim($quote);
        }

        return $quotes;
    }

    /**
     * @param  list<string>  $quotes
     */
    private function attributeQuotes(array $quotes, AiConversationState $state, string $latestConcern): AiSafetyEvidenceAssessment
    {
        $visitorText = $this->visitorText($state, $latestConcern);
        $affirmed = [];

        foreach ($quotes as $quote) {
            $attribution = $this->classifyQuote($quote, $visitorText);

            if ($attribution === 'absent') {
                return $this->assessment(AiSafetyEvidenceStatus::Unsupported);
            }

            if ($attribution === 'negated') {
                return $this->assessment(AiSafetyEvidenceStatus::Negated);
            }

            if ($attribution === 'unknown') {
                return $this->assessment(AiSafetyEvidenceStatus::UnknownAnswer);
            }

            if ($attribution === 'affirmed') {
                $affirmed[] = $quote;
            }
        }

        if ($affirmed === []) {
            return $this->assessment(AiSafetyEvidenceStatus::AffirmationOnly);
        }

        $quotedText = implode("\n", $affirmed);
        $observed = $this->navigationSafety->classify($quotedText, AiConversationState::empty(), $quotedText);

        if ($observed->rule === null) {
            return $this->assessment(AiSafetyEvidenceStatus::QuotedWithoutRule);
        }

        return new AiSafetyEvidenceAssessment(
            AiSafetyEvidenceStatus::QuotedWithExistingRule,
            $observed->rule,
        );
    }

    private function classifyQuote(string $quote, string $visitorText): string
    {
        if (AiVisitorReply::declinedOrUnknown($quote)) {
            return 'unknown';
        }

        if (AiVisitorReply::negativeConfirmation($quote)) {
            return 'negated';
        }

        if (AiVisitorReply::positiveConfirmation($quote)) {
            return 'affirmation';
        }

        if ($this->statementNegates($this->urgentConcerns->normalize($quote))) {
            return 'negated';
        }

        return $this->urgentConcerns->phraseAttribution($visitorText, $quote);
    }

    private function statementNegates(string $normalizedQuote): bool
    {
        return (bool) preg_match(
            '/^(wala|walang|wala akong|hindi ako|hindi|di ako|not|no|without|dont|do not|does not|never)\b/u',
            $normalizedQuote,
        );
    }

    private function visitorText(AiConversationState $state, string $latestConcern): string
    {
        $parts = [];
        $seen = [];

        $push = function (string $text) use (&$parts, &$seen): void {
            $text = trim($text);

            if ($text === '' || isset($seen[$text])) {
                return;
            }

            $seen[$text] = true;
            $parts[] = $text;
        };

        if (is_string($state->openingConcern) && $state->openingConcern !== '') {
            $push($state->openingConcern);
        }

        foreach ($state->turns as $turn) {
            if (($turn['role'] ?? null) === 'user' && is_string($turn['content'] ?? null)) {
                $push($turn['content']);
            }
        }

        $push($latestConcern);

        return implode("\n", $parts);
    }

    private function assessment(AiSafetyEvidenceStatus $status): AiSafetyEvidenceAssessment
    {
        return new AiSafetyEvidenceAssessment($status, null);
    }
}
