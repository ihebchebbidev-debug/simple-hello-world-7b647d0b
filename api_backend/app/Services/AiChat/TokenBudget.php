<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Facades\Cache;

/**
 * Per-user (tenant-ready) daily token budget.
 *
 * Tracks approximate token usage in a rolling daily bucket. When the
 * budget is exhausted the caller degrades to cached / canned answers
 * instead of hitting upstream.
 */
final class TokenBudget
{
    public function isExhausted(int|string|null $subjectId): bool
    {
        if (! (bool) config('openrouter.budget.enabled', true)) {
            return false;
        }
        $limit = (int) config('openrouter.budget.daily_tokens', 200000);
        if ($limit <= 0) {
            return false;
        }
        return $this->used($subjectId) >= $limit;
    }

    public function used(int|string|null $subjectId): int
    {
        return (int) Cache::get($this->key($subjectId), 0);
    }

    public function record(int|string|null $subjectId, int $tokens): void
    {
        if ($tokens <= 0 || ! (bool) config('openrouter.budget.enabled', true)) {
            return;
        }
        $key = $this->key($subjectId);
        // ~26h TTL so end-of-day usage doesn't disappear too early under file cache.
        Cache::put($key, $this->used($subjectId) + $tokens, 26 * 3600);
    }

    /** Rough approximation: 4 chars ≈ 1 token. Good enough for budget accounting. */
    public function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    private function key(int|string|null $subjectId): string
    {
        $subject = $subjectId !== null && $subjectId !== '' ? (string) $subjectId : 'anon';
        return 'openrouter.budget.'.date('Ymd').'.'.$subject;
    }
}
