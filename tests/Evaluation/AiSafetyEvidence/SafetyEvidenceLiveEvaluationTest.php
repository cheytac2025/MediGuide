<?php

namespace Tests\Evaluation\AiSafetyEvidence;

use App\Enums\AiGuidanceType;
use App\Enums\AiSafetyEvidenceStatus;
use App\Enums\AiSafetyKind;
use App\Models\Appointment;
use App\Models\User;
use App\Services\Ai\ClaudeGuidanceService;
use App\Services\Ai\SafetyEvidenceLiveEvaluation;
use App\Services\Ai\SafetyEvidenceLivePlan;
use App\Support\AiConversationState;
use App\Support\AiGuidanceResult;
use App\Support\AiSafetyEvidenceValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SafetyEvidenceLiveEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private const string API_KEY = 'synthetic-key-should-not-print';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => self::API_KEY,
            'services.anthropic.model' => 'claude-sonnet-5',
            'services.anthropic.version' => '2023-06-01',
            'services.anthropic.max_tokens' => 4096,
            'services.anthropic.retry_max_tokens' => 8192,
            'ai.safety_evidence_schema' => false,
            'hospital.data_source' => 'development',
        ]);

        Http::preventStrayRequests();
    }

    public function test_the_evaluation_schema_is_isolated_from_the_front_desk_schema(): void
    {
        $schema = $this->app->make(ClaudeGuidanceService::class)->evaluationSchema();

        $this->assertFalse(config('ai.safety_evidence_schema'));
        $this->assertSame(
            ['recommendation', 'clarification', 'uncertain', 'safety'],
            $this->schemaTypes($schema),
        );
        $this->assertSame(
            ['type', 'message', 'basis', 'evidence', 'proposed_level'],
            $this->safetyRequired($schema),
        );
        $encoded = json_encode($schema, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('maxLength', $encoded);
        $this->assertStringNotContainsString('maxItems', $encoded);
        $this->assertFalse($this->safetyBranch($schema)['additionalProperties']);
        $this->assertFalse($this->safetyBranch($schema)['properties']['evidence']['items']['additionalProperties']);
        $this->assertSame(['quote'], $this->safetyBranch($schema)['properties']['evidence']['items']['required']);
        $overlong = $this->app->make(AiSafetyEvidenceValidator::class)->evaluate([
            'type' => 'safety',
            'message' => 'Please seek care. This is not a diagnosis.',
            'basis' => 'visitor_reported',
            'evidence' => [['quote' => str_repeat('a', 201)]],
            'proposed_level' => 'prompt_evaluation',
        ], AiConversationState::empty(), str_repeat('a', 201));
        $this->assertSame(AiSafetyEvidenceStatus::InvalidContract, $overlong->status);

        Http::fake(fn (Request $request) => $this->guardedResponse($request, $this->safetyPayload('not said by the visitor')));

        $this->artisan('mediguide:evaluate-safety-evidence', ['--execute' => true, '--limit' => 1])
            ->expectsOutputToContain('Global evidence schema: disabled')
            ->expectsOutputToContain('Provider calls: 1')
            ->doesntExpectOutputToContain(self::API_KEY)
            ->doesntExpectOutputToContain('SENTINEL_DO_NOT_LOG')
            ->assertSuccessful();

        $evaluationSchema = Http::recorded()[0][0]->data()['output_config']['format']['schema'];
        $this->assertSame('json_schema', Http::recorded()[0][0]->data()['output_config']['format']['type']);
        $this->assertSame(
            ['type', 'message', 'basis', 'evidence', 'proposed_level'],
            $this->safetyRequired($evaluationSchema),
        );
        $wireSchema = json_encode($evaluationSchema, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('maxLength', $wireSchema);
        $this->assertStringNotContainsString('maxItems', $wireSchema);

        $guidance = $this->app->make(ClaudeGuidanceService::class);
        $vomiting = $guidance->guide('Nagsusuka ako.');
        $headache = $guidance->guide('Masakit ulo ko.');

        $this->assertSame(AiGuidanceType::Safety, $vomiting->type);
        $this->assertSame(AiSafetyKind::PromptEvaluation, $vomiting->safetyKind);
        $this->assertSame(AiGuidanceType::Clarification, $headache->type);
        $this->assertSame(ClaudeGuidanceService::HEADACHE_FOLLOW_UP_FILIPINO, $headache->question);

        $frontDeskSchema = Http::recorded()[1][0]->data()['output_config']['format']['schema'];
        $this->assertSame(['type', 'message'], $this->safetyRequired($frontDeskSchema));
        $frontDeskEncoded = json_encode($frontDeskSchema, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('maxLength', $frontDeskEncoded);
        $this->assertStringNotContainsString('maxItems', $frontDeskEncoded);
        $this->assertStringNotContainsString('minLength', $frontDeskEncoded);
        $this->assertStringContainsString('"additionalProperties":false', $frontDeskEncoded);
        $this->assertSame(
            ['recommendation', 'clarification', 'uncertain', 'safety'],
            $this->schemaTypes($frontDeskSchema),
        );
        $this->assertFalse(config('ai.safety_evidence_schema'));
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, DB::table('audit_events')->count());
        Http::assertSentCount(3);
    }

    public function test_front_desk_wire_schema_drops_provider_limits_and_laravel_still_enforces_them(): void
    {
        $encoded = json_encode($this->app->make(ClaudeGuidanceService::class)->wireSchema(false), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('maxLength', $encoded);
        $this->assertStringNotContainsString('maxItems', $encoded);
        $this->assertStringContainsString('"additionalProperties":false', $encoded);
        $this->assertSame(400, AiGuidanceResult::MAX_TEXT_LENGTH);

        $this->expectException(InvalidArgumentException::class);

        AiGuidanceResult::fromPayload([
            'type' => 'clarification',
            'question' => str_repeat('a', AiGuidanceResult::MAX_TEXT_LENGTH + 1),
        ]);
    }

    public function test_dry_run_sends_no_provider_request_and_prints_the_budget(): void
    {
        Http::fake();

        $this->artisan('mediguide:evaluate-safety-evidence', ['--limit' => 12])
            ->expectsOutputToContain('Dry run. No provider request was sent.')
            ->expectsOutputToContain('Model: claude-sonnet-5')
            ->expectsOutputToContain('Maximum output tokens: 4096')
            ->expectsOutputToContain('Retry output tokens: 8192')
            ->expectsOutputToContain('Request limit: 12')
            ->expectsOutputToContain('Hard maximum provider calls, including retries: 12')
            ->expectsOutputToContain('Provider calls: 0')
            ->expectsOutputToContain('Global evidence schema: disabled')
            ->expectsOutputToContain('Dollar cost: unknown')
            ->expectsOutputToContain('vague-sensation')
            ->expectsOutputToContain('denial-blood')
            ->expectsOutputToContain('sleep')
            ->doesntExpectOutputToContain(self::API_KEY)
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertFalse(config('ai.safety_evidence_schema'));
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, DB::table('audit_events')->count());
        $this->assertCount(SafetyEvidenceLivePlan::MAX_PROVIDER_CALLS, SafetyEvidenceLivePlan::scenarios());
        $this->assertNotContains('existing-emergency', array_column(SafetyEvidenceLivePlan::scenarios(), 'id'));
        $this->assertNotContains('existing-prompt-overlay', array_column(SafetyEvidenceLivePlan::scenarios(), 'id'));

        $defaultIds = array_column(SafetyEvidenceLivePlan::defaultQueue(), 'id');
        $originalIds = array_column(SafetyEvidenceLivePlan::scenarios(), 'id');
        $this->assertSame(
            array_values(array_filter($originalIds, static fn (string $id): bool => $id !== 'vague-sensation')),
            $defaultIds,
        );
        $this->assertNotContains('vague-sensation', $defaultIds);
        $catalogIds = array_column(SyntheticConcernCatalog::scenarios(), 'id');
        $this->assertCount(34, $catalogIds);
        foreach ($catalogIds as $id) {
            $this->assertTrue(SafetyEvidenceLivePlan::isCatalogId($id));
        }
    }

    public function test_a_catalog_scenario_can_be_selected_without_a_provider_call(): void
    {
        Http::fake();

        $this->artisan('mediguide:evaluate-safety-evidence', [
            '--scenario' => 'common-vomiting',
            '--limit' => 12,
        ])
            ->expectsOutputToContain('Dry run. No provider request was sent.')
            ->expectsOutputToContain('common-vomiting')
            ->doesntExpectOutputToContain('Completed scenario omitted')
            ->doesntExpectOutputToContain(self::API_KEY)
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertFalse(config('ai.safety_evidence_schema'));

        $run = $this->app->make(SafetyEvidenceLiveEvaluation::class)->run(12, false, ['common-vomiting']);
        $this->assertSame(['common-vomiting'], array_column($run->scenarios, 'id'));
        $this->assertSame(0, $run->providerCalls);
    }

    public function test_unknown_paths_urls_and_free_text_are_rejected_before_a_provider_call(): void
    {
        Http::fake();

        foreach ([
            'not-a-scenario',
            'Nagsusuka ako.',
            'docs/clinical-evaluation/O-safety-evidence-evaluation.md',
            'https://example.com/concern',
            'common-vomiting,denial-blood',
        ] as $scenario) {
            $this->artisan('mediguide:evaluate-safety-evidence', [
                '--execute' => true,
                '--limit' => 1,
                '--scenario' => $scenario,
            ])
                ->expectsOutputToContain('The scenario must be one synthetic catalog ID. No request was sent.')
                ->assertFailed();
        }

        $duplicate = $this->app->make(SafetyEvidenceLiveEvaluation::class)->run(1, true, [
            'common-vomiting',
            'common-vomiting',
        ]);

        $this->assertTrue($duplicate->failed);
        $this->assertSame('unknown_scenario', $duplicate->stopReason);
        $this->assertSame(0, $duplicate->providerCalls);
        Http::assertNothingSent();
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, DB::table('audit_events')->count());
    }

    public function test_an_explicit_scenario_runs_once_even_when_the_limit_is_higher(): void
    {
        Http::fake(fn (Request $request) => $this->guardedResponse($request, $this->safetyPayload('Nagsusuka ako.')));

        $this->artisan('mediguide:evaluate-safety-evidence', [
            '--execute' => true,
            '--limit' => 12,
            '--scenario' => 'common-vomiting',
        ])
            ->expectsOutputToContain('Provider calls: 1')
            ->doesntExpectOutputToContain('SENTINEL_DO_NOT_LOG')
            ->doesntExpectOutputToContain(self::API_KEY)
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame('Nagsusuka ako.', $this->latestMessage(Http::recorded()[0][0]));
        $this->assertFalse(config('ai.safety_evidence_schema'));
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, DB::table('audit_events')->count());
    }

    public function test_execute_scores_the_representative_scenarios_without_applying_urgency(): void
    {
        Http::fake(fn (Request $request) => $this->guardedResponse($request, $this->payloadFor($this->latestMessage($request))));

        $run = $this->app->make(SafetyEvidenceLiveEvaluation::class)->run(
            12,
            true,
            array_column(SafetyEvidenceLivePlan::scenarios(), 'id'),
        );

        Http::assertSentCount(12);
        $this->assertFalse($run->failed);
        $this->assertSame(12, $run->providerCalls);
        $this->assertFalse($run->schemaEnabledGlobally);
        $this->assertFalse(config('ai.safety_evidence_schema'));
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, DB::table('audit_events')->count());
        $this->assertStringNotContainsString('SENTINEL_DO_NOT_LOG', json_encode($run->scenarios, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::API_KEY, json_encode($run->scenarios, JSON_THROW_ON_ERROR));

        $rows = [];

        foreach ($run->scenarios as $scenario) {
            $this->assertFalse($scenario['urgency_applied']);
            $this->assertArrayNotHasKey('latest', $scenario);
            $rows[$scenario['id']] = $scenario;
        }

        $this->assertSame('clarification', $rows['vague-sensation']['response_type']);
        $this->assertFalse($rows['vague-sensation']['evidence_supplied']);
        $this->assertTrue($rows['vague-sensation']['follow_up_clarification']);
        $this->assertSame('quoted_without_rule', $rows['unfamiliar-sensation']['validation_status']);
        $this->assertSame('quoted_without_rule', $rows['common-headache']['validation_status']);
        $this->assertSame('uncertain', $rows['english-chest']['response_type']);
        $this->assertSame('quoted_without_rule', $rows['taglish-headache']['validation_status']);
        $this->assertSame('recommendation', $rows['multiple-concerns']['response_type']);
        $this->assertSame('negated', $rows['denial-blood']['validation_status']);
        $this->assertTrue($rows['denial-blood']['quotation_matched']);
        $this->assertFalse($rows['denial-blood']['urgency_applied']);
        $this->assertSame('unknown_answer', $rows['unknown-answer']['validation_status']);
        $this->assertSame('model_inferred', $rows['common-vomiting']['validation_status']);
        $this->assertSame('model_inferred', $rows['common-vomiting']['basis']);
        $this->assertNull($rows['common-vomiting']['quotation_matched']);
        $this->assertSame('safety', $rows['intermittent-headache']['response_type']);
        $this->assertSame('quoted_without_rule', $rows['intermittent-headache']['validation_status']);
        $this->assertSame('quoted_without_rule', $rows['short-answer']['validation_status']);
        $this->assertSame('quoted_without_rule', $rows['sleep']['validation_status']);
        $this->assertTrue($rows['sleep']['evidence_supplied']);
        $this->assertTrue($rows['sleep']['contract_valid']);

        foreach (Http::recorded() as $exchange) {
            $this->assertSame(
                ['type', 'message', 'basis', 'evidence', 'proposed_level'],
                $this->safetyRequired($exchange[0]->data()['output_config']['format']['schema']),
            );
        }
    }

    public function test_a_malformed_safety_contract_stops_the_run(): void
    {
        Http::fake(fn (Request $request) => $this->guardedResponse($request, [
            'type' => 'safety',
            'message' => 'SENTINEL_DO_NOT_LOG',
            'basis' => 'visitor_reported',
            'evidence' => [['quote' => 'May kakaibang pakiramdam sa katawan ko.']],
            'proposed_level' => 'prompt_evaluation',
            'symptom' => 'unlisted',
        ]));

        $this->artisan('mediguide:evaluate-safety-evidence', ['--execute' => true, '--limit' => 12])
            ->expectsOutputToContain('Stopped: malformed_response')
            ->doesntExpectOutputToContain('SENTINEL_DO_NOT_LOG')
            ->assertFailed();

        Http::assertSentCount(1);
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, DB::table('audit_events')->count());
    }

    public function test_a_provider_schema_rejection_stops_the_run(): void
    {
        Http::fake(function (Request $request) {
            $this->assertFalse(config('ai.safety_evidence_schema'));
            $schema = json_encode($request->data()['output_config']['format']['schema'], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('maxItems', $schema);
            $this->assertStringNotContainsString('maxLength', $schema);

            return Http::response([
                'type' => 'error',
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => "output_config.format.schema: For 'array' type, property 'maxItems' is not supported. RAW_PROVIDER_MESSAGE_SHOULD_NOT_PRINT",
                ],
            ], 400);
        });

        $this->artisan('mediguide:evaluate-safety-evidence', ['--execute' => true, '--limit' => 12])
            ->expectsOutputToContain('Stopped: schema_rejected')
            ->expectsOutputToContain('HTTP status: 400')
            ->expectsOutputToContain('Error category: unsupported_schema_keyword:maxItems')
            ->doesntExpectOutputToContain('RAW_PROVIDER_MESSAGE_SHOULD_NOT_PRINT')
            ->assertFailed();

        Http::assertSentCount(1);
        $this->assertFalse(config('ai.safety_evidence_schema'));
    }

    public function test_an_incomplete_response_does_not_use_an_extra_retry_past_the_limit(): void
    {
        Http::fake([
            'api.anthropic.com/*' => function (Request $request) {
                $this->assertFalse(config('ai.safety_evidence_schema'));

                return Http::response([
                    'stop_reason' => 'max_tokens',
                    'content' => [],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 4096],
                ], 200);
            },
        ]);

        $this->artisan('mediguide:evaluate-safety-evidence', ['--execute' => true, '--limit' => 1])
            ->expectsOutputToContain('Stopped: incomplete_response')
            ->expectsOutputToContain('Provider calls: 1')
            ->assertFailed();

        Http::assertSentCount(1);
    }

    public function test_limits_outside_the_budget_and_a_missing_key_or_model_send_nothing(): void
    {
        Http::fake();

        $this->artisan('mediguide:evaluate-safety-evidence', ['--execute' => true, '--limit' => 13])
            ->expectsOutputToContain('The request limit must be an integer from 1 to 12.')
            ->assertFailed();

        $this->artisan('mediguide:evaluate-safety-evidence', ['--execute' => true, '--limit' => 0])
            ->assertFailed();

        config(['services.anthropic.key' => null]);

        $this->artisan('mediguide:evaluate-safety-evidence', ['--execute' => true, '--limit' => 1])
            ->expectsOutputToContain('The Anthropic API key is not available. No request was sent.')
            ->doesntExpectOutputToContain(self::API_KEY)
            ->assertFailed();

        config([
            'services.anthropic.key' => self::API_KEY,
            'services.anthropic.model' => '',
        ]);

        $this->artisan('mediguide:evaluate-safety-evidence', ['--execute' => true, '--limit' => 1])
            ->expectsOutputToContain('The configured Anthropic model is not available. No request was sent.')
            ->doesntExpectOutputToContain(self::API_KEY)
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertFalse(config('ai.safety_evidence_schema'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function guardedResponse(Request $request, array $payload, int $status = 200): \GuzzleHttp\Promise\PromiseInterface
    {
        if (config('ai.safety_evidence_schema') === true) {
            throw new RuntimeException('The evaluation enabled the global evidence schema.');
        }

        return Http::response([
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 800, 'output_tokens' => 60],
            'content' => [[
                'type' => 'text',
                'text' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]],
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(string $latest): array
    {
        return match ($latest) {
            'May kakaibang pakiramdam sa katawan ko.' => [
                'type' => 'clarification',
                'question' => 'Gaano na po katagal ito?',
            ],
            'May ubo ako at masakit ang ulo ko.' => [
                'type' => 'recommendation',
                'service_id' => 1,
                'reason' => 'SENTINEL_DO_NOT_LOG',
                'confidence' => null,
            ],
            'My chest feels weird.' => [
                'type' => 'uncertain',
                'message' => 'SENTINEL_DO_NOT_LOG',
            ],
            'Hindi ako nagsusuka ng dugo.' => $this->safetyPayload('nagsusuka ng dugo', 'emergency'),
            'Hindi ko alam.' => $this->safetyPayload('Hindi ko alam'),
            'Nagsusuka ako.' => [
                'type' => 'safety',
                'message' => 'SENTINEL_DO_NOT_LOG',
                'basis' => 'model_inferred',
                'evidence' => [['quote' => 'vomiting blood']],
                'proposed_level' => 'emergency',
            ],
            'two days' => [
                'type' => 'safety',
                'message' => 'SENTINEL_DO_NOT_LOG',
                'basis' => 'visitor_reported',
                'evidence' => [
                    ['quote' => 'Masakit ulo ko'],
                    ['quote' => 'two days'],
                ],
                'proposed_level' => 'prompt_evaluation',
            ],
            default => $this->safetyPayload($latest),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function safetyPayload(string $quote, string $level = 'prompt_evaluation'): array
    {
        return [
            'type' => 'safety',
            'message' => 'SENTINEL_DO_NOT_LOG',
            'basis' => 'visitor_reported',
            'evidence' => [['quote' => $quote]],
            'proposed_level' => $level,
        ];
    }

    private function latestMessage(Request $request): string
    {
        $messages = $request->data()['messages'] ?? [];
        $latest = $messages[array_key_last($messages)]['content'] ?? '';

        return is_string($latest) ? $latest : '';
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function schemaTypes(array $schema): array
    {
        $types = [];

        foreach ($schema['anyOf'] as $branch) {
            $types[] = $branch['properties']['type']['enum'][0];
        }

        return $types;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function safetyRequired(array $schema): array
    {
        return $this->safetyBranch($schema)['required'];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function safetyBranch(array $schema): array
    {
        foreach ($schema['anyOf'] as $branch) {
            if (($branch['properties']['type']['enum'][0] ?? null) === 'safety') {
                return $branch;
            }
        }

        $this->fail('The schema has no safety branch.');
    }
}
