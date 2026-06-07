<?php

/**
 * Pests CRUD — role guards + happy path + filter coverage.
 */

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Pest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PestCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_by_category_and_search(): void
    {
        $this->actingAsRole('technician');

        Pest::factory()->create(['name' => 'Mildiou', 'category' => 'fungus']);
        Pest::factory()->create(['name' => 'Pucerons', 'category' => 'insect']);
        Pest::factory()->create(['name' => 'Chiendent', 'category' => 'weed']);

        $byCat = $this->getJson('/api/v1/pests?category=fungus')->assertOk()->json('data');
        $this->assertCount(1, $byCat);
        $this->assertSame('Mildiou', $byCat[0]['name']);

        $bySearch = $this->getJson('/api/v1/pests?search=puc')->assertOk()->json('data');
        $this->assertCount(1, $bySearch);
        $this->assertSame('Pucerons', $bySearch[0]['name']);
    }

    public function test_admin_can_create_update_destroy(): void
    {
        $this->actingAsRole('admin');

        $created = $this->postJson('/api/v1/pests', [
            'name'            => 'Botrytis',
            'scientific_name' => 'Botrytis cinerea',
            'category'        => 'fungus',
        ])->assertCreated()->json('data');

        $this->putJson("/api/v1/pests/{$created['id']}", ['description' => 'Updated'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Updated');

        $this->deleteJson("/api/v1/pests/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_non_admin_cannot_mutate(): void
    {
        $this->actingAsRole('manager');
        $this->postJson('/api/v1/pests', ['name' => 'X'])->assertForbidden();

        $this->actingAsRole('technician');
        $this->postJson('/api/v1/pests', ['name' => 'X'])->assertForbidden();
    }

    public function test_unauthenticated_request_rejected(): void
    {
        $this->getJson('/api/v1/pests')->assertUnauthorized();
    }
}
