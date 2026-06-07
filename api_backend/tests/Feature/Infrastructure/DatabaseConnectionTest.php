<?php

/**
 * Sanity-check the runtime DB config so deploys never silently fall back to
 * SQLite. In production we always expect a non-sqlite driver to be selected
 * once DATABASE_URL or DB_HOST is provided.
 */

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    public function test_default_connection_is_reachable(): void
    {
        DB::connection()->getPdo();
        $this->assertNotNull(DB::connection()->getDatabaseName());
    }

    public function test_production_never_defaults_to_sqlite_when_db_url_set(): void
    {
        if (! env('DATABASE_URL') && ! env('DB_HOST')) {
            $this->markTestSkipped('No DATABASE_URL/DB_HOST provided in this environment.');
        }

        $this->assertNotSame(
            'sqlite',
            config('database.default'),
            'Default connection unexpectedly resolved to sqlite — check config/database.php fallback.'
        );
    }
}
