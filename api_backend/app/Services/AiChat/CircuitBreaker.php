<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Facades\Cache;

/**
 * Rolling-window circuit breaker for OpenRouter upstream.
 *
 * Records success/failure samples in a per-window bucket. If the failure
 * ratio exceeds the configured threshold AND the sample count meets the
 * minimum, the breaker trips for `cool_down` seconds — during which
 * shouldTrip() short-circuits without hitting upstream.
 */
final class CircuitBreaker
{
    private const OPEN_KEY = 'openrouter.breaker.open_until';
    private const SUCC_KEY = 'openrouter.breaker.succ';
    private const FAIL_KEY = 'openrouter.breaker.fail';

    private const PROBE_KEY = 'openrouter.breaker.probe';

    public function shouldTrip(): bool
    {
        $until = (int) Cache::get(self::OPEN_KEY, 0);
        if ($until <= time()) {
            return false;
        }

        // Half-open: let ONE request through per open window so a user who
        // retries during the cool-down gets a real answer instead of three
        // consecutive "assistant paused" replies.
        $probeKey = self::PROBE_KEY.':'.$until;
        if (! Cache::get($probeKey, false)) {
            Cache::put($probeKey, true, ($until - time()) + 5);
            return false;
        }

        return true;
    }

    public function recordSuccess(): void
    {
        $this->increment(self::SUCC_KEY);
    }

    /**
     * Only *hard* upstream failures (connection refused, 5xx, auth/quota) count
     * towards tripping. Soft hiccups — an empty completion, a truncated stream,
     * a single 429 that the retry loop absorbs — must never open the breaker:
     * they are already handled by retries + model fallback, and counting them
     * made a normal agent run (6+ upstream calls) trip the breaker on its own,
     * which surfaced to users as "the assistant is taking a short pause".
     */
    public function recordFailure(bool $hard = false): void
    {
        if (! $hard) {
            return;
        }
        $this->increment(self::FAIL_KEY);
        $this->evaluate();
    }

    private function evaluate(): void
    {
        $window     = max(10, (int) config('openrouter.breaker.window', 60));
        $minSamples = max(1, (int) config('openrouter.breaker.min_samples', 6));
        $threshold  = (float) config('openrouter.breaker.threshold', 0.5);
        $cool       = max(5, (int) config('openrouter.breaker.cool_down', 30));

        $succ = (int) Cache::get($this->bucketKey(self::SUCC_KEY, $window), 0);
        $fail = (int) Cache::get($this->bucketKey(self::FAIL_KEY, $window), 0);
        $total = $succ + $fail;

        if ($total < $minSamples) {
            return;
        }
        if (($fail / $total) >= $threshold) {
            Cache::put(self::OPEN_KEY, time() + $cool, $cool + 5);
        }
    }

    private function increment(string $base): void
    {
        $window = max(10, (int) config('openrouter.breaker.window', 60));
        $key    = $this->bucketKey($base, $window);
        // File cache lacks atomic increment; get+put is fine for approximate rate.
        Cache::put($key, ((int) Cache::get($key, 0)) + 1, $window + 5);
    }

    private function bucketKey(string $base, int $window): string
    {
        return $base.':'.intdiv(time(), $window);
    }
}
