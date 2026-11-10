<?php

namespace App\ClinicalKnowledge;

/**
 * Loads future reviewer assessments. Engineering labels are not the ground truth.
 *
 * An empty file means the review has not started. A loaded review does not
 * approve a knowledge entry, assign urgency, or name a doctor.
 */
final class ClinicalKnowledgeReviewBenchmark
{
    public function __construct(private ClinicalKnowledgeCatalog $catalog) {}

    public static function published(): ClinicalKnowledgeReviewBenchmarkResult
    {
        $path = resource_path('clinical-knowledge/review-assessments.php');
        $rows = is_file($path) ? require $path : [];

        return (new self(ClinicalKnowledgeCatalog::published()))->load(is_array($rows) ? $rows : null);
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     */
    public function load(?array $rows): ClinicalKnowledgeReviewBenchmarkResult
    {
        if ($rows === null) {
            return $this->rejected(['The assessment file is not a list.']);
        }

        if ($rows === []) {
            return $this->awaiting();
        }

        $errors = [];
        $assessments = [];
        $adjudications = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors[] = 'Row '.$index.' is not a record.';

                continue;
            }

            if (($row['record_type'] ?? 'assessment') === 'adjudication') {
                $adjudication = $this->adjudication($row, $index, $errors);
                if ($adjudication !== null) {
                    $adjudications[$adjudication['key']] = $adjudication;
                }

                continue;
            }

            if (array_diff(array_keys($row), $this->assessmentKeys()) !== []) {
                $errors[] = 'Row '.$index.' has an unexpected field.';

                continue;
            }

            $assessment = $this->assessment($row, $index, $errors);
            if ($assessment === null) {
                continue;
            }

            if (isset($seen[$assessment['key']])) {
                $errors[] = 'Row '.$index.' repeats a reviewer assessment.';

                continue;
            }

            $seen[$assessment['key']] = true;
            $assessments[] = $assessment;
        }

        if ($errors !== []) {
            return $this->rejected($errors);
        }

        $submitted = 0;
        $missing = 0;

        foreach ($assessments as $assessment) {
            if ($assessment['relevance'] === 'not_evaluated' && $assessment['safety'] === 'not_evaluated') {
                $missing++;
            } else {
                $submitted++;
            }
        }

        $groups = [];

        foreach ($assessments as $assessment) {
            $groups[$assessment['pair']][] = $assessment;
        }

        $agreements = [];
        $disagreements = [];

        foreach ($groups as $pair => $group) {
            $relevance = $this->answered($group, 'relevance');
            $safety = $this->answered($group, 'safety');
            $comments = [];

            foreach ($group as $assessment) {
                if ($assessment['comments'] !== '') {
                    $comments[] = [
                        'reviewer_name' => $assessment['reviewer_name'],
                        'comments' => $assessment['comments'],
                    ];
                }
            }

            $relevanceDisagrees = count(array_unique(array_values($relevance))) > 1;
            $safetyDisagrees = count(array_unique(array_values($safety))) > 1;
            $record = [
                'scenario_id' => $group[0]['scenario_id'],
                'source_id' => $group[0]['source_id'],
                'relevance' => $relevance,
                'safety' => $safety,
                'comments' => $comments,
                'adjudication_required' => $relevanceDisagrees || $safetyDisagrees,
                'final_decision' => $adjudications[$pair] ?? null,
            ];

            if ($record['adjudication_required']) {
                $disagreements[] = $record;
            } elseif (count($group) > 1 && ($relevance !== [] || $safety !== [])) {
                $agreements[] = $record;
            }
        }

        if ($submitted === 0) {
            return $this->awaiting($missing);
        }

        return new ClinicalKnowledgeReviewBenchmarkResult(
            status: ClinicalKnowledgeReviewRatings::RECEIVED,
            submittedRatings: $submitted,
            notEvaluatedRows: $missing,
            agreements: $agreements,
            disagreements: $disagreements,
            errors: [],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function assessment(array $row, int $index, array &$errors): ?array
    {
        $scenarioId = $this->text($row, 'scenario_id');
        $scenario = is_string($scenarioId) ? EngineeringRelevanceLabels::find($scenarioId) : null;

        if ($scenario === null) {
            $errors[] = 'Row '.$index.' has an unknown scenario.';

            return null;
        }

        $sourceId = $row['source_id'] ?? null;

        if ($sourceId === null || $sourceId === '') {
            if ($scenario['proposal'] !== []) {
                $errors[] = 'Row '.$index.' is missing a source.';

                return null;
            }

            $sourceId = null;
        } elseif (! is_string($sourceId) || $this->catalog->find($sourceId) === null) {
            $errors[] = 'Row '.$index.' has an unknown source.';

            return null;
        } elseif (! in_array($sourceId, $scenario['proposal'], true)) {
            $errors[] = 'Row '.$index.' names a source that was not proposed for that scenario.';

            return null;
        }

        foreach (['reviewer_name', 'professional_designation', 'credentials', 'institution', 'reviewed_on', 'signature'] as $field) {
            if ($this->text($row, $field) === null) {
                $errors[] = 'Row '.$index.' has a blank reviewer field.';

                return null;
            }
        }

        $relevance = $this->choice($row, 'relevance', ClinicalKnowledgeReviewRatings::relevance());
        $safety = $this->choice($row, 'safety', ClinicalKnowledgeReviewRatings::safety());

        if ($relevance === null || $safety === null) {
            $errors[] = 'Row '.$index.' has an invalid rating.';

            return null;
        }

        $applicability = $row['applicability'] ?? '';

        if ($applicability !== '' && ! in_array($applicability, ClinicalKnowledgeReviewRatings::applicability(), true)) {
            $errors[] = 'Row '.$index.' has an invalid applicability rating.';

            return null;
        }

        $reviewer = $this->text($row, 'reviewer_name');

        return [
            'key' => $reviewer.'|'.$scenarioId.'|'.($sourceId ?? ''),
            'pair' => $scenarioId.'|'.($sourceId ?? ''),
            'reviewer_name' => $reviewer,
            'scenario_id' => $scenarioId,
            'source_id' => $sourceId,
            'relevance' => $relevance,
            'safety' => $safety,
            'comments' => is_string($row['comments'] ?? null) ? trim($row['comments']) : '',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function adjudication(array $row, int $index, array &$errors): ?array
    {
        if (array_diff(array_keys($row), [
            'record_type', 'scenario_id', 'source_id', 'final_relevance', 'final_safety',
            'adjudicator_name', 'adjudicator_designation', 'credentials', 'institution',
            'reviewed_on', 'signature', 'comments',
        ]) !== []) {
            $errors[] = 'Row '.$index.' has an unexpected field.';

            return null;
        }

        $scenarioId = $this->text($row, 'scenario_id');

        if (! is_string($scenarioId) || EngineeringRelevanceLabels::find($scenarioId) === null) {
            $errors[] = 'Row '.$index.' has an unknown scenario.';

            return null;
        }

        $sourceId = $row['source_id'] ?? null;
        $scenario = EngineeringRelevanceLabels::find($scenarioId);

        if ($sourceId === null || $sourceId === '') {
            if ($scenario === null || $scenario['proposal'] !== []) {
                $errors[] = 'Row '.$index.' has an unknown source.';

                return null;
            }

            $sourceId = '';
        } elseif (! is_string($sourceId) || $this->catalog->find($sourceId) === null || ! in_array($sourceId, $scenario['proposal'], true)) {
            $errors[] = 'Row '.$index.' has an unknown source.';

            return null;
        }

        foreach (['adjudicator_name', 'adjudicator_designation', 'credentials', 'institution', 'reviewed_on', 'signature'] as $field) {
            if ($this->text($row, $field) === null) {
                $errors[] = 'Row '.$index.' has a blank reviewer field.';

                return null;
            }
        }

        $relevance = $row['final_relevance'] ?? null;
        $safety = $row['final_safety'] ?? null;

        if (! is_string($relevance) || ! in_array($relevance, ClinicalKnowledgeReviewRatings::relevance(), true) || $relevance === 'not_evaluated') {
            $errors[] = 'Row '.$index.' has an invalid rating.';

            return null;
        }

        if (! is_string($safety) || ! in_array($safety, ClinicalKnowledgeReviewRatings::safety(), true) || $safety === 'not_evaluated') {
            $errors[] = 'Row '.$index.' has an invalid rating.';

            return null;
        }

        return [
            'key' => $scenarioId.'|'.$sourceId,
            'final_relevance' => $relevance,
            'final_safety' => $safety,
            'comments' => is_string($row['comments'] ?? null) ? trim($row['comments']) : '',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $group
     * @return array<string, string>
     */
    private function answered(array $group, string $field): array
    {
        $values = [];

        foreach ($group as $assessment) {
            if ($assessment[$field] !== 'not_evaluated') {
                $values[$assessment['reviewer_name']] = $assessment[$field];
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function assessmentKeys(): array
    {
        return [
            'reviewer_name', 'professional_designation', 'credentials', 'institution',
            'reviewed_on', 'signature', 'scenario_id', 'source_id', 'relevance',
            'safety', 'applicability', 'comments',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $allowed
     */
    private function choice(array $row, string $key, array $allowed): ?string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            return null;
        }

        return $value;
    }

    private function awaiting(int $missing = 0): ClinicalKnowledgeReviewBenchmarkResult
    {
        return new ClinicalKnowledgeReviewBenchmarkResult(
            status: ClinicalKnowledgeReviewRatings::AWAITING,
            submittedRatings: 0,
            notEvaluatedRows: $missing,
            agreements: [],
            disagreements: [],
            errors: [],
        );
    }

    /**
     * @param  list<string>  $errors
     */
    private function rejected(array $errors): ClinicalKnowledgeReviewBenchmarkResult
    {
        return new ClinicalKnowledgeReviewBenchmarkResult(
            status: ClinicalKnowledgeReviewRatings::REJECTED,
            submittedRatings: 0,
            notEvaluatedRows: 0,
            agreements: [],
            disagreements: [],
            errors: $errors,
        );
    }
}
