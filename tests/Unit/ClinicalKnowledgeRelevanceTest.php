<?php

namespace Tests\Unit;

use App\ClinicalKnowledge\ClinicalKnowledgeCatalog;
use App\ClinicalKnowledge\ClinicalKnowledgeEntry;
use App\ClinicalKnowledge\ClinicalKnowledgeProposal;
use App\ClinicalKnowledge\ClinicalKnowledgeProposalSource;
use App\ClinicalKnowledge\ClinicalKnowledgeRelevance;
use App\ClinicalKnowledge\ClinicalKnowledgeRelevanceAssessor;
use App\ClinicalKnowledge\ClinicalKnowledgeSemanticRetriever;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\ClinicalKnowledge\EngineeringRelevanceLabels;
use Tests\TestCase;

class ClinicalKnowledgeRelevanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.clinical_knowledge' => true,
            'ai.safety_evidence_schema' => false,
        ]);

        Http::preventStrayRequests();
    }

    public function test_an_independent_label_confirms_relevance_and_rejects_a_wrong_source(): void
    {
        $confirmed = 0;
        $irrelevantExcluded = 0;
        $falseRelevant = 0;
        $falseIrrelevant = 0;

        foreach (EngineeringRelevanceLabels::scenarios() as $scenario) {
            $result = $this->retriever($scenario, judged: true)->retrieve($scenario['text']);
            $this->assertFalse($result->assignsClinicalUrgency(), $scenario['id']);
            $this->assertFalse($result->harmless(), $scenario['id']);
            $this->assertSame([], $result->specialistIds(), $scenario['id']);

            $relevantIds = array_keys(array_filter(
                $scenario['judgments'],
                static fn (string $level): bool => $level === ClinicalKnowledgeRelevance::RELEVANT,
            ));

            if ($scenario['expected'] === 'relevant') {
                $this->assertEqualsCanonicalizing($scenario['proposal'], $this->ids($result->currentReferences), $scenario['id']);
                $this->assertFalse($result->knowledgeGap, $scenario['id']);
                $confirmed += count($scenario['proposal']);
                $falseIrrelevant += count(array_intersect($relevantIds, array_column($result->rejected, 'id')));
            }

            if ($scenario['expected'] === 'irrelevant') {
                $this->assertSame([], $result->currentReferences, $scenario['id']);
                $this->assertSame(['irrelevant'], array_column($result->rejected, 'reason'), $scenario['id']);
                $this->assertTrue($result->knowledgeGap, $scenario['id']);
                $irrelevantExcluded += count($scenario['proposal']);
                $falseRelevant += count($result->currentReferences);
            }

            if ($scenario['expected'] === 'possibly_relevant') {
                $this->assertSame($scenario['proposal'], $this->ids($result->possiblyRelevantReferences), $scenario['id']);
                $this->assertSame([], $result->currentReferences, $scenario['id']);
                $this->assertTrue($result->knowledgeGap, $scenario['id']);
            }

            if ($scenario['expected'] === 'insufficient_information') {
                $this->assertSame($scenario['proposal'], $this->ids($result->insufficientReferences), $scenario['id']);
                $this->assertSame([], $result->currentReferences, $scenario['id']);
                $this->assertTrue($result->knowledgeGap, $scenario['id']);
            }

            if ($scenario['expected'] === 'population') {
                $this->assertSame([], $result->currentReferences, $scenario['id']);
                $this->assertSame(['population'], array_column($result->rejected, 'reason'), $scenario['id']);
                $this->assertSame(
                    ClinicalKnowledgeRelevance::POPULATION_INAPPLICABLE,
                    $result->rejected[0]['level'],
                    $scenario['id'],
                );
            }

            if ($scenario['expected'] === 'stale') {
                $this->assertSame($scenario['proposal'], $this->ids($result->staleReferences), $scenario['id']);
                $this->assertSame(ClinicalKnowledgeRelevance::STALE_OR_UNSUPPORTED, $result->staleReferences[0]['relevance_level']);
                $this->assertSame([], $result->currentReferences, $scenario['id']);
                $this->assertTrue($result->knowledgeGap, $scenario['id']);
            }

            if ($scenario['expected'] === 'invented') {
                $this->assertSame('invented', $result->rejected[0]['reason'], $scenario['id']);
                $this->assertSame([], $result->currentReferences, $scenario['id']);
            }

            if ($scenario['expected'] === 'withheld') {
                $this->assertSame('withheld', $result->selectorStatus, $scenario['id']);
                $this->assertSame([], $result->currentReferences, $scenario['id']);
            }

            if ($scenario['expected'] === 'gap') {
                $this->assertSame([], $result->currentReferences, $scenario['id']);
                $this->assertTrue($result->knowledgeGap, $scenario['id']);
            }
        }

        $this->assertSame(17, $confirmed);
        $this->assertSame(0, $falseIrrelevant);
        $this->assertSame(8, $irrelevantExcluded);
        $this->assertSame(0, $falseRelevant);
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, DB::table('audit_events')->count());
        Http::assertNothingSent();
    }

    public function test_without_an_independent_judgment_a_valid_id_stays_unconfirmed(): void
    {
        $confirmed = 0;
        $possibly = 0;
        $unassessed = 0;
        $calledIrrelevant = 0;
        $irrelevantStillNotRelevant = 0;

        foreach (EngineeringRelevanceLabels::scenarios() as $scenario) {
            if (! in_array($scenario['expected'], ['relevant', 'irrelevant'], true)) {
                continue;
            }

            $result = $this->retriever($scenario, judged: false)->retrieve($scenario['text']);
            $this->assertSame([], $result->currentReferences, $scenario['id']);
            $this->assertTrue($result->knowledgeGap, $scenario['id']);
            $this->assertFalse($result->harmless(), $scenario['id']);
            $confirmed += count($result->currentReferences);

            if ($scenario['expected'] === 'relevant') {
                $possibly += count(array_intersect($scenario['proposal'], $this->ids($result->possiblyRelevantReferences)));
                $unassessed += count(array_intersect($scenario['proposal'], $this->ids($result->unassessedReferences)));
                $calledIrrelevant += count(array_intersect($scenario['proposal'], array_column($result->rejected, 'id')));
            }

            if ($scenario['expected'] === 'irrelevant') {
                $irrelevantStillNotRelevant += count($scenario['proposal']);
                $this->assertSame([], array_intersect($scenario['proposal'], $this->ids($result->currentReferences)), $scenario['id']);
            }
        }

        $this->assertSame(0, $confirmed);
        $this->assertSame(5, $possibly);
        $this->assertSame(12, $unassessed);
        $this->assertSame(0, $calledIrrelevant);
        $this->assertSame(8, $irrelevantStillNotRelevant);
    }

    public function test_malformed_duplicate_and_self_scored_payloads_do_not_become_relevant(): void
    {
        $catalog = ClinicalKnowledgeCatalog::published();
        $cases = [
            'duplicate' => ['source_ids' => ['medlineplus-nausea-vomiting', 'medlineplus-nausea-vomiting']],
            'four-ids' => ['source_ids' => [
                'medlineplus-nausea-vomiting',
                'medlineplus-chest-pain',
                'medlineplus-headache',
                'medlineplus-cough',
            ]],
            'self-score' => ['source_ids' => ['medlineplus-fever'], 'relevance' => 'relevant'],
            'doctor' => ['source_ids' => ['medlineplus-nausea-vomiting'], 'doctor_id' => 9],
        ];

        foreach ($cases as $name => $payload) {
            $result = (new ClinicalKnowledgeSemanticRetriever($catalog, new class($payload) implements ClinicalKnowledgeProposalSource
            {
                public function __construct(private array $payload) {}

                public function propose(string $concern, ClinicalKnowledgeCatalog $catalog): ClinicalKnowledgeProposal
                {
                    return ClinicalKnowledgeProposal::fromModelPayload($this->payload);
                }
            }))->retrieve('Nahulog ako at masakit ang tuhod ko.');

            $this->assertSame('malformed', $result->selectorStatus, $name);
            $this->assertSame([], $result->currentReferences, $name);
            $this->assertSame([], $result->unassessedReferences, $name);
            $this->assertTrue($result->knowledgeGap, $name);
        }

        $failed = (new ClinicalKnowledgeSemanticRetriever(
            $catalog,
            new class implements ClinicalKnowledgeProposalSource
            {
                public function propose(string $concern, ClinicalKnowledgeCatalog $catalog): ClinicalKnowledgeProposal
                {
                    return ClinicalKnowledgeProposal::failed();
                }
            },
        ))->retrieve('Nagsusuka ako.');
        $this->assertSame('failed', $failed->selectorStatus);
        $this->assertTrue($failed->knowledgeGap);

        $badJudge = new ClinicalKnowledgeSemanticRetriever(
            $catalog,
            new class implements ClinicalKnowledgeProposalSource
            {
                public function propose(string $concern, ClinicalKnowledgeCatalog $catalog): ClinicalKnowledgeProposal
                {
                    return ClinicalKnowledgeProposal::fromModelPayload(['source_ids' => ['medlineplus-fever']]);
                }
            },
            new class implements ClinicalKnowledgeRelevanceAssessor
            {
                public function judge(string $concern, ClinicalKnowledgeEntry $entry): string
                {
                    return 'approved';
                }
            },
        );
        $coerced = $badJudge->retrieve('Nahulog ako at masakit ang tuhod ko.');
        $this->assertSame([], $coerced->currentReferences);
        $this->assertSame(['medlineplus-fever'], $this->ids($coerced->unassessedReferences));
        $this->assertNotSame(ClinicalKnowledgeRelevance::RELEVANT, $coerced->unassessedReferences[0]['relevance_level']);
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function retriever(array $scenario, bool $judged): ClinicalKnowledgeSemanticRetriever
    {
        $source = new class($scenario['proposal']) implements ClinicalKnowledgeProposalSource
        {
            public function __construct(private array $proposal) {}

            public function propose(string $concern, ClinicalKnowledgeCatalog $catalog): ClinicalKnowledgeProposal
            {
                return ClinicalKnowledgeProposal::fromModelPayload(['source_ids' => $this->proposal]);
            }
        };

        if (! $judged) {
            return new ClinicalKnowledgeSemanticRetriever(ClinicalKnowledgeCatalog::published(), $source);
        }

        return new ClinicalKnowledgeSemanticRetriever(
            ClinicalKnowledgeCatalog::published(),
            $source,
            new class($scenario['text'], $scenario['judgments']) implements ClinicalKnowledgeRelevanceAssessor
            {
                public function __construct(private string $text, private array $judgments) {}

                public function judge(string $concern, ClinicalKnowledgeEntry $entry): string
                {
                    if ($concern !== $this->text) {
                        return ClinicalKnowledgeRelevance::UNASSESSED;
                    }

                    return $this->judgments[$entry->id] ?? ClinicalKnowledgeRelevance::UNASSESSED;
                }
            },
        );
    }

    /**
     * @param  list<array<string, mixed>>  $references
     * @return list<string>
     */
    private function ids(array $references): array
    {
        return array_column($references, 'id');
    }
}
