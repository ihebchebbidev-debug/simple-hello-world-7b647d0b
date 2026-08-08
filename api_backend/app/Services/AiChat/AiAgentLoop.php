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
    /**
     * Raw JSON of every successful data-tool result from the last run().
     * Used by the post-generation validator to check that the numbers in
     * the reply actually came from the data.
     *
     * @var array<int, string>
     */
    private array $evidence = [];

    /**
     * Structured record of every data-tool call from the last run(), used to
     * build the deterministic verification footer (metric, plot, period).
     *
     * @var array<int, array{name: string, args: array<string, mixed>, result: array<string, mixed>}>
     */
    private array $calls = [];

    public function __construct(
        private readonly OpenRouterClient $openRouter,
        private readonly AiToolRegistry $tools,
    ) {}

    /** @return array<int, string> */
    public function lastEvidence(): array
    {
        return $this->evidence;
    }

    /** @return array<int, array{name: string, args: array<string, mixed>, result: array<string, mixed>}> */
    public function lastCalls(): array
    {
        return $this->calls;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages       Full transcript (system + user/assistant turns)
     * @param  callable(string):void             $onDelta        Final answer streaming callback
     * @param  null|callable(array):void         $onEvent        Optional plan/tool visibility hook
     * @return string  final assistant reply
     */
    public function run(array $messages, callable $onDelta, ?callable $onEvent = null): string
    {
        $this->evidence = [];
        $this->calls = [];
        $maxIters   = max(1, (int) config('openrouter.agent.max_iterations', 4));
        $maxResBytes = max(256, (int) config('openrouter.agent.max_tool_result', 2048));
        $ctxBudget   = max(8000, (int) config('openrouter.agent.max_context_chars', 90000));
        $toolDefs   = $this->tools->definitions();

        $transcript = $messages;
        $usedTools  = [];   // tool names that returned ok
        $failedOnly = false;
        $searchedWide = false; // a forced locate_data discovery pass already fired


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

            // Parse the whole round first, then execute it in one batch so the
            // registry can deduplicate identical lookups (and run them
            // concurrently when enabled) instead of one blocking call at a time.
            $parsed = [];
            foreach ($toolCalls as $call) {
                $parsed[] = [
                    'name' => (string) ($call['function']['name'] ?? ''),
                    'args' => json_decode((string) ($call['function']['arguments'] ?? '{}'), true) ?: [],
                    'id'   => (string) ($call['id'] ?? uniqid('call_', true)),
                ];
            }

            foreach ($parsed as $p) {
                if ($onEvent === null) {
                    continue;
                }
                if ($p['name'] === 'plan') {
                    $onEvent(['type' => 'plan', 'steps' => (array) ($p['args']['steps'] ?? [])]);
                } else {
                    $onEvent(['type' => 'tool_start', 'name' => $p['name'], 'args' => $p['args']]);
                }
            }

            $results = $this->tools->callMany(array_map(
                static fn (array $p): array => ['name' => $p['name'], 'args' => $p['args']],
                $parsed,
            ));

            $roundEmpty = 0;

            foreach ($parsed as $i => $p) {
                $name = $p['name'];
                $args = $p['args'];
                $id   = $p['id'];

                $result = $results[$i] ?? ['ok' => false, 'error' => 'tool_failed', 'name' => $name];
                $encoded = self::encodeResult($result, $maxResBytes);

                if ($name !== 'plan') {
                    $roundData++;
                    if (($result['ok'] ?? false) === true) {
                        $roundOk++;
                        $usedTools[] = $name;
                        $this->evidence[] = $encoded;
                        $this->calls[] = ['name' => $name, 'args' => $args, 'result' => $result];
                        if (self::resultIsEmpty($result)) $roundEmpty++;
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
                    'content' => '[internal] Every tool call above failed or matched nothing. Read the error payload (it may list `available_plots`) and retry with corrected arguments — fix the plot name, widen or drop the date window. If the arguments look right, call `locate_data` with the raw keywords from the question: it searches every table and every period and tells you where the records actually are. Only after locate_data comes back empty all-time may you say the data does not exist.',
                ];
            }

            // Tools succeeded but every one came back with zero rows. That is
            // the exact shape of the "aucun enregistrement" bug: the data is
            // usually there under another table, plot or period. Force one
            // discovery pass before the model is allowed to conclude.
            if ($roundData > 0 && $roundOk > 0 && $roundEmpty === $roundOk
                && ! $searchedWide && ! in_array('locate_data', $usedTools, true)
                && $iter < $maxIters - 1
            ) {
                $searchedWide = true;
                $transcript[] = [
                    'role'    => 'user',
                    'content' => '[internal] Every tool above succeeded but returned ZERO matching rows. Do NOT conclude that nothing is recorded. Call `locate_data` now with the raw keywords of the question (product, ingredient, pest, plot, whatever the user named) and no date filter: it scans all four operation tables and the catalogs and reports where the records live and over which dates. Then re-query the right tool over the window it reports.',
                ];
            }

            // Keep the transcript inside the model's context window. With 20+
            // rounds of 20 kB tool payloads a free model hits its context limit
            // and the whole request fails — which the user sees as "je n'ai pas
            // pu répondre". Older tool payloads are compacted; the most recent
            // ones (the evidence being reasoned over right now) stay intact.
            $transcript = self::pruneTranscript($transcript, $ctxBudget);
        }






        $transcript = self::pruneTranscript($transcript, $ctxBudget);

        // Final round: force natural-language answer (no tools) and stream it.
        $transcript[] = [
            'role'    => 'user',
            'content' => '[internal] Based ONLY on the tool results above, write the final answer for the user now. Do not call any more tools. Follow the voice, precision and formatting rules from the system prompt. Before you write: (1) every figure you state must appear literally in a tool result — never add, average or extrapolate numbers yourself; (2) state the scope you actually queried (plot + period) and never claim the figures cover "l\'ensemble des enregistrements" or "all records"; (3) if any result carries `_truncated`, `warnings`, `plot_match_warning` or `date_warning`, say so in one short line before the numbers.',
        ];

        $emitted = '';
        $sanitizer = new ReplySanitizer();
        $tracking = $sanitizer->createStreamFilter(static function (string $delta) use ($onDelta, &$emitted): void {
            $emitted .= $delta;
            $onDelta($delta);
        });

        try {
            $rawReply = $this->openRouter->chatStream($transcript, $tracking, 'answer');
            $tracking->flush();
            $cleanReply = $sanitizer->sanitize($rawReply);
            
            if ($sanitizer->isOnlyInternals($rawReply, $cleanReply)) {
                $transcript[] = [
                    'role'    => 'assistant',
                    'content' => $rawReply,
                ];
                $transcript[] = [
                    'role'    => 'user',
                    'content' => '[internal] Tools are closed. Answer the question in plain prose based on the data already provided.',
                ];
                $rawReply2 = $this->openRouter->chatStream($transcript, $tracking, 'answer');
                $tracking->flush();
                return $sanitizer->sanitize($rawReply2);
            }
            
            return $cleanReply;
        } catch (Throwable $e) {
            Log::warning('ai.agent.final_stream_failed', ['message' => $e->getMessage()]);

            // Bytes already reached the client — never re-emit a second full
            // answer on top of the partial one.
            if (trim($emitted) !== '') {
                return trim($emitted);
            }

            // Fall back to non-streaming so the user still gets something useful.
            $fallback = $this->openRouter->chat($transcript, 'answer');
            $cleanFallback = $sanitizer->sanitize($fallback);
            
            if ($sanitizer->isOnlyInternals($fallback, $cleanFallback)) {
                $transcript[] = ['role' => 'assistant', 'content' => $fallback];
                $transcript[] = ['role' => 'user', 'content' => '[internal] Tools are closed. Answer the question in plain prose based on the data already provided.'];
                $fallback = $this->openRouter->chat($transcript, 'answer');
                $cleanFallback = $sanitizer->sanitize($fallback);
            }
            
            $onDelta($cleanFallback);
            return $cleanFallback;
        }

    }

    /**
     * Keep the running transcript under a character budget.
     *
     * Rounds are cheap; context is not. Once the accumulated tool payloads
     * exceed the budget the upstream model rejects the whole request, and the
     * user gets a bare failure instead of an answer. Oldest tool payloads are
     * compacted first (head kept, so the model still knows what was asked and
     * roughly what came back); the last few tool messages — the evidence being
     * reasoned over right now — are never touched.
     *
     * @param  array<int, array<string, mixed>>  $transcript
     * @return array<int, array<string, mixed>>
     */
    private static function pruneTranscript(array $transcript, int $budget, int $keepRecent = 8): array
    {
        $toolIdx = [];
        $total   = 0;
        foreach ($transcript as $i => $m) {
            if (($m['role'] ?? '') !== 'tool') {
                continue;
            }
            $toolIdx[] = $i;
            $total += mb_strlen((string) ($m['content'] ?? ''));
        }

        if ($total <= $budget || count($toolIdx) <= $keepRecent) {
            return $transcript;
        }

        $prunable = array_slice($toolIdx, 0, max(0, count($toolIdx) - $keepRecent));

        foreach ($prunable as $i) {
            if ($total <= $budget) {
                break;
            }
            $content = (string) ($transcript[$i]['content'] ?? '');
            $len     = mb_strlen($content);
            if ($len <= 600) {
                continue;
            }
            $kept = mb_substr($content, 0, 600);
            $transcript[$i]['content'] = $kept.' … [older result compacted — re-run this tool if you need its full detail]';
            $total -= ($len - mb_strlen($kept));
        }

        return $transcript;
    }


    /**
     * Encode a tool result for the transcript within the byte budget.
     *
     * The model must NEVER receive a payload cut mid-JSON: it silently
     * reads half a row as a whole one and reports a total that matches
     * nothing. So we shrink list fields progressively — always leaving
     * valid JSON — and record explicitly how many rows were dropped so the
     * answer can disclose that the listing is partial.
     *
     * @param array<string, mixed> $result
     */
    /**
     * Did a successful tool result actually carry any record?
     *
     * A tool that resolves its plot and window fine, then matches nothing,
     * returns `ok` with empty lists — indistinguishable from real data unless
     * the payload is inspected. This drives the forced discovery pass.
     *
     * @param  array<string, mixed>  $result
     */
    private static function resultIsEmpty(array $result): bool
    {
        // Any non-zero count anywhere in the payload means we found something.
        $countKeys = [
            'total_matching', 'total_all_time', 'total_in_window', 'count',
            'returned_rows', 'irrigation_count', 'treatments_count',
            'applications', 'matches', 'total',
        ];
        $sawSignal = false;

        $walk = function (array $node) use (&$walk, $countKeys, &$sawSignal): void {
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    if (is_string($key) && in_array($key, ['rows', 'items', 'operations', 'found_in', 'history', 'recent_samples'], true) && $value !== []) {
                        $sawSignal = true;
                    }
                    $walk($value);
                    continue;
                }
                if (is_string($key) && in_array($key, $countKeys, true) && (float) $value > 0) {
                    $sawSignal = true;
                }
            }
        };
        $walk($result);

        return ! $sawSignal;
    }

    /**
     * Shrink a tool result to fit the transcript budget WITHOUT ever emitting
     * broken JSON. Public + static because the deterministic pre-fetch in
     * {@see AiChatService} must shorten its evidence exactly the same way —
     * it used to `substr()` the JSON, which could cut mid-object and feed the
     * model malformed "verified" data.
     *
     * @param  array<string, mixed>  $result
     */
    public static function encodeResult(array $result, int $maxBytes): string
    {
        $encode = static fn (array $r): string =>
            json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $json = $encode($result);
        if (mb_strlen($json) <= $maxBytes) return $json;

        $listKeys = ['rows', 'plots', 'campaigns', 'results', 'recent', 'buckets', 'products'];

        // Step down the row cap until the whole payload fits.
        foreach ([25, 15, 10, 6, 3, 1] as $cap) {
            $shrunk = $result;
            $dropped = 0;
            foreach ($listKeys as $k) {
                if (isset($shrunk[$k]) && is_array($shrunk[$k]) && count($shrunk[$k]) > $cap) {
                    $dropped += count($shrunk[$k]) - $cap;
                    $shrunk[$k] = array_slice($shrunk[$k], 0, $cap);
                }
            }
            if ($dropped > 0) {
                $shrunk['_truncated'] = true;
                $shrunk['_rows_omitted'] = $dropped;
                $shrunk['_truncation_notice'] = "This payload was shortened: {$dropped} row(s) are not shown. Aggregate fields (counts and totals) still cover ALL rows. Do not recompute a total by adding up the rows below, and tell the user the list is partial.";
            }
            $json = $encode($shrunk);
            if (mb_strlen($json) <= $maxBytes) return $json;
        }

        // Last resort: drop the lists entirely rather than emit broken JSON.
        $minimal = $result;
        foreach ($listKeys as $k) unset($minimal[$k]);
        $minimal['_truncated'] = true;
        $minimal['_truncation_notice'] = 'The detailed rows were too large to include. Only the summary fields above are available — state that the per-row detail could not be loaded rather than inventing rows.';
        $json = $encode($minimal);
        if (mb_strlen($json) <= $maxBytes) return $json;

        // Still oversized (a single huge scalar): return valid JSON, not a slice.
        return $encode([
            'ok'    => (bool) ($result['ok'] ?? false),
            'error' => 'result_too_large',
            'note'  => 'The tool result exceeded the transcript budget and was dropped. Ask the user to narrow the period or the plot instead of guessing figures.',
        ]);
    }
}