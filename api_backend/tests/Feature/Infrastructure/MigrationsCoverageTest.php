<?php

/**
 * Verifies that every table the application's models rely on actually
 * exists after running the migration set. This is the regression net for
 * "Nothing to migrate" deploys where tables were silently missing.
 */

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array{0:string}> */
    public static function expectedTables(): array
    {
        return array_map(fn ($t) => [$t], [
            'users',
            'sessions',
            'cache',
            'jobs',
            'personal_access_tokens',
            'user_roles',
            'plots',
            'campaigns',
            'water_config',
            'labor_config',
            'fertilizers',
            'pesticides',
            'pests',
            'price_history',
            'postings',
            'irrigation_operations',
            'fertilization_operations',
            'phytosanitary_operations',
            'harvest_operations',
            'notifications',
            'system_logs',
        ]);
    }

    /** @dataProvider expectedTables */
    public function test_table_exists_after_migrations(string $table): void
    {
        $this->assertTrue(
            Schema::hasTable($table),
            "Table [$table] is missing — likely a migration was skipped or never created."
        );
    }

    public function test_harvest_operations_has_days_worked_column(): void
    {
        $this->assertTrue(Schema::hasColumn('harvest_operations', 'days_worked'));
    }

    public function test_postings_has_client_id_for_idempotency(): void
    {
        $this->assertTrue(Schema::hasColumn('postings', 'client_id'));
    }
}
