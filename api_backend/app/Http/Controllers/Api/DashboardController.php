<?php

/**
 * Dashboard / KPI endpoints.
 *
 * Returns lightweight aggregates for the landing page — counts of plots
 * and operations, this-month totals, and the latest activity feed.
 *
 * Performance notes
 * -----------------
 *  • `stats` and `recentActivity` are wrapped in a short-lived cache
 *    (CACHE_TTL seconds). Dashboards reload often; the underlying ops
 *    tables change at most a few times per minute, so a 30–60s cache
 *    cuts DB load by ~95% with no visible staleness.
 *  • `recentActivity` is one UNION ALL query ordered + limited at the
 *    database, instead of 4 queries × limit rows + a PHP sort.
 *  • Response gets `Cache-Control: private, max-age=…` so the browser
 *    avoids the round-trip entirely on quick re-renders.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DashboardController extends Controller
{
    private const CACHE_TTL = 60; // seconds

    public function stats(Request $request): JsonResponse
    {
        $payload = Cache::remember('dash:stats:v1', self::CACHE_TTL, function (): array {
            $monthStart = now()->startOfMonth()->toDateString();

            $waterThisMonth = (float) DB::table('irrigation_operations')
                ->where('operation_date', '>=', $monthStart)
                ->sum('water_quantity');

            $fertThisMonth = (float) DB::table('fertilization_operations')
                ->where('operation_date', '>=', $monthStart)
                ->sum('quantity_applied');

            $treatmentsThisMonth = (int) DB::table('phytosanitary_operations')
                ->where('operation_date', '>=', $monthStart)
                ->count();

            $harvestThisMonth = (float) DB::table('harvest_operations')
                ->where('operation_date', '>=', $monthStart)
                ->sum('quantity_harvested');

            $pendingPostings = Schema::hasTable('postings')
                ? (int) DB::table('postings')->whereIn('status', ['pending', 'failed'])->count()
                : 0;

            return [
                'counts' => [
                    'plots_active'       => (int) DB::table('plots')->where('is_active', true)->count(),
                    'fertilizers_active' => (int) DB::table('fertilizers')->where('is_active', true)->count(),
                    'pesticides_active'  => (int) DB::table('pesticides')->where('is_active', true)->count(),
                    'campaigns_active'   => Schema::hasTable('campaigns')
                        ? (int) DB::table('campaigns')->where('is_active', true)->count() : 0,
                    'pending_postings'   => $pendingPostings,
                ],
                'this_month' => [
                    'period_start'        => $monthStart,
                    'water_quantity'      => $waterThisMonth,
                    'fertilizer_quantity' => $fertThisMonth,
                    'treatments'          => $treatmentsThisMonth,
                    'harvest_quantity'    => $harvestThisMonth,
                ],
            ];
        });

        return ApiResponse::ok($payload)
            ->header('Cache-Control', 'private, max-age=' . self::CACHE_TTL);
    }

    public function recentActivity(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->query('limit', 10), 50));

        $items = Cache::remember(
            'dash:recent:v1:' . $limit,
            self::CACHE_TTL,
            fn (): array => $this->fetchRecent($limit),
        );

        return ApiResponse::ok(['items' => $items])
            ->header('Cache-Control', 'private, max-age=' . self::CACHE_TTL);
    }

    /**
     * Build the recent-activity feed in a single round-trip.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecent(int $limit): array
    {
        $tables = [
            ['irrigation_operations',    'irrigation'],
            ['fertilization_operations', 'fertilization'],
            ['phytosanitary_operations', 'phytosanitary'],
            ['harvest_operations',       'harvest'],
        ];

        $unions = [];
        foreach ($tables as [$table, $type]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            // Pre-LIMIT each branch so the planner can use the
            // (plot_id, operation_date) / created_at indexes per table
            // instead of materialising every row before the UNION.
            $unions[] = DB::table($table . ' as op')
                ->leftJoin('plots', 'plots.id', '=', 'op.plot_id')
                ->select([
                    'op.id',
                    DB::raw("'$type' as type"),
                    'op.plot_id',
                    'plots.name as plot_name',
                    'op.operation_date',
                    'op.created_at',
                ])
                ->orderByDesc('op.created_at')
                ->limit($limit);
        }

        if (empty($unions)) {
            return [];
        }

        $query = array_shift($unions);
        foreach ($unions as $u) {
            $query->unionAll($u);
        }

        return DB::query()
            ->fromSub($query, 'feed')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id'             => $r->id,
                'type'           => $r->type,
                'plot_id'        => $r->plot_id,
                'plot_name'      => $r->plot_name,
                'operation_date' => $r->operation_date,
                'created_at'     => $r->created_at,
            ])
            ->all();
    }
}
