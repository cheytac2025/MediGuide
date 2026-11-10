<?php

namespace App\Console\Commands;

use App\Services\Ai\SafetyEvidenceLiveEvaluation;
use App\Services\Ai\SafetyEvidenceLivePlan;
use Illuminate\Console\Command;

/**
 * Isolated safety-evidence evaluation. Dry-run is the default.
 *
 * --execute is the only path that can contact the provider. This command
 * does not enable the evidence schema for the front desk.
 */
class EvaluateSafetyEvidenceCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mediguide:evaluate-safety-evidence
        {--execute : Send provider requests. Dry-run is the default.}
        {--limit=1 : Provider calls to allow, including retries. Maximum 12.}
        {--scenario= : One synthetic catalog ID. Free text, paths, and URLs are rejected.}';

    /**
     * @var string
     */
    protected $description = 'Dry-run or explicitly execute an isolated Claude safety-evidence evaluation';

    public function handle(SafetyEvidenceLiveEvaluation $evaluation): int
    {
        $limit = $this->option('limit');
        $limit = is_int($limit) ? (string) $limit : $limit;

        if (! is_string($limit) || preg_match('/^[1-9]\d*$/', $limit) !== 1) {
            $this->error('The request limit must be an integer from 1 to '.SafetyEvidenceLivePlan::MAX_PROVIDER_CALLS.'.');

            return self::FAILURE;
        }

        $limit = (int) $limit;

        if ($limit > SafetyEvidenceLivePlan::MAX_PROVIDER_CALLS) {
            $this->error('The request limit must be an integer from 1 to '.SafetyEvidenceLivePlan::MAX_PROVIDER_CALLS.'.');

            return self::FAILURE;
        }

        $scenario = $this->option('scenario');
        $scenarioIds = null;

        if ($scenario !== null) {
            if (! is_string($scenario) || ! SafetyEvidenceLivePlan::isCatalogId($scenario)) {
                $this->error('The scenario must be one synthetic catalog ID. No request was sent.');

                return self::FAILURE;
            }

            $scenarioIds = [$scenario];
        } else {
            $this->line('Completed scenario omitted unless selected: '.SafetyEvidenceLivePlan::COMPLETED_SCENARIO);
        }

        $result = $evaluation->run($limit, (bool) $this->option('execute'), $scenarioIds);

        $this->line($result->message);
        $this->line('Model: '.$result->model);
        $this->line('Maximum output tokens: '.$result->maxOutputTokens);
        $this->line('Retry output tokens: '.$result->retryOutputTokens);
        $this->line('Request limit: '.$result->limit);
        $this->line('Hard maximum provider calls, including retries: '.SafetyEvidenceLivePlan::MAX_PROVIDER_CALLS);
        $this->line('Provider calls: '.$result->providerCalls);
        $this->line('Global evidence schema: '.($result->schemaEnabledGlobally ? 'enabled' : 'disabled'));

        if (! $result->executed) {
            $characters = $result->approximateInputCharacters === null
                ? 'unavailable'
                : (string) $result->approximateInputCharacters;
            $rough = $result->roughInputTokens === null
                ? 'unavailable'
                : (string) $result->roughInputTokens;
            $this->line('Approximate input characters for the first scenario: '.$characters);
            $this->line('Rough input-token estimate at 4 characters per token: '.$rough.'. This is not a provider token count.');
            $this->line('Dollar cost: unknown. This repository has no verified Anthropic price sheet.');
        }

        if ($result->stopReason !== null) {
            $this->error('Stopped: '.$result->stopReason);
        }

        if ($result->httpStatus !== null) {
            $this->line('HTTP status: '.$result->httpStatus);
        }

        if ($result->errorCategory !== null) {
            $this->line('Error category: '.$result->errorCategory);
        }

        $this->table(
            ['ID', 'Language', 'Category', 'Type', 'Evidence', 'Status', 'Calls'],
            array_map(static fn (array $scenario): array => [
                $scenario['id'],
                $scenario['language'],
                $scenario['category'],
                $scenario['response_type'] ?? '',
                $scenario['evidence_supplied'] === null ? '' : ($scenario['evidence_supplied'] ? 'yes' : 'no'),
                $scenario['validation_status'] ?? ($scenario['stop_reason'] ?? ''),
                $scenario['provider_calls'],
            ], $result->scenarios),
        );

        return $result->failed ? self::FAILURE : self::SUCCESS;
    }
}
