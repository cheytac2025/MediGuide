<?php

namespace App\Services\Ai;

use App\Support\AiConversationFacts;
use App\Support\AiConversationInterpreter;
use App\Support\AiConversationState;

/**
 * Builds a patient-reported concern summary from validated Front Desk state.
 *
 * This does not call Anthropic. It only reformats facts already derived from
 * visitor turns. Unknown topics are labeled unknown, never as denials.
 */
class AppointmentAiSummaryBuilder
{
    public const int MAX_ORIGINAL_INPUTS = 8;

    public const int MAX_INPUT_CHARS = 280;

    /**
     * @return array{
     *     concern_summary: string,
     *     reported_details: list<array{label: string, value: string}>,
     *     suggested_specialization: ?string,
     *     original_inputs: list<string>
     * }|null
     */
    public function fromConversation(
        AiConversationState $state,
        ?string $suggestedSpecialization,
        ?string $bookingConcern = null,
    ): ?array {
        if (! $this->hasUsableConversation($state, $bookingConcern)) {
            return null;
        }

        // Prefer the latest visitor turn already in conversation state. The
        // booking form concern must not overwrite answered topics such as severity.
        $latest = $state->latestUserMessage();
        if ($latest === '' && is_string($bookingConcern) && trim($bookingConcern) !== '') {
            $latest = trim($bookingConcern);
        }
        $facts = AiConversationInterpreter::interpret($state, $latest);

        if (! is_string($facts->primaryConcern) || trim($facts->primaryConcern) === '') {
            return null;
        }

        $specialization = is_string($suggestedSpecialization) ? trim($suggestedSpecialization) : null;
        if ($specialization === '') {
            $specialization = null;
        }

        return [
            'concern_summary' => $this->concernSummary($facts),
            'reported_details' => $this->reportedDetails($facts),
            'suggested_specialization' => $specialization,
            'original_inputs' => $this->originalInputs($state, $facts->primaryConcern, $bookingConcern),
        ];
    }

    private function hasUsableConversation(AiConversationState $state, ?string $bookingConcern): bool
    {
        if (is_string($state->openingConcern) && trim($state->openingConcern) !== '') {
            return true;
        }

        foreach ($state->turns as $turn) {
            if (($turn['role'] ?? null) === 'user' && is_string($turn['content'] ?? null) && trim($turn['content']) !== '') {
                return true;
            }
        }

        return is_string($bookingConcern) && trim($bookingConcern) !== '';
    }

    private function concernSummary(AiConversationFacts $facts): string
    {
        $parts = ['Patient reported: '.$facts->primaryConcern];

        if ($facts->hasUsableDuration()) {
            $parts[] = 'Duration: '.$facts->duration;
        } elseif ($facts->isUnknown('duration')) {
            $parts[] = 'Duration: unknown';
        }

        if ($facts->hasUsableLocation()) {
            $location = $facts->bodyLocation;
            if (is_string($facts->locationAnchor) && $facts->locationAnchor !== '' && $location !== $facts->locationAnchor) {
                $location = $facts->locationAnchor.' — '.$location;
            }
            $parts[] = 'Location: '.$location;
        } elseif ($facts->isUnknown('location')) {
            $parts[] = 'Location: unknown';
        }

        if ($facts->hasUsableSeverity()) {
            $parts[] = 'Severity: '.$facts->severity;
        } elseif ($facts->isUnknown('severity')) {
            $parts[] = 'Severity: unknown';
        }

        if (is_string($facts->onset) && $facts->onset !== '' && ! $facts->isUnknown('onset')) {
            $parts[] = 'Onset: '.$facts->onset;
        } elseif ($facts->isUnknown('onset')) {
            $parts[] = 'Onset: unknown';
        }

        if ($facts->hasUsableFunctionalImpact()) {
            $parts[] = 'Functional impact: '.$facts->functionalImpact;
        } elseif ($facts->isUnknown('functional_impact')) {
            $parts[] = 'Functional impact: unknown';
        }

        if ($facts->associated !== []) {
            $parts[] = 'Associated symptoms: '.implode(', ', $facts->associated);
        }

        if ($facts->denied !== []) {
            $parts[] = 'Denied: '.implode(', ', array_map(
                fn (string $topic): string => $this->humanizeTopic($topic),
                $facts->denied,
            ));
        }

        return implode('. ', $parts).'.';
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function reportedDetails(AiConversationFacts $facts): array
    {
        $rows = [
            ['label' => 'Primary concern', 'value' => $facts->primaryConcern ?? ''],
        ];

        if ($facts->hasUsableDuration()) {
            $rows[] = ['label' => 'Duration', 'value' => (string) $facts->duration];
        } elseif ($facts->isUnknown('duration')) {
            $rows[] = ['label' => 'Duration', 'value' => 'Unknown / unanswered'];
        }

        if ($facts->hasUsableLocation()) {
            $rows[] = ['label' => 'Location', 'value' => (string) $facts->bodyLocation];
        } elseif ($facts->isUnknown('location')) {
            $rows[] = ['label' => 'Location', 'value' => 'Unknown / unanswered'];
        }

        if ($facts->hasUsableSeverity()) {
            $rows[] = ['label' => 'Severity', 'value' => (string) $facts->severity];
        } elseif ($facts->isUnknown('severity')) {
            $rows[] = ['label' => 'Severity', 'value' => 'Unknown / unanswered'];
        }

        if (is_string($facts->onset) && $facts->onset !== '' && ! $facts->isUnknown('onset')) {
            $rows[] = ['label' => 'Onset', 'value' => $facts->onset];
        } elseif ($facts->isUnknown('onset')) {
            $rows[] = ['label' => 'Onset', 'value' => 'Unknown / unanswered'];
        }

        if (is_string($facts->frequency) && $facts->frequency !== '' && ! $facts->isUnknown('frequency')) {
            $rows[] = ['label' => 'Frequency', 'value' => $facts->frequency];
        }

        if ($facts->hasUsableFunctionalImpact()) {
            $rows[] = ['label' => 'Functional impact', 'value' => (string) $facts->functionalImpact];
        } elseif ($facts->isUnknown('functional_impact')) {
            $rows[] = ['label' => 'Functional impact', 'value' => 'Unknown / unanswered'];
        }

        if ($facts->associated !== []) {
            $rows[] = ['label' => 'Associated symptoms', 'value' => implode(', ', $facts->associated)];
        }

        if ($facts->denied !== []) {
            $rows[] = [
                'label' => 'Denied symptoms',
                'value' => implode(', ', array_map(
                    fn (string $topic): string => $this->humanizeTopic($topic),
                    $facts->denied,
                )),
            ];
        }

        if ($facts->unknown !== []) {
            $rows[] = [
                'label' => 'Unknown / unanswered',
                'value' => implode(', ', array_map(
                    fn (string $topic): string => $this->humanizeTopic($topic),
                    $facts->unknown,
                )),
            ];
        }

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['value'] !== '',
        ));
    }

    private function humanizeTopic(string $topic): string
    {
        return match ($topic) {
            'associated' => 'additional symptoms',
            'functional_impact' => 'functional impact',
            'asked_detail' => 'asked detail',
            'unspecified' => 'unspecified detail',
            default => str_replace('_', ' ', $topic),
        };
    }

    /**
     * @return list<string>
     */
    private function originalInputs(AiConversationState $state, string $primaryConcern, ?string $bookingConcern): array
    {
        $inputs = [];

        $push = function (string $text) use (&$inputs): void {
            $text = trim($text);

            if ($text === '') {
                return;
            }

            if (mb_strlen($text) > self::MAX_INPUT_CHARS) {
                $text = mb_substr($text, 0, self::MAX_INPUT_CHARS - 1).'…';
            }

            foreach ($inputs as $existing) {
                if (mb_strtolower($existing) === mb_strtolower($text)) {
                    return;
                }
            }

            $inputs[] = $text;
        };

        $push($primaryConcern);

        foreach ($state->turns as $turn) {
            if (($turn['role'] ?? null) !== 'user') {
                continue;
            }

            if (! is_string($turn['content'] ?? null)) {
                continue;
            }

            $push($turn['content']);

            if (count($inputs) >= self::MAX_ORIGINAL_INPUTS) {
                return $inputs;
            }
        }

        if (is_string($bookingConcern)) {
            $push($bookingConcern);
        }

        return array_slice($inputs, 0, self::MAX_ORIGINAL_INPUTS);
    }
}
