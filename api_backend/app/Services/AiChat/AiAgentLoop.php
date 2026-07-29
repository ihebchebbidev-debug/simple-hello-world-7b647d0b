<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Multi-turn tool-calling agent loop.
 *
 * Runs up to N non-streaming rounds where the model may request typed data
 * tools. Each round appends tool results to the transcript. When the model
 * stops calling tools (or the cap is reached), a final streaming call
 * produces the natural-language answer with all gathered evidence in context.
 *
 * Events emitted through the optional $onEvent hook:
 *   - ['type' => 'plan',       'steps' => [...]]
 *   - ['type' => 'tool_start', 'name' => 'x', 'args' => [...]]
 *   - ['type' => 'tool_end',   'name' => 'x', 'ok' => bool, 'preview' => str]
 */
final class AiAgentLoop
{
    public function __construct(
        private readonly OpenRouterClient $openRouter,
        private readonly AiToolRegistry $tools,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $messages       Full transcript (system + user/assistant turns)
     * @param  callable(string):void             $onDelta        Final answer streaming callback
     * @param  null|callable(array):void         $onEvent        Optional plan/tool visibility hook
     * @return string  final assistant reply
     */
    public function run(array $messages, callable $onDelta, ?callable $onEvent = null): string
    {
        $maxIters   = max(1, (int) config('openrouter.agent.max_iterations', 4));
        $maxResBytes = max(256, (int) config('openrouter.agent.max_tool_result', 2048));
        $toolDefs   = $this->tools->definitions();

        $transcript = $messages;

        for ($iter = 0; $iter < $maxIters; $iter++) {
            $msg = $this->openRouter->chatRaw($transcript, $toolDefs);
            $toolCalls = $msg['tool_calls'] ?? [];

            if ($toolCalls === []) {
                // Model produced final content directly — stream it out synthetically.
                $content = (string) ($msg['content'] ?? '');
                if ($content !== '') {
                    $onDelta($content);
                    return $content;
                }
                break; // fall through to final streaming call
            }

            // Append the assistant "tool_call" turn so the follow-up references it.
            $transcript[] = [
                'role'       => 'assistant',
                'content'    => (string) ($msg['content'] ?? ''),
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $rawArgs = (string) ($call['function']['arguments'] ?? '{}');
                $args = json_decode($rawArgs, true) ?: [];
                $id   = (string) ($call['id'] ?? uniqid('call_', true));

                if ($name === 'plan' && $onEvent !== null) {
                    $onEvent(['type' => 'plan', 'steps' => (array) ($args['steps'] ?? [])]);
                } elseif ($onEvent !== null) {
                    $onEvent(['type' => 'tool_start', 'name' => $name, 'args' => $args]);
                }

                $result = $this->tools->call($name, $args);
                $encoded = $this->encodeResult($result, $maxResBytes);

                if ($name !== 'plan' && $onEvent !== null) {
                    $onEvent([
                        'type'    => 'tool_end',
                        'name'    => $name,
                        'ok'      => (bool) ($result['ok'] ?? false),
                        'preview' => mb_substr($encoded, 0, 240),
                    ]);
                }

                $transcript[] = [
                    'role'        => 'tool',
                    'tool_call_id'=> $id,
                    'name'        => $name,
                    'content'     => $encoded,
                ];
            }
        }

        // Final round: force natural-language answer (no tools) and stream it.
        $transcript[] = [
            'role'    => 'user',
            'content' => '[internal] Based only on the tool results above, write the final answer for the user now. Do not call any more tools. Follow the voice, precision and formatting rules from the system prompt.',
        ];

        try {
            return $this->openRouter->chatStream($transcript, $onDelta);
        } catch (Throwable $e) {
            Log::warning('ai.agent.final_stream_failed', ['message' => $e->getMessage()]);
            // Fall back to non-streaming so the user still gets something useful.
            $fallback = $this->openRouter->chat($transcript);
            $onDelta($fallback);
            return $fallback;
        }
    }

    /** @param array<string, mixed> $result */
    private function encodeResult(array $result, int $maxBytes): string
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        if (mb_strlen($json) <= $maxBytes) return $json;
        // Truncate arrays if the payload is too large.
        foreach (['rows', 'plots', 'campaigns', 'results', 'recent', 'buckets'] as $k) {
            if (isset($result[$k]) && is_array($result[$k]) && count($result[$k]) > 10) {
                $result[$k] = array_slice($result[$k], 0, 10);
                $result['_truncated'] = true;
            }
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        return mb_substr($json, 0, $maxBytes);
    }
}