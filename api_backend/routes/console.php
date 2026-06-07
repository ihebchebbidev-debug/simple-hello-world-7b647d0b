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

Artisan::command('inspire', function () {
    /** @var Command $this */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
