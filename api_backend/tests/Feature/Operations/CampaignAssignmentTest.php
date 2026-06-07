<?php

/**
 * Operation CRUD — verifies that campaign_id is accepted on store and
 * actually persisted, so the /reports/* ?campaign_id= filter has data
 * to operate on. Covers all four operation types.
 */

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\Campaign;
use App\Models\Fertilizer;
use App\Models\Pesticide;
use App\Models\Plot;
use App\Models\PriceHistory;
use App\Models\WaterConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CampaignAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function seedPricing(): void
    {
        WaterConfig::factory()->create(['unit' => 'm3']);
        PriceHistory::factory()->water(1.5, '2026-01-01')->create();
        PriceHistory::factory()->labor(50.0, '2026-01-01')->create();
    }

    public function test_irrigation_persists_campaign_id(): void
    {
        $this->seedPricing();
        $this->actingAsRole('technician');
        $plot     = Plot::factory()->create();
        $campaign = Campaign::factory()->create();

        $body = $this->postJson('/api/v1/irrigation-operations', [
            'plot_id'        => $plot->id,
            'campaign_id'    => $campaign->id,
            'operation_date' => '2026-04-25',
            'water_quantity' => 12.50,
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('irrigation_operations', [
            'id'          => $body['id'],
            'campaign_id' => $campaign->id,
        ]);
    }

    public function test_fertilization_persists_campaign_id(): void
    {
        $this->seedPricing();
        $this->actingAsRole('technician');
        $plot       = Plot::factory()->create();
        $fertilizer = Fertilizer::factory()->create();
        $campaign   = Campaign::factory()->create();
        PriceHistory::factory()->fertilizer($fertilizer->id, 4.0, '2026-01-01')->create();

        $body = $this->postJson('/api/v1/fertilization-operations', [
            'plot_id'          => $plot->id,
            'campaign_id'      => $campaign->id,
            'fertilizer_id'    => $fertilizer->id,
            'operation_date'   => '2026-04-25',
            'quantity_applied' => 100.00,
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('fertilization_operations', [
            'id'          => $body['id'],
            'campaign_id' => $campaign->id,
        ]);
    }

    public function test_phytosanitary_persists_campaign_id(): void
    {
        $this->seedPricing();
        $this->actingAsRole('technician');
        $plot      = Plot::factory()->create();
        $pesticide = Pesticide::factory()->create();
        $campaign  = Campaign::factory()->create();
        PriceHistory::factory()->pesticide($pesticide->id, 6.0, '2026-01-01')->create();

        $body = $this->postJson('/api/v1/phytosanitary-operations', [
            'plot_id'          => $plot->id,
            'campaign_id'      => $campaign->id,
            'pesticide_id'     => $pesticide->id,
            'operation_date'   => '2026-04-25',
            'quantity_applied' => 5.00,
            'target_pest'      => 'Aphids',
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('phytosanitary_operations', [
            'id'          => $body['id'],
            'campaign_id' => $campaign->id,
        ]);
    }

    public function test_harvest_persists_campaign_id(): void
    {
        $this->seedPricing();
        $this->actingAsRole('technician');
        $plot     = Plot::factory()->create();
        $campaign = Campaign::factory()->create();

        $body = $this->postJson('/api/v1/harvest-operations', [
            'plot_id'            => $plot->id,
            'campaign_id'        => $campaign->id,
            'operation_date'     => '2026-04-25',
            'num_workers'        => 4,
            'days_worked'        => 1.50,
            'quantity_harvested' => 250.00,
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('harvest_operations', [
            'id'          => $body['id'],
            'campaign_id' => $campaign->id,
        ]);
    }

    public function test_invalid_campaign_id_is_rejected(): void
    {
        $this->seedPricing();
        $this->actingAsRole('technician');
        $plot = Plot::factory()->create();

        $this->postJson('/api/v1/irrigation-operations', [
            'plot_id'        => $plot->id,
            'campaign_id'    => '00000000-0000-0000-0000-000000000000',
            'operation_date' => '2026-04-25',
            'water_quantity' => 1.00,
        ])->assertStatus(422)
          ->assertJsonPath('error.code', 'validation_failed');
    }
}
