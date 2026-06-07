<?php

/**
 * Verifies the new ?campaign_id= filter works on every report endpoint.
 */

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Campaign;
use App\Models\Fertilizer;
use App\Models\FertilizationOperation;
use App\Models\HarvestOperation;
use App\Models\IrrigationOperation;
use App\Models\Pesticide;
use App\Models\PhytosanitaryOperation;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReportsCampaignFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_irrigation_report_filters_by_campaign_id(): void
    {
        $this->actingAsRole('manager');

        $plot       = Plot::factory()->withSurface(10.0)->seasonStart('2026-01-01')->create();
        $campaignA  = Campaign::factory()->create();
        $campaignB  = Campaign::factory()->create();

        IrrigationOperation::factory()->for($plot)->on('2026-03-10')->quantity(40)
            ->state(['campaign_id' => $campaignA->id])->create();
        IrrigationOperation::factory()->for($plot)->on('2026-03-11')->quantity(60)
            ->state(['campaign_id' => $campaignB->id])->create();

        $monthly = collect(
            $this->getJson("/api/v1/reports/irrigation?campaign_id={$campaignA->id}")
                ->assertOk()->json('data.monthly')
        );

        $this->assertCount(1, $monthly);
        $this->assertEqualsWithDelta(40.0, $monthly->first()['total_quantity'], 0.0001);
    }

    public function test_fertilization_report_filters_by_campaign_id(): void
    {
        $this->actingAsRole('admin');

        $plot      = Plot::factory()->withSurface(10.0)->create();
        $fert      = Fertilizer::factory()->create();
        $campaign  = Campaign::factory()->create();

        FertilizationOperation::factory()->for($plot)->for($fert, 'fertilizer')
            ->state(['operation_date' => '2026-03-10', 'quantity_applied' => 100,
                     'n_at_entry' => 20, 'p_at_entry' => 10, 'k_at_entry' => 5,
                     'price_at_entry' => 1.0, 'campaign_id' => $campaign->id])->create();

        FertilizationOperation::factory()->for($plot)->for($fert, 'fertilizer')
            ->state(['operation_date' => '2026-03-15', 'quantity_applied' => 9999,
                     'n_at_entry' => 50, 'p_at_entry' => 50, 'k_at_entry' => 50,
                     'price_at_entry' => 1.0, 'campaign_id' => null])->create();

        $monthly = collect(
            $this->getJson("/api/v1/reports/fertilization?campaign_id={$campaign->id}")
                ->assertOk()->json('data.monthly')
        );
        $this->assertCount(1, $monthly);
        $this->assertEqualsWithDelta(20.0, $monthly->first()['n_total'], 0.0001);
    }

    public function test_phytosanitary_report_filters_by_campaign_id(): void
    {
        $this->actingAsRole('manager');
        $plot      = Plot::factory()->create();
        $pest      = Pesticide::factory()->create();
        $campaign  = Campaign::factory()->create();

        PhytosanitaryOperation::factory()->for($plot)->for($pest, 'pesticide')
            ->state(['operation_date' => '2026-03-10', 'quantity_applied' => 1, 'price_at_entry' => 1.0,
                     'campaign_id' => $campaign->id])->create();
        PhytosanitaryOperation::factory()->for($plot)->for($pest, 'pesticide')
            ->state(['operation_date' => '2026-03-12', 'quantity_applied' => 1, 'price_at_entry' => 1.0])->create();

        $plots = $this->getJson("/api/v1/reports/phytosanitary?campaign_id={$campaign->id}")
            ->assertOk()->json('data.plots');

        $this->assertCount(1, $plots);
        $this->assertCount(1, $plots[0]['treatments']);
    }

    public function test_harvest_and_production_cost_filter_by_campaign_id(): void
    {
        $this->actingAsRole('admin');
        $plot      = Plot::factory()->withSurface(10.0)->create();
        $campaign  = Campaign::factory()->create();

        HarvestOperation::factory()->for($plot)
            ->state(['operation_date' => '2026-04-10', 'num_workers' => 5, 'days_worked' => 1,
                     'quantity_harvested' => 100, 'daily_rate_at_entry' => 50, 'campaign_id' => $campaign->id])
            ->create();

        HarvestOperation::factory()->for($plot)
            ->state(['operation_date' => '2026-04-11', 'num_workers' => 5, 'days_worked' => 1,
                     'quantity_harvested' => 9999, 'daily_rate_at_entry' => 50])
            ->create();

        $harvest = $this->getJson("/api/v1/reports/harvest?campaign_id={$campaign->id}")
            ->assertOk()->json('data.plots');
        $this->assertCount(1, $harvest);
        $this->assertEqualsWithDelta(100.0, $harvest[0]['total_quantity'], 0.01);

        $cost = $this->getJson("/api/v1/reports/production-cost?campaign_id={$campaign->id}")
            ->assertOk()->json('data.plots');
        // num_workers * days_worked * daily_rate = 5 * 1 * 50 = 250
        $this->assertEqualsWithDelta(250.0, $cost[0]['harvest_cost'], 0.01);
    }
}
