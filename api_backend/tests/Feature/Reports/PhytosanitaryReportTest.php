<?php

/**
 * Phytosanitary report — verifies the spec contract:
 *
 *   1. Per-plot grouping with: date, pesticide name, chemical composition,
 *      target pest, remarks.
 *   2. Filters: ?plot_id= (singular alias) / ?plot_ids[]= (canonical),
 *      ?from=/?to= (singular) / ?date_from=/?date_to= (canonical),
 *      ?q= keyword (case-insensitive across pesticide name,
 *      chemical_composition, target_pest, remarks, plot name).
 *   3. Role guard: only manager + admin may read.
 */

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Pesticide;
use App\Models\PhytosanitaryOperation;
use App\Models\Plot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PhytosanitaryReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_plot_grouping_and_required_fields(): void
    {
        $this->actingAsRole('manager');

        $plot = Plot::factory()->withSurface(10.0)->create(['name' => 'Nord']);
        $pesticide = Pesticide::factory()->create([
            'name'                 => 'Roundup',
            'chemical_composition' => 'Glyphosate 360 g/L',
        ]);

        PhytosanitaryOperation::factory()
            ->for($plot)->for($pesticide, 'pesticide')
            ->state([
                'operation_date'   => '2026-04-10',
                'quantity_applied' => 2.5,
                'target_pest'      => 'Pucerons',
                'remarks'          => 'Application matinale',
                'price_at_entry'   => 12.5,
            ])->create();

        $response = $this->getJson('/api/v1/reports/phytosanitary')->assertOk();

        $plotRow = collect($response->json('data.plots'))->firstWhere('plot_id', $plot->id);
        $this->assertNotNull($plotRow);
        $this->assertSame('Nord', $plotRow['plot_name']);

        $treatment = $plotRow['treatments'][0];
        $this->assertSame('2026-04-10', $treatment['date']);
        $this->assertSame('Roundup', $treatment['pesticide_name']);
        $this->assertSame('Glyphosate 360 g/L', $treatment['chemical_composition']);
        $this->assertSame('Pucerons', $treatment['target_pest']);
        $this->assertSame('Application matinale', $treatment['remarks']);
    }

    public function test_plot_id_and_date_range_filters(): void
    {
        $this->actingAsRole('manager');

        $plotA = Plot::factory()->withSurface(10.0)->create();
        $plotB = Plot::factory()->withSurface(10.0)->create();
        $pesticide = Pesticide::factory()->create();

        PhytosanitaryOperation::factory()->for($plotA)->for($pesticide, 'pesticide')
            ->state(['operation_date' => '2026-03-15'])->create();
        PhytosanitaryOperation::factory()->for($plotA)->for($pesticide, 'pesticide')
            ->state(['operation_date' => '2026-02-01'])->create(); // out of range
        PhytosanitaryOperation::factory()->for($plotB)->for($pesticide, 'pesticide')
            ->state(['operation_date' => '2026-03-20'])->create(); // wrong plot

        $response = $this->getJson(
            "/api/v1/reports/phytosanitary?plot_id={$plotA->id}&from=2026-03-01&to=2026-03-31"
        )->assertOk();

        $plots = collect($response->json('data.plots'));
        $this->assertCount(1, $plots);
        $this->assertSame($plotA->id, $plots->first()['plot_id']);
        $this->assertCount(1, $plots->first()['treatments']);
        $this->assertSame('2026-03-15', $plots->first()['treatments'][0]['date']);
    }

    public function test_keyword_filter_matches_across_text_fields(): void
    {
        $this->actingAsRole('manager');

        $plot = Plot::factory()->withSurface(10.0)->create(['name' => 'Parcelle A']);

        $glyphosate = Pesticide::factory()->create([
            'name'                 => 'Roundup',
            'chemical_composition' => 'Glyphosate 360 g/L',
        ]);
        $copper = Pesticide::factory()->create([
            'name'                 => 'Bouillie bordelaise',
            'chemical_composition' => 'Sulfate de cuivre',
        ]);

        $matchByPest = PhytosanitaryOperation::factory()->for($plot)->for($glyphosate, 'pesticide')
            ->state(['target_pest' => 'Pucerons', 'remarks' => null, 'operation_date' => '2026-04-01'])
            ->create();

        $matchByRemark = PhytosanitaryOperation::factory()->for($plot)->for($copper, 'pesticide')
            ->state(['target_pest' => 'Mildiou', 'remarks' => 'pucerons signalés en bordure', 'operation_date' => '2026-04-02'])
            ->create();

        $noMatch = PhytosanitaryOperation::factory()->for($plot)->for($copper, 'pesticide')
            ->state(['target_pest' => 'Mildiou', 'remarks' => 'rien à signaler', 'operation_date' => '2026-04-03'])
            ->create();

        $response = $this->getJson('/api/v1/reports/phytosanitary?q=pucerons')->assertOk();

        $ids = collect($response->json('data.plots'))
            ->flatMap(fn ($p) => collect($p['treatments'])->pluck('id'))
            ->all();

        $this->assertContains($matchByPest->id, $ids);
        $this->assertContains($matchByRemark->id, $ids);
        $this->assertNotContains($noMatch->id, $ids);

        $this->assertSame('pucerons', $response->json('data.filters.q'));
    }

    public function test_keyword_filter_matches_pesticide_name_and_composition(): void
    {
        $this->actingAsRole('manager');

        $plot = Plot::factory()->withSurface(10.0)->create();

        $glyphosate = Pesticide::factory()->create([
            'name'                 => 'Roundup',
            'chemical_composition' => 'Glyphosate 360 g/L',
        ]);
        $other = Pesticide::factory()->create([
            'name'                 => 'Bouillie bordelaise',
            'chemical_composition' => 'Sulfate de cuivre',
        ]);

        $a = PhytosanitaryOperation::factory()->for($plot)->for($glyphosate, 'pesticide')
            ->state(['operation_date' => '2026-04-01'])->create();
        $b = PhytosanitaryOperation::factory()->for($plot)->for($other, 'pesticide')
            ->state(['operation_date' => '2026-04-02'])->create();

        // By pesticide name
        $byName = $this->getJson('/api/v1/reports/phytosanitary?q=roundup')->assertOk();
        $idsName = collect($byName->json('data.plots'))
            ->flatMap(fn ($p) => collect($p['treatments'])->pluck('id'))->all();
        $this->assertContains($a->id, $idsName);
        $this->assertNotContains($b->id, $idsName);

        // By chemical composition
        $byComp = $this->getJson('/api/v1/reports/phytosanitary?q=glyphosate')->assertOk();
        $idsComp = collect($byComp->json('data.plots'))
            ->flatMap(fn ($p) => collect($p['treatments'])->pluck('id'))->all();
        $this->assertContains($a->id, $idsComp);
        $this->assertNotContains($b->id, $idsComp);
    }

    public function test_technician_cannot_access_phytosanitary_report(): void
    {
        $this->actingAsRole('technician');
        $this->getJson('/api/v1/reports/phytosanitary')->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/reports/phytosanitary')->assertUnauthorized();
    }
}
