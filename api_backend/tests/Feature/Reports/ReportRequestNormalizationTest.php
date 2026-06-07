<?php

/**
 * Sanity-checks that the singular ?plot_id=, ?from=, ?to= aliases on the
 * report endpoints behave identically to the plural canonical form.
 */

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\IrrigationOperation;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReportRequestNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_singular_aliases_match_plural_canonical_form(): void
    {
        $this->actingAsRole('manager');
        $plot = Plot::factory()->withSurface(10.0)->create();

        IrrigationOperation::factory()->for($plot)->on('2026-03-10')->quantity(40)->create();
        IrrigationOperation::factory()->for($plot)->on('2026-04-10')->quantity(60)->create();

        $singular = $this->getJson("/api/v1/reports/irrigation?plot_id={$plot->id}&from=2026-03-01&to=2026-03-31")
            ->assertOk()->json('data.monthly');

        $plural = $this->getJson("/api/v1/reports/irrigation?plot_ids[]={$plot->id}&date_from=2026-03-01&date_to=2026-03-31")
            ->assertOk()->json('data.monthly');

        $this->assertSame($plural, $singular);
        $this->assertCount(1, $singular);
        $this->assertEqualsWithDelta(40.0, $singular[0]['total_quantity'], 0.0001);
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/v1/reports/irrigation?from=2026-04-30&to=2026-04-01')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }
}
