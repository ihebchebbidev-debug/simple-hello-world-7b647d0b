<?php

/**
 * Bulk postings sync — idempotency and per-item outcome reporting.
 */

declare(strict_types=1);

namespace Tests\Feature\Postings;

use App\Models\Plot;
use App\Models\PriceHistory;
use App\Models\WaterConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BulkPostingTest extends TestCase
{
    use RefreshDatabase;

    private function seedPricing(): void
    {
        WaterConfig::factory()->create(['unit' => 'm3']);
        PriceHistory::factory()->water(1.5, '2026-01-01')->create();
    }

    public function test_bulk_store_processes_multiple_postings(): void
    {
        $this->seedPricing();
        $this->actingAsRole('technician');
        $plot = Plot::factory()->create();

        $payload = [
            'postings' => [
                [
                    'client_id'      => (string) Str::uuid(),
                    'operation_type' => 'irrigation',
                    'payload'        => ['plot_id' => $plot->id, 'operation_date' => '2026-04-25', 'water_quantity' => 10],
                ],
                [
                    'client_id'      => (string) Str::uuid(),
                    'operation_type' => 'irrigation',
                    'payload'        => ['plot_id' => $plot->id, 'operation_date' => '2026-04-26', 'water_quantity' => 12],
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/postings/bulk', $payload)->assertOk()->json('data');

        $this->assertSame(2, $res['summary']['total']);
        $this->assertSame(2, $res['summary']['synced']);
        $this->assertSame(0, $res['summary']['failed']);
        $this->assertCount(2, $res['postings']);
        $this->assertDatabaseCount('irrigation_operations', 2);
    }

    public function test_bulk_store_is_idempotent_per_client_id(): void
    {
        $this->seedPricing();
        $this->actingAsRole('admin');
        $plot = Plot::factory()->create();

        $clientId = (string) Str::uuid();
        $body     = [
            'postings' => [[
                'client_id'      => $clientId,
                'operation_type' => 'irrigation',
                'payload'        => ['plot_id' => $plot->id, 'operation_date' => '2026-04-25', 'water_quantity' => 10],
            ]],
        ];

        $this->postJson('/api/v1/postings/bulk', $body)->assertOk();
        $this->postJson('/api/v1/postings/bulk', $body)->assertOk(); // replay

        $this->assertDatabaseCount('postings', 1);
        $this->assertDatabaseCount('irrigation_operations', 1);
    }

    public function test_validation_rejects_duplicate_client_ids_in_same_batch(): void
    {
        $this->seedPricing();
        $this->actingAsRole('technician');
        $plot = Plot::factory()->create();
        $dup  = (string) Str::uuid();

        $this->postJson('/api/v1/postings/bulk', [
            'postings' => [
                ['client_id' => $dup, 'operation_type' => 'irrigation',
                 'payload' => ['plot_id' => $plot->id, 'operation_date' => '2026-04-25', 'water_quantity' => 1]],
                ['client_id' => $dup, 'operation_type' => 'irrigation',
                 'payload' => ['plot_id' => $plot->id, 'operation_date' => '2026-04-26', 'water_quantity' => 2]],
            ],
        ])->assertStatus(422);
    }

    public function test_manager_cannot_bulk_post(): void
    {
        $this->actingAsRole('manager');
        $this->postJson('/api/v1/postings/bulk', ['postings' => []])->assertForbidden();
    }
}
