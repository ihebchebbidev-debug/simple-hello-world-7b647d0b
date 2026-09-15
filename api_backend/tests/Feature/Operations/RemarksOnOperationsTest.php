<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\Fertilizer;
use App\Models\Pesticide;
use App\Models\Plot;
use App\Models\PriceHistory;
use App\Models\WaterConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RemarksOnOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function seedPricing(): void
    {
        WaterConfig::factory()->create(['unit' => 'm3']);
        PriceHistory::factory()->water(1.5, '2026-01-01')->create();
        PriceHistory::factory()->labor(50.0, '2026-01-01')->create();
    }

    public function test_irrigation_fertilization_and_harvest_store_optional_remarks(): void
    {
        $this->seedPricing();
        $this->actingAsRole('technician');

        $plot = Plot::factory()->create();

        $this->postJson('/api/v1/irrigation-operations', [
            'plot_id'        => $plot->id,
            'operation_date' => '2026-04-25',
            'water_quantity' => 12.5,
            'remarks'        => 'Irrigation de vérification',
        ])->assertCreated();

        $fertilizer = Fertilizer::factory()->create();
        PriceHistory::factory()->fertilizer($fertilizer->id, 4.0, '2026-01-01')->create();

        $this->postJson('/api/v1/fertilization-operations', [
            'plot_id'          => $plot->id,
            'fertilizer_id'    => $fertilizer->id,
            'operation_date'   => '2026-04-26',
            'quantity_applied' => 100,
            'remarks'          => 'Fertilisation avant pluie',
        ])->assertCreated();

        $this->postJson('/api/v1/harvest-operations', [
            'plot_id'            => $plot->id,
            'operation_date'     => '2026-04-27',
            'num_workers'        => 4,
            'days_worked'        => 1.5,
            'quantity_harvested' => 250,
            'remarks'            => 'Récolte rapide',
        ])->assertCreated();

        $this->assertDatabaseHas('irrigation_operations', ['remarks' => 'Irrigation de vérification']);
        $this->assertDatabaseHas('fertilization_operations', ['remarks' => 'Fertilisation avant pluie']);
        $this->assertDatabaseHas('harvest_operations', ['remarks' => 'Récolte rapide']);
    }
}
