<?php

/**
 * Dashboard — KPI counts, this-month totals, recent activity.
 */

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Fertilizer;
use App\Models\FertilizationOperation;
use App\Models\HarvestOperation;
use App\Models\IrrigationOperation;
use App\Models\Pesticide;
use App\Models\PhytosanitaryOperation;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_returns_counts_and_this_month_totals(): void
    {
        $this->actingAsRole('manager');

        $plot      = Plot::factory()->create();
        $fert      = Fertilizer::factory()->create();
        $pesticide = Pesticide::factory()->create();

        $today = now()->toDateString();

        IrrigationOperation::factory()->for($plot)->on($today)->quantity(50)->create();
        FertilizationOperation::factory()->for($plot)->for($fert, 'fertilizer')
            ->state(['operation_date' => $today, 'quantity_applied' => 30,
                'n_at_entry' => 15, 'p_at_entry' => 15, 'k_at_entry' => 15, 'price_at_entry' => 1.0])->create();
        PhytosanitaryOperation::factory()->for($plot)->for($pesticide, 'pesticide')
            ->state(['operation_date' => $today, 'quantity_applied' => 2, 'price_at_entry' => 5.0])->create();
        HarvestOperation::factory()->for($plot)
            ->state(['operation_date' => $today, 'num_workers' => 10, 'days_worked' => 1, 'quantity_harvested' => 200, 'daily_rate_at_entry' => 50])->create();

        $res = $this->getJson('/api/v1/dashboard/stats')->assertOk()->json('data');

        $this->assertGreaterThanOrEqual(1, $res['counts']['plots_active']);
        $this->assertSame(50.0,  (float) $res['this_month']['water_quantity']);
        $this->assertSame(30.0,  (float) $res['this_month']['fertilizer_quantity']);
        $this->assertSame(1,     (int)   $res['this_month']['treatments']);
        $this->assertSame(200.0, (float) $res['this_month']['harvest_quantity']);
    }

    public function test_recent_activity_aggregates_operations(): void
    {
        $this->actingAsRole('admin');

        $plot = Plot::factory()->create();
        IrrigationOperation::factory()->for($plot)->on('2026-04-10')->create();
        IrrigationOperation::factory()->for($plot)->on('2026-04-11')->create();

        $items = $this->getJson('/api/v1/dashboard/recent-activity?limit=5')
            ->assertOk()
            ->json('data.items');

        $this->assertNotEmpty($items);
        $this->assertSame('irrigation', $items[0]['type']);
        $this->assertSame($plot->id, $items[0]['plot_id']);
    }

    public function test_unauthenticated_requests_rejected(): void
    {
        $this->getJson('/api/v1/dashboard/stats')->assertUnauthorized();
    }
}
