<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Isolated Anthropic Messages API client.
 *
 * The API key is sent only as a request header. It is never logged.
 * Incomplete max_tokens results are retried once with a larger output budget.
 * Partial JSON is never returned to callers.
 */
class AnthropicMessagesClient
{
    private const string ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const int DEFAULT_MAX_TOKENS = 4096;

    private const int DEFAULT_RETRY_MAX_TOKENS = 8192;

    private const int MIN_MAX_TOKENS = 1024;

    private const int ABSOLUTE_MAX_TOKENS = 16384;

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null && $this->model() !== null;
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    public function createStructuredMessage(string $system, array $messages, array $schema): ?array
    {
        $apiKey = $this->apiKey();
        $model = $this->model();

        if ($apiKey === null || $model === null || $messages === []) {
            Log::warning('Claude guidance is not configured.');

            return null;
        }

        $first = $this->postStructuredMessage($system, $messages, $schema, $this->maxTokens());

        if ($first['status'] === 'complete') {
            return $first['payload'];
        }

        if ($first['status'] === 'incomplete') {
            $retry = $this->postStructuredMessage($system, $messages, $schema, $this->retryMaxTokens());

            if ($retry['status'] === 'complete') {
                return $retry['payload'];
            }

            Log::warning('Claude guidance response was not a completed result.', [
                'stop_reason' => 'max_tokens',
                'retried' => true,
            ]);
        }

        return null;
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  array<string, mixed>  $schema
     * @return array{status: 'complete', payload: array<string, mixed>}|array{status: 'incomplete'}|array{status: 'failed'}
     */
    private function postStructuredMessage(string $system, array $messages, array $schema, int $maxTokens): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey(),
                'anthropic-version' => $this->apiVersion(),
            ])
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout())
                ->post(self::ENDPOINT, [
                    'model' => $this->model(),
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'messages' => $messages,
                    'output_config' => [
                        'format' => [
                            'type' => 'json_schema',
                            'schema' => $schema,
                        ],
                    ],
                ]);
        } catch (HttpClientException $exception) {
            $this->logFailure($exception);

            return ['status' => 'failed'];
        }

        if (! $response->successful()) {
            Log::warning('Claude guidance provider request failed.', [
                'http_status' => $response->status(),
            ]);

            return ['status' => 'failed'];
        }

        $body = $response->json();

        if (! is_array($body)) {
            Log::warning('Claude guidance response could not be parsed.');

            return ['status' => 'failed'];
        }

        $stopReason = $body['stop_reason'] ?? null;

        if ($stopReason === 'max_tokens') {
            return ['status' => 'incomplete'];
        }

        if ($stopReason !== 'end_turn') {
            Log::warning('Claude guidance response was not a completed result.', [
                'stop_reason' => is_string($stopReason) ? $stopReason : null,
            ]);

            return ['status' => 'failed'];
        }

        $text = $this->textContent($body);

        if ($text === null) {
            Log::warning('Claude guidance response could not be parsed.');

            return ['status' => 'failed'];
        }

        $payload = $this->decodeJsonObject($text);

        if ($payload === null) {
            return ['status' => 'failed'];
        }

        return [
            'status' => 'complete',
            'payload' => $payload,
        ];
    }

    private function apiKey(): ?string
    {
        $key = config('services.anthropic.key');

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : $key;
    }

    private function model(): ?string
    {
        $model = config('services.anthropic.model');

        if (! is_string($model)) {
            return null;
        }

        $model = trim($model);

        return $model === '' ? null : $model;
    }

    private function apiVersion(): string
    {
        $version = config('services.anthropic.version');

        if (! is_string($version) || trim($version) === '') {
            return '2023-06-01';
        }

        return trim($version);
    }

    private function timeout(): int
    {
        $timeout = config('services.anthropic.timeout');

        if (! is_int($timeout) && ! is_numeric($timeout)) {
            return 20;
        }

        $timeout = (int) $timeout;

        return $timeout > 0 ? $timeout : 20;
    }

    private function maxTokens(): int
    {
        return $this->boundedTokens(config('services.anthropic.max_tokens'), self::DEFAULT_MAX_TOKENS);
    }

    private function retryMaxTokens(): int
    {
        $retry = $this->boundedTokens(
            config('services.anthropic.retry_max_tokens'),
            self::DEFAULT_RETRY_MAX_TOKENS,
        );

        return max($retry, $this->maxTokens());
    }

    private function boundedTokens(mixed $value, int $default): int
    {
        if (! is_int($value) && ! is_numeric($value)) {
            return $default;
        }

        $tokens = (int) $value;

        if ($tokens < self::MIN_MAX_TOKENS) {
            return self::MIN_MAX_TOKENS;
        }

        if ($tokens > self::ABSOLUTE_MAX_TOKENS) {
            return self::ABSOLUTE_MAX_TOKENS;
        }

        return $tokens;
    }

    /**
     * @param  array<mixed>  $body
     */
    private function textContent(array $body): ?string
    {
        $content = $body['content'] ?? null;

        if (! is_array($content)) {
            return null;
        }

        $text = '';

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $text): ?array
    {
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $matches) === 1) {
            $text = trim($matches[1]);
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Log::warning('Claude guidance response could not be parsed.');

            return null;
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            Log::warning('Claude guidance response could not be parsed.');

            return null;
        }

        $object = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                Log::warning('Claude guidance response could not be parsed.');

                return null;
            }

            $object[$key] = $value;
        }

        return $object;
    }

    private function logFailure(HttpClientException $exception): void
    {
        Log::warning('Claude guidance provider request failed.', [
            'exception_type' => $exception::class,
        ]);
    }
}
