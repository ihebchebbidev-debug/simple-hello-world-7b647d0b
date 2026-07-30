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
