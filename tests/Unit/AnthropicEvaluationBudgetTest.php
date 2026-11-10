<?php

namespace Tests\Unit;

use App\Services\Ai\AnthropicMessagesClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicEvaluationBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'test-anthropic-key',
            'services.anthropic.model' => 'claude-sonnet-5',
            'services.anthropic.version' => '2023-06-01',
            'services.anthropic.max_tokens' => 4096,
            'services.anthropic.retry_max_tokens' => 8192,
        ]);

        Http::preventStrayRequests();
    }

    public function test_an_evaluation_request_does_not_retry_beyond_the_remaining_budget(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->stoppedForLength(), 200)
                ->push($this->completed('{"type":"uncertain","message":"Need more information."}'), 200),
        ]);

        $result = $this->client()->sendEvaluationMessage('system', [
            ['role' => 'user', 'content' => 'May kakaibang pakiramdam sa katawan ko.'],
        ], ['type' => 'object'], 1);

        $this->assertSame('incomplete', $result['status']);
        $this->assertSame(1, $result['calls']);
        $this->assertNull($result['payload']);
        Http::assertSentCount(1);
    }

    public function test_one_retry_is_allowed_only_when_the_budget_still_has_a_call(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->stoppedForLength(), 200)
                ->push($this->completed('{"type":"uncertain","message":"Need more information."}', 120, 40), 200),
        ]);

        $result = $this->client()->sendEvaluationMessage('system', [
            ['role' => 'user', 'content' => 'May kakaibang pakiramdam sa katawan ko.'],
        ], ['type' => 'object'], 2);

        $this->assertSame('complete', $result['status']);
        $this->assertSame(2, $result['calls']);
        $this->assertSame('uncertain', $result['payload']['type'] ?? null);
        $this->assertSame(120, $result['usage']['input_tokens'] ?? null);
        $this->assertSame(40, $result['usage']['output_tokens'] ?? null);
        Http::assertSentCount(2);
    }

    public function test_a_rejected_schema_stops_without_another_call(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['type' => 'invalid_request_error']], 400),
        ]);

        $result = $this->client()->sendEvaluationMessage('system', [
            ['role' => 'user', 'content' => 'May kakaibang pakiramdam sa katawan ko.'],
        ], ['type' => 'object'], 12);

        $this->assertSame('failed', $result['status']);
        $this->assertTrue($result['schema_rejected']);
        $this->assertSame(1, $result['calls']);
        $this->assertNull($result['payload']);
        Http::assertSentCount(1);
    }

    public function test_the_front_desk_client_still_retries_one_length_limit(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->stoppedForLength(), 200)
                ->push($this->completed('{"type":"uncertain","message":"Need more information."}'), 200),
        ]);

        $payload = $this->client()->createStructuredMessage('system', [
            ['role' => 'user', 'content' => 'May kakaibang pakiramdam sa katawan ko.'],
        ], ['type' => 'object']);

        $this->assertSame('uncertain', $payload['type'] ?? null);
        Http::assertSentCount(2);
    }

    public function test_a_missing_key_sends_nothing(): void
    {
        config(['services.anthropic.key' => null]);

        $result = $this->client()->sendEvaluationMessage('system', [
            ['role' => 'user', 'content' => 'May kakaibang pakiramdam sa katawan ko.'],
        ], ['type' => 'object'], 12);

        $this->assertSame(0, $result['calls']);
        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function stoppedForLength(): array
    {
        return [
            'stop_reason' => 'max_tokens',
            'content' => [],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 4096],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completed(string $text, int $inputTokens = 100, int $outputTokens = 20): array
    {
        return [
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
            'content' => [[
                'type' => 'text',
                'text' => $text,
            ]],
        ];
    }

    private function client(): AnthropicMessagesClient
    {
        return $this->app->make(AnthropicMessagesClient::class);
    }
}
