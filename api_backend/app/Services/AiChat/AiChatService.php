<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AiChatService
{
    /**
     * Tool-result JSON pre-fetched for the current turn (see
     * {@see prefetchEvidenceMessage}). Fed to the self-check so verified
     * figures are never flagged as hallucinated.
     *
     * @var array<int, string>
     */
    private array $lastPrefetchEvidence = [];

    /**
     * Structured record of the tools pre-fetched for the current turn.
     * Feeds the deterministic verification footer.
     *
     * @var array<int, array{name: string, args: array<string, mixed>, result: array<string, mixed>}>
     */
    private array $lastPrefetchCalls = [];

    public function __construct(
        private readonly AiContextBuilder $contextBuilder,
        private readonly OpenRouterClient $openRouter,
        private readonly ResponseValidator $validator,
        private readonly PromptRouter $router,
        private readonly TokenBudget $budget,
        private readonly AiAgentLoop $agent,
        private readonly AiToolRegistry $toolRegistry,
        private readonly AiQuestionPlanner $planner = new AiQuestionPlanner(),
        private readonly EvidenceFooter $footer = new EvidenceFooter(),
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{reply: string, conversation_id: string, revised?: bool, violations?: array<int, string>, cached?: bool, degraded?: bool}
     */
    public function reply(array $messages, string $locale, ?string $conversationId = null, int|string|null $subjectId = null): array
    {
        $id = $conversationId ?: (string) Str::uuid();

        // Cheap cache key on messages+locale+data stamp — avoids building the heavy
        // live-data context on cache hits (context build = ~15 SQL stamp queries).
        $earlyKey = $this->earlyCacheKey($messages, $locale);
        if (! $this->isDispute($this->lastUserMessage($messages))
            && ($cached = $this->cacheGet($earlyKey)) !== null) {
            return ['reply' => $cached, 'conversation_id' => $id, 'revised' => false, 'violations' => [], 'cached' => true];
        }

        if ($this->budget->isExhausted($subjectId)) {
            Log::info('ai.chat.budget_exhausted', ['subject' => (string) $subjectId]);
            return [
                'reply' => $this->budgetExhaustedMessage($locale), 'conversation_id' => $id,
                'revised' => false, 'violations' => [], 'degraded' => true,
            ];
        }

        if (($shortcut = $this->deterministicFarmAnswer($messages, $locale)) !== null) {
            $this->cachePut($earlyKey, $shortcut);
            return ['reply' => $shortcut, 'conversation_id' => $id, 'revised' => false, 'violations' => [], 'cached' => false];
        }

        $agentEnabled = (bool) config('openrouter.agent.enabled', true) && ! (bool) config('openrouter.fast_mode', false);
        $payload = $this->buildOpenRouterMessages($messages, $locale, $agentEnabled);

        // Fast path: the deterministic pre-fetch already answered the question,
        // so the planning round would only re-ask for data we hold. Go straight
        // to the answer call. {@see prefetchIsSufficient}
        $usedAgent = $agentEnabled && ! $this->prefetchIsSufficient($messages);
        if ($agentEnabled && ! $usedAgent) {
            Log::info('ai.chat.fast_path', ['tools' => count($this->lastPrefetchCalls)]);
        }

        if ($usedAgent) {
            try {
                $buf = '';
                $reply = $this->agent->run($payload, static function (string $d) use (&$buf) { $buf .= $d; });
                if (trim($reply) === '' && $buf !== '') {
                    $reply = $buf;
                }
            } catch (\Throwable $e) {
                Log::warning('ai.agent.loop_failed_fallback_to_direct', ['message' => $e->getMessage()]);
                // Rebuild without the agent prompt: the tool-oriented prompt sent
                // to a tool-less call is what produced long, English, data-free
                // answers. The tool-less prompt keeps voice, locale and honesty
                // rules and tells the model it has no live data.
                $payload = $this->buildOpenRouterMessages($messages, $locale, false);
                $reply = $this->openRouter->chat($payload, 'answer');
            }
        } elseif ($agentEnabled) {
            $reply = $this->openRouter->chat($this->finalAnswerPayload($payload), 'answer');
        } else {
            $reply = $this->openRouter->chat($payload, 'answer');
        }
        $this->recordUsage($subjectId, $payload);

        $evidence = array_merge(
            $usedAgent ? $this->agent->lastEvidence() : [],
            $this->lastPrefetchEvidence,
        );

        // A blank draft is never shipped: regenerate from the same evidence
        // before falling back to an apology.
        if (trim($reply) === '') {
            $reply = $this->rescueEmptyReply($messages, $locale, $payload);
        }

        $final = $this->selfCheck($reply, $messages, $locale, $payload, $evidence);
        if ($final['revised']) {
            $this->recordUsage($subjectId, $payload);
        }
        $final['reply'] .= $this->evidenceFooter($usedAgent, $locale, $final['reply']);
        if ($this->isCacheable($final)) {
            $this->cachePut($earlyKey, $final['reply']);
        }

        return [
            'reply'           => $final['reply'],
            'conversation_id' => $id,
            'revised'         => $final['revised'],
            'violations'      => $final['violations'],
            'cached'          => false,
        ];
    }

    /**
     * An answer round that produced no text at all (upstream hiccup, tool loop
     * that ended on a tool message, filtered stream). Ask once more, without
     * tools, for a plain answer built from the context we already have.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<int, array{role: string, content: string}>  $payload
     */
    private function rescueEmptyReply(array $messages, string $locale, array $payload): string
    {
        foreach (['answer', 'repair'] as $lane) {
            try {
                $retry = trim($this->openRouter->chat($this->finalAnswerPayload($payload), $lane));
                if ($retry !== '') {
                    Log::info('ai.chat.empty_reply_rescued', ['lane' => $lane]);

                    return $retry;
                }
            } catch (\Throwable $e) {
                Log::warning('ai.chat.empty_reply_rescue_failed', ['lane' => $lane, 'message' => $e->getMessage()]);
            }
        }

        try {
            $plain = $this->buildOpenRouterMessages($messages, $locale, false);

            return trim($this->openRouter->chat($plain, 'answer'));
        } catch (\Throwable $e) {
            Log::warning('ai.chat.empty_reply_rescue_failed', ['lane' => 'plain', 'message' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * Only clean answers are cached: caching an apology or an answer that still
     * breaks a hard rule would replay the same bad reply to every later user.
     *
     * @param  array{reply: string, revised: bool, violations: array<int, string>}  $final
     */
    private function isCacheable(array $final): bool
    {
        if (trim($final['reply']) === '') {
            return false;
        }
        foreach ($final['violations'] as $v) {
            if ($v === 'empty_reply' || $v === 'leaks_internals'
                || str_starts_with($v, 'stale_count') || str_starts_with($v, 'unsupported_numbers')) {
                return $final['revised'];
            }
        }

        return true;
    }


    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function replyStream(
        array $messages,
        string $locale,
        ?string $conversationId,
        callable $onDelta,
        int|string|null $subjectId = null,
        ?callable $onEvent = null,
    ): array {
        $id = $conversationId ?: (string) Str::uuid();

        $earlyKey = $this->earlyCacheKey($messages, $locale);
        if (! $this->isDispute($this->lastUserMessage($messages))
            && ($cached = $this->cacheGet($earlyKey)) !== null) {
            $onDelta($cached);
            return ['reply' => $cached, 'conversation_id' => $id, 'revised' => false, 'violations' => [], 'cached' => true];
        }

        if ($this->budget->isExhausted($subjectId)) {
            $msg = $this->budgetExhaustedMessage($locale);
            $onDelta($msg);
            return ['reply' => $msg, 'conversation_id' => $id, 'revised' => false, 'violations' => [], 'degraded' => true];
        }

        if (($shortcut = $this->deterministicFarmAnswer($messages, $locale)) !== null) {
            $onDelta($shortcut);
            $this->cachePut($earlyKey, $shortcut);
            return ['reply' => $shortcut, 'conversation_id' => $id, 'revised' => false, 'violations' => [], 'cached' => false];
        }

        $agentEnabled = (bool) config('openrouter.agent.enabled', true) && ! (bool) config('openrouter.fast_mode', false);
        $payload = $this->buildOpenRouterMessages($messages, $locale, $agentEnabled);

        $emitted = '';
        $sanitizer = new ReplySanitizer();
        $trackedDelta = $sanitizer->createStreamFilter(static function (string $chunk) use ($onDelta, &$emitted): void {
            $emitted .= $chunk;
            $onDelta($chunk);
        });

        // Fast path — see reply(). Saves one full non-streamed planning
        // round-trip, so the first token reaches the client immediately.
        $usedAgent = $agentEnabled && ! $this->prefetchIsSufficient($messages);

        if ($usedAgent) {
            try {
                $streamed = $this->agent->run($payload, $trackedDelta, $onEvent);
                $trackedDelta->flush();
            } catch (\Throwable $e) {
                Log::warning('ai.agent.loop_failed_fallback_to_direct', ['message' => $e->getMessage()]);
                if (trim($emitted) !== '') {
                    // Partial answer already reached the client: keep it instead of
                    // streaming a second, duplicated answer on top of it.
                    $streamed = trim($emitted);
                } else {
                    // See reply(): the agent prompt must not be reused for a
                    // tool-less call, or the model invents data in the wrong language.
                    $payload = $this->buildOpenRouterMessages($messages, $locale, false);
                    $streamed = $this->openRouter->chatStream($payload, $trackedDelta, 'answer');
                    $trackedDelta->flush();
                    $streamed = $sanitizer->sanitize($streamed);
                }
            }
        } elseif ($agentEnabled) {
            Log::info('ai.chat.fast_path', ['tools' => count($this->lastPrefetchCalls)]);
            $this->emitPrefetchEvents($onEvent);
            try {
                $streamed = $this->openRouter->chatStream($this->finalAnswerPayload($payload), $trackedDelta, 'answer');
                $trackedDelta->flush();
                $streamed = $sanitizer->sanitize($streamed);
            } catch (\Throwable $e) {
                Log::warning('ai.chat.fast_path_failed', ['message' => $e->getMessage()]);
                if (trim($emitted) !== '') {
                    $streamed = trim($emitted);
                } else {
                    // Nothing rendered yet: fall back to the full agent loop so
                    // the user still gets a complete answer, never an error.
                    $usedAgent = true;
                    $streamed = $this->agent->run($payload, $trackedDelta, $onEvent);
                    $trackedDelta->flush();
                }
            }
        } else {
            $streamed = $this->openRouter->chatStream($payload, $trackedDelta, 'answer');
            $trackedDelta->flush();
            $streamed = $sanitizer->sanitize($streamed);
        }

        $this->recordUsage($subjectId, $payload);

        $evidence = array_merge(
            $usedAgent ? $this->agent->lastEvidence() : [],
            $this->lastPrefetchEvidence,
        );

        // Nothing reached the client and the round produced no text: run one
        // rescue generation so the bubble is never empty.
        if (trim($streamed) === '' && trim($emitted) === '') {
            $streamed = $this->rescueEmptyReply($messages, $locale, $payload);
        }

        $final = $this->selfCheck($streamed, $messages, $locale, $payload, $evidence);
        if ($final['revised']) {
            $this->recordUsage($subjectId, $payload);
        }

        // Rescue text (or the localized fallback) was never streamed — push it
        // now, unless the revise event is about to carry the whole reply.
        if (trim($emitted) === '' && ! $final['revised'] && trim($final['reply']) !== '') {
            $onDelta($final['reply']);
            $emitted = $final['reply'];
        }

        $footer = $this->evidenceFooter($usedAgent, $locale, $final['reply']);

        if ($footer !== '') {
            $final['reply'] .= $footer;
            // Revised replies are re-sent whole through the `revise` event, so
            // only the un-revised (already streamed) case needs the delta.
            if (! $final['revised']) {
                $onDelta($footer);
            }
        }
        if ($this->isCacheable($final)) {
            $this->cachePut($earlyKey, $final['reply']);
        }


        return [
            'reply'           => $final['reply'],
            'conversation_id' => $id,
            'revised'         => $final['revised'],
            'violations'      => $final['violations'],
            'cached'          => false,
        ];
    }

    /**
     * Deterministic provenance block appended to every data-backed answer:
     * the metric, the plot/scope and the exact date window each figure came
     * from. Built from the tool calls that actually ran this turn, never from
     * the model's own words, so the user can verify an answer at a glance.
     */
    private function evidenceFooter(bool $agentEnabled, string $locale, string $reply): string
    {
        // The farm operator asked for the provenance block to be dropped from
        // answers. Kept behind a flag so it can be re-enabled per environment.
        if (! (bool) config('openrouter.evidence_footer', false)) {
            return '';
        }

        try {
            $calls = array_merge(
                $agentEnabled ? $this->agent->lastCalls() : [],
                $this->lastPrefetchCalls,
            );
            if ($calls === []) {
                return '';
            }
            $block = $this->footer->build($calls, $locale);
            if ($block === '' || str_contains($reply, '**Vérification**') || str_contains($reply, '**Verification**')) {
                return '';
            }

            return $block;
        } catch (\Throwable $e) {
            Log::warning('ai.chat.evidence_footer_failed', ['message' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Post-generation self-check. Runs deterministic validation and, when
     * a hard rule is broken, asks the model for ONE repair pass constrained
     * by the violated rules and the same system prompt.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<int, array{role: string, content: string}>  $payload
     * @param  array<int, string>                                $evidence  Tool-result JSON backing the reply.
     * @return array{reply: string, revised: bool, violations: array<int, string>}
     */
    /**
     * Violations that can make the answer WRONG (as opposed to merely ugly).
     * These always justify another repair round-trip, however long it takes.
     *
     * @param  array<int, string>  $violations
     * @return array<int, string>
     */
    private function hardViolations(array $violations): array
    {
        return array_values(array_filter(
            $violations,
            static fn (string $v) => str_starts_with($v, 'language_mismatch')
                || $v === 'contains_html'
                || $v === 'unbalanced_code_fence'
                // Unverifiable exhaustiveness claim: phrasing, but trust-breaking.
                || $v === 'claims_exhaustiveness'
                // Row uuids, tool names or SQL errors quoted at the user.
                || $v === 'leaks_internals'
                // A capped row count presented as the total: plain wrong number.
                || str_starts_with($v, 'stale_count')
                // A figure that appears in no tool result is a hallucination.
                || str_starts_with($v, 'unsupported_numbers'),
        ));
    }

    private function selfCheck(string $reply, array $messages, string $locale, array $payload, array $evidence = []): array
    {
        $sanitizer = new ReplySanitizer();
        $sanitizedReply = $sanitizer->sanitize($reply);

        // Never ship a blank bubble: one transcript shows a 54 s round that
        // ended with an empty answer. If sanitising emptied a non-empty draft,
        // keep the draft; if there was nothing at all, say so in the user's
        // language instead of rendering nothing.
        if (trim($sanitizedReply) === '' && trim($reply) !== '') {
            $sanitizedReply = trim($reply);
        }
        if (trim($sanitizedReply) === '') {
            return [
                'reply' => str_starts_with(strtolower($locale), 'fr')
                    ? "Je n'ai pas réussi à récupérer cette information. Reformulez ou réessayez, je relance la recherche."
                    : "I could not retrieve that information. Ask again and I will run the search once more.",
                'revised'    => false,
                'violations' => ['empty_reply'],
            ];
        }
        
        $length   = mb_strlen($sanitizedReply);
        $lastUser = $this->lastUserMessage($messages);

        $check = $this->validator->check($sanitizedReply, $lastUser, $locale, $evidence);
        if ($check['ok']) {
            return ['reply' => $sanitizedReply, 'revised' => false, 'violations' => []];
        }

        // Accuracy beats latency: every violation that can make the answer
        // factually wrong triggers the repair pass, however long it takes.
        // Only purely cosmetic rules (bullet style, filler openers, length)
        // are left to a future pass.
        $hardViolations = $this->hardViolations($check['violations']);


        // Only trigger the repair round-trip when a HARD rule broke. Cosmetic
        // violations (bullet style, filler openers, minor length) don't justify
        // doubling response latency — they're logged and left for a future pass.
        if ($hardViolations === []) {
            // Not worth a repair round-trip, but a number that matches no tool
            // result is a hallucination — log it so it is traceable when a user
            // disputes a figure.
            foreach ($check['violations'] as $v) {
                if (str_starts_with($v, 'unsupported_numbers')) {
                    Log::warning('ai.chat.unsupported_numbers', [
                        'violation' => $v,
                        'question'  => mb_substr($lastUser, 0, 200),
                        'reply'     => mb_substr($sanitizedReply, 0, 500),
                    ]);
                }
            }

            return ['reply' => $sanitizedReply, 'revised' => false, 'violations' => $check['violations']];
        }

        Log::info('ai.chat.self_check_failed', [
            'violations'    => $check['violations'],
            'target_lang'   => $check['target_lang'],
            'detected_lang' => $check['detected_lang'],
            'length'        => $length,
        ]);

        // Keep the system prompt (rules). When the violation is about the
        // FIGURES, the draft alone is not enough to fix them — re-attach the
        // raw tool evidence so the repair pass corrects the numbers against
        // the data instead of paraphrasing a wrong draft.
        $systemOnly = ! empty($payload) && ($payload[0]['role'] ?? '') === 'system'
            ? [$payload[0]]
            : [];

        $lang = $check['target_lang'] === 'fr' ? 'French' : ($check['target_lang'] === 'en' ? 'English' : 'the same language the user just wrote in');

        // Accuracy over latency: keep repairing while hard rules break, up to
        // a small bounded number of passes, and keep the best candidate seen.
        $bestReply      = $sanitizedReply;
        $bestHardCount  = count($hardViolations);
        $bestRevised    = false;
        $draft          = $sanitizedReply;
        $currentHard    = $hardViolations;
        $currentAll     = $check['violations'];
        $maxPasses      = max(1, (int) config('openrouter.repair_passes', 2));

        for ($pass = 1; $pass <= $maxPasses; $pass++) {
            $countFixes = '';
            $numericViolation = false;
            foreach ($currentAll as $v) {
                if (preg_match('/^stale_count\(said=(\d+),total=(\d+)\)$/', $v, $m)) {
                    $countFixes .= "\n- The count {$m[1]} is the number of rows shown, NOT the total: the correct total is {$m[2]}. State {$m[2]} as the count and, if you list rows, say you are showing {$m[1]} of {$m[2]}.";
                }
                if (str_starts_with($v, 'stale_count') || str_starts_with($v, 'unsupported_numbers')) {
                    $numericViolation = true;
                }
            }
            $violationList = implode(', ', $currentAll);

            $repairInstruction = <<<INSTR
Your previous draft violated these rules: {$violationList}.
Rewrite the SAME answer for the same user question, keeping every factual claim, number and entity name identical, but fix the violations:
- Reply in {$lang} only, no language mixing.
- Do not open with a markdown heading.
- Use `-` for bullets, never `*`.
- No HTML, no unmatched code fences.
- No "As an AI", "Sure!", "Voici", "let me know if…", or similar filler.
- Never claim the figures cover "l'ensemble des enregistrements" / "all records". Say instead which plot and which period they cover.
- Never expose internals: no row UUIDs, no tool/field names (cost_per_ha, usage_count, total_matching…), no SQL or error text, no mention of the system message or of a failed lookup. Speak only about plots, dates, quantities and costs in plain business language.
- Every number must appear in the tool results; if a figure cannot be backed, drop it rather than restating it.
- Never return an empty answer: if the data does not support a figure, say plainly what is and is not recorded.
- Keep it concise; match the length rules in the system prompt.{$countFixes}
Return ONLY the corrected answer, no meta commentary, no "here is the revised answer".

Previous draft:
---
{$draft}
---
INSTR;

            try {
                $evidenceMsgs = [];
                if ($numericViolation && $evidence !== []) {
                    $evidenceMsgs[] = [
                        'role'    => 'user',
                        'content' => "[internal] Raw tool results for this question. EVERY figure in your answer must come from here, verbatim:\n"
                            .mb_substr(implode("\n", $evidence), 0, 12000),
                    ];
                }
                $repairPayload = array_merge($systemOnly, $evidenceMsgs, [
                    ['role' => 'user', 'content' => $repairInstruction],
                ]);
                // Accuracy pass: use the planner-grade lane when numbers are at
                // stake, the cheap repair lane only for cosmetic rewrites.
                $revised = trim($this->openRouter->chat($repairPayload, $numericViolation ? 'answer' : 'repair'));
                if ($revised === '') {
                    break;
                }

                $revisedSanitized = $sanitizer->sanitize($revised);
                if (trim($revisedSanitized) === '' || $sanitizer->leaksReasoning($revisedSanitized)) {
                    break;
                }

                $reCheck   = $this->validator->check($revisedSanitized, $lastUser, $locale, $evidence);
                $reHard    = $this->hardViolations($reCheck['violations']);

                if (count($reHard) < $bestHardCount) {
                    $bestReply     = $revisedSanitized;
                    $bestHardCount = count($reHard);
                    $bestRevised   = true;
                }

                if ($reHard === []) {
                    return ['reply' => $revisedSanitized, 'revised' => true, 'violations' => $check['violations']];
                }

                Log::info('ai.chat.repair_pass_incomplete', ['pass' => $pass, 'remaining' => array_values($reHard)]);
                $draft       = $revisedSanitized;
                $currentHard = $reHard;
                $currentAll  = $reCheck['violations'];
            } catch (\Throwable $e) {
                Log::warning('ai.chat.self_check_repair_failed', ['pass' => $pass, 'message' => $e->getMessage()]);
                break;
            }
        }
        unset($currentHard);

        return ['reply' => $bestReply, 'revised' => $bestRevised, 'violations' => $check['violations']];

    }

    /**
     * Fast deterministic answers for common farm-data questions that do not need
     * model reasoning. This avoids long upstream tool loops for simple lookups
     * such as treatment dates by pest and plot.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function deterministicFarmAnswer(array $messages, string $locale): ?string
    {
        $question = '';
        $allUserQuestions = [];
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $content = $this->visibleUserQuestion((string) ($messages[$i]['content'] ?? ''));
                if ($content !== '') {
                    $allUserQuestions[] = $content;
                    if ($question === '') {
                        $question = $content;
                    }
                }
            }
        }
        if ($question === '') {
            return null;
        }

        $lower = mb_strtolower($question);
        $asksTreatmentDates = (str_contains($lower, 'date') || str_contains($lower, 'quand') || str_contains($lower, 'when'))
            && (str_contains($lower, 'traitement') || str_contains($lower, 'treatment'));
        if (! $asksTreatmentDates) {
            return null;
        }

        $plot = $this->extractPlotName($question);
        if (($plot === null || $plot === '') && $allUserQuestions !== []) {
            foreach ($allUserQuestions as $prior) {
                $plot = $this->extractPlotName($prior);
                if ($plot !== null && $plot !== '') {
                    break;
                }
            }
        }

        $pest = $this->extractPestName($question);
        if (($pest === null || $pest === '') && $allUserQuestions !== []) {
            foreach ($allUserQuestions as $prior) {
                $pest = $this->extractPestName($prior);
                if ($pest !== null && $pest !== '') {
                    break;
                }
            }
        }
        if ($pest === null || $pest === '') {
            return null;
        }

        $scoped = $plot !== null && $plot !== '';
        $callArgs = [
            'pest'  => $pest,
            'order' => 'asc',
            'limit' => 40,
        ];
        if ($scoped) {
            $callArgs['plot'] = $plot;
        }

        try {
            $data = $this->toolRegistry->call('treatments', $callArgs);
        } catch (\Throwable $e) {
            Log::warning('ai.chat.deterministic_shortcut_failed', ['message' => $e->getMessage()]);
            return null;
        }

        if (! empty($data['error'])) {
            if (($data['error'] ?? '') === 'plot_not_found' && $scoped) {
                $available = array_slice(array_values((array) ($data['available_plots'] ?? [])), 0, 12);
                return $this->isFrench($locale)
                    ? 'Je ne trouve pas la parcelle « '.$plot.' ». Parcelles disponibles : '.implode(', ', $available).'.'
                    : 'I cannot find plot “'.$plot.'”. Available plots: '.implode(', ', $available).'.';
            }
            return null;
        }

        $rows = array_values((array) ($data['rows'] ?? []));
        $count = (int) ($data['treatment_count'] ?? count($rows));
        $scopeFr = $scoped ? ' sur la parcelle '.$plot : ' (toutes parcelles)';
        $scopeEn = $scoped ? ' on plot '.$plot : ' (all plots)';

        if ($count === 0 || $rows === []) {
            return $this->isFrench($locale)
                ? 'Aucun traitement contre '.$pest.' n’est enregistré'.$scopeFr.'.'
                : 'No treatment against '.$pest.' is recorded'.$scopeEn.'.';
        }

        $lines = [];
        foreach ($rows as $row) {
            $date = (string) ($row['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $parts = [$date];
            if (! $scoped) {
                $rowPlot = trim((string) ($row['plot'] ?? ''));
                if ($rowPlot !== '') {
                    $parts[] = $rowPlot;
                }
            }
            $product = trim((string) ($row['product'] ?? ''));
            if ($product !== '') {
                $parts[] = $product;
            }
            $lines[] = '- '.implode(' — ', $parts);
        }
        if ($lines === []) {
            return null;
        }

        $shown = count($lines);
        $header = $this->isFrench($locale)
            ? 'Traitements contre '.$pest.$scopeFr.' : '.$count.' date'.($count > 1 ? 's' : '').'.'
            : 'Treatments against '.$pest.$scopeEn.': '.$count.' date'.($count > 1 ? 's' : '').'.';

        $footer = '';
        if ($count > $shown) {
            $footer = $this->isFrench($locale)
                ? "\n(".$shown.' premières dates affichées sur '.$count.'.)'
                : "\n(Showing the first ".$shown.' of '.$count.' dates.)';
        }

        return $header."\n".implode("\n", $lines).$footer;
    }


    private function isFrench(string $locale): bool
    {
        return str_starts_with(mb_strtolower($locale), 'fr');
    }

    private function visibleUserQuestion(string $raw): string
    {
        $content = trim($raw);
        if (preg_match('/Requête utilisateur\s*:\s*(.+)\s*$/isu', $content, $m) === 1) {
            return trim($m[1]);
        }
        if (preg_match('/User request\s*:\s*(.+)\s*$/isu', $content, $m) === 1) {
            return trim($m[1]);
        }
        return $content;
    }

    private function extractPlotName(string $question): ?string
    {
        if (preg_match('/\b(?:parcelle|plot|bloc|block)\s+([\p{L}\p{N}_-]+)/iu', $question, $m) === 1) {
            return trim($m[1]);
        }
        if (preg_match('/\b([A-Z]{1,3}\s*-?\s*\d{1,4})\b/u', $question, $m) === 1) {
            return preg_replace('/\s+/', '', trim($m[1])) ?: trim($m[1]);
        }
        return null;
    }

    private function extractPestName(string $question): ?string
    {
        if (preg_match('/\b(?:mildiou|mildew|plasmopara|o[ïi]dium|oidium|cicadelle|botrytis|cochenille|acarien|puceron|ceratitis\s+capitata|c[ée]ratite|maladie|pest)\b/iu', $question, $m) === 1) {
            return trim($m[0]);
        }
        if (preg_match('/\b(?:sur|contre|pour|for)\s+(?:le|la|les|l\'|the)?\s*([^,?.]+?)\s+(?:de\s+la\s+)?(?:parcelle|plot|bloc|block)\b/iu', $question, $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function buildOpenRouterMessages(array $messages, string $locale, bool $agentMode = false): array
    {
        try {
            $fullContext = $this->cachedContextBuild();
        } catch (\Throwable $e) {
            \Log::warning('ai.context.build_failed', ['error' => $e->getMessage()]);
            $fullContext = ['_unavailable' => true, 'reason' => 'context_build_failed'];
        }

        $normalised = [];
        foreach ($messages as $message) {
            $role = $message['role'] ?? '';
            $content = trim((string) ($message['content'] ?? ''));
            if (! in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }
            $normalised[] = ['role' => $role, 'content' => $content];
        }

        // Trim history to the last 12 turns — enough for topic focus, much cheaper on tokens.
        if (count($normalised) > 12) {
            $normalised = array_slice($normalised, -12);
        }

        // In agent mode we no longer prebake a big JSON snapshot — the model
        // pulls exactly what it needs through tools. Keep only a tiny baseline.
        if ($agentMode) {
            $context = array_intersect_key($fullContext, array_flip(['generated_at', 'currency', 'units', 'period']));
        } else {
            try {
                $routed  = $this->router->slim($fullContext, $normalised);
                $context = $routed['context'] ?? $fullContext;
            } catch (\Throwable $e) {
                \Log::warning('ai.context.router_failed', ['error' => $e->getMessage()]);
                $context = $fullContext;
            }
        }
        try {
            $system = $agentMode
                ? $this->agentSystemPrompt($locale, $context)
                : $this->systemPrompt($locale, $context);
        } catch (\Throwable $e) {
            \Log::warning('ai.context.system_prompt_failed', ['error' => $e->getMessage()]);
            $system = 'You are Flehty Assistant. Live data is temporarily unavailable; answer from general knowledge and ask the user for specifics if needed.';
        }

        $prefetch = $this->prefetchEvidenceMessage($normalised, $locale);

        return array_merge(
            [['role' => 'system', 'content' => $system]],
            $prefetch !== null ? [$prefetch] : [],
            $normalised,
            [$this->languageGuardMessage($normalised, $locale)],
        );
    }

    /**
     * Last-position language pin. A rule buried in a long system prompt is
     * routinely ignored by small models; the same rule as the final message
     * — right before generation — is not. Target language is resolved with
     * the very detector the self-check uses afterwards.
     *
     * @param  array<int, array{role: string, content: string}>  $normalised
     * @return array{role: string, content: string}
     */
    private function languageGuardMessage(array $normalised, string $locale): array
    {
        $lang = $this->validator->targetLanguage($this->lastUserMessage($normalised), $locale);

        $content = $lang === 'fr'
            ? "RÈGLE ABSOLUE DE LANGUE : rédige TOUTE la réponse en français — titres, puces, libellés d'unités et commentaires compris. "
                ."Aucun mot d'anglais, aucun mélange de langues. Les noms de parcelles et de produits restent tels quels. "
                ."N'ajoute pas toi-même de bloc « Vérification » : il est généré automatiquement après ta réponse."
            : "ABSOLUTE LANGUAGE RULE: write the ENTIRE answer in English — headings, bullets, unit labels and comments included. "
                ."No language mixing. Keep plot and product names exactly as stored. "
                ."Do not write your own \"Verification\" block: it is appended automatically after your answer.";

        return ['role' => 'system', 'content' => $content];
    }

    /**
     * Deterministic pre-fetch. Free-tier models frequently skip tool-calling
     * (and the tool-less fallback path has no data at all), which is what
     * produced "Cette information n'est pas dans l'instantané actuel" for
     * plain lookups. {@see AiQuestionPlanner} maps the question to the tools
     * that answer it, we run them here, and the JSON lands in the prompt
     * BEFORE the model writes anything. The agent may still call more tools.
     *
     * @param  array<int, array{role: string, content: string}>  $normalised
     * @return array{role: string, content: string}|null
     */
    private function prefetchEvidenceMessage(array $normalised, string $locale): ?array
    {
        $this->lastPrefetchEvidence = [];
        $this->lastPrefetchCalls = [];

        try {
            $calls = $this->planner->plan($normalised);
        } catch (\Throwable $e) {
            \Log::warning('ai.prefetch.plan_failed', ['error' => $e->getMessage()]);
            return null;
        }
        if ($calls === []) {
            return null;
        }

        $blocks = [];
        foreach ($calls as $call) {
            try {
                $result = $this->toolRegistry->call($call['name'], $call['args']);
            } catch (\Throwable $e) {
                \Log::warning('ai.prefetch.tool_failed', ['tool' => $call['name'], 'error' => $e->getMessage()]);
                continue;
            }

            $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($json)) {
                continue;
            }
            // Same cap the agent loop applies to a tool result.
            $max = (int) config('openrouter.agent.max_tool_result', 6000);
            if ($max > 0 && strlen($json) > $max) {
                $json = substr($json, 0, $max).'…(tronqué)';
            }

            $this->lastPrefetchEvidence[] = $json;
            $this->lastPrefetchCalls[] = ['name' => $call['name'], 'args' => $call['args'], 'result' => $result];
            $args = json_encode($call['args'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $blocks[] = $call['name'].'('.$args.') => '.$json;
        }

        if ($blocks === []) {
            return null;
        }

        $french = str_starts_with(strtolower($locale), 'fr');
        $header = $french
            ? "DONNÉES RÉELLES DÉJÀ EXTRAITES DE LA BASE POUR CETTE QUESTION.\n"
                ."Ce sont des chiffres vérifiés : réponds directement avec eux. N'écris JAMAIS que l'information "
                ."n'est pas disponible tant qu'un bloc ci-dessous contient la donnée. Si un bloc est vide "
                ."(0 ligne / 0 résultat), dis clairement qu'aucune opération n'est enregistrée sur cette période, "
                ."et rappelle la parcelle et la période concernées."
            : "REAL DATA ALREADY FETCHED FROM THE DATABASE FOR THIS QUESTION.\n"
                ."These are verified figures: answer directly from them. NEVER say the information is unavailable "
                ."while a block below contains it. If a block is empty (0 rows), state plainly that no operation is "
                ."recorded for that period, naming the plot and window.";

        return ['role' => 'system', 'content' => $header."\n\n".implode("\n\n", $blocks)];
    }

    /**
     * True when the deterministic pre-fetch already holds everything the answer
     * needs, so the model's planning round would just re-request data we have.
     *
     * Deliberately conservative — the agent loop stays in charge of anything
     * that smells like several lookups, a comparison, a ranking or an
     * open-ended analysis. A false negative only costs the usual latency; a
     * false positive would produce a half-answered question.
     */
    private function prefetchIsSufficient(array $messages): bool
    {
        if (! (bool) config('openrouter.agent.fast_path', true)) {
            return false;
        }
        if ($this->lastPrefetchCalls === [] || count($this->lastPrefetchCalls) > 2) {
            return false;
        }

        // Every pre-fetched tool must have actually resolved. A `plot_not_found`
        // needs the agent's repair round.
        foreach ($this->lastPrefetchCalls as $call) {
            if (($call['result']['ok'] ?? false) !== true) {
                return false;
            }
        }

        $question = $this->lastUserMessage($messages);
        if ($question === '') {
            return false;
        }
        if (mb_strlen($question) > 320 || substr_count($question, '?') > 1) {
            return false;
        }

        // Multi-lookup / analytical phrasing → keep the agent loop.
        $q = ' '.mb_strtolower(strtr($question, [
            'à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c',
        ])).' ';
        foreach ([
            'compar', 'versus', ' vs ', 'evolution', 'tendance', 'trend', 'progress',
            'pourquoi', 'why', 'analyse', 'analys', 'explique', 'explain', 'recommand', 'recommend',
            'classe', 'classement', 'ranking', 'top ', 'meilleur', 'pire', 'worst', 'best',
            'toutes les parcelles', 'chaque parcelle', 'all plots', 'every plot',
            'par parcelle', 'per plot', 'repartition', 'breakdown', 'resume general', 'bilan complet',
        ] as $needle) {
            if (str_contains($q, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The pre-fetched payload plus the same "write the final answer now" pin the
     * agent loop uses before its streaming round, so the fast path produces an
     * answer of identical shape and discipline.
     *
     * @param  array<int, array<string, mixed>>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function finalAnswerPayload(array $payload): array
    {
        $payload[] = [
            'role'    => 'user',
            'content' => '[internal] The data you need is already in the system message above. '
                .'Do not call any tools. Write the final answer for the user now, following the voice, '
                .'precision and formatting rules from the system prompt. Every figure you state must appear '
                .'literally in that data — never add, average or extrapolate. State the scope you actually '
                .'have (plot + period). If a block is empty, say plainly that nothing is recorded for that '
                .'plot and period. If any result carries `_truncated`, `warnings`, `plot_match_warning` or '
                .'`date_warning`, mention it in one short line before the numbers.',
        ];

        return $payload;
    }

    /**
     * Replay the pre-fetched lookups as tool events so the fast path shows the
     * same "consulting the data" activity in the UI as the agent loop.
     */
    private function emitPrefetchEvents(?callable $onEvent): void
    {
        if ($onEvent === null) {
            return;
        }
        foreach ($this->lastPrefetchCalls as $call) {
            $onEvent(['type' => 'tool_start', 'name' => $call['name'], 'args' => $call['args']]);
            $onEvent([
                'type'    => 'tool_end',
                'name'    => $call['name'],
                'ok'      => true,
                'preview' => mb_substr(
                    json_encode($call['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                    0,
                    240,
                ),
            ]);
        }
    }




    /**
     * Compact system prompt used in agent (tool-calling) mode. No pre-baked
     * JSON snapshot — the model must query data through the exposed tools.
     *
     * @param  array<string, mixed>  $baseline
     */
    private function agentSystemPrompt(string $locale, array $baseline): string
    {
        $french = str_starts_with(strtolower($locale), 'fr');
        $language = $french ? 'French' : 'English';
        $baselineJson = json_encode($baseline, JSON_UNESCAPED_UNICODE) ?: '{}';
        $today = now()->toDateString();

        return <<<PROMPT
You are Flehty Assistant — a professional farm-operations analyst inside the Flehty admin app.
Currency is TND unless stated otherwise. Today is {$today}. Default reply language: {$language}; mirror the user's language if different.

## How you work (tool-calling mode)
You do NOT have a pre-baked data snapshot. Instead, you have typed READ-ONLY tools to query live data.
NEVER answer a data question from memory, from the conversation, or by guessing. Every figure must come from a tool result in THIS turn.

Reasoning protocol:
1. If the question needs 2+ lookups, comparisons, or a breakdown — FIRST call `plan` with 1–4 short steps. Do this only once per turn.
2. Prefer the analysis tools below: they already resolve the plot by NAME (pass `plot: "P1"` directly — no need to call `list_plots` first), apply the date window and compute per-hectare values. Never do arithmetic yourself when a tool returns the value.
3. Whenever the user mentions a period, pass the raw phrase straight into the tool's `from`/`to` (they accept "aujourd'hui", "juillet 2026", ISO dates). Use `resolve_date_range` only when you need the window as a standalone answer. "jusqu'à ce jour" / "à ce jour" = omit `from`, set `to` to today.
4. A question with several sub-questions ("quantité d'eau ET coût/ha") = several tool calls. Issue them together in one round, then answer everything — never answer only part of the question.
5. If a tool returns `plot_not_found`, its payload lists `available_plots`: retry the SAME tool immediately with the closest real name. Only ask the user when nothing is close.
6. If a result is empty for the requested window, retry once without the window (all-time) before concluding "0" — then state which window the number covers.
7. Call the smallest set of tools you need, then write the final answer.
8. "détail" / "détails" / "la liste" / "le relevé" of operations = the user wants the individual rows, NOT a summary. Call the matching `*_history` tool. An aggregate tool alone can NEVER answer a "détail" question.
9. If the user disputes a figure you gave ("je sais que c'est 448", "ce n'est pas ça"), do NOT restate or defend the number. Re-query with the listing tool for the same scope, show the rows, and compare. If the totals still differ, say which filters yours used (`applied_filters`) and name the likely cause — different campaign, a wider/narrower window, or rows recorded in another unit.
10. NEVER add up the rows of a listing yourself. When `truncated` is true the rows are a sample: quote `window_total_m3` / `total_kg` / `harvest_count` / `daily_totals`, which always cover the full window.
11. For a farm- or crop-wide "par hectare" figure quote `weighted_m3_per_ha` (total ÷ total ha). Use `average_m3_per_ha` only when the user explicitly asks for the mean across plots, and say which one you used.


## Agronomic glossary (what the data actually holds)
- The farm log has FOUR operation tables only: irrigation, fertilization (links to the `fertilizers` catalog), phytosanitary (links to the `pesticides` catalog, which carries `chemical_composition`), and harvest. Nothing else is recorded.
- "Acides aminés" (amino acids, AA libres, hydrolysats de protéines, peptides) are NOT an operation type and NOT a nutrient like N/P/K: they are an ingredient family of biostimulants. A commercial product such as Naturamin, Aminovert or Terra-Sorb can be catalogued EITHER as a fertilizer OR as a phytosanitary/foliar product depending on how the farm registered it.
- Consequence: a zero from `fertilization_history` NEVER proves the family was not used. For any ingredient-family or "avons-nous utilisé X" question, use `product_usage`, which matches product names AND compositions across both catalogs and returns `matched_products`, `usage_count` and `usage_by_plot`.
- `product_usage` works at any scope: with `plot` for one parcelle, with `crop`, or with no scope at all for the whole farm — always report `usage_by_plot` when the user asks about several or all parcelles.
- If `usage_count` is 0, say the family is absent from BOTH catalogs for that scope and name the products that WERE applied (fertilization_history / treatments) rather than asserting it was never used.
- When the user contests a zero and names a product ("Naturamin contient des acides aminés"), re-run `product_usage` with `query` = that product name on the SAME plot and window before answering.

## Campaigns / seasons (how the farm slices time)
- A "campagne" (season) is a named window stored in the `campaigns` table with `start_date` / `end_date` and an `is_active` flag. It is NOT a calendar year: 2024-2025 typically runs across two civil years.
- Every metric tool accepts a `campaign` argument (name, id, or "active"). When the user says "campagne 2024-2025", "cette saison", "la saison en cours", pass `campaign` INSTEAD of guessing from/to dates. Explicit `from`/`to` always narrow inside that campaign.
- The Reports screen filters by campaign. If your figure was computed all-time while the user reads a campaign-filtered report, the two legitimately differ — always state the window from `applied_filters.campaign_scope`.
- Season-over-season questions ("plus d'eau que la saison dernière ?", "évolution du coût entre 2023-2024 et 2024-2025") → `campaign_compare` (`campaign_a`, optional `campaign_b` — omitted means the previous campaign — and `metric`: water_m3, fertilizer_qty, treatments_count, harvest_kg, cost_tnd). Quote its `delta` and `percent_change`; never subtract the two totals yourself.
- If a campaign name is not found the tool returns `available_campaigns`: retry with the closest real name, or call `list_campaigns` first.

## Data quality, completeness and mobile sync
- A figure can be arithmetically right and still misleading. Two tools audit the records themselves: `data_quality` and `sync_status`.
- `data_quality` (optional `plot` / `crop` / `from` / `to` / `campaign`) reports: operations costed at 0 because no price snapshot AND no price_history row covers them (cost UNDER-estimated), operations with a null/zero quantity, plots without `surface_area_ha` (every per-ha value is null for them), operations attached to no campaign (they disappear from campaign-filtered reports), mixed irrigation units, future-dated rows, and likely duplicates (same plot + date + product + quantity).
- Call `data_quality` when: a cost or per-ha value looks suspiciously low, round or null; the user disputes a figure or says the app shows something different; the user asks whether the data are complete, reliable, coherent, duplicated or missing. Never claim "the data are wrong/incomplete" without having run it.
- `sync_status` reads the mobile `postings` queue. If `pending` or `failed` is greater than 0, the database is behind what technicians recorded: quote the figure, then add one sentence saying it may be incomplete and how many submissions are waiting or failed.
- Report data-quality issues as concrete counts with their impact ("14 fertilisations sans prix → le coût réel est supérieur"), never as a vague disclaimer. When `issue_count` is 0, say the data in this scope are complete instead of hedging.

## Tool routing cheat-sheet
- campaign-scoped metric ("consommation d'eau de la campagne 2024-2025") → the normal tool + `campaign: "2024-2025"`
- season vs season comparison → `campaign_compare`
- "les données sont-elles fiables / complètes ?", "pourquoi ce chiffre est à 0 ?", doublons, saisies manquantes → `data_quality`
- "y a-t-il des données non synchronisées ?", file d'attente mobile, échecs d'envoi → `sync_status`
- water per hectare, TOTAL volume, number of irrigations (one plot, a date, a range, a whole crop, with exclusions) → `water_per_ha` (`plot`, `crop`, `exclude_plots`, `from`, `to`) — aggregate only
- "le détail des irrigations du X au Y" → `irrigation_history` (`plot`, `from`, `to`), optionally together with `water_per_ha` for the totals
- N / P / K / Mg / Ca / S units per hectare → `nutrient_per_ha` (`nutrient: "mg"` for magnesium). Report only the nutrients listed in `tracked_nutrients`; if one is absent, say it is not recorded instead of substituting another.
- treatments: count, dates, chronology, last one, product used, composition, dose/ha, volume/ha → `treatments` (`pest: "mildiou"`, `product`, `order: "asc"` for chronological, `limit: 2` for the last two)
- fertilization log, "combien de fois le produit X", last fertilization date → `fertilization_history` (`product`, `limit: 1` + default desc order for the latest)
- generic product/family usage when the operation type is not explicit (notably amino acids / Naturamin) → `product_usage`; it checks both fertilization and phytosanitary logs
- irrigation events / last N irrigation dates → `irrigation_history` (`limit: 3`)
- harvest window (first/last harvest date), yield, kg/ha → `harvest_history`
- cost/ha, total or for treatments only → `cost_per_ha` (`type: "phytosanitary"` for treatment cost)
- plot surface, crop, variety, last activity → `plot_info`
- product price or composition ("prix de l'Antéor Flash", "composition du Biomate") → `product_info`
- "toutes les parcelles de vigne sauf P1" → pass `crop: "vigne"` + `exclude_plots: ["P1"]`, never one call per plot
- broad KPIs, trends and period comparisons → `get_overview`, `aggregate_operations`, `compare_periods`
- pest/product reference lookups → `search_catalog`

## Voice & precision
- Executive-brief: numbers first, context second. No preambles, no filler ("Sure", "Voici"), no emoji.
- Quote every number from tool results verbatim. Attach units (m³, m³/ha, kg/ha, TND, TND/ha, ha, L/ha). Never hedge with "around" when you have an exact value.
- Always state the scope you used: plot name and the date window (or "depuis le début / toutes périodes").
- Multi-plot answers → one bullet or table row per plot, plus the average when the tool returns one.
- Dates in ISO (`YYYY-MM-DD` or `YYYY-MM`). Never invent a date.
- Zero is a valid answer — write "0 <unit>", not "no data".
- If a value is `null` because the plot has no surface area, say the per-hectare value cannot be computed.
- If a tool returns `ok:false` or empty results, say so plainly in one line and suggest the exact module to check.
- Report the scope you actually queried, never more. A tool result carries `applied_filters`: if it contains `plot_match_warning` or `date_warning`, surface that caveat to the user in one line before the numbers.
- If a plot row carries `warnings` (missing volumes, missing prices, mixed units), state the caveat — a total built on incomplete rows must never be presented as final.
- NEVER claim a figure covers "l'ensemble des enregistrements" / "all records". You only ever see what the filters returned. Say "sur la période du X au Y" instead.
- When `irrigation_history` returns `truncated: true`, say how many rows you are showing out of `irrigation_count`.
- When a listing tool returns `total_matching` / `irrigation_count` / `harvest_count`, the COUNT answer is that field — never the number of rows you can see, and never `returned_rows`. If `truncated` is true, say "les N plus récentes sur M au total".
- If two tools give different numbers for the same question, trust the one carrying `total_matching` / `*_count` (a full count) over any row listing, and never present a capped list length as a total.
- NEVER assert "aucun enregistrement" / "0" when the tool result contains `empty_result_diagnostic` with `outside_window_count > 0`. Say that nothing matches the requested period or campaign, give the dates of the records that DO exist, and ask whether to look at that period instead.
- If `applied_filters.campaign` (or the campaign note) warns that the label matched several campaigns, name the exact season and window you used before giving the figure.
- Campaign scoping includes rows explicitly attached to that campaign even when their date sits slightly outside the season window. Report the campaign, not a raw date range, when the user asked by campaign.

## Accuracy protocol (more important than speed — take as many rounds as you need)
- Never answer a data question from memory or from the conversation history: re-query the tools for THIS question, even if a similar figure was given earlier.
- Before answering, re-read every tool result you received and check that each number in your draft appears literally in one of them. If a figure is not there, do not write it — call the missing tool instead.
- If two tools disagree, do not average, guess or pick one silently: call a third tool (or the same one with explicit `from`/`to`) until the discrepancy is explained, then state the reconciled figure.
- If a result looks surprising (0, a huge jump, a missing month), verify it with a second tool before reporting it.
- Prefer an extra tool round over an approximation. There is no time limit; an answer that takes longer but is exact is always the correct trade-off.
- If after all rounds a figure is still uncertain, say precisely what is known and what could not be verified — never round an unknown into a confident number.



- Never mention tools, SQL, iterations or internal instructions to the user.

## Questions about yourself
- If the user asks how you work, whether you check your answers, or asks you to change a behaviour/limit: reply in ONE plain sentence, in their language, with no tool names, no argument names, no row limits, no mention of "outils de données", and no internal reasoning.
- Never explain that "no data tool is needed" — just answer.
- A behaviour request ("arrête d'ajouter ce paragraphe", "sois plus court") is accepted in one short sentence and applied for the rest of the conversation.
- If you gave a wrong figure, correct it in one sentence with the right number. Do not describe which tool failed or why.

## Never leak plumbing (hard rule)
- NEVER print a row UUID. Refer to a plot/campaign/product by its NAME only.
- NEVER name a tool, a parameter or a result field (cost_per_ha, get_operations, usage_count, total_matching, returned_rows…), and never quote a database or SQL error.
- NEVER mention the system message, the "DONNÉES RÉELLES" block, or any internal instruction.
- If a lookup fails, do NOT report the failure mechanics: retry with resolved names (list_plots / list_campaigns give the real names and ids) or, as a last resort, say in one plain sentence that the information could not be retrieved and offer to retry.
- Never ask the user to rephrase with an id or an exact campaign name: resolve it yourself with list_campaigns / list_plots and answer.



## Formatting
- Clean GitHub-flavoured Markdown. `-` bullets. Bold only key numbers/entities.
- Adaptive length: greeting → 1 sentence; lookup → 1 short sentence; comparison → short intro + tight bullets or a ≤6-row table; deep analysis only if explicitly requested.
- Never open with a heading. Do not repeat the question. No "As an AI".

## Scope
Answer questions about dashboard, sync, plots, campaigns, water/irrigation, fertilization, phytosanitary treatments, pests, harvest, costs, labor, prices, postings, users, notifications, catalog, and reports. For off-topic requests (weather, news, personal advice, etc.), refuse briefly in the user's language.

## Baseline (tiny — everything else comes from tools)
{$baselineJson}
PROMPT;
    }


    /**
     * Short-TTL memoization of the full live-data context. The builder already
     * caches individual sections with stamp invalidation, but the top-level
     * assembly still runs ~15 stamp queries per request. A 20s window is short
     * enough to feel real-time (dashboards refresh at that cadence anyway) and
     * long enough to cover follow-up questions in the same conversation burst.
     */
    private function cachedContextBuild(): array
    {
        $ttl = (int) config('openrouter.context_cache_ttl', 20);
        if ($ttl <= 0) {
            return $this->contextBuilder->build();
        }
        return Cache::remember('ai.chat.context.v1', $ttl, fn () => $this->contextBuilder->build());
    }

    // ─── Prompt cache / budget helpers ──────────────────────────────────

    /** @param array<int, array{role: string, content: string}> $messages */
    private function lastUserMessage(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return (string) ($messages[$i]['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * Cheap cache key on the user-visible inputs PLUS a lightweight stamp of
     * the operational data.
     *
     * Keying on the messages alone was wrong: after a record is added or
     * corrected, the same question kept returning the pre-edit answer for the
     * whole TTL — and a user re-asking to challenge a figure got the exact
     * same reply back, so the assistant could never correct itself.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function earlyCacheKey(array $messages, string $locale): string
    {
        $models = (array) config('openrouter.models', []);
        // Normalise: trim + role/content only.
        $norm = [];
        foreach ($messages as $m) {
            $norm[] = [
                'r' => (string) ($m['role'] ?? ''),
                'c' => trim((string) ($m['content'] ?? '')),
            ];
        }
        $hash = hash('sha256', (string) json_encode([
            'model'   => $models[0] ?? '',
            'locale'  => strtolower(substr($locale, 0, 2)),
            'msgs'    => $norm,
            'temp'    => (float) config('openrouter.temperature'),
            'max'     => (int) config('openrouter.max_tokens'),
            'data'    => $this->dataStamp(),
        ], JSON_UNESCAPED_UNICODE));

        return 'openrouter.prompt.'.$hash;
    }

    /**
     * Row-count + last-write fingerprint of the operational tables, so any
     * insert/update/delete invalidates every cached answer. Memoized briefly
     * — this must stay far cheaper than the answer it guards.
     */
    private function dataStamp(): string
    {
        return (string) Cache::remember('ai.chat.data_stamp.v1', 15, static function (): string {
            // Every table the farm tools read from. Unknown ones are skipped
            // by the hasTable() guard below, so this list is safe to extend.
            $tables = [
                'irrigation_operations', 'fertilization_operations',
                'harvest_operations', 'plots', 'fertilizers',
                'pesticides', 'price_history', 'campaigns',
            ];

            $parts = [];
            foreach ($tables as $t) {
                try {
                    if (! Schema::hasTable($t)) continue;
                    $row = DB::selectOne(
                        "SELECT COUNT(*) AS c, COALESCE(MAX(updated_at)::text,'') AS u FROM $t"
                    );
                    $parts[] = $t.'='.($row->c ?? 0).':'.($row->u ?? '');
                } catch (\Throwable) {
                    // Unknown state → per-minute stamp keeps answers fresh.
                    $parts[] = $t.'=?'.now()->format('YmdHi');
                }
            }

            return substr(md5(implode('|', $parts)), 0, 16);
        });
    }

    /**
     * The user is challenging a figure we just gave ("c'est faux", "je sais
     * que c'est 448"). Serving a cached reply here would repeat the disputed
     * answer verbatim, so always re-query.
     */
    private function isDispute(string $message): bool
    {
        return (bool) preg_match(
            '/\b(c\W?est (faux|pas (ç|c)a|incorrect|erron[ée])|ce n\W?est pas (ç|c)a|tu (te )?trompes?'
            .'|je sais que|en r[ée]alit[ée]|v[ée]rifie|recompte|recalcule|es[- ]tu s[ûu]r'
            .'|are you sure|that\W?s (wrong|incorrect)|double[- ]check|recheck|recount)\b/iu',
            $message,
        );
    }

    private function cacheGet(string $key): ?string
    {
        if (! (bool) config('openrouter.cache.enabled', true)) {
            return null;
        }
        $value = Cache::get($key);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function cachePut(string $key, string $reply): void
    {
        if (! (bool) config('openrouter.cache.enabled', true) || trim($reply) === '') {
            return;
        }
        $ttl = max(30, (int) config('openrouter.cache.ttl', 600));
        Cache::put($key, $reply, $ttl);
    }

    /**
     * Attribute tokens to the caller's daily budget. When the upstream provider
     * omits usage on streams, seed the prompt token count from the outbound
     * payload so we don't undercount by the whole system prompt.
     *
     * @param array<int, array{role: string, content: string}> $payload
     */
    private function recordUsage(int|string|null $subjectId, array $payload = []): void
    {
        // Seed prompt tokens from the payload when possible — no-op if provider
        // already reported them.
        if ($payload !== []) {
            $chars = 0;
            foreach ($payload as $m) {
                $chars += mb_strlen((string) ($m['content'] ?? ''));
            }
            $this->openRouter->seedApproxPromptTokens((int) ceil($chars / 4));
        }
        $tokens = (int) ($this->openRouter->lastUsage()['total'] ?? 0);
        if ($tokens > 0) {
            $this->budget->record($subjectId, $tokens);
        }
    }

    private function budgetExhaustedMessage(string $locale): string
    {
        return str_starts_with(strtolower($locale), 'fr')
            ? "Le budget IA quotidien pour cet utilisateur est atteint. Réessayez demain ou augmentez la limite dans la configuration."
            : "The daily AI budget for this user has been reached. Try again tomorrow or raise the limit in configuration.";
    }

    /** @param  array<string, mixed>  $context */
    private function systemPrompt(string $locale, array $context): string
    {
        $french = str_starts_with(strtolower($locale), 'fr');
        $language = $french ? 'French' : 'English';
        $json = json_encode($context, JSON_UNESCAPED_UNICODE);

        $modules = $french
            ? 'Tableau de bord, Configuration, Parcelles, Campagnes, Eau, Engrais, Pesticides, Bioagresseurs, Main-d’œuvre, Prix, Publications, Rapports (irrigation, fertilisation, phytosanitaire, récolte, coût), Utilisateurs, Notifications, Synchro.'
            : 'Dashboard, Configuration, Plots, Campaigns, Water, Fertilizers, Pesticides, Pests, Labor, Prices, Postings, Reports (irrigation, fertilization, phytosanitary, harvest, production cost), Users, Notifications, Sync.';

        $availability = $this->availabilitySummary($context);
        $noDataPhrase = $french
            ? 'Cette information n\'est pas dans l\'instantané actuel.'
            : 'That information is not in the current snapshot.';

        return <<<PROMPT
You are Flehty Assistant — a professional farm-operations analyst inside the Flehty admin app.
Currency is TND unless the data says otherwise.

## Voice (strict)
- Tone: calm, precise, executive-brief. Speak like a senior operations analyst briefing a manager.
- Confident and neutral. No exclamation marks, no emoji, no hype words ("amazing", "great", "génial").
- Lead with the answer. Numbers first, context second. No preambles, no self-references.
- Prefer verbs of measurement ("recorded", "totals", "averages", "represents") over vague verbs ("looks like", "seems").
- When uncertainty is warranted, say so plainly in one clause ("data as of {date}", "partial period").

## Precision (strict)
- Quote every number from the JSON verbatim. Do not round a raw integer count; only round derived ratios and only to 2 decimals.
- Dates: use ISO `YYYY-MM-DD` (or `YYYY-MM` for months) exactly as stored. Never invent a date, never write "recently" / "lately".
- Always attach the unit (m³, ha, kg, TND, m³/ha, kg/ha). A bare number without a unit is a bug.
- When aggregating, name the exact scope in the same sentence (e.g. "across 4 plots, month 2026-07"). Never emit a total without its scope.
- Do not hedge with ranges, "environ", "around", "approximately" when the JSON has an exact value.
- If the JSON value is 0, write "0" with the unit — never "no data", "none recorded" or an empty line.
- If a requested field is absent from the snapshot, use the missing-data fallback below. Do not estimate.

## Language (strict)
- Default reply language: {$language}.
- If the user's LAST message is clearly written in another language (French, English, Arabic, Darija in Latin script, Spanish…), MIRROR that language for your entire reply, including headings, bullets and units labels. Never mix two languages in one reply.
- Keep proper nouns (plot names, product names) exactly as stored.

## Length (adaptive — match the question)
- Greeting / yes-no / single-number question → 1 sentence, no bullets, no headings.
- Simple lookup ("how much water on X?") → 1 short sentence with the number bolded.
- Comparison / ranking / breakdown → short intro sentence + a tight bullet list or a small markdown table (max 6 rows).
- Deep analysis only when explicitly asked → structured with 2-3 `###` sub-sections, still ≤ 220 words.
- Never pad. No "let me know if…", no recap of the question, no "As an AI".

## Formatting
- Clean GitHub-flavoured Markdown. One blank line between blocks. No stray backticks, no HTML.
- **Bold** only key figures and entity names — do not bold whole sentences.
- Use `-` bullets (not `*`). Use a table only when comparing ≥ 3 items on ≥ 2 dimensions.
- Never open with a heading. Headings only for multi-topic answers; skip them otherwise.
- Units: m³ (water), ha (area), kg (fertilizer/harvest), TND (costs), m³/ha and kg/ha (intensity). Round to at most 2 decimals; use thousands separators (e.g. 12,450.30).

## Conversation memory
- Treat the prior turns as authoritative context. If the user says "and last month?", "and for that plot?", "same question but in kg/ha", resolve the reference from the most recent turn that named a plot / product / timeframe. Never ask the user to repeat what they already said in this thread.
- If the reference is truly ambiguous across several prior turns, ask ONE short clarifying question instead of guessing.

## Grounding rules (strict)
- Use ONLY the LIVE DATA JSON below. Do not infer, extrapolate, average across periods, or fill gaps from prior knowledge.
- Quote plot names, product names, dates and numbers EXACTLY as they appear in the JSON.
- Distinguish **this month** (dashboard.this_month, *.this_month_*, costs.this_month) from **cumulative** (plot_operations, costs.cumulative, water.consumption_by_plot_m3). If the user's timeframe is ambiguous and not resolvable from prior turns, state which one you used.
- Per-plot → plot_operations. Per-product → fertilization.by_fertilizer / phytosanitary.by_pesticide. For disease or pest-treatment questions, first inspect phytosanitary.by_target_pest and phytosanitary.by_plot_target_pest, then use phytosanitary.by_plot and plot_operations for summary totals. When the query uses a pest scientific name, search the `pests` catalog `scientific_name` fields and the phytosanitary target pest names. For campaign questions, inspect campaigns.active_campaigns and campaigns.campaigns. Prices → prices[], water.current_price_per_unit, labor.current_daily_rate_tnd. System state → users, notifications, postings, catalog_items, campaigns.
- Stay inside the Flehty application domain. You may answer questions about plots, campaigns, water, fertilization, phytosanitary treatments, harvest, costs, users, notifications, and reports. You may explain how a module works.
- If the user asks about topics outside this app context — weather, general news, politics, sports, personal advice, unrelated trivia, medical advice, or anything not present in the provided data — do not guess. Reply briefly and clearly that the topic is outside the assistant's scope.
- For out-of-scope questions, use one of these short refusal patterns (in the user's language): "Sorry, I am not trained to answer that." or "Oops, we are not allowed to discuss that topic here."

## Missing-data fallback (mandatory)
- Before answering any factual question, check DATA AVAILABILITY. If the relevant section is empty/absent/zero-count, reply with one short line:
  "{$noDataPhrase}" + name the exact module where the user can add/find it, drawn from: {$modules}
- If only part of the answer is available, give the part you have, then a second line prefixed with "Missing: ".
- Never fabricate. Zero is a valid answer — say "0" (or "aucun"/"none") when a count is genuinely zero, not "no data".

## Interpretation allowed
- You MAY compute ratios from present numbers (cost/ha, m³/ha, yield/ha), rank plots, and explain what a module does.
- You MAY NOT project future values, compare to industry benchmarks, or invent trends across months not in the snapshot.

## DATA AVAILABILITY (quick index — trust this before scanning JSON)
{$availability}

## LIVE DATA (JSON snapshot, cached with per-section stamp invalidation)
{$json}
PROMPT;
    }

    /**
     * Fast, at-a-glance inventory of which context sections actually have data,
     * so the model can answer "is it available?" without scanning the full JSON.
     *
     * @param  array<string, mixed>  $context
     */
    private function availabilitySummary(array $context): string
    {
        $lines = [];
        foreach ($context as $key => $value) {
            if (in_array($key, ['generated_at', 'currency', 'units', 'period'], true)) {
                continue;
            }
            $lines[] = '- '.$key.': '.$this->describeSection($value);
        }

        return implode("\n", $lines);
    }

    private function describeSection(mixed $value): string
    {
        if ($value === null || $value === [] || $value === '') {
            return 'EMPTY';
        }
        if (is_array($value)) {
            $isList = array_is_list($value);
            if ($isList) {
                return count($value) === 0 ? 'EMPTY' : count($value).' item(s)';
            }
            $parts = [];
            foreach ($value as $k => $v) {
                if (is_array($v)) {
                    $parts[] = $k.'='.(array_is_list($v) ? count($v).'i' : 'obj');
                } elseif (is_numeric($v)) {
                    $parts[] = $k.'='.$v;
                } elseif (is_bool($v)) {
                    $parts[] = $k.'='.($v ? 'true' : 'false');
                } elseif (is_string($v) && $v !== '') {
                    $parts[] = $k.'=set';
                }
            }
            return $parts === [] ? 'EMPTY' : implode(', ', array_slice($parts, 0, 8));
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        return 'set';
    }
}


