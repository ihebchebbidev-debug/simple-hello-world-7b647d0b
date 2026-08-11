<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Per-turn tracing for the AI assistant.
 *
 * Every AI log line goes through here so that `tail -f storage/logs/laravel.log`
 * tells the whole story of one question without cross-referencing timestamps:
 *
 *   [..] production.INFO: ai.turn.start {"trace":"a1b2c3d4","t_ms":0,"left_s":110,"q":"Combien de …","locale":"fr","stream":true}
 *   [..] production.INFO: ai.plan.window {"trace":"a1b2c3d4","t_ms":41,"left_s":109.9,"from":"2026-01-01","to":"2026-12-31"}
 *   [..] production.INFO: ai.llm.attempt {"trace":"a1b2c3d4","t_ms":52,…,"purpose":"planner","model":"nvidia/…","attempt":0,"timeout_s":60}
 *   [..] production.INFO: ai.llm.ok {"trace":"a1b2c3d4",…,"ms":2310,"tool_calls":2,"prompt_tokens":…}
 *   [..] production.INFO: ai.tool.ok {"trace":"a1b2c3d4",…,"tool":"harvest_history","ms":38,"empty":false}
 *   [..] production.INFO: ai.turn.end {"trace":"a1b2c3d4","t_ms":6120,"path":"agent","reply_chars":412}
 *
 * Fields present on EVERY line:
 *   trace  - short id shared by all lines of one HTTP request (grep-able)
 *   t_ms   - ms since the turn started
 *   left_s - wall-clock budget left (see {@see AiDeadline}); null = unlimited
 *
 * Static on purpose: same rationale as AiDeadline — per-request state that
 * every layer must see without container wiring.
 */
final class AiTrace
{
    private static ?string $id = null;

    private static float $t0 = 0.0;

    /** Rolling per-turn counters, dumped on {@see finish}. */
    private static array $counters = [];

    /** Start a new trace for the current request. Returns the trace id. */
    public static function start(array $context = []): string
    {
        self::$id = substr((string) Str::uuid(), 0, 8);
        self::$t0 = microtime(true);
        self::$counters = ['llm_calls' => 0, 'llm_ms' => 0, 'tool_calls' => 0, 'tool_ms' => 0, 'retries' => 0];
        self::info('ai.turn.start', $context);

        return self::$id;
    }

    public static function id(): ?string
    {
        return self::$id;
    }

    /** Milliseconds elapsed since the trace started (0 when untraced). */
    public static function elapsedMs(): int
    {
        return self::$id === null ? 0 : (int) round((microtime(true) - self::$t0) * 1000);
    }

    public static function count(string $key, int|float $by = 1): void
    {
        if (self::$id === null) {
            return;
        }
        self::$counters[$key] = (self::$counters[$key] ?? 0) + $by;
    }

    /** Final line of the turn: path taken plus the per-turn totals. */
    public static function finish(array $context = []): void
    {
        $payload = array_merge($context, array_map(
            static fn ($v) => is_float($v) ? round($v, 1) : $v,
            self::$counters,
        ));
        // A slow turn is the thing to spot while tailing: log it louder.
        if (self::elapsedMs() >= 15000) {
            self::warning('ai.turn.end', $payload);
        } else {
            self::info('ai.turn.end', $payload);
        }
        self::$id = null;
    }

    /** @param array<string, mixed> $context */
    public static function info(string $event, array $context = []): void
    {
        Log::info($event, self::decorate($context));
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $event, array $context = []): void
    {
        Log::warning($event, self::decorate($context));
    }

    /** @param array<string, mixed> $context */
    public static function error(string $event, array $context = []): void
    {
        Log::error($event, self::decorate($context));
    }

    /**
     * Log at info when fast, warning past a threshold — so `grep WARNING` on
     * the log file lists exactly the slow steps of the turn.
     *
     * @param array<string, mixed> $context
     */
    public static function timed(string $event, int $ms, int $warnAboveMs, array $context = []): void
    {
        $context = array_merge(['ms' => $ms], $context);
        $ms >= $warnAboveMs ? self::warning($event, $context) : self::info($event, $context);
    }

    /** Short, log-safe preview of any value (questions, tool payloads, errors). */
    public static function preview(mixed $value, int $limit = 160): string
    {
        $text = is_string($value) ? $value : (json_encode($value, JSON_UNESCAPED_UNICODE) ?: '');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }

    /** @param array<string, mixed> $context */
    private static function decorate(array $context): array
    {
        $left = AiDeadline::remaining();

        return array_merge([
            'trace'  => self::$id ?? '-',
            't_ms'   => self::elapsedMs(),
            'left_s' => $left === null ? null : round($left, 1),
        ], $context);
    }
}
