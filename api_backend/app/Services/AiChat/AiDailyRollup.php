<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Pre-aggregated daily rollup of farm operations, used by the assistant's
 * period/statistics tools.
 *
 * Why this exists
 * ---------------
 * "Quantité d'eau de la parcelle B12 du 15 au 30 juin" is the highest-volume
 * question the assistant answers. Computing it from raw rows on every turn was
 * both slow and *unstable*: unit normalisation, NULL handling and campaign
 * scoping were re-derived each time, so the same question could come back with
 * a different total. This class computes each (type, plot, campaign, day) cell
 * exactly once and every answer then reads the same numbers.
 *
 * Freshness
 * ---------
 * The rollup is not a cache with a TTL — a stale figure is worse than a slow
 * one. Before each read we compare a cheap signature of the source table
 * (row count + latest write) against the signature stored at build time:
 *  - identical           → serve the rollup;
 *  - only newer writes   → rebuild just the days those writes touch;
 *  - row count shrank    → rows were deleted, rebuild everything.
 */
final class AiDailyRollup
{
    /** Source table per operation type. */
    private const TABLE = [
        'irrigation'    => 'irrigation_operations',
        'fertilization' => 'fertilization_operations',
        'phytosanitary' => 'phytosanitary_operations',
        'harvest'       => 'harvest_operations',
    ];

    /** Quantity column per operation type. */
    private const QTY_COL = [
        'irrigation'    => 'water_quantity',
        'fertilization' => 'quantity_applied',
        'phytosanitary' => 'quantity_applied',
        'harvest'       => 'quantity_harvested',
    ];

    /** Unit price column per operation type (NULL when the type has none). */
    private const PRICE_COL = [
        'irrigation'    => 'price_at_entry',
        'fertilization' => 'price_at_entry',
        'phytosanitary' => 'price_at_entry',
        'harvest'       => 'daily_rate_at_entry',
    ];

    /**
     * Litre-denominated labels written into `unit_at_entry`. The farm's water
     * unit is a setting that can change mid-season and is snapshotted per row,
     * so a raw SUM() would add litres to cubic metres. Normalised once here.
     */
    private const LITRE_UNITS = ['l', 'lt', 'ltr', 'liter', 'liters', 'litre', 'litres'];

    /** Rebuilds triggered by a read are capped so a chat turn never stalls. */
    private const MAX_INLINE_REBUILD_DAYS = 400;

    /** @var array<string,bool> per-request guard: refresh each type at most once. */
    private array $refreshed = [];

    public function available(): bool
    {
        return Schema::hasTable('ai_daily_rollups') && Schema::hasTable('ai_rollup_state');
    }

    /**
     * Aggregate one operation type over a plot set and an inclusive date window.
     *
     * @param  list<string>  $plotIds
     * @return array<string,mixed>|null  null when the rollup cannot serve the
     *                                   read; the caller then queries raw rows.
     */
    public function aggregate(string $opType, array $plotIds, ?string $from, ?string $to, ?string $campaignId = null): ?array
    {
        if ($plotIds === [] || ! isset(self::TABLE[$opType]) || ! $this->available()) {
            return null;
        }

        try {
            if (! $this->refresh($opType)) {
                return null;
            }

            $q = DB::table('ai_daily_rollups')
                ->selectRaw('plot_id,
                    COALESCE(SUM(ops),0)           AS ops,
                    COALESCE(SUM(qty),0)           AS qty,
                    COALESCE(SUM(cost),0)          AS cost,
                    COALESCE(SUM(missing_qty),0)   AS missing_qty,
                    COALESCE(SUM(missing_price),0) AS missing_price,
                    COALESCE(MAX(unit_variants),1) AS unit_variants,
                    MIN(day) AS first_date,
                    MAX(day) AS last_date')
                ->where('op_type', $opType)
                ->whereIn('plot_id', $plotIds)
                ->groupBy('plot_id');

            if ($from !== null) $q->where('day', '>=', $from);
            if ($to !== null)   $q->where('day', '<=', $to);
            if ($campaignId !== null && $campaignId !== '') $q->where('campaign_key', $campaignId);

            $rows = [];
            foreach ($q->get() as $r) {
                $rows[(string) $r->plot_id] = [
                    'ops'           => (int) $r->ops,
                    'qty'           => (float) $r->qty,
                    'cost'          => (float) $r->cost,
                    'missing_qty'   => (int) $r->missing_qty,
                    'missing_price' => (int) $r->missing_price,
                    'unit_variants' => (int) $r->unit_variants,
                    'first_date'    => $r->first_date ? Carbon::parse((string) $r->first_date)->toDateString() : null,
                    'last_date'     => $r->last_date ? Carbon::parse((string) $r->last_date)->toDateString() : null,
                ];
            }

            return $rows;
        } catch (Throwable $e) {
            Log::warning('ai.rollup.aggregate_failed', ['op_type' => $opType, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Per-day cells for one plot set and window — the fast path behind
     * "le détail" / per-date breakdown questions.
     *
     * @param  list<string>  $plotIds
     * @return list<array<string,mixed>>|null
     */
    public function daily(string $opType, array $plotIds, ?string $from, ?string $to, int $limit = 120): ?array
    {
        if ($plotIds === [] || ! isset(self::TABLE[$opType]) || ! $this->available()) {
            return null;
        }

        try {
            if (! $this->refresh($opType)) {
                return null;
            }

            $q = DB::table('ai_daily_rollups')
                ->selectRaw('day, plot_id, SUM(ops) AS ops, SUM(qty) AS qty, SUM(cost) AS cost')
                ->where('op_type', $opType)
                ->whereIn('plot_id', $plotIds)
                ->groupBy('day', 'plot_id')
                ->orderBy('day')
                ->limit($limit + 1);

            if ($from !== null) $q->where('day', '>=', $from);
            if ($to !== null)   $q->where('day', '<=', $to);

            $out = [];
            foreach ($q->get() as $r) {
                $out[] = [
                    'date'    => Carbon::parse((string) $r->day)->toDateString(),
                    'plot_id' => (string) $r->plot_id,
                    'ops'     => (int) $r->ops,
                    'qty'     => round((float) $r->qty, 2),
                    'cost'    => round((float) $r->cost, 2),
                ];
            }

            return $out;
        } catch (Throwable $e) {
            Log::warning('ai.rollup.daily_failed', ['op_type' => $opType, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Bring one operation type up to date. Returns false when the rollup is not
     * trustworthy for this read (too much to rebuild inline, or no source table)
     * so the caller falls back to querying raw rows rather than serving a
     * stale number.
     */
    public function refresh(string $opType, bool $force = false): bool
    {
        $table = self::TABLE[$opType] ?? null;
        if ($table === null || ! Schema::hasTable($table) || ! $this->available()) {
            return false;
        }
        if (! $force && ($this->refreshed[$opType] ?? false)) {
            return true;
        }

        $sig   = $this->sourceSignature($table);
        $state = DB::table('ai_rollup_state')->where('op_type', $opType)->first();

        $fullRebuild = $force
            || $state === null
            || $state->built_at === null
            // Fewer rows than last time means deletions, which an incremental
            // pass cannot see (the deleted row is gone, its day still holds a
            // stale total). Only a full rebuild is correct here.
            || (int) $state->source_rows > $sig['rows'];

        if (! $fullRebuild && (int) $state->source_rows === $sig['rows']
            && (string) ($state->source_max_updated_at ?? '') === (string) ($sig['max_updated_at'] ?? '')) {
            $this->refreshed[$opType] = true;

            return true; // already current
        }

        try {
            if ($fullRebuild) {
                $this->rebuildDays($opType, $table, null);
            } else {
                $days = $this->daysTouchedSince($table, (string) $state->built_at);
                if (count($days) > self::MAX_INLINE_REBUILD_DAYS) {
                    $this->rebuildDays($opType, $table, null);
                } elseif ($days !== []) {
                    $this->rebuildDays($opType, $table, $days);
                }
            }

            DB::table('ai_rollup_state')->updateOrInsert(
                ['op_type' => $opType],
                [
                    'source_rows'           => $sig['rows'],
                    'source_max_updated_at' => $sig['max_updated_at'],
                    'built_at'              => now(),
                    'updated_at'            => now(),
                    'created_at'            => $state->created_at ?? now(),
                ],
            );

            $this->refreshed[$opType] = true;

            return true;
        } catch (Throwable $e) {
            Log::warning('ai.rollup.refresh_failed', ['op_type' => $opType, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Rebuild every operation type. Used by the scheduled/CLI refresh. */
    public function refreshAll(bool $force = false): void
    {
        foreach (array_keys(self::TABLE) as $type) {
            $this->refresh($type, $force);
        }
    }

    /** @return array{rows:int, max_updated_at:?string} */
    private function sourceSignature(string $table): array
    {
        $hasUpdatedAt = Schema::hasColumn($table, 'updated_at');
        $row = DB::table($table)
            ->selectRaw('COUNT(*) AS c'.($hasUpdatedAt ? ', MAX(updated_at) AS m' : ', NULL AS m'))
            ->first();

        return [
            'rows'           => (int) ($row->c ?? 0),
            'max_updated_at' => isset($row->m) && $row->m !== null
                ? Carbon::parse((string) $row->m)->toDateTimeString()
                : null,
        ];
    }

    /** @return list<string> operation_date values written/edited since $since. */
    private function daysTouchedSince(string $table, string $since): array
    {
        if (! Schema::hasColumn($table, 'updated_at')) {
            return [];
        }

        return DB::table($table)
            ->where('updated_at', '>=', $since)
            ->distinct()
            ->orderBy('operation_date')
            ->limit(self::MAX_INLINE_REBUILD_DAYS + 1)
            ->pluck('operation_date')
            ->map(static fn ($d) => Carbon::parse((string) $d)->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Recompute the rollup cells for the given days (all days when null).
     *
     * @param  list<string>|null  $days
     */
    private function rebuildDays(string $opType, string $table, ?array $days): void
    {
        $qty   = self::QTY_COL[$opType];
        $price = self::PRICE_COL[$opType];
        $qtyExpr = $opType === 'irrigation' && Schema::hasColumn($table, 'unit_at_entry')
            ? $this->m3Expr($qty)
            : "($qty)";
        $hasUnit  = $opType === 'irrigation' && Schema::hasColumn($table, 'unit_at_entry');
        $hasPrice = Schema::hasColumn($table, $price);

        $costExpr = $hasPrice ? "COALESCE(SUM(($qtyExpr) * $price), 0)" : '0';
        $missingPrice = $hasPrice ? "COUNT(*) FILTER (WHERE $price IS NULL)" : '0';
        $unitVariants = $hasUnit
            ? "COUNT(DISTINCT LOWER(TRIM(COALESCE(unit_at_entry, 'm3'))))"
            : '1';

        $select = DB::table($table)
            ->selectRaw("plot_id,
                COALESCE(campaign_id::text, '') AS campaign_key,
                operation_date AS day,
                COUNT(*) AS ops,
                COALESCE(SUM($qtyExpr), 0) AS qty,
                $costExpr AS cost,
                COUNT(*) FILTER (WHERE $qty IS NULL) AS missing_qty,
                $missingPrice AS missing_price,
                $unitVariants AS unit_variants")
            ->whereNotNull('plot_id')
            ->groupBy('plot_id', 'campaign_id', 'operation_date');

        if ($days !== null) {
            $select->whereIn('operation_date', $days);
        }

        DB::transaction(function () use ($opType, $days, $select): void {
            $stale = DB::table('ai_daily_rollups')->where('op_type', $opType);
            if ($days !== null) {
                $stale->whereIn('day', $days);
            }
            $stale->delete();

            $now = now();
            foreach ($select->get()->chunk(500) as $chunk) {
                $insert = [];
                foreach ($chunk as $r) {
                    $insert[] = [
                        'op_type'       => $opType,
                        'plot_id'       => (string) $r->plot_id,
                        'campaign_key'  => (string) $r->campaign_key,
                        'day'           => Carbon::parse((string) $r->day)->toDateString(),
                        'ops'           => (int) $r->ops,
                        'qty'           => round((float) $r->qty, 4),
                        'cost'          => round((float) $r->cost, 4),
                        'missing_qty'   => (int) $r->missing_qty,
                        'missing_price' => (int) $r->missing_price,
                        'unit_variants' => max(1, (int) $r->unit_variants),
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ];
                }
                if ($insert !== []) {
                    DB::table('ai_daily_rollups')->insert($insert);
                }
            }
        });
    }

    /** SQL expression normalising a litre-or-m³ column to cubic metres. */
    private function m3Expr(string $col): string
    {
        $list = "'".implode("','", self::LITRE_UNITS)."'";

        return "CASE WHEN LOWER(TRIM(COALESCE(unit_at_entry, 'm3'))) IN ($list)
                     THEN ($col) / 1000.0 ELSE ($col) END";
    }
}