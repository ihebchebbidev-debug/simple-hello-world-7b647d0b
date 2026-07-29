<?php

declare(strict_types=1);

namespace Tests\Unit\AiChat;

use App\Models\Pest;
use App\Services\AiChat\AiToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AiToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_catalog_matches_pest_category_synonym(): void
    {
        $registry = new AiToolRegistry();

        Pest::factory()->create([
            'name' => 'Mildiou',
            'scientific_name' => 'Plasmopara viticola',
            'category' => 'fungus',
        ]);

        Pest::factory()->create([
            'name' => 'Pucerons',
            'scientific_name' => 'Aphididae',
            'category' => 'insect',
        ]);

        $result = $registry->call('search_catalog', [
            'kind' => 'pest',
            'query' => 'champignons',
            'limit' => 20,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('pest', $result['kind']);
        $this->assertSame('champignons', $result['query']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('Mildiou', $result['results'][0]->name);
    }
}
