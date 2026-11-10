<?php

namespace App\ClinicalKnowledge;

/**
 * Supplies a source-id proposal for isolated evaluation.
 *
 * The Front Desk does not use this. A live model call is a separate approval.
 */
interface ClinicalKnowledgeProposalSource
{
    public function propose(string $concern, ClinicalKnowledgeCatalog $catalog): ClinicalKnowledgeProposal;
}
