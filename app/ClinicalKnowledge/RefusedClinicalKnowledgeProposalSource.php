<?php

namespace App\ClinicalKnowledge;

/**
 * Returns a provider failure without contacting a model.
 */
final class RefusedClinicalKnowledgeProposalSource implements ClinicalKnowledgeProposalSource
{
    public function propose(string $concern, ClinicalKnowledgeCatalog $catalog): ClinicalKnowledgeProposal
    {
        return ClinicalKnowledgeProposal::failed();
    }
}
