<?php

/**
 * Dashboard / KPI endpoints.
 *
 * Returns lightweight aggregates for the landing page — counts of plots
 * and operations, this-month totals, and the latest activity feed. Keeps
 * the mobile + admin shells from issuing many tiny queries.
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
        ]);
    }

    public function recentActivity(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 10), 50);

        $rows = collect();

        $rows = $rows->merge($this->fetch('irrigation_operations', 'irrigation', $limit));
        $rows = $rows->merge($this->fetch('fertilization_operations', 'fertilization', $limit));
        $rows = $rows->merge($this->fetch('phytosanitary_operations', 'phytosanitary', $limit));
        $rows = $rows->merge($this->fetch('harvest_operations', 'harvest', $limit));

        $sorted = $rows
            ->sortByDesc(fn ($r) => $r['operation_date'] . ' ' . $r['created_at'])
            ->take($limit)
            ->values()
            ->all();

        return ApiResponse::ok(['items' => $sorted]);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetch(string $table, string $type, int $limit): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table . ' as op')
            ->leftJoin('plots', 'plots.id', '=', 'op.plot_id')
            ->select([
                'op.id',
                'op.plot_id',
                'plots.name as plot_name',
                'op.operation_date',
                'op.created_at',
            ])
            ->orderByDesc('op.created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id'             => $r->id,
                'type'           => $type,
                'plot_id'        => $r->plot_id,
                'plot_name'      => $r->plot_name,
                'operation_date' => $r->operation_date,
                'created_at'     => $r->created_at,
            ])
            ->all();
    }
}
