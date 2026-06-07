<?php

/**
 * Application service provider.
 *
 * Central place for app-wide bindings, macros and boot-time wiring.
 * Kept intentionally empty for now; future cross-cutting concerns
 * (rate-limiters, morph maps, observers) will land here.
 */

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
