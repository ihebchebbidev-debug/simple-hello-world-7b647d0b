<?php

/**
 * Dashboard / KPI endpoints.
 *
 * Returns lightweight aggregates for the landing page — counts of plots
 * and operations, this-month totals, and the latest activity feed.
 *
 * No response caching: the client needs to see new entries instantly
 * after a technician posts one. Perf is improved purely by reducing
 * work at the database layer (single UNION ALL for the activity feed
 * instead of 4 queries + a PHP sort).
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $monthStart = now()->startOfMonth()->toDateString();

        // Single round-trip: all 9 aggregates rolled into one SELECT using
        // FILTER (...) clauses. Postgres only scans each table once instead
        // of 9 sequential queries, which is the biggest dashboard win.
        $hasCampaigns = Schema::hasTable('campaigns');
        $hasPostings  = Schema::hasTable('postings');

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
            [$monthStart, $monthStart, $monthStart, $monthStart]
        );

        return ApiResponse::ok([
            'counts' => [
                'plots_active'       => (int) $row->plots_active,
                'fertilizers_active' => (int) $row->fertilizers_active,
                'pesticides_active'  => (int) $row->pesticides_active,
                'campaigns_active'   => (int) $row->campaigns_active,
                'pending_postings'   => (int) $row->pending_postings,
            ],
            'this_month' => [
                'period_start'        => $monthStart,
                'water_quantity'      => (float) $row->water_q,
                'fertilizer_quantity' => (float) $row->fert_q,
                'treatments'          => (int)   $row->phyto_n,
                'harvest_quantity'    => (float) $row->harvest_q,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function recentActivity(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->query('limit', 10), 50));

        return ApiResponse::ok(['items' => $this->fetchRecent($limit)])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Build the recent-activity feed in a single round-trip.
     *
     * Each branch is pre-LIMITed so the planner uses the per-table
     * (plot_id, operation_date) / created_at indexes instead of
     * materialising every row before the UNION.
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
