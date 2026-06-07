<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Support\Facades\Schema;

trait HasSchemaAwareColumns
{
    /**
     * Filter an associative array down to keys that exist as columns on the
     * given table. Lets controllers stay forward/backward compatible with
     * schema variations (e.g. `password` vs `password_hash`).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function onlyExistingColumns(string $table, array $values): array
    {
        return array_filter(
            $values,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }
}
