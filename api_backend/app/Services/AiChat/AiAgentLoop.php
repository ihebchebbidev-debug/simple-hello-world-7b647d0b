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
        $usedTools  = [];   // tool names that returned ok
        $failedOnly = false;

        for ($iter = 0; $iter < $maxIters; $iter++) {
            // Keep the HTTP response alive while the (non-streaming) planning
            // round runs — proxies drop idle upstream connections after ~60s.
            if ($onEvent !== null) {
                $onEvent(['type' => 'tick', 'iteration' => $iter]);
            }

            $msg = $this->openRouter->chatRaw($transcript, $toolDefs, 'planner');
            $toolCalls = $msg['tool_calls'] ?? [];


            if ($toolCalls === []) {
                $content = (string) ($msg['content'] ?? '');

                // Guard: the model answered from thin air without ever touching
                // the data. Push it back once to gather evidence first.
                if ($usedTools === [] && $iter === 0) {
                    $transcript[] = [
                        'role'    => 'assistant',
                        'content' => $content !== '' ? $content : '(no answer yet)',
                    ];
                    $transcript[] = [
                        'role'    => 'user',
                        'content' => '[internal] You answered without querying the data. Unless this was a pure greeting or an off-topic refusal, call the appropriate data tool(s) now (plot/period questions → water_per_ha, nutrient_per_ha, treatments, fertilization_history, irrigation_history, harvest_history, cost_per_ha, plot_info, product_info) and only then answer with real figures.',
                    ];
                    continue;
                }

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

            $roundOk = 0;
            $roundData = 0;

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

                if ($name !== 'plan') {
                    $roundData++;
                    if (($result['ok'] ?? false) === true) {
                        $roundOk++;
                        $usedTools[] = $name;
                    }
                }

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

            // Every data tool failed this round: nudge one explicit repair pass
            // (usually a wrong plot name — the payload carries available_plots).
            if ($roundData > 0 && $roundOk === 0 && ! $failedOnly && $iter < $maxIters - 1) {
                $failedOnly = true;
                $transcript[] = [
                    'role'    => 'user',
                    'content' => '[internal] Every tool call above failed or matched nothing. Read the error payload (it may list `available_plots`) and retry ONCE with corrected arguments — fix the plot name, widen or drop the date window. If it still cannot resolve, say plainly what is missing.',
                ];
            }
        }


        // Final round: force natural-language answer (no tools) and stream it.
        $transcript[] = [
            'role'    => 'user',
            'content' => '[internal] Based only on the tool results above, write the final answer for the user now. Do not call any more tools. Follow the voice, precision and formatting rules from the system prompt.',
        ];

        $emitted = '';
        $tracking = static function (string $delta) use ($onDelta, &$emitted): void {
            $emitted .= $delta;
            $onDelta($delta);
        };

        try {
            return $this->openRouter->chatStream($transcript, $tracking, 'answer');
        } catch (Throwable $e) {
            Log::warning('ai.agent.final_stream_failed', ['message' => $e->getMessage()]);

            // Bytes already reached the client — never re-emit a second full
            // answer on top of the partial one.
            if (trim($emitted) !== '') {
                return trim($emitted);
            }

            // Fall back to non-streaming so the user still gets something useful.
            $fallback = $this->openRouter->chat($transcript, 'answer');
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
        foreach (['rows', 'plots', 'campaigns', 'results', 'recent', 'buckets', 'products'] as $k) {
            if (isset($result[$k]) && is_array($result[$k]) && count($result[$k]) > 10) {
                $result[$k] = array_slice($result[$k], 0, 10);
                $result['_truncated'] = true;
            }
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        return mb_substr($json, 0, $maxBytes);
    }
}