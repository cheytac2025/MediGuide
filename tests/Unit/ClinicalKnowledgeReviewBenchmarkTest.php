<?php

namespace Tests\Unit;

use App\ClinicalKnowledge\ClinicalKnowledgeCatalog;
use App\ClinicalKnowledge\ClinicalKnowledgeReviewBenchmark;
use App\ClinicalKnowledge\ClinicalKnowledgeReviewPackage;
use App\ClinicalKnowledge\ClinicalKnowledgeReviewRatings;
use App\ClinicalKnowledge\EngineeringRelevanceLabels;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClinicalKnowledgeReviewBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_the_worksheet_uses_the_existing_scenarios_and_leaves_clinical_cells_blank(): void
    {
        $catalog = ClinicalKnowledgeCatalog::published();
        $pairs = ClinicalKnowledgeReviewPackage::pairs($catalog);
        $scenarioIds = array_values(array_unique(array_column($pairs, 'scenario_id')));

        $this->assertCount(33, EngineeringRelevanceLabels::scenarios());
        $this->assertCount(33, $scenarioIds);
        $this->assertCount(14, $catalog->entries());
        $this->assertNotContains('doh-cpg-compendium', array_column($pairs, 'source_id'));
        $this->assertContains('chest-specialist-9', array_column($pairs, 'source_id'));

        $markdown = ClinicalKnowledgeReviewPackage::worksheetMarkdown($catalog);
        $this->assertStringContainsString('filipino-vomiting', $markdown);
        $this->assertStringContainsString('taglish-headache', $markdown);
        $this->assertStringContainsString('informal-headache', $markdown);
        $this->assertStringContainsString('| Clinical reviewer relevance assessment | |', $markdown);
        $this->assertStringContainsString('| Safety concerns | |', $markdown);
        $this->assertStringNotContainsString('| Appropriate |', $markdown);
        $this->assertStringContainsString('doh-cpg-compendium', $markdown);

        $checkedIn = file_get_contents(base_path('docs/clinical-evaluation/P3-relevance-worksheet.md'));
        $this->assertSame($markdown, $checkedIn);
    }

    public function test_an_empty_review_file_is_awaiting_clinical_review_and_approves_nothing(): void
    {
        $result = ClinicalKnowledgeReviewBenchmark::published();

        $this->assertSame(ClinicalKnowledgeReviewRatings::AWAITING, $result->status);
        $this->assertSame(0, $result->submittedRatings);
        $this->assertFalse($result->approvesCatalog());
        $this->assertFalse($result->usesEngineeringLabelsAsGroundTruth());
        $this->assertFalse($result->assignsClinicalUrgency());
        $this->assertSame([], $result->specialistIds());
        $this->assertSame([], $result->agreements);
        $this->assertSame([], $result->disagreements);

        $notEvaluated = $this->benchmark()->load([
            $this->row('english-chest', 'medlineplus-chest-pain', 'not_evaluated', 'not_evaluated'),
        ]);
        $this->assertSame(ClinicalKnowledgeReviewRatings::AWAITING, $notEvaluated->status);
        $this->assertSame(1, $notEvaluated->notEvaluatedRows);
        $this->assertSame(0, $notEvaluated->submittedRatings);
        $this->assertFalse($notEvaluated->approvesCatalog());

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, DB::table('audit_events')->count());
        Http::assertNothingSent();

        foreach ([
            app_path('Services/Ai/ClaudeGuidanceService.php'),
            app_path('Http/Controllers/Patient/AiGuidanceController.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('ClinicalKnowledgeReview', $source);
        }
    }

    public function test_invalid_reviews_are_rejected_without_becoming_approval(): void
    {
        $benchmark = $this->benchmark();

        $blank = $benchmark->load([
            array_merge($this->row('english-chest', 'medlineplus-chest-pain', 'relevant', 'appropriate'), [
                'reviewer_name' => ' ',
            ]),
        ]);
        $this->assertSame(ClinicalKnowledgeReviewRatings::REJECTED, $blank->status);
        $this->assertFalse($blank->approvesCatalog());

        $unknownRating = $benchmark->load([
            $this->row('english-chest', 'medlineplus-chest-pain', 'approved', 'appropriate'),
        ]);
        $this->assertSame(ClinicalKnowledgeReviewRatings::REJECTED, $unknownRating->status);

        $unknownScenario = $benchmark->load([
            $this->row('real-patient-note', 'medlineplus-chest-pain', 'relevant', 'appropriate'),
        ]);
        $this->assertSame(ClinicalKnowledgeReviewRatings::REJECTED, $unknownScenario->status);

        $unknownSource = $benchmark->load([
            $this->row('invented-id', 'chest-specialist-9', 'irrelevant', 'not_applicable'),
        ]);
        $this->assertSame(ClinicalKnowledgeReviewRatings::REJECTED, $unknownSource->status);

        $duplicate = $benchmark->load([
            $this->row('english-chest', 'medlineplus-chest-pain', 'relevant', 'appropriate'),
            $this->row('english-chest', 'medlineplus-chest-pain', 'irrelevant', 'unsafe'),
        ]);
        $this->assertSame(ClinicalKnowledgeReviewRatings::REJECTED, $duplicate->status);
        $this->assertSame([], $duplicate->disagreements);
    }

    public function test_reviewer_disagreement_is_kept_and_a_single_opinion_is_not_approval(): void
    {
        $benchmark = $this->benchmark();
        $one = $benchmark->load([
            $this->row('fever-for-knee', 'medlineplus-fever', 'irrelevant', 'unsafe', 'The fever page does not fit a knee injury.'),
        ]);

        $this->assertSame(ClinicalKnowledgeReviewRatings::RECEIVED, $one->status);
        $this->assertSame([], $one->agreements);
        $this->assertSame([], $one->disagreements);
        $this->assertFalse($one->approvesCatalog());
        $this->assertFalse($one->assignsClinicalUrgency());

        $two = $benchmark->load([
            $this->row('fever-for-knee', 'medlineplus-fever', 'irrelevant', 'unsafe', 'Unsafe to present as relevant.', 'Reviewer One'),
            $this->row('fever-for-knee', 'medlineplus-fever', 'relevant', 'appropriate', 'The page is still useful background.', 'Reviewer Two'),
        ]);

        $this->assertSame(ClinicalKnowledgeReviewRatings::RECEIVED, $two->status);
        $this->assertCount(1, $two->disagreements);
        $this->assertTrue($two->disagreements[0]['adjudication_required']);
        $this->assertNull($two->disagreements[0]['final_decision']);
        $this->assertSame('irrelevant', $two->disagreements[0]['relevance']['Reviewer One']);
        $this->assertSame('relevant', $two->disagreements[0]['relevance']['Reviewer Two']);
        $this->assertSame('unsafe', $two->disagreements[0]['safety']['Reviewer One']);
        $this->assertSame('appropriate', $two->disagreements[0]['safety']['Reviewer Two']);
        $this->assertCount(2, $two->disagreements[0]['comments']);
        $this->assertFalse($two->approvesCatalog());
        $this->assertFalse($two->usesEngineeringLabelsAsGroundTruth());

        $closed = $benchmark->load([
            $this->row('fever-for-knee', 'medlineplus-fever', 'irrelevant', 'unsafe', 'Unsafe to present as relevant.', 'Reviewer One'),
            $this->row('fever-for-knee', 'medlineplus-fever', 'relevant', 'appropriate', 'The page is still useful background.', 'Reviewer Two'),
            [
                'record_type' => 'adjudication',
                'scenario_id' => 'fever-for-knee',
                'source_id' => 'medlineplus-fever',
                'final_relevance' => 'irrelevant',
                'final_safety' => 'needs_revision',
                'adjudicator_name' => 'Adjudicator',
                'adjudicator_designation' => 'Physician',
                'credentials' => 'To be completed by the adjudicator',
                'institution' => 'To be completed by the adjudicator',
                'reviewed_on' => '2026-11-10',
                'signature' => 'To be signed by the adjudicator',
                'comments' => 'Safety disagreement stands until the page is revised.',
            ],
        ]);

        $this->assertSame('irrelevant', $closed->disagreements[0]['final_decision']['final_relevance']);
        $this->assertSame('needs_revision', $closed->disagreements[0]['final_decision']['final_safety']);
        $this->assertFalse($closed->approvesCatalog());
        $this->assertSame([], $closed->specialistIds());
    }

    private function benchmark(): ClinicalKnowledgeReviewBenchmark
    {
        return new ClinicalKnowledgeReviewBenchmark(ClinicalKnowledgeCatalog::published());
    }

    /**
     * @return array<string, string>
     */
    private function row(
        string $scenarioId,
        string $sourceId,
        string $relevance,
        string $safety,
        string $comments = '',
        string $name = 'Synthetic Reviewer',
    ): array {
        return [
            'reviewer_name' => $name,
            'professional_designation' => 'To be completed by the reviewer',
            'credentials' => 'To be completed by the reviewer',
            'institution' => 'To be completed by the reviewer',
            'reviewed_on' => '2026-11-10',
            'signature' => 'To be signed by the reviewer',
            'scenario_id' => $scenarioId,
            'source_id' => $sourceId,
            'relevance' => $relevance,
            'safety' => $safety,
            'applicability' => '',
            'comments' => $comments,
        ];
    }
}
