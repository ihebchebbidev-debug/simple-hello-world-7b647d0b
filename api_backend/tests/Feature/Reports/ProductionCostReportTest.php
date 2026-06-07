<?php

/**
 * Production-cost report — guards the money math.
 *
 * Cost formulas (one row per plot for the selected window):
 *   irrigation      = SUM(water_quantity       * price_at_entry)
 *   fertilization   = SUM(quantity_applied     * price_at_entry)
 *   phytosanitary   = SUM(quantity_applied     * price_at_entry)
 *   harvest         = SUM(num_workers * days_worked * daily_rate_at_entry)
 *   total           = sum of the four above
 *
 * Prices frozen at entry (*_at_entry) are what's used, NOT today's config
 * — that's the whole reason those columns exist.
 */

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\FertilizationOperation;
use App\Models\Fertilizer;
use App\Models\HarvestOperation;
use App\Models\IrrigationOperation;
use App\Models\Pesticide;
use App\Models\PhytosanitaryOperation;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductionCostReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_plot_cost_breakdown_matches_the_spec_formulas(): void
    {
        $this->actingAsRole('manager');

        $plot      = Plot::factory()->withSurface(10.0)->create(['name' => 'Nord']);
        $fert      = Fertilizer::factory()->create();
        $pesticide = Pesticide::factory()->create();

        // Irrigation: 100 * 1.5 + 50 * 2.0 = 150 + 100 = 250
        IrrigationOperation::factory()->for($plot)->quantity(100)->priceAtEntry(1.5)->on('2026-03-10')->create();
        IrrigationOperation::factory()->for($plot)->quantity(50)->priceAtEntry(2.0)->on('2026-03-20')->create();

        // Fertilization: 40 * 2.0 = 80
        FertilizationOperation::factory()
            ->for($plot)->for($fert, 'fertilizer')
            ->state(['quantity_applied' => 40, 'price_at_entry' => 2.0, 'operation_date' => '2026-03-15'])
            ->create();

        // Phytosanitary: 2 * 50 = 100
        PhytosanitaryOperation::factory()
            ->for($plot)->for($pesticide, 'pesticide')
            ->state(['quantity_applied' => 2, 'price_at_entry' => 50.0, 'operation_date' => '2026-04-05'])
            ->create();

        // Harvest: 5 workers * 2 days * 20/day = 200
        HarvestOperation::factory()
            ->for($plot)
            ->state([
                'num_workers'         => 5,
                'days_worked'         => 2,
                'quantity_harvested'  => 800,
                'daily_rate_at_entry' => 20.0,
                'operation_date'      => '2026-07-20',
            ])
            ->create();

        $response = $this->getJson('/api/v1/reports/production-cost')->assertOk();

        $row = collect($response->json('data.plots'))->firstWhere('plot_id', $plot->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(250.0, $row['irrigation_cost'],    0.0001);
        $this->assertEqualsWithDelta( 80.0, $row['fertilization_cost'], 0.0001);
        $this->assertEqualsWithDelta(100.0, $row['phytosanitary_cost'], 0.0001);
        $this->assertEqualsWithDelta(200.0, $row['harvest_cost'],       0.0001);
        $this->assertEqualsWithDelta(630.0, $row['total_cost'],         0.0001); // 250+80+100+200
        $this->assertEqualsWithDelta( 63.0, $row['cost_per_ha'],        0.0001); // 630 / 10

        $this->assertEqualsWithDelta(630.0, $response->json('data.grand_total'), 0.0001);
    }

    public function test_uses_frozen_price_at_entry_not_todays_price(): void
    {
        // Re-price changes must NOT retroactively rewrite historical costs.
        $this->actingAsRole('manager');

        $plot = Plot::factory()->withSurface(1.0)->create();

        IrrigationOperation::factory()
            ->for($plot)->quantity(10)->priceAtEntry(1.0)->on('2026-03-10')->create();

        $response = $this->getJson('/api/v1/reports/production-cost')->assertOk();
        $row      = collect($response->json('data.plots'))->firstWhere('plot_id', $plot->id);

        // 10 * 1.0 = 10.0 regardless of any price_history row changes.
        $this->assertEqualsWithDelta(10.0, $row['irrigation_cost'], 0.0001);
    }
}
