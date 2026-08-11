<?php

declare(strict_types=1);

namespace App\Services\AiChat;

/**
 * Per-request wall-clock budget for the whole AI turn.
 *
 * Every layer of the assistant used to have its own independent retry budget
 * (model chain x attempts-per-model x recovery passes x agent iterations x
 * repair passes x a browser-side retry of the entire request). Multiplied out,
 * a single "simple" question could legitimately spend 400+ seconds before
 * surfacing a generic failure. Nothing was watching the total.
 *
 * This class is that watchdog: one deadline started at the top of the request,
 * consulted by the OpenRouter client (before each attempt), the agent loop
 * (before each planning round) and the self-check repair loop. When the budget
 * is gone we stop escalating and answer with what we already have instead of
 * starting yet another minute-long attempt whose result nobody will wait for.
 *
 * Static on purpose: PHP-FPM/Octane-per-request state, no container wiring
 * needed for a value every layer must see.
 */
final class AiDeadline
{
    private static ?float $deadline = null;

    /** Start (or restart) the budget for the current request. */
    public static function start(?float $seconds = null): void
    {
        $seconds = $seconds ?? (float) config('openrouter.wall_budget', 110);
        self::$deadline = $seconds > 0 ? microtime(true) + $seconds : null;
    }

    public static function clear(): void
    {
        self::$deadline = null;
    }

    public static function isActive(): bool
    {
        return self::$deadline !== null;
    }

    /** Seconds left, or null when no budget is active (never expires). */
    public static function remaining(): ?float
    {
        return self::$deadline === null ? null : max(0.0, self::$deadline - microtime(true));
    }

    public static function expired(): bool
    {
        $left = self::remaining();
        return $left !== null && $left <= 0.0;
    }

    /** True when at least $seconds of budget are still available (or no budget). */
    public static function hasAtLeast(float $seconds): bool
    {
        $left = self::remaining();
        return $left === null || $left >= $seconds;
    }

    /**
     * Clamp a configured per-attempt timeout to whatever budget is left, so a
     * 60s attempt is never started with 8s of budget remaining.
     */
    public static function clampTimeout(int $configured): int
    {
        $left = self::remaining();
        if ($left === null) {
            return $configured;
        }
        $capped = (int) floor($left);
        if ($configured <= 0) {
            return max(1, $capped);
        }
        return max(1, min($configured, $capped));
    }
}
