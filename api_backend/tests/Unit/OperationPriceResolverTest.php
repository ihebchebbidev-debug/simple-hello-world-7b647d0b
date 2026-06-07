<?php

/**
 * Verifies OperationPriceResolver picks the correct price_history row
 * for a given date, including effective_to windows and entity scoping.
 */

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Fertilizer;
use App\Models\PriceHistory;
use App\Models\WaterConfig;
use App\Support\OperationPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OperationPriceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_zero_when_no_price_row_exists(): void
    {
        $resolver = new OperationPriceResolver();

        $this->assertSame('0.0000', $resolver->priceFor('water', null, '2026-05-01'));
    }

    public function test_picks_most_recent_effective_row_for_water(): void
    {
        PriceHistory::factory()->water(1.50, '2026-01-01')->create();
        PriceHistory::factory()->water(2.00, '2026-04-01')->create();

        $resolver = new OperationPriceResolver();

        $this->assertSame('1.5000', $resolver->priceFor('water', null, '2026-03-31'));
        $this->assertSame('2.0000', $resolver->priceFor('water', null, '2026-04-15'));
    }

    public function test_respects_effective_to_window(): void
    {
        PriceHistory::factory()->water(1.50, '2026-01-01')->create([
            'effective_to' => '2026-03-31',
        ]);

        $resolver = new OperationPriceResolver();
        $this->assertSame('1.5000', $resolver->priceFor('water', null, '2026-03-15'));
        // Outside the window → no row matches → fallback "0.0000".
        $this->assertSame('0.0000', $resolver->priceFor('water', null, '2026-04-15'));
    }

    public function test_scopes_by_entity_id_for_fertilizers(): void
    {
        $a = Fertilizer::factory()->create();
        $b = Fertilizer::factory()->create();

        PriceHistory::factory()->fertilizer($a->id, 4.00, '2026-01-01')->create();
        PriceHistory::factory()->fertilizer($b->id, 9.00, '2026-01-01')->create();

        $resolver = new OperationPriceResolver();
        $this->assertSame('4.0000', $resolver->priceFor('fertilizer', $a->id, '2026-04-01'));
        $this->assertSame('9.0000', $resolver->priceFor('fertilizer', $b->id, '2026-04-01'));
    }

    public function test_active_water_unit_falls_back_to_m3(): void
    {
        $resolver = new OperationPriceResolver();
        $this->assertSame('m3', $resolver->activeWaterUnit());

        WaterConfig::factory()->create(['unit' => 'liters', 'is_active' => true]);
        $this->assertSame('liters', $resolver->activeWaterUnit());
    }
}
