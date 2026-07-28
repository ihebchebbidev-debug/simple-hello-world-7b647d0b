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

return [
    // Key pool — round-robin, with automatic quarantine on 401/402/429.
    // Populate OPENROUTER_API_KEY plus up to three fallbacks. Blanks are ignored.
    'api_keys' => array_values(array_filter([
        env('OPENROUTER_API_KEY'),
        env('OPENROUTER_API_KEY_2'),
        env('OPENROUTER_API_KEY_3'),
        env('OPENROUTER_API_KEY_4'),
    ], static fn ($k) => is_string($k) && trim($k) !== '')),

    // Model fallback chain — first entry is primary; used in order on upstream failure.
    'models' => array_values(array_filter([
        env('OPENROUTER_MODEL', 'meta-llama/llama-3.3-70b-instruct:free'),
        env('OPENROUTER_MODEL_FALLBACK', 'google/gemini-2.0-flash-exp:free'),
    ], static fn ($m) => is_string($m) && trim($m) !== '')),

    'base_url'    => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    'max_tokens'  => (int) env('OPENROUTER_MAX_TOKENS', 1600),
    'temperature' => (float) env('OPENROUTER_TEMPERATURE', 0.35),

    // Identity headers OpenRouter uses to classify traffic as legitimate.
    'referer' => env('OPENROUTER_REFERER', env('APP_URL', 'http://localhost')),
    'title'   => env('OPENROUTER_TITLE', 'Flehty Assistant'),

    // Timeouts (seconds).
    'connect_timeout'   => (int) env('OPENROUTER_CONNECT_TIMEOUT', 15),
    'request_timeout'   => (int) env('OPENROUTER_REQUEST_TIMEOUT', 60),  // non-stream hard cap
    'stream_idle_timeout' => (int) env('OPENROUTER_STREAM_IDLE_TIMEOUT', 90), // per-chunk idle

    // Retry / backoff.
    'max_retries'       => (int) env('OPENROUTER_MAX_RETRIES', 2),
    'retry_base_ms'     => (int) env('OPENROUTER_RETRY_BASE_MS', 400),
    'retry_max_ms'      => (int) env('OPENROUTER_RETRY_MAX_MS', 4000),
    'quarantine_seconds' => (int) env('OPENROUTER_QUARANTINE_SECONDS', 300),

    // Prompt cache — hash(system+messages+model) → cached reply.
    'cache' => [
        'enabled' => (bool) env('OPENROUTER_CACHE_ENABLED', true),
        'ttl'     => (int) env('OPENROUTER_CACHE_TTL', 600), // 10 minutes
    ],

    // Circuit breaker — trips when upstream error rate > threshold in `window` seconds.
    'breaker' => [
        'window'      => (int) env('OPENROUTER_BREAKER_WINDOW', 60),
        'min_samples' => (int) env('OPENROUTER_BREAKER_MIN_SAMPLES', 6),
        'threshold'   => (float) env('OPENROUTER_BREAKER_THRESHOLD', 0.5),
        'cool_down'   => (int) env('OPENROUTER_BREAKER_COOLDOWN', 30),
    ],

    // Per-user daily token budget (tenant-ready — swap user_id for tenant_id later).
    'budget' => [
        'enabled'      => (bool) env('OPENROUTER_BUDGET_ENABLED', true),
        'daily_tokens' => (int) env('OPENROUTER_DAILY_TOKENS', 200000),
    ],
];
