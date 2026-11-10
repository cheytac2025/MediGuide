<?php

namespace App\Services\Ai;

use App\Enums\AiGuidanceType;
use App\Enums\AiSafetyEvidenceStatus;
use App\Support\AiGuidanceResult;
use App\Support\AiNavigationSafety;
use App\Support\AiSafetyEvidenceValidator;
use InvalidArgumentException;
use Throwable;

/**
 * Isolated safety-evidence evaluation.
 *
 * Dry-run sends nothing. Execution uses the evidence schema for these
 * requests only and does not write the global schema flag, patients,
 * appointments, or audit events. Model payloads stay in memory for scoring
 * and are not returned in the public summary.
 */
final class SafetyEvidenceLiveEvaluation
{
    public function __construct(
        private ClaudeGuidanceService $guidance,
        private AnthropicMessagesClient $anthropic,
        private AiSafetyEvidenceValidator $validator,
        private AiNavigationSafety $navigationSafety,
    ) {}

    /**
     * @param  list<string>|null  $scenarioIds
     */
    public function run(int $limit, bool $execute, ?array $scenarioIds = null): SafetyEvidenceLiveRun
    {
        $schemaFlag = config('ai.safety_evidence_schema');

        try {
            $queue = SafetyEvidenceLivePlan::resolve($scenarioIds);

            if ($queue === null) {
                return $this->stopped('unknown_scenario', 0, $limit, [], 'The scenario is not in the synthetic catalog. No request was sent.');
            }

            return $execute ? $this->execute($limit, $queue) : $this->dryRun($limit, $queue);
        } finally {
            config(['ai.safety_evidence_schema' => $schemaFlag]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $queue
     */
    private function dryRun(int $limit, array $queue): SafetyEvidenceLiveRun
    {
        $estimate = $this->inputEstimate($queue);

        return $this->runResult(
            executed: false,
            failed: false,
            stopReason: null,
            providerCalls: 0,
            limit: $limit,
            approximateInputCharacters: $estimate['characters'],
            roughInputTokens: $estimate['rough_tokens'],
            scenarios: array_map(fn (array $scenario): array => $this->plannedRow($scenario), $queue),
            message: 'Dry run. No provider request was sent.',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $queue
     */
    private function execute(int $limit, array $queue): SafetyEvidenceLiveRun
    {
        if (! $this->anthropic->hasApiKey()) {
            return $this->stopped('api_key_unavailable', 0, $limit, [], 'The Anthropic API key is not available. No request was sent.');
        }

        if ($this->anthropic->configuredModel() === null) {
            return $this->stopped('model_unavailable', 0, $limit, [], 'The configured Anthropic model is not available. No request was sent.');
        }

        $remaining = $limit;
        $calls = 0;
        $rows = [];

        foreach ($queue as $scenario) {
            if ($remaining < 1) {
                break;
            }

            $state = SafetyEvidenceLivePlan::state($scenario);
            $overlay = $this->navigationSafety->classify($scenario['latest'], $state, $scenario['latest']);

            if ($overlay->blocksRoutineNavigation()) {
                $rows[] = $this->scoredRow(
                    $scenario,
                    responseType: 'local_overlay',
                    evidenceSupplied: false,
                    validationStatus: null,
                    quotationMatched: null,
                    basis: null,
                    unsupportedClaim: false,
                    followUpClarification: false,
                    contractValid: true,
                    stopReason: 'local_overlay',
                    calls: 0,
                    inputTokens: null,
                    outputTokens: null,
                    urgencyApplied: false,
                );

                continue;
            }

            try {
                $request = $this->guidance->evaluationRequest($scenario['latest'], $state);
            } catch (Throwable) {
                return $this->stopped('provider_error', $calls, $limit, $rows, 'The evaluation request could not be prepared. No further request was sent.');
            }

            $sent = $this->anthropic->sendEvaluationMessage(
                $request['system'],
                $request['messages'],
                $request['schema'],
                $remaining,
            );
            $calls += $sent['calls'];
            $remaining -= $sent['calls'];
            $usage = is_array($sent['usage']) ? $sent['usage'] : null;

            if ($sent['schema_rejected'] === true) {
                $rows[] = $this->providerStopRow($scenario, $sent, 'schema_rejected');

                return $this->stopped('schema_rejected', $calls, $limit, $rows, 'The provider rejected the evaluation schema. No further request was sent.', $sent);
            }

            if ($sent['status'] === 'failed') {
                $rows[] = $this->providerStopRow($scenario, $sent, 'provider_error');

                return $this->stopped('provider_error', $calls, $limit, $rows, 'The provider request failed. No further request was sent.', $sent);
            }

            if ($sent['status'] === 'incomplete' || ! is_array($sent['payload'])) {
                $rows[] = $this->providerStopRow($scenario, $sent, 'incomplete_response');

                return $this->stopped('incomplete_response', $calls, $limit, $rows, 'The provider response was incomplete. No further request was sent.');
            }

            $scored = $this->score($scenario, $sent['payload'], $sent, $usage);

            if ($scored === null) {
                $rows[] = $this->providerStopRow($scenario, $sent, 'malformed_response');

                return $this->stopped('malformed_response', $calls, $limit, $rows, 'The structured response did not match the evaluation contract. No further request was sent.');
            }

            $rows[] = $scored;
        }

        return $this->runResult(
            executed: true,
            failed: false,
            stopReason: null,
            providerCalls: $calls,
            limit: $limit,
            approximateInputCharacters: null,
            roughInputTokens: null,
            scenarios: $rows,
            message: 'Evaluation finished within the approved request limit. Model text was not stored.',
        );
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $sent
     * @param  array<string, mixed>|null  $usage
     * @return array<string, mixed>|null
     */
    private function score(array $scenario, array $payload, array $sent, ?array $usage): ?array
    {
        if (! $this->contractMatchesSchema($payload)) {
            return null;
        }

        try {
            $type = AiGuidanceResult::fromPayload($payload)->type;
        } catch (InvalidArgumentException) {
            return null;
        }

        $assessment = $this->validator->evaluate($payload, SafetyEvidenceLivePlan::state($scenario), $scenario['latest']);

        if ($type === AiGuidanceType::Safety && in_array($assessment->status, [
            AiSafetyEvidenceStatus::NotSupplied,
            AiSafetyEvidenceStatus::InvalidContract,
        ], true)) {
            return null;
        }

        $basis = in_array($payload['basis'] ?? null, ['visitor_reported', 'model_inferred'], true)
            && $assessment->status !== AiSafetyEvidenceStatus::InvalidContract
            ? $payload['basis']
            : null;

        return $this->scoredRow(
            $scenario,
            responseType: $type->value,
            evidenceSupplied: $assessment->status !== AiSafetyEvidenceStatus::NotSupplied,
            validationStatus: $assessment->status->value,
            quotationMatched: $this->quotationMatched($assessment->status),
            basis: is_string($basis) ? $basis : null,
            unsupportedClaim: $type === AiGuidanceType::Safety && $assessment->status !== AiSafetyEvidenceStatus::QuotedWithExistingRule,
            followUpClarification: $type === AiGuidanceType::Clarification,
            contractValid: true,
            stopReason: is_string($sent['stop_reason'] ?? null) ? $sent['stop_reason'] : null,
            calls: is_int($sent['calls'] ?? null) ? $sent['calls'] : 0,
            inputTokens: is_numeric($usage['input_tokens'] ?? null) ? (int) $usage['input_tokens'] : null,
            outputTokens: is_numeric($usage['output_tokens'] ?? null) ? (int) $usage['output_tokens'] : null,
            urgencyApplied: $assessment->assignsClinicalUrgency(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function contractMatchesSchema(array $payload): bool
    {
        $type = $payload['type'] ?? null;
        $allowed = match ($type) {
            'recommendation' => ['type', 'service_id', 'reason', 'confidence'],
            'clarification' => ['type', 'question'],
            'uncertain' => ['type', 'message'],
            'safety' => ['type', 'message', 'basis', 'evidence', 'proposed_level'],
            default => null,
        };

        if ($allowed === null) {
            return false;
        }

        return array_diff(array_keys($payload), $allowed) === [];
    }

    private function quotationMatched(AiSafetyEvidenceStatus $status): ?bool
    {
        return match ($status) {
            AiSafetyEvidenceStatus::QuotedWithoutRule,
            AiSafetyEvidenceStatus::QuotedWithExistingRule,
            AiSafetyEvidenceStatus::Negated,
            AiSafetyEvidenceStatus::UnknownAnswer,
            AiSafetyEvidenceStatus::AffirmationOnly => true,
            AiSafetyEvidenceStatus::Unsupported => false,
            AiSafetyEvidenceStatus::NotSupplied,
            AiSafetyEvidenceStatus::InvalidContract,
            AiSafetyEvidenceStatus::ModelInferred => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $scenarios
     * @param  array<string, mixed>|null  $sent
     */
    private function stopped(string $reason, int $calls, int $limit, array $scenarios, string $message, ?array $sent = null): SafetyEvidenceLiveRun
    {
        return $this->runResult(
            executed: true,
            failed: true,
            stopReason: $reason,
            providerCalls: $calls,
            limit: $limit,
            approximateInputCharacters: null,
            roughInputTokens: null,
            scenarios: $scenarios,
            message: $message,
            httpStatus: is_array($sent) && is_int($sent['http_status'] ?? null) ? $sent['http_status'] : null,
            errorCategory: is_array($sent) && is_string($sent['error_category'] ?? null) ? $sent['error_category'] : null,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $scenarios
     */
    private function runResult(
        bool $executed,
        bool $failed,
        ?string $stopReason,
        int $providerCalls,
        int $limit,
        ?int $approximateInputCharacters,
        ?int $roughInputTokens,
        array $scenarios,
        string $message,
        ?int $httpStatus = null,
        ?string $errorCategory = null,
    ): SafetyEvidenceLiveRun {
        return new SafetyEvidenceLiveRun(
            executed: $executed,
            failed: $failed,
            stopReason: $stopReason,
            providerCalls: $providerCalls,
            limit: $limit,
            model: $this->anthropic->configuredModel() ?? 'unavailable',
            maxOutputTokens: $this->anthropic->outputTokenLimit(),
            retryOutputTokens: $this->anthropic->retryOutputTokenLimit(),
            approximateInputCharacters: $approximateInputCharacters,
            roughInputTokens: $roughInputTokens,
            scenarios: $scenarios,
            message: $message,
            schemaEnabledGlobally: config('ai.safety_evidence_schema') === true,
            httpStatus: $httpStatus,
            errorCategory: $errorCategory,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $queue
     * @return array{characters: int|null, rough_tokens: int|null}
     */
    private function inputEstimate(array $queue): array
    {
        $scenario = $queue[0] ?? null;

        if (! is_array($scenario)) {
            return ['characters' => null, 'rough_tokens' => null];
        }

        try {
            $request = $this->guidance->evaluationRequest($scenario['latest'], SafetyEvidenceLivePlan::state($scenario));
        } catch (Throwable) {
            return ['characters' => null, 'rough_tokens' => null];
        }

        $characters = mb_strlen($request['system']);

        foreach ($request['messages'] as $message) {
            $characters += mb_strlen($message['content']);
        }

        return [
            'characters' => $characters,
            'rough_tokens' => (int) ceil($characters / 4),
        ];
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function plannedRow(array $scenario): array
    {
        return [
            'id' => $scenario['id'],
            'language' => $scenario['language'],
            'category' => $scenario['category'],
            'purpose' => $scenario['purpose'],
            'response_type' => null,
            'evidence_supplied' => null,
            'validation_status' => null,
            'quotation_matched' => null,
            'basis' => null,
            'unsupported_safety_claim' => null,
            'follow_up_clarification' => null,
            'contract_valid' => null,
            'stop_reason' => null,
            'provider_calls' => 0,
            'input_tokens' => null,
            'output_tokens' => null,
            'urgency_applied' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $sent
     * @return array<string, mixed>
     */
    private function providerStopRow(array $scenario, array $sent, string $stopReason): array
    {
        $usage = is_array($sent['usage'] ?? null) ? $sent['usage'] : null;

        return $this->scoredRow(
            $scenario,
            responseType: null,
            evidenceSupplied: false,
            validationStatus: null,
            quotationMatched: null,
            basis: null,
            unsupportedClaim: false,
            followUpClarification: false,
            contractValid: false,
            stopReason: $stopReason,
            calls: is_int($sent['calls'] ?? null) ? $sent['calls'] : 0,
            inputTokens: is_numeric($usage['input_tokens'] ?? null) ? (int) $usage['input_tokens'] : null,
            outputTokens: is_numeric($usage['output_tokens'] ?? null) ? (int) $usage['output_tokens'] : null,
            urgencyApplied: false,
        );
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function scoredRow(
        array $scenario,
        ?string $responseType,
        bool $evidenceSupplied,
        ?string $validationStatus,
        ?bool $quotationMatched,
        ?string $basis,
        bool $unsupportedClaim,
        bool $followUpClarification,
        bool $contractValid,
        ?string $stopReason,
        int $calls,
        ?int $inputTokens,
        ?int $outputTokens,
        bool $urgencyApplied,
    ): array {
        return [
            'id' => $scenario['id'],
            'language' => $scenario['language'],
            'category' => $scenario['category'],
            'purpose' => $scenario['purpose'],
            'response_type' => $responseType,
            'evidence_supplied' => $evidenceSupplied,
            'validation_status' => $validationStatus,
            'quotation_matched' => $quotationMatched,
            'basis' => $basis,
            'unsupported_safety_claim' => $unsupportedClaim,
            'follow_up_clarification' => $followUpClarification,
            'contract_valid' => $contractValid,
            'stop_reason' => $stopReason,
            'provider_calls' => $calls,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'urgency_applied' => $urgencyApplied,
        ];
    }
}
