<?php

namespace App\ClinicalKnowledge;

use App\Support\AiVisitorReply;

/**
 * Accepts catalog IDs only, then classifies topical relevance separately.
 *
 * A valid ID is not relevant evidence. The default judge abstains. Token
 * overlap can mark a source possibly relevant and cannot mark it relevant
 * or irrelevant. Source text cannot change urgency or name a doctor.
 */
final class ClinicalKnowledgeSemanticGate
{
    public function __construct(
        private ClinicalKnowledgeRelevanceAssessor $assessor = new AbstainingClinicalKnowledgeRelevanceAssessor(),
    ) {}

    public function accept(
        string $concern,
        ClinicalKnowledgeProposal $proposal,
        ClinicalKnowledgeCatalog $catalog,
    ): ClinicalKnowledgeAssessment {
        if ($proposal->status === ClinicalKnowledgeProposal::FAILED) {
            return $this->gap(ClinicalKnowledgeProposal::FAILED);
        }

        if ($proposal->status === ClinicalKnowledgeProposal::MALFORMED) {
            return $this->gap(ClinicalKnowledgeProposal::MALFORMED);
        }

        if ($this->withholds($concern)) {
            return $this->rejectAll($proposal->sourceIds, 'withheld');
        }

        $relevant = [];
        $possibly = [];
        $unassessed = [];
        $insufficient = [];
        $stale = [];
        $rejected = [];

        foreach ($proposal->sourceIds as $id) {
            $entry = $catalog->find($id);

            if ($entry === null) {
                $rejected[] = ['id' => $id, 'reason' => 'invented'];

                continue;
            }

            if ($entry->stale) {
                $reference = $entry->reference();
                $reference['relevance_level'] = ClinicalKnowledgeRelevance::STALE_OR_UNSUPPORTED;
                $stale[] = $reference;

                continue;
            }

            if ($entry->populationScope === 'index' || ! $entry->recommendationExtracted) {
                $rejected[] = ['id' => $id, 'reason' => 'not_a_recommendation', 'level' => ClinicalKnowledgeRelevance::STALE_OR_UNSUPPORTED];

                continue;
            }

            if (! $this->populationAllows($entry->populationScope, $this->statedPopulation($concern))) {
                $rejected[] = ['id' => $id, 'reason' => 'population', 'level' => ClinicalKnowledgeRelevance::POPULATION_INAPPLICABLE];

                continue;
            }

            $level = $this->levelFor($concern, $entry);
            $reference = $entry->reference();
            $reference['relevance_level'] = $level;

            match ($level) {
                ClinicalKnowledgeRelevance::RELEVANT => $relevant[] = $reference,
                ClinicalKnowledgeRelevance::POSSIBLY_RELEVANT => $possibly[] = $reference,
                ClinicalKnowledgeRelevance::INSUFFICIENT_INFORMATION => $insufficient[] = $reference,
                ClinicalKnowledgeRelevance::IRRELEVANT => $rejected[] = ['id' => $id, 'reason' => 'irrelevant', 'level' => $level],
                default => $unassessed[] = $reference,
            };
        }

        return $this->assessment(
            ClinicalKnowledgeProposal::PROPOSED,
            $relevant,
            $stale,
            $rejected,
            $possibly,
            $unassessed,
            $insufficient,
        );
    }

    private function levelFor(string $concern, ClinicalKnowledgeEntry $entry): string
    {
        $judged = $this->assessor->judge($concern, $entry);

        if (! in_array($judged, ClinicalKnowledgeRelevance::judgeLevels(), true)) {
            $judged = ClinicalKnowledgeRelevance::UNASSESSED;
        }

        if ($judged !== ClinicalKnowledgeRelevance::UNASSESSED) {
            return $judged;
        }

        if ($this->subjectOverlaps($concern, $entry)) {
            return ClinicalKnowledgeRelevance::POSSIBLY_RELEVANT;
        }

        return ClinicalKnowledgeRelevance::UNASSESSED;
    }

    private function subjectOverlaps(string $concern, ClinicalKnowledgeEntry $entry): bool
    {
        $result = (new ClinicalKnowledgeRetriever(ClinicalKnowledgeCatalog::containing($entry)))->retrieve($concern);

        return in_array($entry->id, array_column($result->currentReferences, 'id'), true);
    }

    private function withholds(string $concern): bool
    {
        $normalized = AiVisitorReply::normalize($concern);

        if ($normalized === '') {
            return true;
        }

        if (! AiVisitorReply::statesHealthConcern($concern)) {
            return true;
        }

        if (AiVisitorReply::declinedOrUnknown($concern) && mb_strlen($normalized) <= 40) {
            return true;
        }

        return preg_match('/^(?:i\s+)?(?:do not|does not|dont|never|not|no|hindi|wala|walang)\b/u', $normalized) === 1;
    }

    private function statedPopulation(string $concern): string
    {
        $normalized = AiVisitorReply::normalize($concern);
        $child = preg_match('/\b(?:anak|bata|baby|infant|child|children|pediatric)\b/u', $normalized) === 1;
        $pregnancy = preg_match('/\b(?:buntis|pregnant|pregnancy)\b/u', $normalized) === 1;
        $adult = preg_match('/\b(?:adult|adults)\b/u', $normalized) === 1;
        $marked = ($child ? 1 : 0) + ($pregnancy ? 1 : 0) + ($adult ? 1 : 0);

        if ($marked > 1) {
            return 'conflicting';
        }

        if ($child) {
            return 'child';
        }

        if ($pregnancy) {
            return 'pregnancy';
        }

        if ($adult) {
            return 'adult';
        }

        return 'unspecified';
    }

    private function populationAllows(string $scope, string $stated): bool
    {
        if ($scope === 'general') {
            return true;
        }

        return match ($stated) {
            'adult' => $scope === 'adult' || $scope === 'twelve_and_over',
            'child' => $scope === 'child',
            'pregnancy' => $scope === 'pregnancy',
            default => false,
        };
    }

    /**
     * @param  list<string>  $ids
     */
    private function rejectAll(array $ids, string $status): ClinicalKnowledgeAssessment
    {
        $rejected = [];

        foreach ($ids as $id) {
            $rejected[] = ['id' => $id, 'reason' => 'withheld'];
        }

        return $this->assessment($status, [], [], $rejected);
    }

    /**
     * @param  list<array<string, mixed>>  $relevant
     * @param  list<array<string, mixed>>  $stale
     * @param  list<array{id: string|null, reason: string}>  $rejected
     * @param  list<array<string, mixed>>  $possibly
     * @param  list<array<string, mixed>>  $unassessed
     * @param  list<array<string, mixed>>  $insufficient
     */
    private function assessment(
        string $status,
        array $relevant,
        array $stale,
        array $rejected,
        array $possibly = [],
        array $unassessed = [],
        array $insufficient = [],
    ): ClinicalKnowledgeAssessment {
        $dimensions = [];

        foreach ($relevant as $reference) {
            foreach ($reference['clarification_dimensions'] as $dimension) {
                if (is_string($dimension)) {
                    $dimensions[] = $dimension;
                }
            }
        }

        return new ClinicalKnowledgeAssessment(
            enabled: true,
            knowledgeGap: $relevant === [],
            currentReferences: $relevant,
            staleReferences: $stale,
            clarificationDimensions: array_values(array_unique($dimensions)),
            rejected: $rejected,
            selectorStatus: $status,
            possiblyRelevantReferences: $possibly,
            unassessedReferences: $unassessed,
            insufficientReferences: $insufficient,
        );
    }

    private function gap(string $status): ClinicalKnowledgeAssessment
    {
        return $this->assessment($status, [], [], []);
    }
}
