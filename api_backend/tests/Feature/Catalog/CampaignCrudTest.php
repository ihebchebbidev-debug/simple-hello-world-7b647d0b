<?php

/**
 * Campaigns CRUD — role guards + happy-path coverage.
 */

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CampaignCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_paginated_campaigns_for_technician(): void
    {
        $this->actingAsRole('technician');
        Campaign::factory()->count(3)->create();

        $this->getJson('/api/v1/campaigns')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'total']]);
    }

    public function test_admin_can_create_update_and_deactivate(): void
    {
        $this->actingAsRole('admin');

        $created = $this->postJson('/api/v1/campaigns', [
            'name'       => 'Saison 2026',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
        ])->assertCreated()->json('data');

        $id = $created['id'];
        $this->assertSame('Saison 2026', $created['name']);
        $this->assertTrue($created['is_active']);

        $this->putJson("/api/v1/campaigns/{$id}", ['name' => 'Saison 2026 (renommée)'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Saison 2026 (renommée)');

        $this->deleteJson("/api/v1/campaigns/{$id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_unique_name_constraint_returns_422(): void
    {
        $this->actingAsRole('admin');
        Campaign::factory()->create(['name' => 'Already Taken']);

        $this->postJson('/api/v1/campaigns', [
            'name' => 'Already Taken', 'start_date' => '2026-01-01',
        ])->assertStatus(422);
    }

    public function test_technician_and_manager_cannot_mutate(): void
    {
        $payload = ['name' => 'X', 'start_date' => '2026-01-01'];

        $this->actingAsRole('manager');
        $this->postJson('/api/v1/campaigns', $payload)->assertForbidden();

        $this->actingAsRole('technician');
        $this->postJson('/api/v1/campaigns', $payload)->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/campaigns')->assertUnauthorized();
    }
}
