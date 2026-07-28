<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Round-robin OpenRouter API key selector with per-key quarantine.
 *
 * On 401/402/429 the caller reports the failure via markFailed() and the
 * key is skipped for `openrouter.quarantine_seconds`. When all keys are
 * quarantined the pool degrades to the least-recently-quarantined key
 * so requests still have a chance to succeed.
 */
final class OpenRouterKeyPool
{
    private const CACHE_PREFIX = 'openrouter.key.quarantine.';
    private const CURSOR_KEY   = 'openrouter.key.cursor';

    /** @var array<int, string> */
    private array $keys;

    public function __construct()
    {
        /** @var array<int, string> $keys */
        $keys = (array) config('openrouter.api_keys', []);
        $this->keys = array_values(array_filter($keys, static fn ($k) => is_string($k) && trim($k) !== ''));

        if ($this->keys === []) {
            throw new RuntimeException('No OpenRouter API keys configured. Set OPENROUTER_API_KEY (and optional _2/_3/_4).');
        }
    }

    /** Total configured keys. */
    public function size(): int
    {
        return count($this->keys);
    }

    /**
     * Return the next non-quarantined key. Falls back to any key when all
     * are quarantined (so a transient global outage doesn't lock us out).
     */
    public function next(): string
    {
        $count  = count($this->keys);
        $cursor = (int) Cache::get(self::CURSOR_KEY, 0);

        for ($i = 0; $i < $count; $i++) {
            $idx = ($cursor + $i) % $count;
            $key = $this->keys[$idx];
            if (! $this->isQuarantined($key)) {
                Cache::put(self::CURSOR_KEY, ($idx + 1) % $count, 3600);
                return $key;
            }
        }

        // All quarantined — advance the cursor and return the picked key so
        // successive requests spread load instead of hammering one degraded key.
        $idx = $cursor % $count;
        Cache::put(self::CURSOR_KEY, ($idx + 1) % $count, 3600);
        return $this->keys[$idx];
    }

    public function markFailed(string $key, int $status): void
    {
        if (! in_array($status, [401, 402, 429], true)) {
            return;
        }
        $ttl = max(30, (int) config('openrouter.quarantine_seconds', 300));
        Cache::put(self::CACHE_PREFIX.$this->hash($key), 1, $ttl);
    }

    public function isQuarantined(string $key): bool
    {
        return (bool) Cache::get(self::CACHE_PREFIX.$this->hash($key), false);
    }

    private function hash(string $key): string
    {
        return substr(hash('sha256', $key), 0, 16);
    }
}
