<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class AiChatService
{
    public function __construct(
        private readonly AiContextBuilder $contextBuilder,
        private readonly OpenRouterClient $openRouter,
        private readonly ResponseValidator $validator,
        private readonly PromptRouter $router,
        private readonly TokenBudget $budget,
        private readonly AiAgentLoop $agent,
        private readonly AiToolRegistry $toolRegistry,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{reply: string, conversation_id: string, revised?: bool, violations?: array<int, string>, cached?: bool, degraded?: bool}
     */
    public function reply(array $messages, string $locale, ?string $conversationId = null, int|string|null $subjectId = null): array
    {
        $id = $conversationId ?: (string) Str::uuid();

        // Cheap cache key on messages+locale — avoids building the heavy live-data
        // context on cache hits (context build = ~15 SQL stamp queries + section aggregates).
        $earlyKey = $this->earlyCacheKey($messages, $locale);
        if (($cached = $this->cacheGet($earlyKey)) !== null) {
            return ['reply' => $cached, 'conversation_id' => $id, 'revised' => false, 'violations' => [], 'cached' => true];
        }

        if ($this->budget->isExhausted($subjectId)) {
            Log::info('ai.chat.budget_exhausted', ['subject' => (string) $subjectId]);
            return [
                'reply' => $this->budgetExhaustedMessage($locale), 'conversation_id' => $id,
                'revised' => false, 'violations' => [], 'degraded' => true,
            ];
        }

        $agentEnabled = (bool) config('openrouter.agent.enabled', true);
        $payload = $this->buildOpenRouterMessages($messages, $locale, $agentEnabled);

        if ($agentEnabled) {
            try {
                $buf = '';
                $reply = $this->agent->run($payload, static function (string $d) use (&$buf) { $buf .= $d; });
                if (trim($reply) === '' && $buf !== '') {
                    $reply = $buf;
                }
            } catch (\Throwable $e) {
                Log::warning('ai.agent.loop_failed_fallback_to_direct', ['message' => $e->getMessage()]);
                $reply = $this->openRouter->chat($payload);
            }
        } else {
            $reply = $this->openRouter->chat($payload);
        }
        $this->recordUsage($subjectId, $payload);

        $final = $this->selfCheck($reply, $messages, $locale, $payload);
        if ($final['revised']) {
            $this->recordUsage($subjectId, $payload);
        }
        $this->cachePut($earlyKey, $final['reply']);

        return [
            'reply'           => $final['reply'],
            'conversation_id' => $id,
            'revised'         => $final['revised'],
            'violations'      => $final['violations'],
            'cached'          => false,
        ];
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
        if (($cached = $this->cacheGet($earlyKey)) !== null) {
            $onDelta($cached);
            return ['reply' => $cached, 'conversation_id' => $id, 'revised' => false, 'violations' => [], 'cached' => true];
        }

        if ($this->budget->isExhausted($subjectId)) {
            $msg = $this->budgetExhaustedMessage($locale);
            $onDelta($msg);
            return ['reply' => $msg, 'conversation_id' => $id, 'revised' => false, 'violations' => [], 'degraded' => true];
        }

        $agentEnabled = (bool) config('openrouter.agent.enabled', true);
        $payload = $this->buildOpenRouterMessages($messages, $locale, $agentEnabled);

        if ($agentEnabled) {
            try {
                $streamed = $this->agent->run($payload, $onDelta, $onEvent);
            } catch (\Throwable $e) {
                Log::warning('ai.agent.loop_failed_fallback_to_direct', ['message' => $e->getMessage()]);
                $streamed = $this->openRouter->chatStream($payload, $onDelta);
            }
        } else {
            $streamed = $this->openRouter->chatStream($payload, $onDelta);
        }
        $this->recordUsage($subjectId, $payload);

        $final = $this->selfCheck($streamed, $messages, $locale, $payload);
        if ($final['revised']) {
            $this->recordUsage($subjectId, $payload);
        }
        $this->cachePut($earlyKey, $final['reply']);

        return [
            'reply'           => $final['reply'],
            'conversation_id' => $id,
            'revised'         => $final['revised'],
            'violations'      => $final['violations'],
            'cached'          => false,
        ];
    }

    /**
     * Post-generation self-check. Runs deterministic validation and, when
     * a hard rule is broken, asks the model for ONE repair pass constrained
     * by the violated rules and the same system prompt.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<int, array{role: string, content: string}>  $payload
     * @return array{reply: string, revised: bool, violations: array<int, string>}
     */
    private function selfCheck(string $reply, array $messages, string $locale, array $payload): array
    {
        $length   = mb_strlen($reply);
        $lastUser = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $lastUser = (string) ($messages[$i]['content'] ?? '');
                break;
            }
        }

        $check = $this->validator->check($reply, $lastUser, $locale);
        if ($check['ok']) {
            return ['reply' => $reply, 'revised' => false, 'violations' => []];
        }

        // Gate: short greetings / one-liners rarely justify a full repair call.
        // Only revise when a hard rule (language, HTML, unbalanced fence) is broken
        // or the reply is long enough to matter. This halves upstream volume in
        // the common "hello" / yes-no / single-number cases.
        $hardViolations = array_filter(
            $check['violations'],
            static fn (string $v) => str_starts_with($v, 'language_mismatch')
                || $v === 'contains_html'
                || $v === 'unbalanced_code_fence',
        );
        // Only trigger the repair round-trip when a HARD rule broke. Cosmetic
        // violations (bullet style, filler openers, minor length) don't justify
        // doubling response latency — they're logged and left for a future pass.
        if ($hardViolations === []) {
            return ['reply' => $reply, 'revised' => false, 'violations' => $check['violations']];
        }

        Log::info('ai.chat.self_check_failed', [
            'violations'    => $check['violations'],
            'target_lang'   => $check['target_lang'],
            'detected_lang' => $check['detected_lang'],
            'length'        => $length,
        ]);

        $lang = $check['target_lang'] === 'fr' ? 'French' : ($check['target_lang'] === 'en' ? 'English' : 'the same language the user just wrote in');
        $violationList = implode(', ', $check['violations']);

        $repairInstruction = <<<INSTR
Your previous draft violated these rules: {$violationList}.
Rewrite the SAME answer for the same user question, keeping every factual claim, number and entity name identical, but fix the violations:
- Reply in {$lang} only, no language mixing.
- Do not open with a markdown heading.
- Use `-` for bullets, never `*`.
- No HTML, no unmatched code fences.
- No "As an AI", "Sure!", "Voici", "let me know if…", or similar filler.
- Keep it concise; match the length rules in the system prompt.
Return ONLY the corrected answer, no meta commentary, no "here is the revised answer".

Previous draft:
---
{$reply}
---
INSTR;

        try {
            // Slim repair payload: keep the system prompt (rules) but drop the
            // heavy live-data JSON isn't needed — the numbers are already in the
            // draft. This roughly halves upstream tokens on the repair pass.
            $systemOnly = ! empty($payload) && ($payload[0]['role'] ?? '') === 'system'
                ? [$payload[0]]
                : [];
            $repairPayload = array_merge($systemOnly, [
                ['role' => 'user', 'content' => $repairInstruction],
            ]);
            $revised = trim($this->openRouter->chat($repairPayload));
            if ($revised === '') {
                return ['reply' => $reply, 'revised' => false, 'violations' => $check['violations']];
            }

            return ['reply' => $revised, 'revised' => true, 'violations' => $check['violations']];
        } catch (\Throwable $e) {
            Log::warning('ai.chat.self_check_repair_failed', ['message' => $e->getMessage()]);
            return ['reply' => $reply, 'revised' => false, 'violations' => $check['violations']];
        }
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

        return array_merge(
            [['role' => 'system', 'content' => $system]],
            $normalised,
        );
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

        return <<<PROMPT
You are Flehty Assistant — a professional farm-operations analyst inside the Flehty admin app.
Currency is MAD unless stated otherwise. Default reply language: {$language}; mirror the user's language if different.

## How you work (tool-calling mode)
You do NOT have a pre-baked data snapshot. Instead, you have typed READ-ONLY tools to query live data.

Reasoning protocol:
1. If the question needs 2+ lookups, comparisons, or a breakdown — FIRST call `plan` with 1–4 short steps. Do this only once per turn.
2. Whenever the user mentions a period ("last july", "juillet dernier", "this month", "cette saison", "Q2 2024", "30 derniers jours"…) — call `resolve_date_range` FIRST with the raw phrase, then pass the returned `from`/`to` to other tools. Never guess dates yourself.
3. Call the smallest set of data tools you need. Prefer `aggregate_operations` and `compare_periods` over raw row dumps.
4. Resolve entities (plot name → plot_id) via `list_plots`; campaigns via `list_campaigns`; catalog items via `search_catalog`.
5. When you have enough data, stop calling tools and write the final answer.

## Voice & precision
- Executive-brief: numbers first, context second. No preambles, no filler ("Sure", "Voici"), no emoji.
- Quote every number from tool results verbatim. Attach units (m³, kg, MAD, ha). Never hedge with "around" when you have an exact value.
- Dates in ISO (`YYYY-MM-DD` or `YYYY-MM`). Never invent a date.
- Zero is a valid answer — write "0 <unit>", not "no data".
- If a tool returns `ok:false` or empty results, say so plainly in one line and suggest the exact module to check.

## Formatting
- Clean GitHub-flavoured Markdown. `-` bullets. Bold only key numbers/entities.
- Adaptive length: greeting → 1 sentence; lookup → 1 short sentence; comparison → short intro + tight bullets or a ≤6-row table; deep analysis only if explicitly requested.
- Never open with a heading. Do not repeat the question. No "As an AI".

## Scope
Answer questions about plots, campaigns, water/irrigation, fertilization, phytosanitary treatments, harvest, costs, users, notifications, catalog, and reports. For off-topic requests (weather, news, personal advice, etc.), refuse briefly in the user's language.

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

    /**
     * Cheap cache key on the user-visible inputs only. Avoids building the
     * heavy live-data context just to compute a key that would miss anyway
     * once any DB row changes (context bakes stamps into its own inner caches).
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
        ], JSON_UNESCAPED_UNICODE));

        return 'openrouter.prompt.'.$hash;
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
            ? 'Tableau de bord, Configuration, Parcelles, Campagnes, Eau, Engrais, Pesticides, Bioagresseurs, Rapports (irrigation, fertilisation, phytosanitaire, récolte, coût), Utilisateurs, Notifications, Synchro.'
            : 'Dashboard, Configuration, Plots, Campaigns, Water, Fertilizers, Pesticides, Pests, Reports (irrigation, fertilization, phytosanitary, harvest, production cost), Users, Notifications, Sync.';

        $availability = $this->availabilitySummary($context);
        $noDataPhrase = $french
            ? 'Cette information n\'est pas dans l\'instantané actuel.'
            : 'That information is not in the current snapshot.';

        return <<<PROMPT
You are Flehty Assistant — a professional farm-operations analyst inside the Flehty admin app.
Currency is MAD unless the data says otherwise.

## Voice (strict)
- Tone: calm, precise, executive-brief. Speak like a senior operations analyst briefing a manager.
- Confident and neutral. No exclamation marks, no emoji, no hype words ("amazing", "great", "génial").
- Lead with the answer. Numbers first, context second. No preambles, no self-references.
- Prefer verbs of measurement ("recorded", "totals", "averages", "represents") over vague verbs ("looks like", "seems").
- When uncertainty is warranted, say so plainly in one clause ("data as of {date}", "partial period").

## Precision (strict)
- Quote every number from the JSON verbatim. Do not round a raw integer count; only round derived ratios and only to 2 decimals.
- Dates: use ISO `YYYY-MM-DD` (or `YYYY-MM` for months) exactly as stored. Never invent a date, never write "recently" / "lately".
- Always attach the unit (m³, ha, kg, MAD, m³/ha, kg/ha). A bare number without a unit is a bug.
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
- Units: m³ (water), ha (area), kg (fertilizer/harvest), MAD (costs), m³/ha and kg/ha (intensity). Round to at most 2 decimals; use thousands separators (e.g. 12,450.30).

## Conversation memory
- Treat the prior turns as authoritative context. If the user says "and last month?", "and for that plot?", "same question but in kg/ha", resolve the reference from the most recent turn that named a plot / product / timeframe. Never ask the user to repeat what they already said in this thread.
- If the reference is truly ambiguous across several prior turns, ask ONE short clarifying question instead of guessing.

## Grounding rules (strict)
- Use ONLY the LIVE DATA JSON below. Do not infer, extrapolate, average across periods, or fill gaps from prior knowledge.
- Quote plot names, product names, dates and numbers EXACTLY as they appear in the JSON.
- Distinguish **this month** (dashboard.this_month, *.this_month_*, costs.this_month) from **cumulative** (plot_operations, costs.cumulative, water.consumption_by_plot_m3). If the user's timeframe is ambiguous and not resolvable from prior turns, state which one you used.
- Per-plot → plot_operations. Per-product → fertilization.by_fertilizer / phytosanitary.by_pesticide. For disease or pest-treatment questions, first inspect phytosanitary.by_target_pest and phytosanitary.by_plot_target_pest, then use phytosanitary.by_plot and plot_operations for summary totals. When the query uses a pest scientific name, search the `pests` catalog `scientific_name` fields and the phytosanitary target pest names. For campaign questions, inspect campaigns.active_campaigns and campaigns.campaigns. Prices → prices[], water.current_price_per_unit, labor.current_daily_rate_mad. System state → users, notifications, postings, catalog_items, campaigns.
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


