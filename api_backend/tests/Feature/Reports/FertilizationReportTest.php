<?php

/**
 * Fertilization report — verifies the spec contract:
 *
 *   1. Monthly N, P, K totals AND per-hectare values, calculated as:
 *        n_total = SUM(quantity_applied * n_at_entry / 100)
 *        n_per_ha = n_total / surface_area_ha     (idem for P and K)
 *   2. Cumulative NPK per hectare since each plot's season_start_date
 *      (entries before that date are excluded from the cumulative block).
 *   3. Filters: ?plot_id= / ?plot_ids[]=, ?from=/?to= / ?date_from=/?date_to=.
 *   4. Role guard: technician forbidden; manager + admin allowed.
 */

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\FertilizationOperation;
use App\Models\Fertilizer;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FertilizationReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_npk_totals_and_per_hectare(): void
    {
        $this->actingAsRole('manager');

        $plot = Plot::factory()->withSurface(10.0)
            ->seasonStart('2026-01-01')
            ->create(['name' => 'Nord']);

        $fert = Fertilizer::factory()->create([
            'n_percent' => 20,
            'p_percent' => 10,
            'k_percent' => 5,
        ]);

        // March: 100 kg @ 20/10/5  → N=20, P=10, K=5
        FertilizationOperation::factory()->for($plot)->for($fert, 'fertilizer')->state([
            'operation_date'   => '2026-03-10',
            'quantity_applied' => 100,
            'n_at_entry'       => 20,
            'p_at_entry'       => 10,
            'k_at_entry'       => 5,
            'price_at_entry'   => 1.0,
        ])->create();

        // March: 50 kg @ 20/10/5  → N=10, P=5, K=2.5  (March totals: 30/15/7.5)
        FertilizationOperation::factory()->for($plot)->for($fert, 'fertilizer')->state([
            'operation_date'   => '2026-03-25',
            'quantity_applied' => 50,
            'n_at_entry'       => 20,
            'p_at_entry'       => 10,
            'k_at_entry'       => 5,
            'price_at_entry'   => 1.0,
        ])->create();

        // April: 200 kg @ 10/0/0 → N=20, P=0, K=0
        FertilizationOperation::factory()->for($plot)->for($fert, 'fertilizer')->state([
            'operation_date'   => '2026-04-05',
            'quantity_applied' => 200,
            'n_at_entry'       => 10,
            'p_at_entry'       => 0,
            'k_at_entry'       => 0,
            'price_at_entry'   => 1.0,
        ])->create();

        $response = $this->getJson('/api/v1/reports/fertilization')->assertOk();
        $monthly = collect($response->json('data.monthly'));

        $march = $monthly->first(fn ($r) => $r['plot_id'] === $plot->id && $r['month'] === 3);
        $april = $monthly->first(fn ($r) => $r['plot_id'] === $plot->id && $r['month'] === 4);

        $this->assertNotNull($march);
        $this->assertNotNull($april);

        $this->assertEqualsWithDelta(30.0, $march['n_total'], 0.0001);
        $this->assertEqualsWithDelta(15.0, $march['p_total'], 0.0001);
        $this->assertEqualsWithDelta( 7.5, $march['k_total'], 0.0001);

        // per-hectare = total / 10 ha
        $this->assertEqualsWithDelta(3.0,  $march['n_per_ha'], 0.0001);
        $this->assertEqualsWithDelta(1.5,  $march['p_per_ha'], 0.0001);
        $this->assertEqualsWithDelta(0.75, $march['k_per_ha'], 0.0001);

        $this->assertEqualsWithDelta(20.0, $april['n_total'], 0.0001);
        $this->assertEqualsWithDelta(2.0,  $april['n_per_ha'], 0.0001);
        $this->assertEqualsWithDelta(0.0,  $april['p_total'], 0.0001);
        $this->assertEqualsWithDelta(0.0,  $april['k_total'], 0.0001);
    }

    public function test_cumulative_excludes_entries_before_season_start(): void
    {
        $this->actingAsRole('admin');

        $plot = Plot::factory()->withSurface(5.0)
            ->seasonStart('2026-02-01')
            ->create();

        $fert = Fertilizer::factory()->create();

        // Before season start — must be excluded from cumulative block.
        FertilizationOperation::factory()->for($plot)->for($fert, 'fertilizer')->state([
            'operation_date'   => '2026-01-15',
            'quantity_applied' => 9999,
            'n_at_entry'       => 50,
            'p_at_entry'       => 50,
            'k_at_entry'       => 50,
            'price_at_entry'   => 1.0,
        ])->create();

        // After season start: 100 kg @ 20/10/5 → N=20, P=10, K=5
        FertilizationOperation::factory()->for($plot)->for($fert, 'fertilizer')->state([
            'operation_date'   => '2026-02-10',
            'quantity_applied' => 100,
            'n_at_entry'       => 20,
            'p_at_entry'       => 10,
            'k_at_entry'       => 5,
            'price_at_entry'   => 1.0,
        ])->create();

        // After season start: 50 kg @ 20/10/5 → N=10, P=5, K=2.5
        FertilizationOperation::factory()->for($plot)->for($fert, 'fertilizer')->state([
            'operation_date'   => '2026-03-10',
            'quantity_applied' => 50,
            'n_at_entry'       => 20,
            'p_at_entry'       => 10,
            'k_at_entry'       => 5,
            'price_at_entry'   => 1.0,
        ])->create();

        $response = $this->getJson('/api/v1/reports/fertilization')->assertOk();

        $row = collect($response->json('data.cumulative'))->firstWhere('plot_id', $plot->id);
        $this->assertNotNull($row);
        $this->assertSame('2026-02-01', $row['since']);

        // totals since season start: N=30, P=15, K=7.5  → /5 ha
        $this->assertEqualsWithDelta(6.0, $row['n_per_ha'], 0.0001);
        $this->assertEqualsWithDelta(3.0, $row['p_per_ha'], 0.0001);
        $this->assertEqualsWithDelta(1.5, $row['k_per_ha'], 0.0001);
    }

    public function test_plot_id_and_date_range_filters(): void
    {
        $this->actingAsRole('manager');

        $plotA = Plot::factory()->withSurface(10.0)->create();
        $plotB = Plot::factory()->withSurface(10.0)->create();
        $fert  = Fertilizer::factory()->create();

        FertilizationOperation::factory()->for($plotA)->for($fert, 'fertilizer')->state([
            'operation_date' => '2026-03-15', 'quantity_applied' => 100,
            'n_at_entry' => 20, 'p_at_entry' => 10, 'k_at_entry' => 5, 'price_at_entry' => 1.0,
        ])->create();

        // out-of-range date
        FertilizationOperation::factory()->for($plotA)->for($fert, 'fertilizer')->state([
            'operation_date' => '2026-02-01', 'quantity_applied' => 100,
            'n_at_entry' => 20, 'p_at_entry' => 10, 'k_at_entry' => 5, 'price_at_entry' => 1.0,
        ])->create();

        // wrong plot
        FertilizationOperation::factory()->for($plotB)->for($fert, 'fertilizer')->state([
            'operation_date' => '2026-03-20', 'quantity_applied' => 100,
            'n_at_entry' => 20, 'p_at_entry' => 10, 'k_at_entry' => 5, 'price_at_entry' => 1.0,
        ])->create();

        $response = $this->getJson(
            "/api/v1/reports/fertilization?plot_id={$plotA->id}&from=2026-03-01&to=2026-03-31"
        )->assertOk();

        $monthly = collect($response->json('data.monthly'));
        $this->assertSame([$plotA->id], $monthly->pluck('plot_id')->unique()->values()->all());
        $this->assertCount(1, $monthly);
        $this->assertSame(3, $monthly->first()['month']);
        $this->assertEqualsWithDelta(20.0, $monthly->first()['n_total'], 0.0001);
    }

    public function test_plural_canonical_filters_also_work(): void
    {
        $this->actingAsRole('manager');

        $plot = Plot::factory()->withSurface(10.0)->create();
        $fert = Fertilizer::factory()->create();

        FertilizationOperation::factory()->for($plot)->for($fert, 'fertilizer')->state([
            'operation_date' => '2026-03-15', 'quantity_applied' => 100,
            'n_at_entry' => 20, 'p_at_entry' => 10, 'k_at_entry' => 5, 'price_at_entry' => 1.0,
        ])->create();

        $response = $this->getJson(
            "/api/v1/reports/fertilization?plot_ids[]={$plot->id}&date_from=2026-03-01&date_to=2026-03-31"
        )->assertOk();

        $this->assertSame([$plot->id], collect($response->json('data.monthly'))
            ->pluck('plot_id')->unique()->values()->all());
    }

    public function test_technician_cannot_access_fertilization_report(): void
    {
        $this->actingAsRole('technician');
        $this->getJson('/api/v1/reports/fertilization')->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/reports/fertilization')->assertUnauthorized();
    }
}
