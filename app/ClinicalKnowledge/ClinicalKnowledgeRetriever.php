<?php

namespace App\ClinicalKnowledge;

use App\Support\AiVisitorReply;

/**
 * Isolated retrieval over curated summaries.
 *
 * The Front Desk does not call this class. Missing knowledge is a gap,
 * not a safety determination. Retrieved text stays an untrusted reference.
 */
final class ClinicalKnowledgeRetriever
{
    private const int MAX_CURRENT = 3;

    public function __construct(private ClinicalKnowledgeCatalog $catalog) {}

    public function retrieve(string $concern): ClinicalKnowledgeAssessment
    {
        if (config('ai.clinical_knowledge') !== true) {
            return ClinicalKnowledgeAssessment::disabled();
        }

        $tokens = $this->tokens($this->usableText($concern));

        if ($tokens === []) {
            return new ClinicalKnowledgeAssessment(true, true, [], [], []);
        }

        $current = [];
        $stale = [];

        foreach ($this->catalog->entries() as $entry) {
            if (! $this->matches($tokens, $entry)) {
                continue;
            }

            if ($entry->stale) {
                $stale[] = $entry->reference();
            } else {
                $current[] = $entry->reference();
            }
        }

        $current = array_slice($current, 0, self::MAX_CURRENT);
        $dimensions = [];

        foreach ($current as $reference) {
            foreach ($reference['clarification_dimensions'] as $dimension) {
                if (is_string($dimension)) {
                    $dimensions[] = $dimension;
                }
            }
        }

        return new ClinicalKnowledgeAssessment(
            enabled: true,
            knowledgeGap: $current === [],
            currentReferences: $current,
            staleReferences: $stale,
            clarificationDimensions: array_values(array_unique($dimensions)),
        );
    }

    /**
     * @param  list<string>  $tokens
     */
    private function matches(array $tokens, ClinicalKnowledgeEntry $entry): bool
    {
        $subject = array_flip($this->tokens($entry->scoringText()));

        foreach ($tokens as $token) {
            if (isset($subject[$token])) {
                return true;
            }
        }

        return false;
    }

    private function usableText(string $text): string
    {
        $normalized = AiVisitorReply::normalize($text);

        if ($normalized === '') {
            return '';
        }

        if (AiVisitorReply::declinedOrUnknown($text) && mb_strlen($normalized) <= 40) {
            return '';
        }

        $clauses = preg_split('/[.!?;]+|\b(?:pero|but)\b/u', $normalized) ?: [];
        $kept = [];

        foreach ($clauses as $clause) {
            $pieces = preg_split('/\b(?:do not|does not|dont|hindi|wala|walang|never|without|not|no)\b/u', $clause, 2) ?: [];
            $clause = trim($pieces[0] ?? '');

            if ($clause === '') {
                continue;
            }

            $kept[] = $clause;
        }

        return implode(' ', $kept);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $normalized = AiVisitorReply::normalize($text);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [];
        $stop = array_flip([
            'with', 'from', 'that', 'this', 'have', 'been', 'your', 'their', 'about', 'when',
            'what', 'which', 'into', 'over', 'under', 'para', 'ako', 'may', 'ang', 'ako',
            'ako', 'sa', 'ako', 'the', 'and', 'for', 'are', 'was', 'were', 'you', 'not',
            'health', 'clinical', 'medical', 'care', 'provider', 'person', 'people',
            'immediate', 'contact', 'signs', 'symptoms', 'treatment', 'cause', 'common',
            'page', 'source', 'guideline', 'approved', 'department', 'hospital', 'child',
            'children', 'emergency', 'pain',
        ]);
        $tokens = [];

        foreach ($parts as $part) {
            if (mb_strlen($part) < 5 || isset($stop[$part])) {
                continue;
            }

            $tokens[$part] = true;
        }

        return array_keys($tokens);
    }
}
