<?php

namespace App\ClinicalKnowledge;

/**
 * Isolated source selection. The proposal is validated before any summary
 * is returned. The Front Desk does not construct this class.
 */
final class ClinicalKnowledgeSemanticRetriever
{
    public function __construct(
        private ClinicalKnowledgeCatalog $catalog,
        private ClinicalKnowledgeProposalSource $source,
        private ClinicalKnowledgeRelevanceAssessor $assessor = new AbstainingClinicalKnowledgeRelevanceAssessor(),
    ) {}

    public function retrieve(string $concern): ClinicalKnowledgeAssessment
    {
        if (config('ai.clinical_knowledge') !== true) {
            return ClinicalKnowledgeAssessment::disabled();
        }

        return (new ClinicalKnowledgeSemanticGate($this->assessor))->accept(
            $concern,
            $this->source->propose($concern, $this->catalog),
            $this->catalog,
        );
    }
}
