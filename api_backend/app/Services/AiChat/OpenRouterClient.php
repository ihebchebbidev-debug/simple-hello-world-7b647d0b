<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * OpenRouter HTTP client with:
 *  - key-pool rotation + per-key quarantine on 401/402/429
 *  - model fallback chain
 *  - jittered exponential backoff honouring Retry-After (max_retries)
 *  - circuit breaker on repeated upstream failures
 *  - per-chunk idle timeout on streams so stalled providers don't pin workers
 *  - correct identity headers (HTTP-Referer, X-Title)
 *  - token usage capture for budget accounting
 */
final class OpenRouterClient
{
    /** Last upstream token usage — read by AiChatService for budget accounting. */
    private int $lastPromptTokens = 0;
    private int $lastCompletionTokens = 0;

    /** Last upstream HTTP status seen (0 when no HTTP response reached us, e.g. connection error). */
    private int $lastStatus = 0;

    public function __construct(
        private readonly OpenRouterKeyPool $keys,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function lastUsage(): array
    {
        return [
            'prompt'     => $this->lastPromptTokens,
            'completion' => $this->lastCompletionTokens,
            'total'      => $this->lastPromptTokens + $this->lastCompletionTokens,
        ];
    }

    /** @param array<int, array<string, string>> $messages */
    public function chat(array $messages): string
    {
        $this->assertBreakerClosed();

        $models     = $this->models();
        $maxRetries = max(0, (int) config('openrouter.max_retries', 2));
        $lastError  = null;
        $this->lastStatus = 0;

        foreach ($models as $model) {
            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                $key = $this->keys->next();
                try {
                    $response = $this->request($key, ['messages' => $messages, 'stream' => false, 'model' => $model]);
                } catch (ConnectionException $e) {
                    $this->breaker->recordFailure();
                    $lastError = $e->getMessage();
                    $this->lastStatus = 0;
                    $this->sleepBackoff($attempt, null);
                    continue;
                }

                if ($response->successful()) {
                    $json    = $response->json();
                    $content = $json['choices'][0]['message']['content'] ?? null;
                    $this->captureUsage($json);

                    if (! is_string($content) || trim($content) === '') {
                        $this->breaker->recordFailure();
                        throw new RuntimeException('empty_reply: OpenRouter returned an empty reply.');
                    }
                    $this->breaker->recordSuccess();
                    return trim($content);
                }

                $status = $response->status();
                $this->lastStatus = $status;
                $this->keys->markFailed($key, $status);
                $this->breaker->recordFailure();
                $lastError = 'HTTP '.$status.' '.mb_substr($response->body(), 0, 400);

                if ($this->isRetryable($status) && $attempt < $maxRetries) {
                    $this->sleepBackoff($attempt, $response);
                    continue;
                }
                // Non-retryable → try next model (breaks out of retry loop).
                break;
            }
        }

        throw new RuntimeException($this->classify($this->lastStatus).': '.($lastError ?? 'unknown error'));
    }

    /** @param array<int, array<string, string>> $messages */
    public function chatStream(array $messages, callable $onDelta): string
    {
        $this->assertBreakerClosed();

        $models     = $this->models();
        $maxRetries = max(0, (int) config('openrouter.max_retries', 2));
        $lastError  = null;
        $this->lastStatus = 0;

        foreach ($models as $model) {
            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                $key = $this->keys->next();
                try {
                    $response = $this->request(
                        $key,
                        ['messages' => $messages, 'stream' => true, 'model' => $model],
                        stream: true,
                    );
                } catch (ConnectionException $e) {
                    $this->breaker->recordFailure();
                    $lastError = $e->getMessage();
                    $this->lastStatus = 0;
                    $this->sleepBackoff($attempt, null);
                    continue;
                }

                if (! $response->successful()) {
                    $status = $response->status();
                    $this->lastStatus = $status;
                    $this->keys->markFailed($key, $status);
                    $this->breaker->recordFailure();
                    $lastError = 'HTTP '.$status;

                    if ($this->isRetryable($status) && $attempt < $maxRetries) {
                        $this->sleepBackoff($attempt, $response);
                        continue;
                    }
                    break; // try next model
                }

                // Stream: no retry once bytes start flowing.
                try {
                    $full = $this->consumeStream($response, $onDelta);
                } catch (\Throwable $e) {
                    $this->breaker->recordFailure();
                    throw new RuntimeException('network: OpenRouter stream interrupted: '.$e->getMessage(), 0, $e);
                }

                if (trim($full) === '') {
                    $this->breaker->recordFailure();
                    throw new RuntimeException('empty_reply: OpenRouter stream returned no content.');
                }
                $this->breaker->recordSuccess();
                return trim($full);
            }
        }

        throw new RuntimeException($this->classify($this->lastStatus).': '.($lastError ?? 'unknown error'));
    }

    /** @param array<string, mixed> $payload */
    private function request(string $apiKey, array $payload, bool $stream = false): Response
    {
        $url = rtrim((string) config('openrouter.base_url'), '/').'/chat/completions';

        $body = array_merge([
            'temperature' => config('openrouter.temperature'),
            'max_tokens'  => (int) config('openrouter.max_tokens'),
        ], $payload);

        $connectTimeout = (int) config('openrouter.connect_timeout', 15);
        $reqTimeout     = (int) config('openrouter.request_timeout', 60);
        $streamIdle     = (int) config('openrouter.stream_idle_timeout', 90);

        $pending = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'HTTP-Referer'  => (string) config('openrouter.referer'),
            'X-Title'       => (string) config('openrouter.title'),
            'Content-Type'  => 'application/json',
            'Accept'        => $stream ? 'text/event-stream' : 'application/json',
        ])->connectTimeout($connectTimeout)
          ->timeout($stream ? $streamIdle : $reqTimeout);

        if ($stream) {
            // Guzzle: enable streaming + per-chunk read timeout so a stalled
            // provider doesn't pin a PHP-FPM worker forever.
            $pending = $pending->withOptions([
                'stream'       => true,
                'read_timeout' => $streamIdle,
            ]);
        }

        return $pending->post($url, $body);
    }

    private function consumeStream(Response $response, callable $onDelta): string
    {
        $body = $response->toPsrResponse()->getBody();
        $full = '';
        $lastRawUsage = null;

        while (! $body->eof()) {
            $line = $this->readLine($body);
            if ($line === '' || ! str_starts_with($line, 'data:')) {
                continue;
            }
            $payload = trim(substr($line, 5));
            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }
            $json = json_decode($payload, true);
            if (! is_array($json)) {
                continue;
            }
            if (isset($json['usage']) && is_array($json['usage'])) {
                $lastRawUsage = $json['usage'];
            }
            $delta = $json['choices'][0]['delta']['content'] ?? null;
            if (! is_string($delta) || $delta === '') {
                continue;
            }
            $full .= $delta;
            $onDelta($delta);
        }

        if ($lastRawUsage !== null) {
            $this->captureUsage(['usage' => $lastRawUsage]);
        } else {
            // Approximation from character length if provider omitted usage on stream.
            $this->lastCompletionTokens = (int) ceil(mb_strlen($full) / 4);
        }

        return $full;
    }

    private function readLine(\Psr\Http\Message\StreamInterface $body): string
    {
        $buffer = '';
        while (! $body->eof()) {
            $char = $body->read(1);
            if ($char === '' || $char === "\n") {
                break;
            }
            $buffer .= $char;
        }
        return rtrim($buffer, "\r");
    }

    /** @return array<int, string> */
    private function models(): array
    {
        $models = array_values(array_filter((array) config('openrouter.models', []), static fn ($m) => is_string($m) && trim($m) !== ''));
        if ($models === []) {
            throw new RuntimeException('No OpenRouter model configured.');
        }
        return $models;
    }

    private function assertBreakerClosed(): void
    {
        if ($this->breaker->shouldTrip()) {
            throw new RuntimeException('circuit_open: OpenRouter breaker is open (temporary outage protection).');
        }
    }

    private function isRetryable(int $status): bool
    {
        return $status === 408 || $status === 425 || $status === 429 || $status >= 500;
    }

    /**
     * Map the last HTTP status (0 = no response) to a stable machine code
     * so callers can render precise user messages instead of a generic error.
     */
    private function classify(int $status): string
    {
        return match (true) {
            $status === 0                     => 'network',
            $status === 401 || $status === 403 => 'upstream_auth',
            $status === 402                   => 'quota_exceeded',
            $status === 429                   => 'rate_limited',
            $status === 408 || $status === 425 => 'timeout',
            $status >= 500                    => 'upstream_error',
            default                           => 'upstream_error',
        };
    }

    private function sleepBackoff(int $attempt, ?Response $response): void
    {
        $base = max(50, (int) config('openrouter.retry_base_ms', 400));
        $cap  = max($base, (int) config('openrouter.retry_max_ms', 4000));
        $ms   = min($cap, (int) ($base * (2 ** $attempt)));
        $ms   = random_int((int) ($ms * 0.5), $ms); // full jitter

        // Honour Retry-After if the server asked us to wait longer.
        if ($response !== null) {
            $retryAfter = $response->header('Retry-After');
            if (is_string($retryAfter) && $retryAfter !== '') {
                $seconds = is_numeric($retryAfter)
                    ? (int) $retryAfter
                    : max(0, strtotime($retryAfter) - time());
                $ms = max($ms, min($cap, $seconds * 1000));
            }
        }

        Log::info('ai.chat.retry_backoff', ['attempt' => $attempt, 'sleep_ms' => $ms]);
        usleep($ms * 1000);
    }

    /** @param array<string, mixed> $json */
    private function captureUsage(array $json): void
    {
        $usage = $json['usage'] ?? null;
        if (! is_array($usage)) {
            $this->lastPromptTokens = 0;
            $this->lastCompletionTokens = 0;
            return;
        }
        $this->lastPromptTokens     = (int) ($usage['prompt_tokens'] ?? 0);
        $this->lastCompletionTokens = (int) ($usage['completion_tokens'] ?? 0);
    }
}
