<?php

/**
 * Console (Artisan) routes.
 *
 * Place ad-hoc CLI commands here. Long-lived commands should be promoted
 * to dedicated `App\Console\Commands` classes once they earn it.
 */

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use App\Services\AiChat\AiDailyRollup;

Artisan::command('inspire', function () {
    /** @var Command $this */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Rebuild the assistant's pre-aggregated daily rollup.
 *
 * Reads self-heal (a stale rollup is rebuilt on demand), so this is only a
 * safety net that keeps the first question of the day instant. `--force`
 * rebuilds every cell from scratch.
 */
Artisan::command('ai:rollup {--force}', function (AiDailyRollup $rollup) {
    /** @var Command $this */
    $rollup->refreshAll((bool) $this->option('force'));
    $this->info('AI daily rollup refreshed.');
})->purpose('Rebuild the AI assistant daily operation rollup');

Schedule::command('ai:rollup')->hourly()->withoutOverlapping();

/**
 * Bust the assistant's caches without a full redeploy.
 *
 * Normally never needed — the docker entrypoint already runs `cache:clear`
 * on every boot, and cached chat replies expire on their own within
 * `openrouter.cache.ttl` seconds (60s by default). This exists for the case
 * where someone needs a stale answer or a stale live-data snapshot gone
 * RIGHT NOW, between deploys.
 *
 * - `ai.chat.context.v1`     — the ~15-query live-data snapshot (context_cache_ttl)
 * - `ai.chat.data_stamp.v1`  — the row-count/updated_at fingerprint used to key it
 * - `--all`                  — also flush the entire cache store (drops cached
 *                              chat replies too; safe on the `file` cache driver
 *                              used here, does not touch sessions, which use a
 *                              separate store).
 */
Artisan::command('ai:cache-clear {--all : Also flush the whole cache store, including cached chat replies}', function () {
    /** @var Command $this */
    Cache::forget('ai.chat.context.v1');
    Cache::forget('ai.chat.data_stamp.v1');
    $this->info('Cleared the AI live-data context cache and data-stamp fingerprint.');

    if ($this->option('all')) {
        Cache::flush();
        $this->info('Flushed the entire cache store (cached chat replies included).');
    }
})->purpose('Clear the AI assistant\'s live-data and reply caches on demand');
