<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| OpenRouter configuration
|--------------------------------------------------------------------------
| All secrets are read from the environment. NEVER commit a real key here.
| The client rotates through up to 4 keys and 2 models, honours Retry-After
| on 429/5xx, and quarantines a key on 401/402/429 for `quarantine_seconds`.
*/

/**
 * Collect API keys for a prefix from the environment.
 *
 * Accepts, in order:
 *   - OPENROUTER_..._API_KEYS  → comma / whitespace / semicolon separated list
 *   - OPENROUTER_..._API_KEY   → single primary key
 *   - OPENROUTER_..._API_KEY_2 … _10 → numbered fallback keys
 *
 * Blanks are ignored and duplicates removed while preserving order.
 *
 * @return array<int, string>
 */
$openrouterKeys = static function (string $base): array {
    $collected = [];

    $push = static function ($value) use (&$collected): void {
        if (! is_string($value)) {
            return;
        }
        foreach (preg_split('/[\s,;]+/', $value) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $collected[] = $part;
            }
        }
    };

    $push(env($base.'S'));       // e.g. OPENROUTER_API_KEYS
    $push(env($base));           // e.g. OPENROUTER_API_KEY
    for ($i = 2; $i <= 10; $i++) {
        $push(env($base.'_'.$i)); // e.g. OPENROUTER_API_KEY_2 … _10
    }

    return array_values(array_unique($collected));
};

return [
    // Shared key pool — round-robin, with automatic quarantine on 401/402/429.
    // Set OPENROUTER_API_KEY plus OPENROUTER_API_KEY_2…_10, and/or a
    // comma-separated OPENROUTER_API_KEYS list. Blanks/duplicates are ignored.
    'api_keys' => $openrouterKeys('OPENROUTER_API_KEY'),

    // Optional dedicated key lanes. This lets the tool-planning model, the final
    // answer model, and the repair/fallback model run on separate free keys when
    // available, instead of all competing for the same quota. Empty lanes fall
    // back to the shared `api_keys` pool above, and every lane also appends the
    // shared pool as a last-resort fallback at runtime.
    'planner_api_keys' => $openrouterKeys('OPENROUTER_PLANNER_API_KEY'),

    'answer_api_keys' => $openrouterKeys('OPENROUTER_ANSWER_API_KEY'),

    'repair_api_keys' => $openrouterKeys('OPENROUTER_REPAIR_API_KEY'),


    // Model fallback chain — first entry is primary; used in order on upstream
    // failure. Hardcoded on purpose (not env-driven) — this is a point-in-time
    // pick from OpenRouter's live catalog, not something to override per
    // environment. Edit the arrays directly when the roster needs to change.
    //
    // IMPORTANT — free-tier model slugs churn constantly on OpenRouter (providers
    // add/retire `:free` variants with no warning; a retired slug 404s as
    // `model_not_found`, which is NOT retried — the client just moves to the next
    // entry, dead or not). The previous roster here (deepseek-chat-v3.1:free,
    // gemini-2.0-flash-exp:free, llama-3.3-70b-instruct:free, qwen-2.5-72b-instruct:free)
    // was ALL retired/paywalled as of the verification below — every call to any
    // of them 404'd, which is what forced every request through the full
    // recovery-pass/retry storm before limping to a degraded, tool-less answer.
    //
    // Verified LIVE against OpenRouter's own `GET /api/v1/models` on 2026-08-09 —
    // filtered for `pricing.prompt == 0` AND `tools` in `supported_parameters`.
    // Spread across 4 different providers on purpose so one provider retiring a
    // slug doesn't take down the whole chain again; `openrouter/free` (an
    // OpenRouter-managed auto-router over whatever free+tool-capable models are
    // live right now) sits last as a self-healing safety net against the next
    // retirement wave. Re-run the same check periodically (fetch
    // openrouter.ai/api/v1/models and filter as above) and update these arrays.
    'models' => [
        'openai/gpt-oss-20b:free',
        'nvidia/nemotron-3-ultra-550b-a55b:free',
        'nvidia/nemotron-3-super-120b-a12b:free',
        'google/gemma-4-31b-it:free',
        'openrouter/free',
    ],

    // Dedicated model lanes. Planner = best tool-calling/data-finding; answer =
    // concise final wording; repair = cheap language/format cleanup. Each lane
    // is short (2-3 models) on purpose — every extra entry is another wasted
    // round-trip once the ones ahead of it are dead — and each ends on
    // `openrouter/free`, an OpenRouter-managed auto-router over whatever free
    // tool-capable models are live right now, as a self-healing safety net.
    'planner_models' => [
        'openai/gpt-oss-20b:free',
        'nvidia/nemotron-3-super-120b-a12b:free',
        'openrouter/free',
    ],

    'answer_models' => [
        'openai/gpt-oss-20b:free',
        'google/gemma-4-31b-it:free',
        'openrouter/free',
    ],

    'repair_models' => [
        'nvidia/nemotron-3-nano-30b-a3b:free',
        'openai/gpt-oss-20b:free',
        'openrouter/free',
    ],

    'base_url'    => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    // Accuracy over latency: a listing answer ("les 12 traitements de P4") needs
    // room to name every row. Truncating mid-list is worse than waiting.
    'max_tokens'  => (int) env('OPENROUTER_MAX_TOKENS', 4000),

    // Accuracy first: deterministic decoding. Creativity is never wanted on
    // figures pulled from the farm database.
    'temperature' => (float) env('OPENROUTER_TEMPERATURE', 0.0),

    // Ask OpenRouter to route to the fastest provider serving the model,
    // instead of the default (cheapest). Big latency win on free models.
    // Override via OPENROUTER_PROVIDER_SORT=price to restore default routing.
    'provider_sort' => env('OPENROUTER_PROVIDER_SORT', 'throughput'),

    // Identity headers OpenRouter uses to classify traffic as legitimate.
    'referer' => env('OPENROUTER_REFERER', env('APP_URL', 'http://localhost')),
    'title'   => env('OPENROUTER_TITLE', 'Flehty Assistant'),

    // Timeouts (seconds). A struggling free-tier attempt used to be allowed to
    // hang indefinitely (0 = unlimited) before failing over to the next model —
    // that, multiplied by several recovery passes, is what drove "simple"
    // questions past 300s end-to-end. Bounding it here just makes a stuck
    // attempt fail over faster; it never cuts off a model that is actively
    // producing tokens (streams are governed by stream_idle_timeout instead).
    'connect_timeout'   => (int) env('OPENROUTER_CONNECT_TIMEOUT', 30),
    'request_timeout'   => (int) env('OPENROUTER_REQUEST_TIMEOUT', 30),
    'stream_idle_timeout' => (int) env('OPENROUTER_STREAM_IDLE_TIMEOUT', 300), // per-chunk idle only


    // Retry / backoff.
    'max_retries'       => (int) env('OPENROUTER_MAX_RETRIES', 2),
    'retry_base_ms'     => (int) env('OPENROUTER_RETRY_BASE_MS', 400),
    'retry_max_ms'      => (int) env('OPENROUTER_RETRY_MAX_MS', 4000),
    // Full passes over the whole model fallback chain before giving up.
    // Never surface "service indisponible" while an untried model remains —
    // but each extra pass multiplies worst-case latency, so keep it lean.
    'recovery_passes'   => (int) env('OPENROUTER_RECOVERY_PASSES', 1),

    // Shorter quarantine: a rate-limited key recovers in seconds, not minutes,
    // and keeping it benched shrinks the usable key pool during a burst.
    'quarantine_seconds' => (int) env('OPENROUTER_QUARANTINE_SECONDS', 60),




    // Deterministic playbook answers.
    // A question the planner resolves to ONE known data tool (water/ha,
    // unités d'azote, dernières irrigations, coût/ha, prix produit, surface…)
    // is answered straight from that tool's numbers, with no model round:
    // instant, and impossible to mis-word. Anything ambiguous, disputed or
    // multi-tool still goes through the full agent route.
    'fast_answers' => (bool) env('OPENROUTER_FAST_ANSWERS', true),

    // Tool-calling agent loop.
    // When enabled, the assistant plans + calls typed data tools (aggregations,
    // per-plot/per-campaign/YoY breakdowns, catalog lookups) instead of relying
    // on a pre-baked JSON context blob. Free models only.
    'agent' => [
        'enabled'         => (bool) env('OPENROUTER_AGENT_ENABLED', true),
        // Accuracy first: give the model enough rounds to cross-check a figure
        // with a second tool (e.g. confirm a zero-cost result against the
        // unfiltered treatment list) instead of answering from one lookup.
        // A real question rarely needs more than a handful of rounds — 24 only
        // ever gets exercised when a weak free model loops, which is exactly
        // what drives multi-minute replies. Capped lower; still generous.
        'max_iterations'  => (int) env('OPENROUTER_AGENT_MAX_ITERATIONS', 8),

        'max_tool_result' => (int) env('OPENROUTER_AGENT_MAX_TOOL_RESULT_BYTES', 20000),

        // Hard ceiling on the accumulated tool evidence kept in the transcript.
        // Beyond this the oldest tool payloads are compacted: 24 rounds of 20 kB
        // results otherwise overflow a free model's context window and the whole
        // request fails, which the user sees as "je n'ai pas pu répondre".
        'max_context_chars' => (int) env('OPENROUTER_AGENT_MAX_CONTEXT_CHARS', 90000),


        // NOTE: there is no fast path and no fast mode. The agent always plans
        // and verifies with the tools, even when the deterministic pre-fetch
        // already looks sufficient — a shortcut answer is the one that skips
        // the cross-check that catches a wrong filter.



        // Run a round's distinct tool calls concurrently. Off by default:
        // Laravel's concurrency drivers fork a process per task, which on a
        // small container can cost more than the queries themselves.
        // Deduplication of identical calls is always on, flag or not.
        'parallel_tools'  => (bool) env('AI_PARALLEL_TOOLS', false),
    ],


    // Prompt cache — hash(system+messages+model) → cached reply.
    // Short TTL on purpose: an operation entered from the mobile app must show
    // up in the next answer. A stale-but-fast reply is a wrong reply here.
    'cache' => [
        'enabled' => (bool) env('OPENROUTER_CACHE_ENABLED', true),
        'ttl'     => (int) env('OPENROUTER_CACHE_TTL', 60),
    ],


    // Short-TTL memo of the full context build (per-request assembly of ~15
    // stamp queries). Set to 0 to disable.
    'context_cache_ttl' => (int) env('OPENROUTER_CONTEXT_CACHE_TTL', 20),

    // Circuit breaker — trips when upstream error rate > threshold in `window` seconds.
    'breaker' => [
        'window'      => (int) env('OPENROUTER_BREAKER_WINDOW', 60),
        'min_samples' => (int) env('OPENROUTER_BREAKER_MIN_SAMPLES', 15),
        'threshold'   => (float) env('OPENROUTER_BREAKER_THRESHOLD', 0.85),
        'cool_down'   => (int) env('OPENROUTER_BREAKER_COOLDOWN', 15),
    ],

    // Per-user daily token budget (tenant-ready — swap user_id for tenant_id later).
    // Deterministic "Vérification / Verification" provenance block appended to
    // data answers. Off by default — the farm operator asked for it to go.
    'evidence_footer' => (bool) env('AI_EVIDENCE_FOOTER', false),

    // Maximum self-check repair rounds. Accuracy beats latency: each round
    // re-validates the rewrite and keeps the best candidate seen. Each round
    // is a full ~100s model round-trip on the free tier, so this is capped at
    // 2 — a violation that survives two rewrites essentially never clears on
    // a third with the same model.
    'repair_passes' => (int) env('AI_REPAIR_PASSES', 2),



    'budget' => [
        'enabled'      => (bool) env('OPENROUTER_BUDGET_ENABLED', true),
        'daily_tokens' => (int) env('OPENROUTER_DAILY_TOKENS', 200000),
    ],
];
