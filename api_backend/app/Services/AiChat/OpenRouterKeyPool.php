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
    private const CURSOR_KEY   = 'openrouter.key.cursor.';

    /** @var array<int, string> */
    private array $keys;

    /** @var array<string, array<int, string>> */
    private array $lanes;

    public function __construct()
    {
        /** @var array<int, string> $keys */
        $keys = (array) config('openrouter.api_keys', []);
        $this->keys = array_values(array_filter($keys, static fn ($k) => is_string($k) && trim($k) !== ''));

        $this->lanes = [
            'default' => $this->keys,
            'planner' => $this->configuredLane('planner_api_keys'),
            'answer'  => $this->configuredLane('answer_api_keys'),
            'repair'  => $this->configuredLane('repair_api_keys'),
        ];

        if ($this->keys === []) {
            foreach (['planner', 'answer', 'repair'] as $lane) {
                if (($this->lanes[$lane] ?? []) !== []) {
                    $this->keys = $this->lanes[$lane];
                    $this->lanes['default'] = $this->keys;
                    break;
                }
            }
        }

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
     * Return the next non-quarantined key for the lane.
     *
     * Order of preference:
     *   1. a healthy key from the requested lane (round-robin),
     *   2. a healthy key from the shared pool (lane fallback),
     *   3. the next lane key even if quarantined (never hard-fail).
     */
    public function next(string $purpose = 'default'): string
    {
        $keys = $this->keysFor($purpose);
        $count  = count($keys);
        $cursorKey = self::CURSOR_KEY.$this->normalisePurpose($purpose);
        $cursor = (int) Cache::get($cursorKey, 0);

        for ($i = 0; $i < $count; $i++) {
            $idx = ($cursor + $i) % $count;
            $key = $keys[$idx];
            if (! $this->isQuarantined($key)) {
                Cache::put($cursorKey, ($idx + 1) % $count, 3600);
                return $key;
            }
        }

        // Lane exhausted — borrow a healthy key from the shared pool.
        foreach ($this->keys as $fallback) {
            if (! in_array($fallback, $keys, true) && ! $this->isQuarantined($fallback)) {
                return $fallback;
            }
        }

        // All quarantined — advance the cursor and return the picked key so
        // successive requests spread load instead of hammering one degraded key.
        $idx = $cursor % $count;
        Cache::put($cursorKey, ($idx + 1) % $count, 3600);
        return $keys[$idx];
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

    /** @return array<int, string> */
    private function configuredLane(string $configKey): array
    {
        /** @var array<int, string> $keys */
        $keys = (array) config('openrouter.'.$configKey, []);
        return array_values(array_filter($keys, static fn ($k) => is_string($k) && trim($k) !== ''));
    }

    /** @return array<int, string> */
    private function keysFor(string $purpose): array
    {
        $purpose = $this->normalisePurpose($purpose);
        $lane = $this->lanes[$purpose] ?? [];
        return $lane !== [] ? $lane : $this->keys;
    }

    private function normalisePurpose(string $purpose): string
    {
        return in_array($purpose, ['planner', 'answer', 'repair'], true) ? $purpose : 'default';
    }
}
