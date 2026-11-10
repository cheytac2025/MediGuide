<?php

namespace App\ClinicalKnowledge;

/**
 * Returns unassessed for every source.
 *
 * A second model call was not added. It would be another provider request,
 * its price is unknown, and a model grading its own selection would not be
 * an independent judge. Abstention is the default until a separate review
 * supplies a judgment.
 */
final class AbstainingClinicalKnowledgeRelevanceAssessor implements ClinicalKnowledgeRelevanceAssessor
{
    public function judge(string $concern, ClinicalKnowledgeEntry $entry): string
    {
        return ClinicalKnowledgeRelevance::UNASSESSED;
    }
}
