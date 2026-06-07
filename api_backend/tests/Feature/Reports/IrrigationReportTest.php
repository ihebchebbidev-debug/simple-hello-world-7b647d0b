<?php

/**
 * Irrigation report — verifies the three aggregates required by the
 * spec (milestone M2):
 *
 *   1. Monthly water quantity per hectare (qty / plot surface area), grouped by month
 *   2. Cumulative water since each plot's season_start_date
 *   3. Total water per plot up to today (monthly block without date filters)
 *
 * Also covers the filter contract documented in docs/Features-Spec.md:
 *   - singular ?plot_id= and ?from=/?to= (spec aliases)
 *   - plural ?plot_ids[]= and ?date_from=/?date_to= (canonical keys)
 *   - role guard: technicians are forbidden, managers and admins allowed.
 */

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\IrrigationOperation;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IrrigationReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Monthly block:
     *  - groups by (plot, year, month)
     *  - total_quantity is SUM(water_quantity) for the bucket
     *  - per_hectare  = total_quantity / plots.surface_area_ha
     */
    public function test_monthly_grouping_and_per_hectare(): void
    {
        $this->actingAsRole('manager');

        $plot = Plot::factory()
            ->withSurface(10.0)
            ->seasonStart('2026-01-01')
            ->create(['name' => 'Nord']);

        // March bucket: 100 + 50 = 150 m³
        IrrigationOperation::factory()->for($plot)->on('2026-03-10')->quantity(100)->create();
        IrrigationOperation::factory()->for($plot)->on('2026-03-20')->quantity(50)->create();

        // April bucket: 80 m³
        IrrigationOperation::factory()->for($plot)->on('2026-04-05')->quantity(80)->create();

        $response = $this->getJson('/api/v1/reports/irrigation');
        $response->assertOk();

        $monthly = collect($response->json('data.monthly'));

        $march = $monthly->firstWhere(fn ($r) => $r['month'] === 3 && $r['plot_id'] === $plot->id);
        $april = $monthly->firstWhere(fn ($r) => $r['month'] === 4 && $r['plot_id'] === $plot->id);

        $this->assertNotNull($march);
        $this->assertNotNull($april);

        $this->assertEqualsWithDelta(150.0, $march['total_quantity'], 0.0001);
        $this->assertEqualsWithDelta(15.0,  $march['per_hectare'],    0.0001); // 150 / 10

        $this->assertEqualsWithDelta(80.0, $april['total_quantity'], 0.0001);
        $this->assertEqualsWithDelta(8.0,  $april['per_hectare'],    0.0001); // 80 / 10
    }

    /**
     * Cumulative block sums only entries on-or-after each plot's
     * season_start_date, regardless of any ?from= filter.
     */
    public function test_cumulative_respects_plot_season_start_date(): void
    {
        $this->actingAsRole('admin');

        $plot = Plot::factory()
            ->withSurface(5.0)
            ->seasonStart('2026-02-01')
            ->create(['name' => 'Sud']);

        // Before season start — MUST be excluded from cumulative.
        IrrigationOperation::factory()->for($plot)->on('2026-01-15')->quantity(999)->create();

        // After season start — counted.
        IrrigationOperation::factory()->for($plot)->on('2026-02-10')->quantity(30)->create();
        IrrigationOperation::factory()->for($plot)->on('2026-03-10')->quantity(20)->create();

        $response = $this->getJson('/api/v1/reports/irrigation')->assertOk();

        $row = collect($response->json('data.cumulative'))
            ->firstWhere('plot_id', $plot->id);

        $this->assertNotNull($row);
        $this->assertSame('2026-02-01', $row['since']);
        $this->assertEqualsWithDelta(50.0, $row['total_quantity'], 0.0001);
        $this->assertEqualsWithDelta(10.0, $row['per_hectare'],    0.0001); // 50 / 5
    }

    /**
     * With no date filters the monthly block, summed over months for a
     * given plot, equals "total water per plot to current date" — which
     * is exactly requirement #3 from the spec.
     */
    public function test_total_per_plot_to_date(): void
    {
        $this->actingAsRole('manager');

        $plot = Plot::factory()->withSurface(10.0)->create();

        IrrigationOperation::factory()->for($plot)->on('2026-03-10')->quantity(100)->create();
        IrrigationOperation::factory()->for($plot)->on('2026-05-10')->quantity(70)->create();
        IrrigationOperation::factory()->for($plot)->on('2026-08-10')->quantity(30)->create();

        $response = $this->getJson('/api/v1/reports/irrigation')->assertOk();

        $totalForPlot = collect($response->json('data.monthly'))
            ->where('plot_id', $plot->id)
            ->sum('total_quantity');

        $this->assertEqualsWithDelta(200.0, $totalForPlot, 0.0001);
    }

    /**
     * The spec exposes ?plot_id= and ?from=/?to=; the dashboard uses
     * ?plot_ids[]= + ?date_from=/?date_to=. Both must work.
     */
    public function test_singular_spec_aliases_and_plural_canonical_filters_both_work(): void
    {
        $this->actingAsRole('manager');

        $plotA = Plot::factory()->withSurface(10.0)->create();
        $plotB = Plot::factory()->withSurface(10.0)->create();

        IrrigationOperation::factory()->for($plotA)->on('2026-03-10')->quantity(100)->create();
        IrrigationOperation::factory()->for($plotB)->on('2026-03-10')->quantity(999)->create();

        // Singular spec aliases.
        $singular = $this->getJson("/api/v1/reports/irrigation?plot_id={$plotA->id}&from=2026-03-01&to=2026-03-31")
            ->assertOk();
        $this->assertSame([$plotA->id], collect($singular->json('data.monthly'))->pluck('plot_id')->unique()->values()->all());

        // Plural canonical form.
        $plural = $this->getJson("/api/v1/reports/irrigation?plot_ids[]={$plotA->id}&date_from=2026-03-01&date_to=2026-03-31")
            ->assertOk();
        $this->assertSame([$plotA->id], collect($plural->json('data.monthly'))->pluck('plot_id')->unique()->values()->all());
    }

    public function test_date_range_filter_excludes_out_of_range_entries(): void
    {
        $this->actingAsRole('manager');

        $plot = Plot::factory()->withSurface(10.0)->create();

        IrrigationOperation::factory()->for($plot)->on('2026-02-28')->quantity(11)->create(); // before
        IrrigationOperation::factory()->for($plot)->on('2026-03-15')->quantity(50)->create(); // in
        IrrigationOperation::factory()->for($plot)->on('2026-04-01')->quantity(22)->create(); // after

        $response = $this->getJson("/api/v1/reports/irrigation?from=2026-03-01&to=2026-03-31")
            ->assertOk();

        $sum = collect($response->json('data.monthly'))
            ->where('plot_id', $plot->id)
            ->sum('total_quantity');

        $this->assertEqualsWithDelta(50.0, $sum, 0.0001);
    }

    public function test_technician_cannot_access_reports(): void
    {
        $this->actingAsRole('technician');

        $this->getJson('/api/v1/reports/irrigation')->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/reports/irrigation')->assertUnauthorized();
    }
}
