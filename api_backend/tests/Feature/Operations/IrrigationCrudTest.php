<?php

/**
 * Irrigation CRUD — happy-path and role-guard coverage for
 * /api/v1/irrigation-operations.
 *
 * Store route relies on OperationPriceResolver to freeze the water price
 * at entry, so tests seed price_history + water_config before POSTing.
 */

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\IrrigationOperation;
use App\Models\Plot;
use App\Models\PriceHistory;
use App\Models\WaterConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IrrigationCrudTest extends TestCase
{
    use RefreshDatabase;

    private function seedWaterPricing(float $price = 1.5): void
    {
        WaterConfig::factory()->create(['unit' => 'm3']);
        PriceHistory::factory()->water($price, '2026-01-01')->create();
    }

    public function test_index_is_paginated_and_filterable(): void
    {
        $this->actingAsRole('manager');

        $plot  = Plot::factory()->create();
        $other = Plot::factory()->create();

        IrrigationOperation::factory()->for($plot)->on('2026-03-10')->count(3)->create();
        IrrigationOperation::factory()->for($other)->on('2026-03-10')->create();
        IrrigationOperation::factory()->for($plot)->on('2026-01-05')->create();

        $res = $this->getJson("/api/v1/irrigation-operations?plot_id={$plot->id}&date_from=2026-02-01&date_to=2026-12-31")
            ->assertOk();

        $this->assertCount(3, $res->json('data'));
    }

    public function test_technician_can_create_and_price_is_frozen_from_price_history(): void
    {
        $this->seedWaterPricing(1.5);
        $this->actingAsRole('technician');

        $plot = Plot::factory()->create();

        $res = $this->postJson('/api/v1/irrigation-operations', [
            'plot_id'        => $plot->id,
            'operation_date' => '2026-03-15',
            'water_quantity' => 42.5,
        ])->assertCreated();

        $this->assertDatabaseHas('irrigation_operations', [
            'plot_id'        => $plot->id,
            'operation_date' => '2026-03-15',
            'water_quantity' => '42.50',
            'unit_at_entry'  => 'm3',
            'price_at_entry' => '1.5000',
        ]);

        $this->assertNotEmpty($res->json('data.id'));
    }

    public function test_manager_cannot_create_operations(): void
    {
        $this->actingAsRole('manager');

        $plot = Plot::factory()->create();

        $this->postJson('/api/v1/irrigation-operations', [
            'plot_id'        => $plot->id,
            'operation_date' => '2026-03-15',
            'water_quantity' => 10,
        ])->assertForbidden();
    }

    public function test_only_admin_can_update_or_delete(): void
    {
        $this->seedWaterPricing();

        $plot = Plot::factory()->create();
        $op   = IrrigationOperation::factory()->for($plot)->create();

        // Technician → 403 on update/delete.
        $this->actingAsRole('technician');
        $this->putJson("/api/v1/irrigation-operations/{$op->id}", ['water_quantity' => 99])->assertForbidden();
        $this->deleteJson("/api/v1/irrigation-operations/{$op->id}")->assertForbidden();

        // Admin → allowed.
        $this->actingAsRole('admin');
        $this->putJson("/api/v1/irrigation-operations/{$op->id}", ['water_quantity' => 99])->assertOk();
        $this->assertDatabaseHas('irrigation_operations', ['id' => $op->id, 'water_quantity' => '99.00']);

        $this->deleteJson("/api/v1/irrigation-operations/{$op->id}")->assertOk();
        $this->assertDatabaseMissing('irrigation_operations', ['id' => $op->id]);
    }

    public function test_store_validation_rejects_bad_payload(): void
    {
        $this->seedWaterPricing();
        $this->actingAsRole('technician');

        $this->postJson('/api/v1/irrigation-operations', [
            'plot_id'        => 'not-a-uuid',
            'operation_date' => 'yesterday',
            'water_quantity' => -5,
        ])->assertStatus(422);
    }
}
