<?php

/**
 * Aggregated reporting endpoints for the Manager & Admin dashboard.
 *
 * Filters: plot_ids[], campaign_id, date_from, date_to.
 *
 * Campaign filtering semantics (v4 — client feedback):
 *   When a campaign is selected the report scope = ops belonging to that
 *   campaign UNION ops without a campaign_id but whose operation_date
 *   falls inside the campaign's [start_date, end_date] window. This way
 *   legacy rows (created before the campaign FK was added) are still
 *   attributable to the active campaign by date.
 *
 * Cost calculations use the `price_at_entry` snapshot frozen at operation
 * creation time, falling back to the price_history row effective on
 * operation_date. The LATEST price is never used — price changes must not
 * retroactively alter historical costs.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ReportRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class ReportController extends Controller
{


    public function irrigation(ReportRequest $request): JsonResponse
    {
        [$plotIds, $campaignId, $from, $to, $campaign] = $this->commonFilters($request);
        [$effFrom, $effTo] = $this->effectiveRange($campaign, $from, $to);

        $applyCampaign = fn (Builder $q) => $this->applyCampaignScope($q, $campaignId, $campaign);

        $monthly = DB::table('irrigation_operations as op')
            ->join('plots', 'plots.id', '=', 'op.plot_id')
            ->select([
                'op.plot_id',
                'plots.name as plot_name',
                'plots.surface_area_ha',
                DB::raw('EXTRACT(YEAR  FROM op.operation_date)::int AS year'),
                DB::raw('EXTRACT(MONTH FROM op.operation_date)::int AS month'),
                DB::raw('SUM(op.water_quantity) AS total_quantity'),
            ])
            ->when($plotIds, fn ($q) => $q->whereIn('op.plot_id', $plotIds))
            ->when($effFrom, fn ($q) => $q->where('op.operation_date', '>=', $effFrom))
            ->when($effTo,   fn ($q) => $q->where('op.operation_date', '<=', $effTo))
            ->tap($applyCampaign)
            ->groupBy('op.plot_id', 'plots.name', 'plots.surface_area_ha', 'year', 'month')
            ->orderBy('op.plot_id')->orderBy('year')->orderBy('month')
            ->get()
            ->map(fn ($r) => [
                'plot_id'        => $r->plot_id,
                'plot_name'      => $r->plot_name,
                'year'           => $r->year,
                'month'          => $r->month,
                'total_quantity' => (float) $r->total_quantity,
                'per_hectare'    => $r->surface_area_ha > 0
                    ? round((float) $r->total_quantity / (float) $r->surface_area_ha, 4)
                    : null,
            ]);

        // Cumulative now respects the user's effective date window (campaign
        // dates OR explicit date_from/date_to). The old version locked the
        // lower bound to plots.season_start_date which silently truncated
        // P2's history to a single month — see client v4 remarks.
        $cumulative = DB::table('irrigation_operations as op')
            ->join('plots', 'plots.id', '=', 'op.plot_id')
            ->select([
                'op.plot_id',
                'plots.name as plot_name',
                'plots.surface_area_ha',
                DB::raw('SUM(op.water_quantity) AS total_quantity'),
            ])
            ->when($plotIds, fn ($q) => $q->whereIn('op.plot_id', $plotIds))
            ->when($effFrom, fn ($q) => $q->where('op.operation_date', '>=', $effFrom))
            ->when($effTo,   fn ($q) => $q->where('op.operation_date', '<=', $effTo))
            ->tap($applyCampaign)
            ->groupBy('op.plot_id', 'plots.name', 'plots.surface_area_ha')
            ->orderBy('plots.name')
            ->get()
            ->map(fn ($r) => [
                'plot_id'        => $r->plot_id,
                'plot_name'      => $r->plot_name,
                'surface_area_ha'=> (float) $r->surface_area_ha,
                'total_quantity' => (float) $r->total_quantity,
                'per_hectare'    => $r->surface_area_ha > 0
                    ? round((float) $r->total_quantity / (float) $r->surface_area_ha, 4)
                    : null,
                'since'          => $effFrom,
            ]);

        return ApiResponse::ok([
            'monthly'    => $monthly,
            'cumulative' => $cumulative,
            'filters'    => $this->filterSummary($plotIds, $campaignId, $effFrom, $effTo),
        ]);
    }

    public function fertilization(ReportRequest $request): JsonResponse
    {
        [$plotIds, $campaignId, $from, $to, $campaign] = $this->commonFilters($request);
        [$effFrom, $effTo] = $this->effectiveRange($campaign, $from, $to);

        $applyCampaign = fn (Builder $q) => $this->applyCampaignScope($q, $campaignId, $campaign);

        $monthly = DB::table('fertilization_operations as op')
            ->join('plots', 'plots.id', '=', 'op.plot_id')
            ->select([
                'op.plot_id',
                'plots.name as plot_name',
                'plots.surface_area_ha',
                DB::raw('EXTRACT(YEAR  FROM op.operation_date)::int AS year'),
                DB::raw('EXTRACT(MONTH FROM op.operation_date)::int AS month'),
                DB::raw('SUM(op.quantity_applied * op.n_at_entry / 100.0) AS total_n'),
                DB::raw('SUM(op.quantity_applied * op.p_at_entry / 100.0) AS total_p'),
                DB::raw('SUM(op.quantity_applied * op.k_at_entry / 100.0) AS total_k'),
            ])
            ->when($plotIds, fn ($q) => $q->whereIn('op.plot_id', $plotIds))
            ->when($effFrom, fn ($q) => $q->where('op.operation_date', '>=', $effFrom))
            ->when($effTo,   fn ($q) => $q->where('op.operation_date', '<=', $effTo))
            ->tap($applyCampaign)
            ->groupBy('op.plot_id', 'plots.name', 'plots.surface_area_ha', 'year', 'month')
            ->orderBy('op.plot_id')->orderBy('year')->orderBy('month')
            ->get()
            ->map(fn ($r) => [
                'plot_id'   => $r->plot_id,
                'plot_name' => $r->plot_name,
                'year'      => $r->year,
                'month'     => $r->month,
                'n_total'   => round((float) $r->total_n, 4),
                'p_total'   => round((float) $r->total_p, 4),
                'k_total'   => round((float) $r->total_k, 4),
                'n_per_ha'  => $r->surface_area_ha > 0
                    ? round((float) $r->total_n / (float) $r->surface_area_ha, 4) : null,
                'p_per_ha'  => $r->surface_area_ha > 0
                    ? round((float) $r->total_p / (float) $r->surface_area_ha, 4) : null,
                'k_per_ha'  => $r->surface_area_ha > 0
                    ? round((float) $r->total_k / (float) $r->surface_area_ha, 4) : null,
            ]);

        $cumulative = DB::table('fertilization_operations as op')
            ->join('plots', 'plots.id', '=', 'op.plot_id')
            ->select([
                'op.plot_id',
                'plots.name as plot_name',
                'plots.surface_area_ha',
                DB::raw('SUM(op.quantity_applied * op.n_at_entry / 100.0) AS total_n'),
                DB::raw('SUM(op.quantity_applied * op.p_at_entry / 100.0) AS total_p'),
                DB::raw('SUM(op.quantity_applied * op.k_at_entry / 100.0) AS total_k'),
            ])
            ->when($plotIds, fn ($q) => $q->whereIn('op.plot_id', $plotIds))
            ->when($effFrom, fn ($q) => $q->where('op.operation_date', '>=', $effFrom))
            ->when($effTo,   fn ($q) => $q->where('op.operation_date', '<=', $effTo))
            ->tap($applyCampaign)
            ->groupBy('op.plot_id', 'plots.name', 'plots.surface_area_ha')
            ->orderBy('plots.name')
            ->get()
            ->map(fn ($r) => [
                'plot_id'        => $r->plot_id,
                'plot_name'      => $r->plot_name,
                'surface_area_ha'=> (float) $r->surface_area_ha,
                'since'          => $effFrom,
                'n_per_ha'       => $r->surface_area_ha > 0
                    ? round((float) $r->total_n / (float) $r->surface_area_ha, 4) : null,
                'p_per_ha'       => $r->surface_area_ha > 0
                    ? round((float) $r->total_p / (float) $r->surface_area_ha, 4) : null,
                'k_per_ha'       => $r->surface_area_ha > 0
                    ? round((float) $r->total_k / (float) $r->surface_area_ha, 4) : null,
            ]);

        return ApiResponse::ok([
            'monthly'    => $monthly,
            'cumulative' => $cumulative,
            'filters'    => $this->filterSummary($plotIds, $campaignId, $effFrom, $effTo),
        ]);
    }

    public function phytosanitary(ReportRequest $request): JsonResponse
    {
        [$plotIds, $campaignId, $from, $to, $campaign] = $this->commonFilters($request);
        [$effFrom, $effTo] = $this->effectiveRange($campaign, $from, $to);
        $data    = $request->validated();
        $keyword = isset($data['q']) ? trim((string) $data['q']) : '';

        $applyCampaign = fn (Builder $q) => $this->applyCampaignScope($q, $campaignId, $campaign);

        $rows = DB::table('phytosanitary_operations as op')
            ->join('plots',     'plots.id',     '=', 'op.plot_id')
            ->join('pesticides','pesticides.id', '=', 'op.pesticide_id')
            ->select([
                'op.id',
                'op.plot_id',
                'plots.name as plot_name',
                'plots.surface_area_ha',
                'op.pesticide_id',
                'pesticides.name as pesticide_name',
                'pesticides.unit as pesticide_unit',
                'pesticides.chemical_composition',
                'op.operation_date',
                'op.quantity_applied',
                'op.water_volume_l',
                'op.target_pest',
                'op.remarks',
                'op.price_at_entry',
            ])
            ->when($plotIds, fn ($q) => $q->whereIn('op.plot_id', $plotIds))
            ->when($effFrom, fn ($q) => $q->where('op.operation_date', '>=', $effFrom))
            ->when($effTo,   fn ($q) => $q->where('op.operation_date', '<=', $effTo))
            ->tap($applyCampaign)
            ->when($keyword !== '', function ($q) use ($keyword) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('pesticides.name', 'ilike', $like)
                      ->orWhere('pesticides.chemical_composition', 'ilike', $like)
                      ->orWhere('op.target_pest', 'ilike', $like)
                      ->orWhere('op.remarks', 'ilike', $like)
                      ->orWhere('plots.name', 'ilike', $like);
                });
            })
            ->orderBy('plots.name')
            ->orderBy('op.operation_date', 'desc')
            ->get();

        $grouped = $rows->groupBy('plot_id')->map(function ($items, $plotId) {
            $first   = $items->first();
            $surface = (float) ($first->surface_area_ha ?? 0);

            return [
                'plot_id'         => $plotId,
                'plot_name'       => $first->plot_name,
                'surface_area_ha' => $surface,
                'treatments'      => $items->map(function ($r) use ($surface) {
                    $qty    = (float) $r->quantity_applied;
                    $volume = $r->water_volume_l !== null ? (float) $r->water_volume_l : null;

                    return [
                        'id'                   => $r->id,
                        'date'                 => $r->operation_date,
                        'pesticide_id'         => $r->pesticide_id,
                        'pesticide_name'       => $r->pesticide_name,
                        'pesticide_unit'       => $r->pesticide_unit,
                        'chemical_composition' => $r->chemical_composition,
                        'quantity_applied'     => $qty,
                        'pesticide_per_ha'     => $surface > 0 ? round($qty / $surface, 3) : null,
                        'water_volume_l'       => $volume,
                        'volume_per_ha'        => ($volume !== null && $surface > 0) ? round($volume / $surface, 3) : null,
                        'target_pest'          => $r->target_pest,
                        'remarks'              => $r->remarks,
                        'price_at_entry'       => (float) $r->price_at_entry,
                    ];
                })->values(),
            ];
        })->values();

        return ApiResponse::ok([
            'plots'   => $grouped,
            'filters' => $this->filterSummary($plotIds, $campaignId, $effFrom, $effTo)
                + ['q' => $keyword !== '' ? $keyword : null],
        ]);
    }

    public function harvest(ReportRequest $request): JsonResponse
    {
        [$plotIds, $campaignId, $from, $to, $campaign] = $this->commonFilters($request);
        [$effFrom, $effTo] = $this->effectiveRange($campaign, $from, $to);

        $applyCampaign = fn (Builder $q) => $this->applyCampaignScope($q, $campaignId, $campaign);

        $rows = DB::table('harvest_operations as op')
            ->join('plots', 'plots.id', '=', 'op.plot_id')
            ->select([
                'op.id',
                'op.plot_id',
                'plots.name as plot_name',
                'op.operation_date',
                'op.num_workers',
                'op.days_worked',
                'op.quantity_harvested',
                'op.daily_rate_at_entry',
            ])
            ->when($plotIds, fn ($q) => $q->whereIn('op.plot_id', $plotIds))
            ->when($effFrom, fn ($q) => $q->where('op.operation_date', '>=', $effFrom))
            ->when($effTo,   fn ($q) => $q->where('op.operation_date', '<=', $effTo))
            ->tap($applyCampaign)
            ->orderBy('plots.name')
            ->orderBy('op.operation_date', 'desc')
            ->get();

        $grouped = $rows->groupBy('plot_id')->map(fn ($items, $plotId) => [
            'plot_id'              => $plotId,
            'plot_name'            => $items->first()->plot_name,
            'total_quantity'       => round($items->sum(fn ($r) => (float) $r->quantity_harvested), 2),
            'total_worker_days'    => round($items->sum(fn ($r) => (float) $r->num_workers * (float) $r->days_worked), 2),
            'harvests'             => $items->map(fn ($r) => [
                'id'                  => $r->id,
                'date'                => $r->operation_date,
                'num_workers'         => (int) $r->num_workers,
                'days_worked'         => (float) $r->days_worked,
                'quantity_harvested'  => (float) $r->quantity_harvested,
                'daily_rate_at_entry' => (float) $r->daily_rate_at_entry,
                // Pre-computed man-days = workers * days. Client v4 wants a
                // single "Main d'œuvre (homme/jour)" column on the report.
                'worker_days'         => round((float) $r->num_workers * (float) $r->days_worked, 2),
            ])->values(),
        ])->values();

        return ApiResponse::ok([
            'plots'   => $grouped,
            'filters' => $this->filterSummary($plotIds, $campaignId, $effFrom, $effTo),
        ]);
    }

    public function productionCost(ReportRequest $request): JsonResponse
    {
        [$plotIds, $campaignId, $from, $to, $campaign] = $this->commonFilters($request);
        [$effFrom, $effTo] = $this->effectiveRange($campaign, $from, $to);

        $applyCampaign = fn (Builder $q) => $this->applyCampaignScope($q, $campaignId, $campaign);

        $plots = DB::table('plots')
            ->when($plotIds, fn ($q) => $q->whereIn('id', $plotIds))
            ->select(['id', 'name', 'surface_area_ha'])
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        // Use the price that was active at the time the operation was recorded
        // (price_at_entry), falling back to the price_history row whose
        // effective_from <= operation_date only when price_at_entry is absent.
        // Never use the LATEST price — changing a price today must not alter
        // historical costs from 2024 or any prior period.
        // Final COALESCE arm: the EARLIEST known price for the entity. It only
        // ever applies to operations dated *before* the first price_history row
        // exists for that entity — without it those operations resolve to 0 and
        // silently vanish from cost totals (client bug: P6/B12 water under-
        // counted). Operations that DO have an applicable historical price keep
        // using it, so changing a price today still never rewrites past costs.
        $priceLookup = fn (string $entityType, string $entityFkColumn) => '(
            COALESCE(
                NULLIF(op.price_at_entry, 0),
                (SELECT ph.price_per_unit FROM price_history ph
                 WHERE ph.entity_type = '."'$entityType'".'
                   AND ph.entity_id = op.'.$entityFkColumn.'
                   AND ph.effective_from <= op.operation_date
                 ORDER BY ph.effective_from DESC, ph.id DESC LIMIT 1),
                (SELECT ph.price_per_unit FROM price_history ph
                 WHERE ph.entity_type = '."'$entityType'".'
                   AND ph.entity_id = op.'.$entityFkColumn.'
                 ORDER BY ph.effective_from ASC, ph.id ASC LIMIT 1)
            )
        )';

        $globalPriceLookup = fn (string $entityType, string $fallbackColumn = 'price_at_entry') => '(
            COALESCE(
                NULLIF(op.'.$fallbackColumn.', 0),
                (SELECT ph.price_per_unit FROM price_history ph
                 WHERE ph.entity_type = '."'$entityType'".'
                   AND ph.effective_from <= op.operation_date
                 ORDER BY ph.effective_from DESC, ph.id DESC LIMIT 1),
                (SELECT ph.price_per_unit FROM price_history ph
                 WHERE ph.entity_type = '."'$entityType'".'
                 ORDER BY ph.effective_from ASC, ph.id ASC LIMIT 1)
            )
        )';

        $applyAll = function (Builder $q) use ($plotIds, $effFrom, $effTo, $applyCampaign) {
            return $q
                ->when($plotIds, fn ($qq) => $qq->whereIn('op.plot_id', $plotIds))
                ->when($effFrom, fn ($qq) => $qq->where('op.operation_date', '>=', $effFrom))
                ->when($effTo,   fn ($qq) => $qq->where('op.operation_date', '<=', $effTo))
                ->tap($applyCampaign);
        };

        $irrigationCosts = $applyAll(DB::table('irrigation_operations as op')
            ->select(['op.plot_id', DB::raw('SUM(op.water_quantity * '.$globalPriceLookup('water').') AS cost')]))
            ->groupBy('op.plot_id')->get()->keyBy('plot_id');

        $fertilizationCosts = $applyAll(DB::table('fertilization_operations as op')
            ->select(['op.plot_id', DB::raw('SUM(op.quantity_applied * '.$priceLookup('fertilizer', 'fertilizer_id').') AS cost')]))
            ->groupBy('op.plot_id')->get()->keyBy('plot_id');

        $phytosanitaryCosts = $applyAll(DB::table('phytosanitary_operations as op')
            ->select(['op.plot_id', DB::raw('SUM(op.quantity_applied * '.$priceLookup('pesticide', 'pesticide_id').') AS cost')]))
            ->groupBy('op.plot_id')->get()->keyBy('plot_id');

        $harvestCosts = $applyAll(DB::table('harvest_operations as op')
            ->select(['op.plot_id', DB::raw('SUM(op.num_workers * op.days_worked * '.$globalPriceLookup('labor', 'daily_rate_at_entry').') AS cost')]))
            ->groupBy('op.plot_id')->get()->keyBy('plot_id');

        $rows = $plots->map(function ($plot) use ($irrigationCosts, $fertilizationCosts, $phytosanitaryCosts, $harvestCosts) {
            $id  = $plot->id;
            $irr = (float) ($irrigationCosts[$id]->cost ?? 0);
            $fer = (float) ($fertilizationCosts[$id]->cost ?? 0);
            $phy = (float) ($phytosanitaryCosts[$id]->cost ?? 0);
            $har = (float) ($harvestCosts[$id]->cost ?? 0);
            $tot = $irr + $fer + $phy + $har;
            $ha  = (float) $plot->surface_area_ha;

            return [
                'plot_id'              => $id,
                'plot_name'            => $plot->name,
                'surface_area_ha'      => $ha,
                'irrigation_cost'      => round($irr, 3),
                'fertilization_cost'   => round($fer, 3),
                'phytosanitary_cost'   => round($phy, 3),
                'harvest_cost'         => round($har, 3),
                'total_cost'           => round($tot, 3),
                'cost_per_ha'          => $ha > 0 ? round($tot / $ha, 3) : null,
            ];
        })->values();

        return ApiResponse::ok([
            'plots'       => $rows,
            'grand_total' => round($rows->sum('total_cost'), 3),
            'filters'     => $this->filterSummary($plotIds, $campaignId, $effFrom, $effTo),
        ]);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * @return array{0: array<int,string>|null, 1: ?string, 2: ?string, 3: ?string, 4: ?object}
     */
    private function commonFilters(ReportRequest $request): array
    {
        $data = $request->validated();
        $campaignId = $data['campaign_id'] ?? null;
        $campaign   = $campaignId
            ? DB::table('campaigns')
                ->where('id', $campaignId)
                ->select(['id', 'start_date', 'end_date'])
                ->first()
            : null;

        return [
            $data['plot_ids']    ?? null,
            $campaignId,
            $data['date_from']   ?? null,
            $data['date_to']     ?? null,
            $campaign,
        ];
    }

    /**
     * Effective date range for the outer WHERE.
     *
     * v5 fix (client: "Filtre campagne ne marche pas"): when the user
     * selects a campaign WITHOUT explicit dates, do NOT clamp the outer
     * query to the campaign window. The scoping is handled by
     * applyCampaignScope() which already accepts (campaign_id = X) and,
     * as a legacy fallback, (campaign_id IS NULL AND date in window).
     * Clamping the outer query was excluding ops with campaign_id = X
     * whose operation_date drifted outside the campaign window.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function effectiveRange(?object $campaign, ?string $from, ?string $to): array
    {
        return [$from ?: null, $to ?: null];
    }


    /**
     * Scope an operation query to a campaign. We accept rows where
     * campaign_id matches OR (campaign_id IS NULL AND operation_date is
     * inside the campaign window) — so historic ops without a FK still
     * count for the campaign that owns their date.
     */
    private function applyCampaignScope(Builder $query, ?string $campaignId, ?object $campaign): Builder
    {
        if (! $campaignId || ! $campaign) {
            return $query;
        }

        return $query->where(function (Builder $w) use ($campaignId, $campaign) {
            $w->where('op.campaign_id', $campaignId)
              ->orWhere(function (Builder $ww) use ($campaign) {
                  $ww->whereNull('op.campaign_id')
                     ->where('op.operation_date', '>=', $campaign->start_date)
                     ->where('op.operation_date', '<=', $campaign->end_date);
              });
        });
    }

    /** @param array<int,string>|null $plotIds */
    private function filterSummary(?array $plotIds, ?string $campaignId, ?string $from, ?string $to): array
    {
        return [
            'date_from'   => $from,
            'date_to'     => $to,
            'plot_ids'    => $plotIds,
            'campaign_id' => $campaignId,
        ];
    }
}
