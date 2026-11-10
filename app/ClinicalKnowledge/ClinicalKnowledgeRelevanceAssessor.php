<?php

namespace App\ClinicalKnowledge;

/**
 * Independent of the proposal source. A selector must not grade its own ids.
 */
interface ClinicalKnowledgeRelevanceAssessor
{
    public function judge(string $concern, ClinicalKnowledgeEntry $entry): string;
}
