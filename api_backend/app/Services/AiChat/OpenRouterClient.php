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

    /** Cause of the last empty/failed stream, extracted from the SSE body (HTTP 200 can still carry an error). */
    private ?string $lastStreamError = null;

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

    /** Let the caller feed an approximate prompt-token count when the stream provider omits usage. */
    public function seedApproxPromptTokens(int $tokens): void
    {
        if ($this->lastPromptTokens === 0 && $tokens > 0) {
            $this->lastPromptTokens = $tokens;
        }
    }

    /** @param array<int, array<string, mixed>> $messages */
    public function chat(array $messages, string $purpose = 'answer'): string
    {
        $msg = $this->chatRaw($messages, null, $purpose);
        $content = (string) ($msg['content'] ?? '');
        if (trim($content) === '') {
            $this->breaker->recordFailure();
            throw new RuntimeException('empty_reply: OpenRouter returned an empty reply.');
        }
        return trim($content);
    }

    /**
     * Full non-stream call that returns the raw assistant message array
     * so callers can inspect `tool_calls` in addition to `content`.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>|null  $tools  OpenAI-style function tool definitions.
     * @return array{content: ?string, tool_calls: array<int, array<string, mixed>>}
     */
    public function chatRaw(array $messages, ?array $tools = null, string $purpose = 'planner'): array
    {
        $maxRetries = $this->retryBudget();
        $models     = $this->models($purpose);
        $lastError  = null;
        $this->lastStatus = 0;

        // Several recovery passes over the whole model chain: a transient outage
        // on every model at once is almost always over by the next pass, and a
        // second pass is far cheaper than surfacing an error to the user.
        $passes = $this->recoveryPasses();

        for ($pass = 0; $pass < $passes; $pass++) {
            if ($pass > 0) {
                $this->sleepBackoff($pass, null);
            }
        foreach ($models as $model) {
            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {

                $key = $this->keys->next($purpose);
                $payload = ['messages' => $messages, 'stream' => false, 'model' => $model];
                if ($tools !== null && $tools !== []) {
                    $payload['tools']       = $tools;
                    $payload['tool_choice'] = 'auto';
                }
                try {
                    $response = $this->request($key, $payload);
                } catch (ConnectionException $e) {
                    $this->breaker->recordFailure(hard: true);
                    $lastError = $e->getMessage();
                    $this->lastStatus = 0;
                    $this->sleepBackoff($attempt, null);
                    continue;
                }

                if ($response->successful()) {
                    $json    = $response->json();
                    $message = $json['choices'][0]['message'] ?? [];
                    $content = $message['content'] ?? null;
                    $toolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
                    $this->captureUsage($json);

                    // Empty content is OK when the model requested tools.
                    if ((! is_string($content) || trim($content) === '') && $toolCalls === []) {
                        // An empty completion is a TRANSIENT provider hiccup, not a
                        // terminal error: throwing here killed the whole agent run on
                        // the first blip and dropped the user onto the tool-less
                        // fallback. Treat it like any retryable failure so the
                        // remaining attempts and fallback models get their turn.
                        $this->breaker->recordFailure();
                        $finish = $json['choices'][0]['finish_reason'] ?? 'unknown';
                        $lastError = "empty completion from {$model} (finish_reason={$finish})";
                        $this->lastStatus = 0;

                        // finish_reason=length means the budget was consumed before any
                        // visible text (typically by reasoning tokens) — retrying the
                        // identical request just burns it again, so move to the next model.
                        if ($finish === 'length') {
                            Log::warning('ai.openrouter.empty_completion_truncated', [
                                'model' => $model, 'purpose' => $purpose,
                            ]);
                            break;
                        }
                        if ($attempt < $maxRetries) {
                            $this->sleepBackoff($attempt, null);
                            continue;
                        }
                        break; // exhausted this model → fall through to the next one
                    }
                    $this->breaker->recordSuccess();
                    return [
                        'content'    => is_string($content) ? $content : null,
                        'tool_calls' => $toolCalls,
                    ];
                }

                $status = $response->status();
                $this->lastStatus = $status;
                $this->keys->markFailed($key, $status);
                $this->breaker->recordFailure(hard: $status >= 500 || $status === 401 || $status === 402 || $status === 403);
                $lastError = 'HTTP '.$status.' '.mb_substr($response->body(), 0, 400);

                if ($this->isRetryable($status) && $attempt < $maxRetries) {
                    $this->sleepBackoff($attempt, $response);
                    continue;
                }
                // Non-retryable → try next model (breaks out of retry loop).
                break;
            }
        }
        } // recovery passes

        // Last resort: some providers reject the tool payload (or truncate on it)
        // while answering the exact same messages fine without tools. Better a
        // tool-less answer than an error page.
        if ($tools !== null && $tools !== []) {
            try {
                return $this->chatRaw($messages, null, $purpose);
            } catch (\Throwable) {
                // fall through to the original error below
            }
        }

        // Keep the empty-completion cause visible instead of relabelling it
        // "network" (classify(0)) — that mislabel is what surfaced to users as
        // a bogus "network error" when the provider simply returned nothing.
        $code = ($this->lastStatus === 0 && is_string($lastError) && str_contains($lastError, 'empty completion'))
            ? 'empty_reply'
            : $this->classify($this->lastStatus);

        throw new RuntimeException($code.': '.($lastError ?? 'unknown error'));
    }


    /** @param array<int, array<string, string>> $messages */
    public function chatStream(array $messages, callable $onDelta, string $purpose = 'answer'): string
    {
        $maxRetries = $this->retryBudget();
        $models     = $this->models($purpose);
        $lastError  = null;
        $this->lastStatus = 0;
        $passes = $this->recoveryPasses();

        for ($pass = 0; $pass < $passes; $pass++) {
            if ($pass > 0) {
                $this->sleepBackoff($pass, null);
            }
        foreach ($models as $model) {
            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {

                $key = $this->keys->next($purpose);
                try {
                    $response = $this->request(
                        $key,
                        ['messages' => $messages, 'stream' => true, 'model' => $model],
                        stream: true,
                    );
                } catch (ConnectionException $e) {
                    $this->breaker->recordFailure(hard: true);
                    $lastError = $e->getMessage();
                    $this->lastStatus = 0;
                    $this->sleepBackoff($attempt, null);
                    continue;
                }

                if (! $response->successful()) {
                    $status = $response->status();
                    $this->lastStatus = $status;
                    $this->keys->markFailed($key, $status);
                    $this->breaker->recordFailure(hard: $status >= 500 || $status === 401 || $status === 402 || $status === 403);
                    $lastError = 'HTTP '.$status;

                    if ($this->isRetryable($status) && $attempt < $maxRetries) {
                        $this->sleepBackoff($attempt, $response);
                        continue;
                    }
                    break; // try next model
                }

                // Stream: no retry once bytes start flowing.
                $emittedBytes = 0;
                $emitted = '';
                $countingDelta = static function (string $chunk) use ($onDelta, &$emittedBytes, &$emitted): void {
                    $emittedBytes += strlen($chunk);
                    $emitted .= $chunk;
                    $onDelta($chunk);
                };
                try {
                    $full = $this->consumeStream($response, $countingDelta);
                } catch (\Throwable $e) {
                    // Nothing reached the client yet → safe to retry cleanly.
                    if ($emittedBytes === 0) {
                        $this->breaker->recordFailure();
                        $lastError = 'stream interrupted: '.$e->getMessage();
                        $this->sleepBackoff($attempt, null);
                        continue;
                    }
                    // Text already reached the user: keep it instead of turning a
                    // usable partial answer into an error.
                    $this->breaker->recordFailure();
                    Log::warning('ai.openrouter.stream_truncated_kept', [
                        'model' => $model, 'purpose' => $purpose, 'error' => $e->getMessage(),
                    ]);
                    return trim($emitted);
                }

                if (trim($full) === '') {
                    // No content and no bytes emitted: retry, then fall back to the
                    // next model rather than failing the request outright.
                    $this->breaker->recordFailure();
                    $lastError = 'empty stream from '.$model
                        .($this->lastStreamError !== null ? ' ('.$this->lastStreamError.')' : '');
                    $this->lastStatus = 0;
                    Log::warning('ai.openrouter.empty_stream', [
                        'model' => $model, 'purpose' => $purpose, 'cause' => $this->lastStreamError,
                    ]);
                    if ($attempt < $maxRetries) {
                        $this->sleepBackoff($attempt, null);
                        continue;
                    }
                    break; // next model
                }
                $this->breaker->recordSuccess();
                return trim($full);
            }
        }
        } // recovery passes

        // Last resort: the streaming transport itself can be broken (proxy,
        // provider SSE bug) while the plain JSON call answers fine. Emit the
        // whole answer as a single delta so the user still gets a real reply.
        try {
            $msg = $this->chatRaw($messages, null, $purpose);
            $content = trim((string) ($msg['content'] ?? ''));
            if ($content !== '') {
                Log::warning('ai.openrouter.stream_fallback_nonstream', ['purpose' => $purpose]);
                $onDelta($content);
                return $content;
            }
        } catch (\Throwable) {
            // fall through to the original error below
        }

        $code = ($this->lastStatus === 0 && is_string($lastError) && str_contains($lastError, 'empty stream'))
            ? 'empty_reply'
            : $this->classify($this->lastStatus);

        throw new RuntimeException($code.': '.($lastError ?? 'unknown error'));
    }


    /** @param array<string, mixed> $payload */
    private function request(string $apiKey, array $payload, bool $stream = false): Response
    {
        $url = rtrim((string) config('openrouter.base_url'), '/').'/chat/completions';

        $body = array_merge([
            'temperature' => config('openrouter.temperature'),
            'max_tokens'  => (int) config('openrouter.max_tokens'),
        ], $payload);

        // Route to the fastest provider for the chosen model (OpenRouter feature).
        // Massively reduces TTFT + generation time on free-tier models where the
        // default "cheapest" provider is often the slowest one.
        $sort = (string) config('openrouter.provider_sort', '');
        if ($sort !== '' && ! isset($body['provider'])) {
            $body['provider'] = ['sort' => $sort, 'allow_fallbacks' => true];
        }

        $connectTimeout = (int) config('openrouter.connect_timeout', 30);
        $reqTimeout     = (int) config('openrouter.request_timeout', 0);
        $streamIdle     = (int) config('openrouter.stream_idle_timeout', 300);

        $pending = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'HTTP-Referer'  => (string) config('openrouter.referer'),
            'X-Title'       => (string) config('openrouter.title'),
            'Content-Type'  => 'application/json',
            'Accept'        => $stream ? 'text/event-stream' : 'application/json',
        ])->connectTimeout($connectTimeout)
          // No wall-clock cap by default (0 = unlimited in Guzzle): the model is
          // allowed to think for as long as it needs. Liveness is still enforced
          // per-chunk via `read_timeout` on streams.
          ->timeout(max(0, $reqTimeout));


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
        $this->lastStreamError = null;
        $reasoningOnly = 0;

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
            // OpenRouter reports mid-stream failures (rate limit, upstream 5xx,
            // moderation) as an error object inside the SSE body with HTTP 200.
            // Swallowing it turned a real, explainable failure into a blank
            // "empty reply" with no cause in the logs.
            if (isset($json['error'])) {
                $err = $json['error'];
                $this->lastStreamError = is_array($err)
                    ? trim((string) ($err['message'] ?? 'provider error')
                        .(isset($err['code']) ? ' [code '.$err['code'].']' : ''))
                    : (string) $err;
                continue;
            }
            if (isset($json['usage']) && is_array($json['usage'])) {
                $lastRawUsage = $json['usage'];
            }
            $choice = $json['choices'][0] ?? [];
            if (($choice['finish_reason'] ?? null) === 'length' && $full === '') {
                $this->lastStreamError = 'output truncated before any text (finish_reason=length)';
            }
            $delta = $choice['delta']['content'] ?? null;
            // Reasoning models emit thinking tokens on a separate field; those
            // are not an answer, but they prove the stream was alive.
            if ((! is_string($delta) || $delta === '')
                && is_string($choice['delta']['reasoning'] ?? null)
                && $choice['delta']['reasoning'] !== '') {
                $reasoningOnly++;
            }
            if (! is_string($delta) || $delta === '') {
                continue;
            }
            $full .= $delta;
            $onDelta($delta);
        }

        if ($full === '' && $reasoningOnly > 0 && $this->lastStreamError === null) {
            $this->lastStreamError = 'model emitted only reasoning tokens, no answer text';
        }

        if ($lastRawUsage !== null) {
            $this->captureUsage(['usage' => $lastRawUsage]);
        } else {
            // Approximate both prompt + completion when provider omits usage on stream,
            // so budget accounting doesn't undercount by the (large) system prompt.
            $this->lastCompletionTokens = (int) ceil(mb_strlen($full) / 4);
            // Prompt tokens are approximated by the caller via approxPromptTokens().
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
    private function models(string $purpose = 'default'): array
    {
        $configKey = match ($purpose) {
            'planner' => 'planner_models',
            'answer'  => 'answer_models',
            'repair'  => 'repair_models',
            default   => 'models',
        };
        $models = array_values(array_filter((array) config('openrouter.'.$configKey, []), static fn ($m) => is_string($m) && trim($m) !== ''));
        if ($models === [] && $configKey !== 'models') {
            $models = array_values(array_filter((array) config('openrouter.models', []), static fn ($m) => is_string($m) && trim($m) !== ''));
        }
        if ($models === []) {
            throw new RuntimeException('No OpenRouter model configured.');
        }
        return $models;
    }

    /**
     * Attempts per model. Always at least enough to rotate through every
     * configured key, so one quarantined/rate-limited key can never fail a
     * request while a healthy key sits unused. The breaker is advisory: while
     * open we still make a real attempt, just with a smaller budget.
     */
    private function retryBudget(): int
    {
        $configured = max(0, (int) config('openrouter.max_retries', 2));
        if ($this->breaker->shouldTrip()) {
            return 1;
        }
        return max($configured, max(0, $this->keys->size() - 1));
    }

    /** Full passes over the model chain before giving up. */
    private function recoveryPasses(): int
    {
        return max(1, (int) config('openrouter.recovery_passes', 2));
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
            $status === 404                   => 'model_not_found',
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
