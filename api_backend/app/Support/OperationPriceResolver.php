<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resolves the effective price for an entity on a given date by querying
 * price_history. Returns the most recent row whose [effective_from, effective_to]
 * window contains the requested date.
 */
final class OperationPriceResolver
{
    /**
     * Look up the price for an entity on $date.
     *
     * @param  string       $entityType  water|fertilizer|pesticide|labor
     * @param  string|null  $entityId    UUID of the config row (null for water/labor which are global)
     * @param  string|null  $date        Y-m-d; defaults to today
     */
    public function priceFor(string $entityType, ?string $entityId = null, ?string $date = null): string
    {
        $date ??= now()->toDateString();

        // Return the price that was active on $date (effective_from <= $date),
        // so that price_at_entry snapshots the historically correct value.
        // Changing a price today must NOT alter the snapshot stored on past ops.
        $row = DB::table('price_history')
            ->where('entity_type', $entityType)
            ->when($entityId !== null, fn ($q) => $q->where('entity_id', $entityId))
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first(['price_per_unit']);

        // No price was effective yet on $date — fall back to the EARLIEST known
        // price for this entity rather than snapshotting 0. Otherwise an
        // operation back-dated before the first price record is stored with a
        // zero cost and drops out of the production-cost report entirely.
        if (! $row) {
            $row = DB::table('price_history')
                ->where('entity_type', $entityType)
                ->when($entityId !== null, fn ($q) => $q->where('entity_id', $entityId))
                ->orderBy('effective_from')
                ->orderBy('id')
                ->first(['price_per_unit']);
        }

        return $row ? (string) $row->price_per_unit : '0.0000';
    }

    /**
     * Returns the unit of the currently active water config row.
     */
    public function activeWaterUnit(): string
    {
        return DB::table('water_config')
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->value('unit') ?? 'm3';
    }
}
