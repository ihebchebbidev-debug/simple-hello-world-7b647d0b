<?php

/**
 * GET /reports/harvest — grouping by plot, totals, and date filtering.
 */

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\HarvestOperation;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HarvestReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_harvests_by_plot_with_totals(): void
    {
        $this->actingAsRole('manager');

        $plotA = Plot::factory()->create(['name' => 'A']);
        $plotB = Plot::factory()->create(['name' => 'B']);

        HarvestOperation::factory()->for($plotA)->state([
            'operation_date' => '2026-04-10', 'num_workers' => 3, 'days_worked' => 2,
            'quantity_harvested' => 100, 'daily_rate_at_entry' => 50,
        ])->create();
        HarvestOperation::factory()->for($plotA)->state([
            'operation_date' => '2026-04-11', 'num_workers' => 4, 'days_worked' => 1,
            'quantity_harvested' => 80, 'daily_rate_at_entry' => 50,
        ])->create();
        HarvestOperation::factory()->for($plotB)->state([
            'operation_date' => '2026-04-12', 'num_workers' => 2, 'days_worked' => 1,
            'quantity_harvested' => 30, 'daily_rate_at_entry' => 50,
        ])->create();

        $plots = $this->getJson('/api/v1/reports/harvest')
            ->assertOk()->json('data.plots');

        $this->assertCount(2, $plots);
        $byName = collect($plots)->keyBy('plot_name');
        $this->assertEqualsWithDelta(180.0, $byName['A']['total_quantity'], 0.01);
        $this->assertEqualsWithDelta(10.0,  $byName['A']['total_worker_days'], 0.01); // 3*2 + 4*1
        $this->assertCount(2, $byName['A']['harvests']);
        $this->assertEqualsWithDelta(30.0,  $byName['B']['total_quantity'], 0.01);
    }

    public function test_filters_by_plot_id_and_date_range(): void
    {
        $this->actingAsRole('admin');

        $plotA = Plot::factory()->create();
        $plotB = Plot::factory()->create();

        HarvestOperation::factory()->for($plotA)->state([
            'operation_date' => '2026-03-15', 'num_workers' => 1, 'days_worked' => 1,
            'quantity_harvested' => 10, 'daily_rate_at_entry' => 1,
        ])->create();
        HarvestOperation::factory()->for($plotA)->state([
            'operation_date' => '2026-04-15', 'num_workers' => 1, 'days_worked' => 1,
            'quantity_harvested' => 20, 'daily_rate_at_entry' => 1,
        ])->create();
        HarvestOperation::factory()->for($plotB)->state([
            'operation_date' => '2026-04-15', 'num_workers' => 1, 'days_worked' => 1,
            'quantity_harvested' => 999, 'daily_rate_at_entry' => 1,
        ])->create();

        // ?plot_id= singular alias + ?from/?to aliases
        $plots = $this->getJson("/api/v1/reports/harvest?plot_id={$plotA->id}&from=2026-04-01&to=2026-04-30")
            ->assertOk()->json('data.plots');

        $this->assertCount(1, $plots);
        $this->assertCount(1, $plots[0]['harvests']);
        $this->assertEqualsWithDelta(20.0, $plots[0]['total_quantity'], 0.01);
    }

    public function test_technician_is_forbidden(): void
    {
        $this->actingAsRole('technician');
        $this->getJson('/api/v1/reports/harvest')->assertForbidden();
    }
}
