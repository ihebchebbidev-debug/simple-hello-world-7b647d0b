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


    // Model fallback chain — first entry is primary; used in order on upstream failure.
    // Defaults target OpenRouter's currently-available free tier; override per env if
    // a slug is retired (OpenRouter returns 404 for removed models — surface as
    // `model_not_found` in the client).
    'models' => array_values(array_filter([
        // Free tool-capable models. Ordered by tool-calling reliability.
        env('OPENROUTER_MODEL',            'deepseek/deepseek-chat-v3.1:free'),
        env('OPENROUTER_MODEL_FALLBACK',   'google/gemini-2.0-flash-exp:free'),
        env('OPENROUTER_MODEL_FALLBACK_2', 'meta-llama/llama-3.3-70b-instruct:free'),
        env('OPENROUTER_MODEL_FALLBACK_3', 'qwen/qwen-2.5-72b-instruct:free'),
    ], static fn ($m) => is_string($m) && trim($m) !== '')),

    // Optional dedicated model lanes. Planner = best tool-calling/data-finding;
    // answer = concise final wording; repair = cheap language/format cleanup.
    // Empty lanes fall back to `models` above.
    'planner_models' => array_values(array_filter([
        env('OPENROUTER_PLANNER_MODEL',            env('OPENROUTER_MODEL', 'deepseek/deepseek-chat-v3.1:free')),
        env('OPENROUTER_PLANNER_MODEL_FALLBACK',   env('OPENROUTER_MODEL_FALLBACK_2', 'qwen/qwen-2.5-72b-instruct:free')),
    ], static fn ($m) => is_string($m) && trim($m) !== '')),

    'answer_models' => array_values(array_filter([
        env('OPENROUTER_ANSWER_MODEL',            env('OPENROUTER_MODEL_FALLBACK', 'google/gemini-2.0-flash-exp:free')),
        env('OPENROUTER_ANSWER_MODEL_FALLBACK',   env('OPENROUTER_MODEL_FALLBACK_3', 'meta-llama/llama-3.3-70b-instruct:free')),
    ], static fn ($m) => is_string($m) && trim($m) !== '')),

    'repair_models' => array_values(array_filter([
        env('OPENROUTER_REPAIR_MODEL',            env('OPENROUTER_ANSWER_MODEL', 'qwen/qwen-2.5-72b-instruct:free')),
        env('OPENROUTER_REPAIR_MODEL_FALLBACK',   env('OPENROUTER_MODEL_FALLBACK', 'google/gemini-2.0-flash-exp:free')),
    ], static fn ($m) => is_string($m) && trim($m) !== '')),

    'base_url'    => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    // Lower cap = faster completions on free-tier models (~30-50 tok/s).
    // Most answers are short lookups; long analyses still fit in 700 tokens.
    'max_tokens'  => (int) env('OPENROUTER_MAX_TOKENS', 1600),
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

    // Timeouts (seconds). 0 = no cap (let the model think as long as it needs).
    // Only the connect phase and the per-chunk idle guard stay bounded, so a
    // dead socket still fails fast while a slow-but-alive answer never gets cut.
    'connect_timeout'   => (int) env('OPENROUTER_CONNECT_TIMEOUT', 30),
    'request_timeout'   => (int) env('OPENROUTER_REQUEST_TIMEOUT', 0),  // 0 = unlimited wall clock
    'stream_idle_timeout' => (int) env('OPENROUTER_STREAM_IDLE_TIMEOUT', 300), // per-chunk idle only


    // Retry / backoff.
    'max_retries'       => (int) env('OPENROUTER_MAX_RETRIES', 2),
    'retry_base_ms'     => (int) env('OPENROUTER_RETRY_BASE_MS', 400),
    'retry_max_ms'      => (int) env('OPENROUTER_RETRY_MAX_MS', 4000),
    // Full passes over the whole model fallback chain before giving up.
    'recovery_passes'   => (int) env('OPENROUTER_RECOVERY_PASSES', 2),
    // Shorter quarantine: a rate-limited key recovers in seconds, not minutes,
    // and keeping it benched shrinks the usable key pool during a burst.
    'quarantine_seconds' => (int) env('OPENROUTER_QUARANTINE_SECONDS', 60),


    // Fast mode: disable the agent tool loop to reduce round-trips.
    'fast_mode' => (bool) env('OPENROUTER_FAST_MODE', false),

    // Tool-calling agent loop.
    // When enabled, the assistant plans + calls typed data tools (aggregations,
    // per-plot/per-campaign/YoY breakdowns, catalog lookups) instead of relying
    // on a pre-baked JSON context blob. Free models only.
    'agent' => [
        'enabled'         => (bool) env('OPENROUTER_AGENT_ENABLED', true),
        'max_iterations'  => (int) env('OPENROUTER_AGENT_MAX_ITERATIONS', 8),
        'max_tool_result' => (int) env('OPENROUTER_AGENT_MAX_TOOL_RESULT_BYTES', 12000),

        // Skip the (non-streamed) planning round when the deterministic
        // pre-fetch already holds the answer — saves one full LLM round-trip
        // on simple single-metric questions. Set false to always plan.
        // Accuracy over speed: always let the model plan and verify with the
        // tools, even when the pre-fetch looks sufficient.
        'fast_path'       => (bool) env('AI_FAST_PATH', false),

        // Run a round's distinct tool calls concurrently. Off by default:
        // Laravel's concurrency drivers fork a process per task, which on a
        // small container can cost more than the queries themselves.
        // Deduplication of identical calls is always on, flag or not.
        'parallel_tools'  => (bool) env('AI_PARALLEL_TOOLS', false),
    ],


    // Prompt cache — hash(system+messages+model) → cached reply.
    'cache' => [
        'enabled' => (bool) env('OPENROUTER_CACHE_ENABLED', true),
        'ttl'     => (int) env('OPENROUTER_CACHE_TTL', 600), // 10 minutes
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
    // re-validates the rewrite and keeps the best candidate seen.
    'repair_passes' => (int) env('AI_REPAIR_PASSES', 2),



    'budget' => [
        'enabled'      => (bool) env('OPENROUTER_BUDGET_ENABLED', true),
        'daily_tokens' => (int) env('OPENROUTER_DAILY_TOKENS', 200000),
    ],
];
