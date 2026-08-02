<?php

declare(strict_types=1);

namespace Tests\Unit\AiChat;

use App\Services\AiChat\AiQuestionPlanner;
use Tests\TestCase;

/**
 * The planner is what guarantees a data question reaches a data tool even
 * when the free-tier model skips tool-calling. Each case below mirrors one of
 * the reference questions the assistant must never refuse.
 */
final class AiQuestionPlannerTest extends TestCase
{
    private function plan(string $question): array
    {
        return (new AiQuestionPlanner())->plan([['role' => 'user', 'content' => $question]]);
    }

    /** @return array<int, string> */
    private function names(array $calls): array
    {
        return array_map(static fn (array $c): string => $c['name'], $calls);
    }

    private function argsFor(array $calls, string $tool): ?array
    {
        foreach ($calls as $c) {
            if ($c['name'] === $tool) {
                return $c['args'];
            }
        }
        return null;
    }

    public function test_farm_wide_water_question_is_routed_with_a_month_window(): void
    {
        $calls = $this->plan("Quelle est la consommation d'eau par parcelle ce mois-ci ?");

        $this->assertContains('water_per_ha', $this->names($calls));
        $args = $this->argsFor($calls, 'water_per_ha');
        // "par parcelle" is not a plot name — the question is farm-wide.
        $this->assertArrayNotHasKey('plot', $args);
        $this->assertSame(now()->startOfMonth()->toDateString(), $args['from']);
    }

    public function test_water_per_ha_for_a_named_plot_today(): void
    {
        $calls = $this->plan("Quelle quantité d'eau / ha a reçu la parcelle P12 à la date d'aujourd'hui");

        $args = $this->argsFor($calls, 'water_per_ha');
        $this->assertNotNull($args);
        $this->assertSame('P12', $args['plot']);
        $this->assertSame(now()->toDateString(), $args['to']);
    }

    public function test_nitrogen_units_to_date(): void
    {
        $calls = $this->plan("Combien d'unités d'azote la parcelle A-3 a reçu jusqu'à ce jour ?");

        $args = $this->argsFor($calls, 'nutrient_per_ha');
        $this->assertNotNull($args);
        $this->assertSame('n', $args['nutrient']);
        $this->assertSame('A-3', $args['plot']);
    }

    public function test_last_three_irrigations_requests_three_rows_newest_first(): void
    {
        $calls = $this->plan('Quelles sont les dates des 3 dernières irrigations reçues par la parcelle P12');

        $args = $this->argsFor($calls, 'irrigation_history');
        $this->assertNotNull($args);
        $this->assertSame(3, $args['limit']);
        $this->assertSame('desc', $args['order']);
    }

    public function test_product_price_uses_the_catalog_tool(): void
    {
        $calls = $this->plan("Quel est le prix de l'Antéor Flash ?");

        $this->assertSame(['product_info'], $this->names($calls));
        $this->assertSame('Antéor Flash', $this->argsFor($calls, 'product_info')['query']);
    }

    public function test_pest_treatment_question_carries_pest_and_month(): void
    {
        $calls = $this->plan('Quels traitements contre le mildiou sur la vigne en juin 2026 ?');

        $args = $this->argsFor($calls, 'treatments');
        $this->assertNotNull($args);
        $this->assertSame('mildiou', $args['pest']);
        $this->assertSame('2026-06-01', $args['from']);
        $this->assertSame('2026-06-30', $args['to']);
    }

    public function test_explicit_date_range_cost_question(): void
    {
        $calls = $this->plan('Coût total par hectare de la parcelle P12 entre le 01/01/2026 et le 30/06/2026');

        $args = $this->argsFor($calls, 'cost_per_ha');
        $this->assertNotNull($args);
        $this->assertSame('2026-01-01', $args['from']);
        $this->assertSame('2026-06-30', $args['to']);
    }

    public function test_exclusion_clause_is_not_read_as_the_target_plot(): void
    {
        $calls = $this->plan('Quel rendement de récolte sur les parcelles de vigne sauf P1 cette année ?');

        $args = $this->argsFor($calls, 'harvest_history');
        $this->assertNotNull($args);
        $this->assertSame('vigne', $args['crop']);
        $this->assertSame(['P1'], $args['exclude_plots']);
        $this->assertArrayNotHasKey('plot', $args);
    }

    public function test_small_talk_triggers_no_database_work(): void
    {
        $this->assertSame([], $this->plan('bonjour'));
        $this->assertSame([], $this->plan('merci !'));
    }

    public function test_plot_is_inherited_from_an_earlier_turn(): void
    {
        $calls = (new AiQuestionPlanner())->plan([
            ['role' => 'user', 'content' => 'Combien d\'eau sur la parcelle P7 en juin ?'],
            ['role' => 'assistant', 'content' => '...'],
            ['role' => 'user', 'content' => 'Et le coût ?'],
        ]);

        $args = $this->argsFor($calls, 'cost_per_ha');
        $this->assertNotNull($args);
        $this->assertSame('P7', $args['plot']);
    }

    public function test_amino_acid_usage_checks_both_operation_catalogs(): void
    {
        $calls = $this->plan('Combien de fois nous avons utilisé les acides aminés sur la parcelle P4');

        $this->assertSame(['product_usage'], $this->names($calls));
        $args = $this->argsFor($calls, 'product_usage');
        $this->assertNotNull($args);
        $this->assertSame('P4', $args['plot']);
        $this->assertSame('acides amines', $args['query']);
    }

    public function test_dispute_about_naturamin_stays_on_product_usage(): void
    {
        $calls = (new AiQuestionPlanner())->plan([
            ['role' => 'user', 'content' => 'Combien de fois avons-nous utilisé les acides aminés sur la parcelle P4 ?'],
            ['role' => 'assistant', 'content' => '0 fois.'],
            ['role' => 'user', 'content' => 'Faux, le produit Naturamin contient des acides aminés et il a été utilisé.'],
        ]);

        $this->assertSame(['product_usage'], $this->names($calls));
        $this->assertSame('P4', $this->argsFor($calls, 'product_usage')['plot']);
        $this->assertSame('Naturamin', $this->argsFor($calls, 'product_usage')['query']);
    }

    public function test_named_campaign_scopes_the_metric_tool(): void
    {
        $calls = $this->plan('Quelle est la consommation d\'eau de la parcelle P2 sur la campagne 2024-2025 ?');

        $args = $this->argsFor($calls, 'water_per_ha');
        $this->assertNotNull($args);
        $this->assertSame('2024-2025', $args['campaign']);
        $this->assertSame('P2', $args['plot']);
    }

    public function test_current_season_resolves_to_the_active_campaign(): void
    {
        $args = $this->argsFor($this->plan('Coût de la parcelle P1 cette saison ?'), 'cost_per_ha');

        $this->assertNotNull($args);
        $this->assertSame('active', $args['campaign'] ?? null);
    }

    public function test_season_over_season_question_routes_to_campaign_compare(): void
    {
        $calls = $this->plan('Avons-nous consommé plus d\'eau sur la campagne 2024-2025 par rapport à la précédente ?');

        $args = $this->argsFor($calls, 'campaign_compare');
        $this->assertNotNull($args);
        $this->assertSame('2024-2025', $args['campaign_a']);
        $this->assertSame('water_m3', $args['metric']);
    }

    public function test_data_quality_question_routes_to_the_audit_tool(): void
    {
        $calls = $this->plan('Les données de la parcelle P2 sont-elles fiables ? je pense qu\'il manque des saisies');

        $args = $this->argsFor($calls, 'data_quality');
        $this->assertNotNull($args);
        $this->assertSame('P2', $args['plot']);
    }

    public function test_sync_question_routes_to_sync_status(): void
    {
        $this->assertNotNull($this->argsFor($this->plan('Y a-t-il des données non synchronisées depuis l\'application mobile ?'), 'sync_status'));
    }
}
