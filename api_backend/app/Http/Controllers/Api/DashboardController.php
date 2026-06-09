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

        return ApiResponse::ok([
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
