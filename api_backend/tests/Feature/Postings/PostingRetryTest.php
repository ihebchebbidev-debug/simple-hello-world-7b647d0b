<?php

/**
 * POST /postings/{id}/retry — admin only, recovers from failed dispatches
 * and bumps retry_count without breaking idempotency.
 */

declare(strict_types=1);

namespace Tests\Feature\Postings;

use App\Models\Plot;
use App\Models\Posting;
use App\Models\PriceHistory;
use App\Models\WaterConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PostingRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_re_dispatches_a_failed_posting_and_increments_retry_count(): void
    {
        WaterConfig::factory()->create(['unit' => 'm3']);
        PriceHistory::factory()->water(1.5, '2026-01-01')->create();

        $this->actingAsRole('admin');
        $plot = Plot::factory()->create();

        $posting = Posting::create([
            'id'             => (string) Str::uuid(),
            'client_id'      => (string) Str::uuid(),
            'operation_type' => 'irrigation',
            'payload'        => [
                'plot_id'        => $plot->id,
                'operation_date' => '2026-04-25',
                'water_quantity' => 12,
            ],
            'status'         => 'failed',
            'retry_count'    => 0,
            'submitted_at'   => now(),
        ]);

        $body = $this->postJson("/api/v1/postings/{$posting->id}/retry")
            ->assertOk()->json('data');

        $this->assertSame('synced', $body['status']);
        $this->assertSame(1, $body['retry_count']);
        $this->assertDatabaseCount('irrigation_operations', 1);
    }

    public function test_retry_rejects_already_synced_postings(): void
    {
        $this->actingAsRole('admin');

        $posting = Posting::create([
            'id'             => (string) Str::uuid(),
            'client_id'      => (string) Str::uuid(),
            'operation_type' => 'irrigation',
            'payload'        => ['plot_id' => (string) Str::uuid(), 'water_quantity' => 1],
            'status'         => 'synced',
            'submitted_at'   => now(),
            'synced_at'      => now(),
        ]);

        $this->postJson("/api/v1/postings/{$posting->id}/retry")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'already_synced');
    }

    public function test_retry_requires_admin(): void
    {
        $this->actingAsRole('technician');
        $posting = Posting::create([
            'id'             => (string) Str::uuid(),
            'client_id'      => (string) Str::uuid(),
            'operation_type' => 'irrigation',
            'payload'        => [],
            'status'         => 'failed',
            'submitted_at'   => now(),
        ]);

        $this->postJson("/api/v1/postings/{$posting->id}/retry")->assertForbidden();
    }
}
