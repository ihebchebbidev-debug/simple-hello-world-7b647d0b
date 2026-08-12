<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds a compact JSON snapshot of live Flehty data for the AI system prompt.
 *
 * Cache strategy — sharded, stamp-invalidated:
 *   Each section is cached under a key that embeds a lightweight "stamp"
 *   (row count + MAX(updated_at)) of its source tables. When a row is added
 *   or updated the stamp changes, the key changes, and the next request
 *   recomputes only that section. Untouched sections stay warm.
 *
 *   TTLs are safety nets: short for volatile aggregates (30s), long for
 *   near-static catalogs (10 min). Time-window aggregates (this_month_*)
 *   also include the current minute in the stamp so numbers stay fresh
 *   even when no write occurred.
 */
final class AiContextBuilder
{
    private const KEY_PREFIX = 'ai_chat.ctx.v6';

    /** Per-section TTLs (seconds) — used as fallback if the stamp never changes. */
    private const TTL = [
        'dashboard'         => 30,
        'plots'             => 300,
        'plot_operations'   => 120,
        'water'             => 60,
        'fertilization'     => 60,
        'phytosanitary'     => 60,
        'harvest'           => 60,
        'costs'             => 60,
        'labor'             => 300,
        'prices'            => 300,
        'campaigns'         => 300,
        'recent_operations' => 30,
        'catalog'           => 300,
        'catalog_items'     => 600,
        'pests'             => 600,
        'users'             => 600,
        'notifications'     => 30,
        'postings'          => 30,
    ];

    /** Which tables feed each section — used to compute its cache stamp. */
    private const SOURCES = [
        'dashboard'         => ['plots', 'fertilizers', 'pesticides', 'campaigns', 'postings', 'irrigation_operations', 'fertilization_operations', 'phytosanitary_operations', 'harvest_operations'],
        'plots'             => ['plots'],
        'plot_operations'   => ['plots', 'irrigation_operations', 'fertilization_operations', 'phytosanitary_operations', 'harvest_operations'],
        'water'             => ['irrigation_operations', 'water_config', 'price_history', 'plots'],
        'fertilization'     => ['fertilization_operations', 'fertilizers'],
        'phytosanitary'     => ['phytosanitary_operations', 'pesticides'],
        'harvest'           => ['harvest_operations'],
        'costs'             => ['irrigation_operations', 'fertilization_operations', 'phytosanitary_operations', 'harvest_operations', 'price_history'],
        'labor'             => ['labor_config', 'price_history'],
        'prices'            => ['price_history', 'fertilizers', 'pesticides'],
        'campaigns'         => ['campaigns'],
        'recent_operations' => ['irrigation_operations', 'fertilization_operations', 'phytosanitary_operations', 'harvest_operations'],
        'catalog'           => ['fertilizers', 'pesticides', 'pests'],
        'catalog_items'     => ['fertilizers', 'pesticides', 'pests'],
        'pests'             => ['pests'],
        'users'             => ['users', 'user_roles'],
        'notifications'    => ['notifications'],
        'postings'          => ['postings'],
    ];

    /** Sections whose result depends on "this month" — bust when the month rolls over. */
    private const MONTH_SCOPED = ['dashboard', 'water', 'fertilization', 'phytosanitary', 'harvest', 'costs'];

    /** In-request memoization of per-table stamps to avoid repeat COUNT/MAX queries. */
    private array $stampCache = [];

    /** In-request memo of Schema::hasTable / hasColumn — each check is a DB round-trip. */
    private array $tableExists = [];
    private array $columnExists = [];

    private function hasTable(string $table): bool
    {
        return $this->tableExists[$table] ??= Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table.'.'.$column;
        return $this->columnExists[$key] ??= Schema::hasColumn($table, $column);
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $seasonStart = now()->subMonths(12)->toDateString();
        $this->stampCache = [];
        $this->tableExists = [];
        $this->columnExists = [];

        // Pre-warm every source-table stamp in ONE round-trip instead of
        // ~9 sequential COUNT(*)+MAX(updated_at) queries. Slashes context
        // build latency for the cold path (first request after a write).
        $this->prewarmStamps();

        return [
            'generated_at'   => now()->toIso8601String(),
            'currency'       => 'TND',
            'units'          => ['area' => 'ha', 'water' => 'm3', 'fertilizer' => 'kg', 'harvest' => 'kg'],
            'period'         => ['this_month_start' => $monthStart, 'season_lookback_start' => $seasonStart],
            'dashboard'         => $this->section('dashboard',         fn () => $this->dashboard($monthStart)),
            'plots'             => $this->section('plots',             fn () => $this->plots()),
            'plot_operations'   => $this->section('plot_operations',   fn () => $this->plotOperationRollup()),
            'water'             => $this->section('water',             fn () => [
                'active_unit'            => $this->activeWaterUnit(),
                'current_price_per_unit' => $this->currentPrice('water', null),
                'this_month_total_m3'    => $this->tableScalar(
                    'irrigation_operations',
                    'SELECT COALESCE(SUM(water_quantity),0) AS v FROM irrigation_operations WHERE operation_date >= ?',
                    [$monthStart],
                ),
                'this_month_by_plot_m3'  => $this->waterByPlot($monthStart),
                'consumption_by_plot_m3' => $this->waterByPlot(),
                'note'                   => 'this_month_* = since '.$monthStart.'; consumption_by_plot_m3 = cumulative.',
            ]),
            'fertilization'     => $this->section('fertilization',     fn () => $this->fertilizationSummary($monthStart)),
            'phytosanitary'     => $this->section('phytosanitary',     fn () => $this->phytosanitarySummary($monthStart)),
            'harvest'           => $this->section('harvest',           fn () => $this->harvestSummary($monthStart)),
            'costs'             => $this->section('costs',             fn () => $this->costs($monthStart)),
            'labor'             => $this->section('labor',             fn () => $this->labor()),
            'prices'            => $this->section('prices',            fn () => $this->priceCatalog()),
            'campaigns'         => $this->section('campaigns',         fn () => $this->campaigns()),
            'recent_operations' => $this->section('recent_operations', fn () => $this->recentOperations()),
            'catalog'           => $this->section('catalog',           fn () => $this->catalogCounts()),
            'catalog_items'     => $this->section('catalog_items',     fn () => $this->catalogItems()),
            'pests'             => $this->section('pests',             fn () => $this->pestCatalog()),
            'users'             => $this->section('users',             fn () => $this->users()),
            'notifications'     => $this->section('notifications',     fn () => $this->notifications()),
            'postings'          => $this->section('postings',          fn () => $this->postings()),
        ];
    }

    /**
     * Batch-load COUNT(*) + MAX(updated_at) for every table referenced by any
     * section into $this->stampCache with a single UNION ALL query. Silently
     * skips missing tables/columns; the per-table fallback in tableStamp()
     * still handles anything not pre-warmed.
     */
    private function prewarmStamps(): void
    {
        $tables = array_values(array_unique(array_merge(...array_values(self::SOURCES))));
        $selects = [];
        foreach ($tables as $table) {
            if (! $this->hasTable($table)) {
                $this->stampCache[$table] = '0';
                continue;
            }
            $hasUpdated = $this->hasColumn($table, 'updated_at');
            // Quote table as literal so we can identify rows back in PHP.
            $literal = "'".str_replace("'", "''", $table)."'";
            $selects[] = $hasUpdated
                ? "SELECT $literal AS t, COUNT(*) AS c, COALESCE(MAX(updated_at)::text,'') AS u FROM $table"
                : "SELECT $literal AS t, COUNT(*) AS c, '' AS u FROM $table";
        }
        if ($selects === []) {
            return;
        }
        try {
            $rows = DB::select(implode(' UNION ALL ', $selects));
            foreach ($rows as $row) {
                $this->stampCache[$row->t] = ($row->c ?? 0).':'.($row->u ?? '');
            }
        } catch (\Throwable $e) {
            \Log::warning('ai.context.prewarm_stamps_failed', ['error' => $e->getMessage()]);
            // Leave $stampCache alone — tableStamp() will fall back per-table.
        }
    }

    /**
     * Cache one section under a stamp-embedded key so writes invalidate it
     * naturally while untouched data stays warm past the TTL floor.
     */
    private function section(string $name, \Closure $compute): mixed
    {
        $stamp = $this->sectionStamp($name);
        $key   = self::KEY_PREFIX.":$name:$stamp";
        $ttl   = self::TTL[$name] ?? 60;

        return Cache::remember($key, $ttl, function () use ($compute, $name) {
            try {
                return $compute();
            } catch (\Throwable $e) {
                // Never let a single bad column / missing table nuke the whole context.
                \Log::warning('ai.context.section_failed', [
                    'section' => $name,
                    'error'   => $e->getMessage(),
                ]);
                return ['_unavailable' => true, 'reason' => 'schema_mismatch'];
            }
        });
    }


    /**
     * Cheap fingerprint of a section's source tables. Includes row count and
     * MAX(updated_at) per table, plus the current month for month-scoped
     * sections so month rollover invalidates cleanly.
     */
    private function sectionStamp(string $name): string
    {
        $parts = [];
        foreach (self::SOURCES[$name] ?? [] as $table) {
            $parts[] = $table.'='.$this->tableStamp($table);
        }
        if (in_array($name, self::MONTH_SCOPED, true)) {
            $parts[] = 'm='.now()->format('Y-m');
        }

        return substr(md5(implode('|', $parts)), 0, 12);
    }

    private function tableStamp(string $table): string
    {
        if (isset($this->stampCache[$table])) {
            return $this->stampCache[$table];
        }
        if (! $this->hasTable($table)) {
            return $this->stampCache[$table] = '0';
        }

        $hasUpdated = $this->hasColumn($table, 'updated_at');
        $sql = $hasUpdated
            ? "SELECT COUNT(*) AS c, COALESCE(MAX(updated_at)::text,'') AS u FROM $table"
            : "SELECT COUNT(*) AS c, '' AS u FROM $table";

        try {
            $row = DB::selectOne($sql);
            return $this->stampCache[$table] = ($row->c ?? 0).':'.($row->u ?? '');
        } catch (\Throwable) {
            // Fall back to a per-minute stamp so we still refresh, just less optimally.
            return $this->stampCache[$table] = 'err:'.now()->format('YmdHi');
        }
    }

    /**
     * Manual bust — call from a model observer or admin action to force a
     * targeted refresh without waiting for the stamp to shift.
     */
    public static function forget(?string $section = null): void
    {
        if ($section === null) {
            // Bulk forget by rotating the prefix would need cache tags; instead,
            // deletion by pattern is driver-dependent. Rely on stamp change +
            // TTL expiry. Callers should pass a specific section.
            return;
        }
        // Section keys embed a stamp we don't know here — the cleanest bust is
        // to write a poison stamp for the section's source tables, forcing the
        // next request to compute a new key. In practice, writes update
        // updated_at and the stamp shifts automatically; this hook exists for
        // rare cases (bulk imports, restores) where updated_at doesn't move.
        Cache::forget(self::KEY_PREFIX.":$section:manual");
    }


    /** @return array<string, mixed> */
    private function dashboard(string $monthStart): array
    {
        if (! $this->hasTable('plots')) {
            return ['counts' => [], 'this_month' => ['period_start' => $monthStart]];
        }

        $hasCampaigns = $this->hasTable('campaigns');
        $hasPostings  = $this->hasTable('postings');

        $row = DB::selectOne(
            'SELECT
                (SELECT COUNT(*) FROM plots       WHERE is_active = true) AS plots_active,
                (SELECT COUNT(*) FROM fertilizers WHERE is_active = true) AS fertilizers_active,
                (SELECT COUNT(*) FROM pesticides  WHERE is_active = true) AS pesticides_active,
                '.($hasCampaigns
                    ? '(SELECT COUNT(*) FROM campaigns WHERE is_active = true)'
                    : '0').' AS campaigns_active,
                '.($hasPostings
                    ? "(SELECT COUNT(*) FROM postings WHERE status IN ('pending','failed'))"
                    : '0').' AS pending_postings,
                (SELECT COALESCE(SUM(water_quantity),0)    FROM irrigation_operations    WHERE operation_date >= ?) AS water_q,
                (SELECT COALESCE(SUM(quantity_applied),0)  FROM fertilization_operations WHERE operation_date >= ?) AS fert_q,
                (SELECT COUNT(*)                           FROM phytosanitary_operations WHERE operation_date >= ?) AS phyto_n,
                (SELECT COALESCE(SUM(quantity_harvested),0) FROM harvest_operations      WHERE operation_date >= ?) AS harvest_q
            ',
            [$monthStart, $monthStart, $monthStart, $monthStart],
        );

        return [
            'counts' => [
                'plots_active'       => (int) ($row->plots_active ?? 0),
                'fertilizers_active' => (int) ($row->fertilizers_active ?? 0),
                'pesticides_active'  => (int) ($row->pesticides_active ?? 0),
                'campaigns_active'   => (int) ($row->campaigns_active ?? 0),
                'pending_postings'   => (int) ($row->pending_postings ?? 0),
            ],
            'this_month' => [
                'period_start'        => $monthStart,
                'water_quantity_m3'   => (float) ($row->water_q ?? 0),
                'fertilizer_quantity' => (float) ($row->fert_q ?? 0),
                'treatments'          => (int) ($row->phyto_n ?? 0),
                'harvest_quantity'    => (float) ($row->harvest_q ?? 0),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function plots(): array
    {
        if (! $this->hasTable('plots')) {
            return [];
        }

        return DB::table('plots')
            ->where('is_active', true)
            ->select(['id', 'name', 'surface_area_ha', 'crop_type', 'variety', 'season_start_date'])
            ->orderBy('name')
            ->limit(80)
            ->get()
            ->map(fn ($r) => [
                'id'                => $r->id,
                'name'              => $r->name,
                'surface_area_ha'   => (float) $r->surface_area_ha,
                'crop_type'         => $r->crop_type,
                'variety'           => $r->variety,
                'season_start_date' => $r->season_start_date,
            ])
            ->all();
    }

    /**
     * Per-plot cumulative rollup: irrigation, fertilization, phytosanitary count, harvest.
     * Lets the AI answer "how much fertilizer on Parcelle X?" or "harvest per plot".
     *
     * @return array<int, array<string, mixed>>
     */
    private function plotOperationRollup(): array
    {
        if (! $this->hasTable('plots')) {
            return [];
        }

        $rows = DB::table('plots')
            ->where('plots.is_active', true)
            ->leftJoin(
                DB::raw('(SELECT plot_id, SUM(water_quantity) w, SUM(COALESCE(price_at_entry,0)*water_quantity) c FROM irrigation_operations GROUP BY plot_id) irr'),
                'irr.plot_id', '=', 'plots.id',
            )
            ->leftJoin(
                DB::raw('(SELECT plot_id, SUM(quantity_applied) q, SUM(COALESCE(price_at_entry,0)*quantity_applied) c FROM fertilization_operations GROUP BY plot_id) fer'),
                'fer.plot_id', '=', 'plots.id',
            )
            ->leftJoin(
                DB::raw('(SELECT plot_id, COUNT(*) n, SUM(quantity_applied) q, SUM(COALESCE(price_at_entry,0)*quantity_applied) c FROM phytosanitary_operations GROUP BY plot_id) phy'),
                'phy.plot_id', '=', 'plots.id',
            )
            ->leftJoin(
                DB::raw('(SELECT plot_id, SUM(quantity_harvested) q, SUM(COALESCE(daily_rate_at_entry,0)*num_workers*days_worked) c FROM harvest_operations GROUP BY plot_id) hrv'),
                'hrv.plot_id', '=', 'plots.id',
            )
            ->select([
                'plots.name',
                'plots.surface_area_ha',
                'plots.crop_type',
                DB::raw('COALESCE(irr.w,0) AS irrigation_m3'),
                DB::raw('COALESCE(irr.c,0) AS irrigation_cost'),
                DB::raw('COALESCE(fer.q,0) AS fertilizer_kg'),
                DB::raw('COALESCE(fer.c,0) AS fertilizer_cost'),
                DB::raw('COALESCE(phy.n,0) AS phyto_count'),
                DB::raw('COALESCE(phy.q,0) AS phyto_qty'),
                DB::raw('COALESCE(phy.c,0) AS phyto_cost'),
                DB::raw('COALESCE(hrv.q,0) AS harvest_kg'),
                DB::raw('COALESCE(hrv.c,0) AS labor_cost'),
            ])
            ->orderBy('plots.name')
            ->limit(80)
            ->get();

        return $rows->map(function ($r) {
            $totalCost = (float) $r->irrigation_cost + (float) $r->fertilizer_cost + (float) $r->phyto_cost + (float) $r->labor_cost;
            return [
                'plot_name'       => $r->name,
                'surface_area_ha' => (float) $r->surface_area_ha,
                'crop_type'       => $r->crop_type,
                'irrigation_m3'   => (float) $r->irrigation_m3,
                'fertilizer_kg'   => (float) $r->fertilizer_kg,
                'phyto_treatments'=> (int) $r->phyto_count,
                'phyto_qty'       => (float) $r->phyto_qty,
                'harvest_kg'      => (float) $r->harvest_kg,
                'cost_breakdown_tnd' => [
                    'irrigation'   => round((float) $r->irrigation_cost, 2),
                    'fertilizer'   => round((float) $r->fertilizer_cost, 2),
                    'phytosanitary'=> round((float) $r->phyto_cost, 2),
                    'labor'        => round((float) $r->labor_cost, 2),
                    'total'        => round($totalCost, 2),
                ],
                'cost_per_ha_tnd' => $r->surface_area_ha > 0
                    ? round($totalCost / (float) $r->surface_area_ha, 2)
                    : null,
                'yield_kg_per_ha' => $r->surface_area_ha > 0
                    ? round((float) $r->harvest_kg / (float) $r->surface_area_ha, 2)
                    : null,
            ];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function waterByPlot(?string $sinceDate = null): array
    {
        if (! $this->hasTable('irrigation_operations') || ! $this->hasTable('plots')) {
            return [];
        }

        $query = DB::table('irrigation_operations as op')
            ->join('plots', 'plots.id', '=', 'op.plot_id')
            ->where('plots.is_active', true);

        if ($sinceDate !== null) {
            $query->where('op.operation_date', '>=', $sinceDate);
        }

        return $query
            ->select([
                'plots.name as plot_name',
                'plots.surface_area_ha',
                DB::raw('SUM(op.water_quantity) AS total_m3'),
            ])
            ->groupBy('plots.name', 'plots.surface_area_ha')
            ->orderByDesc('total_m3')
            ->limit(40)
            ->get()
            ->map(fn ($r) => [
                'plot_name'      => $r->plot_name,
                'surface_area_ha'=> (float) $r->surface_area_ha,
                'total_m3'       => (float) $r->total_m3,
                'm3_per_hectare' => $r->surface_area_ha > 0
                    ? round((float) $r->total_m3 / (float) $r->surface_area_ha, 2)
                    : null,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function fertilizationSummary(string $monthStart): array
    {
        if (! $this->hasTable('fertilization_operations')) {
            return ['this_month_quantity' => 0, 'by_fertilizer' => []];
        }

        $byFert = DB::table('fertilization_operations as op')
            ->leftJoin('fertilizers as f', 'f.id', '=', 'op.fertilizer_id')
            ->select([
                'f.name as fertilizer',
                DB::raw('SUM(op.quantity_applied) AS total_kg'),
                DB::raw('SUM(COALESCE(op.price_at_entry,0) * op.quantity_applied) AS total_cost'),
                DB::raw('COUNT(*) AS applications'),
            ])
            ->groupBy('f.name')
            ->orderByDesc('total_kg')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'fertilizer'   => $r->fertilizer ?? 'unknown',
                'applications' => (int) $r->applications,
                'total_kg'     => (float) $r->total_kg,
                'total_cost_tnd' => round((float) $r->total_cost, 2),
            ])
            ->all();

        return [
            'this_month_quantity_kg' => $this->tableScalar(
                'fertilization_operations',
                'SELECT COALESCE(SUM(quantity_applied),0) AS v FROM fertilization_operations WHERE operation_date >= ?',
                [$monthStart],
            ),
            'by_fertilizer' => $byFert,
        ];
    }

    /** @return array<string, mixed> */
    private function phytosanitarySummary(string $monthStart): array
    {
        if (! $this->hasTable('phytosanitary_operations')) {
            return ['this_month_treatments' => 0, 'by_pesticide' => []];
        }

        $byPest = DB::table('phytosanitary_operations as op')
            ->leftJoin('pesticides as p', 'p.id', '=', 'op.pesticide_id')
            ->select([
                'p.name as pesticide',
                DB::raw('COUNT(*) AS treatments'),
                DB::raw('SUM(op.quantity_applied) AS total_qty'),
                DB::raw('SUM(COALESCE(op.price_at_entry,0) * op.quantity_applied) AS total_cost'),
            ])
            ->groupBy('p.name')
            ->orderByDesc('treatments')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'pesticide'      => $r->pesticide ?? 'unknown',
                'treatments'     => (int) $r->treatments,
                'total_quantity' => (float) $r->total_qty,
                'total_cost_tnd' => round((float) $r->total_cost, 2),
            ])
            ->all();

        $byTargetPest = DB::table('phytosanitary_operations as op')
            ->select([
                DB::raw("COALESCE(NULLIF(op.target_pest, ''), 'unknown') AS target_pest"),
                DB::raw('COUNT(*) AS treatments'),
                DB::raw('SUM(op.quantity_applied) AS total_qty'),
            ])
            ->groupBy('op.target_pest')
            ->orderByDesc('treatments')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'target_pest'    => $r->target_pest ?? 'unknown',
                'treatments'     => (int) $r->treatments,
                'total_quantity' => (float) $r->total_qty,
            ])
            ->all();

        $byPlot = DB::table('phytosanitary_operations as op')
            ->leftJoin('plots as plt', 'plt.id', '=', 'op.plot_id')
            ->select([
                DB::raw("COALESCE(plt.name, 'unknown') AS plot_name"),
                DB::raw('COUNT(*) AS treatments'),
                DB::raw('SUM(op.quantity_applied) AS total_qty'),
            ])
            ->groupBy('plt.name')
            ->orderByDesc('treatments')
            ->limit(40)
            ->get()
            ->map(fn ($r) => [
                'plot_name'      => $r->plot_name ?? 'unknown',
                'treatments'     => (int) $r->treatments,
                'total_quantity' => (float) $r->total_qty,
            ])
            ->all();

        $byPlotTargetPest = DB::table('phytosanitary_operations as op')
            ->leftJoin('plots as plt', 'plt.id', '=', 'op.plot_id')
            ->select([
                DB::raw("COALESCE(plt.name, 'unknown') AS plot_name"),
                DB::raw("COALESCE(NULLIF(op.target_pest, ''), 'unknown') AS target_pest"),
                DB::raw('COUNT(*) AS treatments'),
                DB::raw('SUM(op.quantity_applied) AS total_qty'),
            ])
            ->groupBy('plt.name', 'op.target_pest')
            ->orderByDesc('treatments')
            ->limit(100)
            ->get()
            ->map(fn ($r) => [
                'plot_name'      => $r->plot_name ?? 'unknown',
                'target_pest'    => $r->target_pest ?? 'unknown',
                'treatments'     => (int) $r->treatments,
                'total_quantity' => (float) $r->total_qty,
            ])
            ->all();

        $recent = DB::table('phytosanitary_operations as op')
            ->leftJoin('plots as plt', 'plt.id', '=', 'op.plot_id')
            ->leftJoin('pesticides as p', 'p.id', '=', 'op.pesticide_id')
            ->select([
                'op.operation_date',
                DB::raw("COALESCE(plt.name, 'unknown') AS plot_name"),
                DB::raw("COALESCE(NULLIF(op.target_pest, ''), 'unknown') AS target_pest"),
                DB::raw("COALESCE(p.name, 'unknown') AS pesticide"),
                'op.quantity_applied',
            ])
            ->orderByDesc('op.operation_date')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'operation_date' => $r->operation_date,
                'plot_name'      => $r->plot_name ?? 'unknown',
                'target_pest'    => $r->target_pest ?? 'unknown',
                'pesticide'      => $r->pesticide ?? 'unknown',
                'quantity_applied' => (float) $r->quantity_applied,
            ])
            ->all();

        return [
            'this_month_treatments' => (int) $this->tableScalar(
                'phytosanitary_operations',
                'SELECT COUNT(*) AS v FROM phytosanitary_operations WHERE operation_date >= ?',
                [$monthStart],
            ),
            'by_pesticide' => $byPest,
            'by_target_pest' => $byTargetPest,
            'by_plot' => $byPlot,
            'by_plot_target_pest' => $byPlotTargetPest,
            'recent_treatments' => $recent,
        ];
    }

    /** @return array<string, mixed> */
    private function harvestSummary(string $monthStart): array
    {
        if (! $this->hasTable('harvest_operations')) {
            return ['this_month_quantity' => 0, 'season_total_kg' => 0];
        }

        $row = DB::selectOne(
            'SELECT
                COALESCE(SUM(quantity_harvested),0)                                      AS season_kg,
                COALESCE(SUM(num_workers * days_worked),0)                               AS man_days,
                COALESCE(SUM(num_workers * days_worked * COALESCE(daily_rate_at_entry,0)),0) AS labor_cost
             FROM harvest_operations'
        );

        return [
            'this_month_quantity_kg' => $this->tableScalar(
                'harvest_operations',
                'SELECT COALESCE(SUM(quantity_harvested),0) AS v FROM harvest_operations WHERE operation_date >= ?',
                [$monthStart],
            ),
            'season_total_kg'      => (float) ($row->season_kg ?? 0),
            'total_man_days'       => (float) ($row->man_days ?? 0),
            'total_labor_cost_tnd' => round((float) ($row->labor_cost ?? 0), 2),
        ];
    }

    /** @return array<string, mixed> */
    private function costs(string $monthStart): array
    {
        $irr = $this->costRow('irrigation_operations',  'water_quantity',     $monthStart);
        $fer = $this->costRow('fertilization_operations','quantity_applied',  $monthStart);
        $phy = $this->costRow('phytosanitary_operations','quantity_applied',  $monthStart);
        $lab = $this->laborCostRow($monthStart);

        $totalSeason = $irr['season'] + $fer['season'] + $phy['season'] + $lab['season'];
        $totalMonth  = $irr['month']  + $fer['month']  + $phy['month']  + $lab['month'];

        return [
            'currency' => 'TND',
            'this_month' => [
                'irrigation'   => $irr['month'],
                'fertilizer'   => $fer['month'],
                'phytosanitary'=> $phy['month'],
                'labor'        => $lab['month'],
                'total'        => round($totalMonth, 2),
            ],
            'cumulative' => [
                'irrigation'   => $irr['season'],
                'fertilizer'   => $fer['season'],
                'phytosanitary'=> $phy['season'],
                'labor'        => $lab['season'],
                'total'        => round($totalSeason, 2),
            ],
        ];
    }

    /** @return array{month: float, season: float} */
    private function costRow(string $table, string $qtyCol, string $monthStart): array
    {
        if (! $this->hasTable($table)) {
            return ['month' => 0.0, 'season' => 0.0];
        }
        $row = DB::selectOne(
            "SELECT
                COALESCE(SUM(CASE WHEN operation_date >= ? THEN COALESCE(price_at_entry,0)*$qtyCol ELSE 0 END),0) AS m,
                COALESCE(SUM(COALESCE(price_at_entry,0)*$qtyCol),0) AS s
             FROM $table",
            [$monthStart],
        );

        return ['month' => round((float) $row->m, 2), 'season' => round((float) $row->s, 2)];
    }

    /** @return array{month: float, season: float} */
    private function laborCostRow(string $monthStart): array
    {
        if (! $this->hasTable('harvest_operations')) {
            return ['month' => 0.0, 'season' => 0.0];
        }
        $row = DB::selectOne(
            'SELECT
                COALESCE(SUM(CASE WHEN operation_date >= ? THEN COALESCE(daily_rate_at_entry,0)*num_workers*days_worked ELSE 0 END),0) AS m,
                COALESCE(SUM(COALESCE(daily_rate_at_entry,0)*num_workers*days_worked),0) AS s
             FROM harvest_operations',
            [$monthStart],
        );

        return ['month' => round((float) $row->m, 2), 'season' => round((float) $row->s, 2)];
    }

    /** @return array<string, mixed> */
    private function labor(): array
    {
        if (! $this->hasTable('labor_config')) {
            return ['active' => false];
        }
        $active = DB::table('labor_config')->where('is_active', true)->first();
        $currentRate = $this->currentPrice('labor', null);

        return [
            'active'                    => (bool) $active,
            'current_daily_rate_tnd'    => $currentRate,
            'currency'                  => 'TND',
        ];
    }

    /** @return array<string, mixed> */
    private function priceCatalog(): array
    {
        if (! $this->hasTable('price_history')) {
            return [];
        }

        // Latest active price per (entity_type, entity_id).
        // Use DISTINCT ON (Postgres) ordered by effective_from desc since id is a uuid (MAX(uuid) is unsupported).
        $rows = collect(DB::select('
            SELECT DISTINCT ON (entity_type, entity_id)
                   entity_type, entity_id, price_per_unit, unit, effective_from
            FROM price_history
            WHERE effective_from <= CURRENT_DATE
            ORDER BY entity_type, entity_id, effective_from DESC
        '));

        $fertNames = $this->hasTable('fertilizers')
            ? DB::table('fertilizers')->pluck('name', 'id')->all() : [];
        $pestNames = $this->hasTable('pesticides')
            ? DB::table('pesticides')->pluck('name', 'id')->all() : [];

        return $rows->map(function ($r) use ($fertNames, $pestNames) {
            $name = null;
            if ($r->entity_type === 'fertilizer') $name = $fertNames[$r->entity_id] ?? null;
            if ($r->entity_type === 'pesticide')  $name = $pestNames[$r->entity_id] ?? null;

            return [
                'entity_type'    => $r->entity_type,
                'name'           => $name,
                'price_per_unit' => (float) $r->price_per_unit,
                'unit'           => $r->unit,
                'effective_from' => $r->effective_from,
            ];
        })->take(80)->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function campaigns(): array
    {
        if (! $this->hasTable('campaigns')) {
            return [];
        }

        $rows = DB::table('campaigns')
            ->select(['id', 'name', 'start_date', 'end_date', 'is_active'])
            ->orderByDesc('start_date')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'id'         => $r->id,
                'name'       => $r->name,
                'start_date' => $r->start_date,
                'end_date'   => $r->end_date,
                'is_active'  => (bool) $r->is_active,
            ])
            ->all();

        $activeCampaigns = array_values(array_filter($rows, fn (array $row): bool => (bool) ($row['is_active'] ?? false)));

        return [
            'count'           => count($rows),
            'active_count'    => count($activeCampaigns),
            'active_campaigns' => $activeCampaigns,
            'campaigns'       => $rows,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function recentOperations(): array
    {
        $tables = [
            ['irrigation_operations',    'irrigation',    'water_quantity'],
            ['fertilization_operations', 'fertilization', 'quantity_applied'],
            ['phytosanitary_operations', 'phytosanitary', 'quantity_applied'],
            ['harvest_operations',       'harvest',       'quantity_harvested'],
        ];

        $unions = [];
        foreach ($tables as [$table, $type, $qtyCol]) {
            if (! $this->hasTable($table)) {
                continue;
            }

            $unions[] = DB::table($table . ' as op')
                ->leftJoin('plots', 'plots.id', '=', 'op.plot_id')
                ->select([
                    DB::raw("'$type' as type"),
                    'plots.name as plot_name',
                    'op.operation_date',
                    DB::raw("op.$qtyCol as quantity"),
                ])
                ->orderByDesc('op.created_at')
                ->limit(15);
        }

        if ($unions === []) {
            return [];
        }

        $query = array_shift($unions);
        foreach ($unions as $u) {
            $query->unionAll($u);
        }

        return DB::query()
            ->fromSub($query, 'feed')
            ->orderByDesc('operation_date')
            ->limit(30)
            ->get()
            ->map(fn ($r) => [
                'type'           => $r->type,
                'plot_name'      => $r->plot_name,
                'operation_date' => $r->operation_date,
                'quantity'       => (float) $r->quantity,
            ])
            ->all();
    }

    /** @return array<string, int> */
    private function catalogCounts(): array
    {
        return [
            'fertilizers' => $this->hasTable('fertilizers')
                ? (int) DB::table('fertilizers')->where('is_active', true)->count() : 0,
            'pesticides' => $this->hasTable('pesticides')
                ? (int) DB::table('pesticides')->where('is_active', true)->count() : 0,
            'pests' => $this->hasTable('pests')
                ? (int) DB::table('pests')->where('is_active', true)->count() : 0,
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function catalogItems(): array
    {
        $out = ['fertilizers' => [], 'pesticides' => [], 'pests' => []];

        if ($this->hasTable('fertilizers')) {
            // Full nutrient composition (N-P-K + Mg-Ca-S) and unit, exactly as
            // shown in the Engrais catalogue screen, so composition questions
            // never fall back to guessing.
            $nutrients = ['n_percent', 'p_percent', 'k_percent', 'mg_percent', 'ca_percent', 's_percent'];
            $cols = array_values(array_filter(
                array_merge(['name', 'unit'], $nutrients, ['density_kg_per_l']),
                fn ($c) => $this->hasColumn('fertilizers', $c),
            ));
            if (! in_array('name', $cols, true)) $cols = array_merge(['name'], $cols);
            $q = DB::table('fertilizers');
            if ($this->hasColumn('fertilizers', 'is_active')) $q->where('is_active', true);
            $out['fertilizers'] = $q->select($cols)
                ->orderBy('name')->limit(300)->get()
                ->map(fn ($r) => [
                    'name' => $r->name ?? null,
                    'unit' => $r->unit ?? null,
                    'n'    => (float) ($r->n_percent ?? 0),
                    'p'    => (float) ($r->p_percent ?? 0),
                    'k'    => (float) ($r->k_percent ?? 0),
                    'mg'   => (float) ($r->mg_percent ?? 0),
                    'ca'   => (float) ($r->ca_percent ?? 0),
                    's'    => (float) ($r->s_percent ?? 0),
                    // null = not recorded: no % -> g/L or kg/ha conversion allowed.
                    'density_kg_per_l' => isset($r->density_kg_per_l) && $r->density_kg_per_l !== null
                        ? (float) $r->density_kg_per_l : null,
                ])
                ->all();
        }
        if ($this->hasTable('pesticides')) {
            // The current schema stores the pesticide formula in
            // `chemical_composition`; do not fallback to the removed
            // `active_ingredient` column.
            $ingredientCol = $this->hasColumn('pesticides', 'chemical_composition')
                ? 'chemical_composition'
                : null;
            $cols = ['name'];
            if ($ingredientCol) $cols[] = $ingredientCol;
            if ($this->hasColumn('pesticides', 'unit')) $cols[] = 'unit';
            $q = DB::table('pesticides');
            if ($this->hasColumn('pesticides', 'is_active')) $q->where('is_active', true);
            // The treatment catalogue is long (dozens of products); truncating
            // it made the model invent compositions for the missing tail.
            $out['pesticides'] = $q->select($cols)
                ->orderBy('name')->limit(300)->get()
                ->map(fn ($r) => [
                    'name' => $r->name ?? null,
                    'chemical_composition' => $ingredientCol ? ($r->{$ingredientCol} ?? null) : null,
                    'unit' => $r->unit ?? null,
                ])
                ->all();
        }
        if ($this->hasTable('pests')) {
            $cols = array_values(array_filter(['name', 'scientific_name', 'category'], fn ($c) => $this->hasColumn('pests', $c)));
            if (! in_array('name', $cols, true)) $cols = array_merge(['name'], $cols);
            $q = DB::table('pests');
            if ($this->hasColumn('pests', 'is_active')) $q->where('is_active', true);
            $out['pests'] = $q->select($cols)
                ->orderBy('name')->limit(200)->get()
                ->map(fn ($r) => [
                    'name' => $r->name ?? null,
                    'scientific_name' => $r->scientific_name ?? null,
                    'category' => $r->category ?? null,
                ])
                ->all();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function users(): array
    {
        if (! $this->hasTable('users')) return ['total' => 0];

        $total = (int) DB::table('users')->count();
        $byRole = [];
        if ($this->hasTable('user_roles')) {
            $byRole = DB::table('user_roles')
                ->select('role', DB::raw('COUNT(*) AS n'))
                ->groupBy('role')->pluck('n', 'role')->all();
        }

        return ['total' => $total, 'by_role' => $byRole];
    }

    /** @return array<string, int> */
    private function notifications(): array
    {
        if (! $this->hasTable('notifications')) return [];
        return [
            'total'  => (int) DB::table('notifications')->count(),
            'unread' => (int) DB::table('notifications')->whereNull('read_at')->count(),
        ];
    }

    /** @return array<string, int> */
    private function postings(): array
    {
        if (! $this->hasTable('postings')) return [];
        return DB::table('postings')
            ->select('status', DB::raw('COUNT(*) AS n'))
            ->groupBy('status')->pluck('n', 'status')->all();
    }

    private function currentPrice(string $entityType, ?string $entityId): float
    {
        if (! $this->hasTable('price_history')) return 0.0;

        $q = DB::table('price_history')
            ->where('entity_type', $entityType)
            ->where('effective_from', '<=', now()->toDateString());
        if ($entityId !== null) {
            $q->where('entity_id', $entityId);
        }
        // price_history.id is a UUID — lexicographic order is not chronological.
        // Break ties on the same effective_from with created_at instead.
        $row = $q->orderByDesc('effective_from')->orderByDesc('created_at')->first(['price_per_unit']);

        return $row ? (float) $row->price_per_unit : 0.0;
    }

    private function activeWaterUnit(): string
    {
        if (! $this->hasTable('water_config')) return 'm3';
        return (string) (DB::table('water_config')->where('is_active', true)->orderByDesc('created_at')->value('unit') ?? 'm3');
    }

    /** @return array<int, array<string, mixed>> */
    private function pestCatalog(): array
    {
        if (! $this->hasTable('pests')) {
            return [];
        }

        $cols = array_values(array_filter(['name', 'scientific_name', 'category'], fn ($c) => $this->hasColumn('pests', $c)));
        if (! in_array('name', $cols, true)) {
            $cols = array_merge(['name'], $cols);
        }

        $q = DB::table('pests');
        if ($this->hasColumn('pests', 'is_active')) {
            $q->where('is_active', true);
        }

        return $q->select($cols)
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name ?? null,
                'scientific_name' => $r->scientific_name ?? null,
                'category' => $r->category ?? null,
            ])
            ->all();
    }

    private function tableScalar(string $table, string $sql, array $bindings = []): float
    {
        if (! $this->hasTable($table)) {
            return 0.0;
        }
        $row = DB::selectOne($sql, $bindings);
        return (float) ($row->v ?? 0);
    }
}
