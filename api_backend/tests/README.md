# Testing & Seeding

## Prerequisites

The report endpoints use Postgres-specific SQL (`EXTRACT(YEAR FROM …)`),
so tests must run against **Postgres**, not SQLite.

Set up a throwaway local Postgres database for testing:

```bash
createdb agrysync_test
```

Defaults in `phpunit.xml` point at:

```
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=agrysync_test
DB_USERNAME=postgres
DB_PASSWORD=postgres
```

Override any of them via shell env vars when running `vendor/bin/phpunit`
or inside your CI config.

## Running the suite

```bash
# Install dev dependencies (first time only)
composer install

# Run all tests — migrations are applied automatically via RefreshDatabase
vendor/bin/phpunit

# Just the irrigation report
vendor/bin/phpunit --filter IrrigationReportTest

# Just the cost math
vendor/bin/phpunit --filter ProductionCostReportTest
```

## Seeding a demo database

```bash
php artisan migrate:fresh --seed
```

Creates three demo users (password: `password`):

| Email                      | Role       |
|----------------------------|------------|
| `admin@agrysync.local`     | admin      |
| `manager@agrysync.local`   | manager    |
| `tech@agrysync.local`      | technician |

Plus two plots, a fertilizer, a pesticide, water/labor config, active
prices for every entity type, and one sample operation of each kind so
the dashboard has non-empty data on first load.
