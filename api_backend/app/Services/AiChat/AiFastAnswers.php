<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Facades\Log;

/**
 * Deterministic "playbook" answers for the questions the farm actually asks
 * every day (water/ha, unités d'azote, dernières irrigations, coût/ha, dates
 * de traitement, prix d'un produit, surface d'une parcelle…).
 *
 * Why this exists
 * ---------------
 * Those questions have exactly ONE correct answer and it is already computed
 * by the farm tools. Routing them through the model costs ~100 s, burns
 * tokens, and introduces the only real source of error left in the pipeline:
 * the wording layer (a mis-picked average, a dropped unit, an invented total).
 * So when — and only when — the planner resolves the question to a single
 * known tool call, we render the answer from the tool result with plain PHP
 * and ship it. Everything else keeps the normal agent route.
 *
 * Rules that keep this safe:
 *  - one tool call only (a mixed question still goes to the model),
 *  - no tool error, no "empty result diagnostic" (those need explaining),
 *  - no dispute / open-ended / advice wording (see {@see needsReasoning()}),
 *  - the numbers printed are the tool's own fields, never recomputed here.
 */
final class AiFastAnswers
{
    /** Tools this class knows how to render. Anything else → model route. */
    private const RENDERABLE = [
        'water_per_ha',
        'irrigation_history',
        'nutrient_per_ha',
        'treatments',
        'fertilization_history',
        'product_usage',
        'harvest_history',
        'cost_per_ha',
        'product_info',
        'plot_info',
    ];

    /**
     * Wording that means the user wants judgement, comparison, explanation or
     * is contesting a previous answer. A canned table is the wrong reply.
     */
    private const NEEDS_REASONING = [
        'pourquoi', 'explique', 'expliquer', 'analyse', 'analyser', 'conseil', 'conseille',
        'recommand', 'que penses', 'qu en penses', 'faux', 'erreur', 'tu te trompes',
        'est ce normal', 'est-ce normal', 'compare', 'comparer', 'par rapport',
        'devrais', 'faut il', 'faut-il', 'optimis', 'ameliorer', 'améliorer', 'resume',
        'why', 'explain', 'advise', 'recommend', 'should i', 'wrong', 'compare to',
    ];

    public function __construct(
        private readonly AiQuestionPlanner $planner,
        private readonly AiToolRegistry $tools,
    ) {}

    /**
     * @param  array<int, array{role?: string, content?: string}>  $messages
     * @return array{reply: string, call: array{name: string, args: array<string, mixed>}, result: array<string, mixed>}|null
     */
    public function answer(array $messages, string $locale): ?array
    {
        if (! (bool) config('openrouter.fast_answers', true)) {
            return null;
        }

        $question = $this->lastUserQuestion($messages);
        if ($question === '' || $this->needsReasoning($question)) {
            return null;
        }

        try {
            $calls = $this->planner->plan($messages);
        } catch (\Throwable $e) {
            Log::warning('ai.fast_answers.plan_failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (count($calls) !== 1) {
            return null;
        }
        $call = $calls[0];
        if (! in_array($call['name'], self::RENDERABLE, true)) {
            return null;
        }

        try {
            $result = $this->tools->call($call['name'], $call['args']);
        } catch (\Throwable $e) {
            Log::warning('ai.fast_answers.tool_failed', ['tool' => $call['name'], 'error' => $e->getMessage()]);

            return null;
        }

        // Anything the tool itself flags as needing narration goes to the model:
        // an unresolved plot, a filter that matched nothing while other data
        // exists, or a window probe are exactly the cases a table answers badly.
        if (! empty($result['error'])
            || isset($result['empty_result_diagnostic'])
            || isset($result['filter_context'])
            || isset($result['unfiltered_treatment_count'])) {
            return null;
        }

        $fr = str_starts_with(mb_strtolower($locale), 'fr');

        $body = match ($call['name']) {
            'water_per_ha'          => $this->renderWater($result, $fr),
            'irrigation_history'    => $this->renderIrrigations($result, $fr),
            'nutrient_per_ha'       => $this->renderNutrients($result, $fr),
            'treatments'            => $this->renderTreatments($result, $fr),
            'fertilization_history' => $this->renderFertilizations($result, $fr),
            'product_usage'         => $this->renderProductUsage($result, $fr),
            'harvest_history'       => $this->renderHarvest($result, $fr),
            'cost_per_ha'           => $this->renderCost($result, $fr),
            'product_info'          => $this->renderProduct($result, $fr),
            'plot_info'             => $this->renderPlots($result, $fr),
            default                 => null,
        };

        if ($body === null || trim($body) === '') {
            return null;
        }

        return ['reply' => trim($body), 'call' => $call, 'result' => $result];
    }

    // ─── Renderers ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $r */
    private function renderWater(array $r, bool $fr): ?string
    {
        $plots = array_values((array) ($r['plots'] ?? []));
        if ($plots === []) {
            return null;
        }

        $head = ($fr ? "**Consommation d'eau" : '**Water consumption').$this->period($r, $fr).'**';

        if (count($plots) === 1) {
            $p = $plots[0];
            if ((int) ($p['irrigations'] ?? 0) === 0) {
                return $head."\n\n".($fr
                    ? 'Aucune irrigation enregistrée sur la parcelle '.$p['plot'].' pour cette période.'
                    : 'No irrigation recorded on plot '.$p['plot'].' for this period.');
            }

            return $head."\n\n".implode("\n", array_filter([
                '- '.($fr ? 'Parcelle' : 'Plot').' : '.$p['plot'].' ('.$this->num($p['surface_area_ha'], 2).' ha)',
                '- '.($fr ? 'Volume total' : 'Total volume').' : '.$this->num($p['total_m3'], 1).' m³',
                '- '.($fr ? 'Eau par hectare' : 'Water per hectare').' : **'.$this->num($p['m3_per_ha'], 1).' m³/ha**',
                '- '.($fr ? 'Irrigations' : 'Irrigations').' : '.(int) $p['irrigations']
                    .($p['first_date'] ?? null ? ' ('.$p['first_date'].' → '.$p['last_date'].')' : ''),
                '- '.($fr ? 'Coût' : 'Cost').' : '.$this->num($p['cost_tnd'], 2).' TND',
            ]));
        }

        $rows = [$fr
            ? '| Parcelle | Surface (ha) | Irrigations | Total m³ | m³/ha | Coût (TND) |'
            : '| Plot | Area (ha) | Irrigations | Total m³ | m³/ha | Cost (TND) |',
            '|---|---:|---:|---:|---:|---:|'];
        foreach ($plots as $p) {
            $rows[] = '| '.$p['plot']
                .' | '.$this->num($p['surface_area_ha'], 2)
                .' | '.(int) ($p['irrigations'] ?? 0)
                .' | '.$this->num($p['total_m3'], 1)
                .' | '.$this->num($p['m3_per_ha'], 1)
                .' | '.$this->num($p['cost_tnd'], 2).' |';
        }

        $total = ($fr ? '**Total : ' : '**Total: ')
            .$this->num($r['total_m3'] ?? null, 1).' m³ — '
            .$this->num($r['per_ha'] ?? null, 1).' m³/ha'
            .($fr ? ' (moyenne pondérée)' : ' (weighted average)')
            .' — '.$this->num($r['total_cost_tnd'] ?? null, 2).' TND**';

        return $head."\n\n".implode("\n", $rows)."\n\n".$total;
    }

    /** @param array<string, mixed> $r */
    private function renderIrrigations(array $r, bool $fr): ?string
    {
        $rows = array_values((array) ($r['rows'] ?? []));
        if ($rows === []) {
            return null;
        }

        $lines = [($fr ? '**Irrigations' : '**Irrigations').$this->period($r, $fr).'** — '
            .(int) ($r['irrigation_count'] ?? count($rows)).($fr ? ' irrigation(s), ' : ' irrigation(s), ')
            .$this->num($r['window_total_m3'] ?? null, 1).' m³', ''];

        foreach ($rows as $row) {
            $lines[] = '- '.$row['date']
                .($row['plot'] ?? null ? ' — '.$row['plot'] : '')
                .' — '.$this->num($row['quantity_m3'], 1).' m³'
                .($row['per_ha'] !== null ? ' ('.$this->num($row['per_ha'], 1).' m³/ha)' : '')
                .' — '.$this->num($row['cost_tnd'], 2).' TND';
        }

        if (! empty($r['truncated'])) {
            $lines[] = '';
            $lines[] = $fr
                ? '_'.count($rows).' lignes affichées sur '.(int) $r['irrigation_count'].'._'
                : '_Showing '.count($rows).' of '.(int) $r['irrigation_count'].' rows._';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $r */
    private function renderNutrients(array $r, bool $fr): ?string
    {
        $plots = array_values((array) ($r['plots'] ?? []));
        if ($plots === []) {
            return null;
        }
        $tracked = array_values((array) ($r['tracked_nutrients'] ?? []));
        $wanted = mb_strtoupper((string) ($r['nutrient'] ?? 'all'));
        $cols = $wanted === 'ALL' ? $tracked : array_values(array_filter($tracked, static fn ($n) => $n === $wanted));
        if ($cols === []) {
            return null;
        }

        $head = ($fr ? '**Unités fertilisantes (kg/ha)' : '**Fertilising units (kg/ha)').$this->period($r, $fr).'**';

        $header = '| '.($fr ? 'Parcelle' : 'Plot').' | ha | '.($fr ? 'Apports' : 'Applications');
        $sep = '|---|---:|---:';
        foreach ($cols as $c) {
            $header .= ' | '.$c.' kg | '.$c.' u/ha';
            $sep .= '|---:|---:';
        }
        $lines = [$header.' |', $sep.'|'];

        foreach ($plots as $p) {
            $line = '| '.$p['plot'].' | '.$this->num($p['surface_area_ha'], 2).' | '.(int) ($p['applications'] ?? 0);
            foreach ($cols as $c) {
                $line .= ' | '.$this->num($p[$c.'_kg'] ?? null, 1).' | '.$this->num($p[$c.'_units_ha'] ?? null, 1);
            }
            $lines[] = $line.' |';
        }

        return $head."\n\n".implode("\n", $lines);
    }

    /** @param array<string, mixed> $r */
    private function renderTreatments(array $r, bool $fr): ?string
    {
        $rows = array_values((array) ($r['rows'] ?? []));
        $count = (int) ($r['treatment_count'] ?? count($rows));
        $pest = $r['pest'] ?? null;

        if ($count === 0) {
            return $fr
                ? 'Aucun traitement'.($pest ? ' contre '.$pest : '').' enregistré'.$this->period($r, $fr).'.'
                : 'No treatment'.($pest ? ' against '.$pest : '').' recorded'.$this->period($r, $fr).'.';
        }
        if ($rows === []) {
            return null;
        }

        $lines = [
            ($fr ? '**Traitements' : '**Treatments')
                .($pest ? ($fr ? ' contre ' : ' against ').$pest : '')
                .$this->period($r, $fr).'** — '.$count.($fr ? ' traitement(s)' : ' treatment(s)'),
            '',
            $fr
                ? '| Date | Parcelle | Produit | Composition | Dose | Dose/ha | Volume/ha | Coût (TND) |'
                : '| Date | Plot | Product | Composition | Dose | Dose/ha | Volume/ha | Cost (TND) |',
            '|---|---|---|---|---:|---:|---:|---:|',
        ];

        foreach ($rows as $row) {
            $lines[] = '| '.$row['date']
                .' | '.($row['plot'] ?? '—')
                .' | '.($row['product'] ?? '—')
                .' | '.($row['composition'] ?? '—')
                .' | '.$this->num($row['dose'], 2).' '.($row['dose_unit'] ?? '')
                .' | '.$this->num($row['dose_per_ha'], 2)
                .' | '.($row['volume_l_per_ha'] !== null ? $this->num($row['volume_l_per_ha'], 1).' L' : '—')
                .' | '.$this->num($row['cost_tnd'], 2).' |';
        }

        if (! empty($r['truncated'])) {
            $lines[] = '';
            $lines[] = $fr
                ? '_'.count($rows).' lignes affichées sur '.$count.'._'
                : '_Showing '.count($rows).' of '.$count.' rows._';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $r */
    private function renderFertilizations(array $r, bool $fr): ?string
    {
        $rows = array_values((array) ($r['rows'] ?? []));
        $count = (int) ($r['application_count'] ?? count($rows));
        if ($count === 0) {
            return $fr
                ? 'Aucune fertilisation enregistrée'.$this->period($r, $fr).'.'
                : 'No fertilization recorded'.$this->period($r, $fr).'.';
        }
        if ($rows === []) {
            return null;
        }

        $lines = [
            ($fr ? '**Fertilisations' : '**Fertilizations').$this->period($r, $fr).'** — '
                .$count.($fr ? ' apport(s)' : ' application(s)'),
            '',
            $fr
                ? '| Date | Parcelle | Produit | N-P-K (%) | Quantité | Qté/ha | Coût (TND) |'
                : '| Date | Plot | Product | N-P-K (%) | Quantity | Qty/ha | Cost (TND) |',
            '|---|---|---|---|---:|---:|---:|',
        ];

        foreach ($rows as $row) {
            $npk = (array) ($row['npk_percent'] ?? []);
            $lines[] = '| '.$row['date']
                .' | '.($row['plot'] ?? '—')
                .' | '.($row['product'] ?? '—')
                .' | '.implode('-', array_map(fn ($v) => $this->num($v, 1), $npk))
                .' | '.$this->num($row['quantity'], 2).' '.($row['unit'] ?? '')
                .' | '.$this->num($row['quantity_per_ha'], 2)
                .' | '.$this->num($row['cost_tnd'], 2).' |';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $r */
    private function renderProductUsage(array $r, bool $fr): ?string
    {
        $count = (int) ($r['usage_count'] ?? 0);
        $query = (string) ($r['query'] ?? '');

        if ($count === 0) {
            // "0 fois" is a claim that gets disputed — let the model explain it.
            return null;
        }

        $products = array_values((array) ($r['matched_products'] ?? []));
        $lines = [
            ($fr ? '**Utilisations de « '.$query.' »' : '**Uses of “'.$query.'”').$this->period($r, $fr).'** — '
                .$count.($fr ? ' application(s)' : ' application(s)'),
            '',
        ];
        if ($products !== []) {
            $lines[] = ($fr ? 'Produits correspondants : ' : 'Matching products: ').implode(', ', $products);
            $lines[] = '';
        }
        foreach (array_values((array) ($r['rows'] ?? [])) as $row) {
            $lines[] = '- '.$row['date'].' — '.($row['plot'] ?? '—').' — '.($row['product'] ?? '—')
                .' ('.($row['operation_type'] ?? '').') — '.$this->num($row['quantity'] ?? null, 2);
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $r */
    private function renderHarvest(array $r, bool $fr): ?string
    {
        $count = (int) ($r['harvest_count'] ?? 0);
        if ($count === 0) {
            return $fr
                ? 'Aucune récolte enregistrée'.$this->period($r, $fr).'.'
                : 'No harvest recorded'.$this->period($r, $fr).'.';
        }

        $lines = [
            ($fr ? '**Récolte' : '**Harvest').$this->period($r, $fr).'**',
            '',
            '- '.($fr ? 'Période de récolte' : 'Harvest window').' : '.($r['first_harvest'] ?? '—').' → '.($r['last_harvest'] ?? '—'),
            '- '.($fr ? 'Passages' : 'Passes').' : '.$count,
            '- '.($fr ? 'Quantité totale' : 'Total quantity').' : '.$this->num($r['total_kg'] ?? null, 1).' kg',
            '- '.($fr ? 'Rendement' : 'Yield').' : **'.$this->num($r['kg_per_ha'] ?? null, 1).' kg/ha**',
            '- '.($fr ? "Coût main-d'œuvre" : 'Labour cost').' : '.$this->num($r['labour_cost_tnd'] ?? null, 2).' TND'
                .($r['cost_per_kg_tnd'] ?? null ? ' ('.$this->num($r['cost_per_kg_tnd'], 3).' TND/kg)' : ''),
        ];

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $r */
    private function renderCost(array $r, bool $fr): ?string
    {
        $plots = array_values((array) ($r['plots'] ?? []));
        if ($plots === []) {
            return null;
        }
        $labels = $fr
            ? ['irrigation' => 'Irrigation', 'fertilization' => 'Fertilisation', 'phytosanitary' => 'Traitements', 'harvest' => 'Récolte']
            : ['irrigation' => 'Irrigation', 'fertilization' => 'Fertilization', 'phytosanitary' => 'Treatments', 'harvest' => 'Harvest'];

        $scope = (string) ($r['scope'] ?? 'all');
        $head = ($fr ? '**Coûts' : '**Costs')
            .($scope !== 'all' ? ' — '.($labels[$scope] ?? $scope) : '')
            .($r['pest'] ?? null ? ($fr ? ' contre ' : ' against ').$r['pest'] : '')
            .$this->period($r, $fr).'**';

        if (count($plots) === 1) {
            $p = $plots[0];
            $lines = [$head, '',
                '- '.($fr ? 'Parcelle' : 'Plot').' : '.$p['plot'].' ('.$this->num($p['surface_area_ha'], 2).' ha)'];
            foreach ((array) ($p['by_type_tnd'] ?? []) as $type => $amount) {
                $lines[] = '- '.($labels[$type] ?? $type).' : '.$this->num($amount, 2).' TND';
            }
            $lines[] = '- '.($fr ? 'Total' : 'Total').' : '.$this->num($p['total_tnd'], 2).' TND';
            $lines[] = '- **'.($fr ? 'Coût par hectare' : 'Cost per hectare').' : '.$this->num($p['cost_per_ha_tnd'], 2).' TND/ha**';

            return implode("\n", $lines);
        }

        $lines = [$head, '',
            '| '.($fr ? 'Parcelle' : 'Plot').' | ha | '.($fr ? 'Total (TND)' : 'Total (TND)').' | TND/ha |',
            '|---|---:|---:|---:|'];
        foreach ($plots as $p) {
            $lines[] = '| '.$p['plot'].' | '.$this->num($p['surface_area_ha'], 2)
                .' | '.$this->num($p['total_tnd'], 2).' | '.$this->num($p['cost_per_ha_tnd'], 2).' |';
        }
        $overall = (array) ($r['overall'] ?? []);
        $lines[] = '';
        $lines[] = ($fr ? '**Total ferme : ' : '**Farm total: ').$this->num($overall['total_tnd'] ?? null, 2)
            .' TND — '.$this->num($overall['cost_per_ha_tnd'] ?? null, 2).' TND/ha**';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $r */
    private function renderProduct(array $r, bool $fr): ?string
    {
        $products = array_values((array) ($r['products'] ?? []));
        if ($products === []) {
            return null;
        }

        $lines = [];
        foreach ($products as $p) {
            $lines[] = '**'.$p['name'].'** ('.($p['kind'] === 'fertilizer' ? ($fr ? 'engrais' : 'fertilizer') : ($fr ? 'produit phytosanitaire' : 'pesticide')).')';
            $lines[] = '- '.($fr ? 'Unité' : 'Unit').' : '.($p['unit'] ?? '—');

            $composition = $p['composition'] ?? null;
            if (is_array($composition)) {
                $parts = [];
                foreach ($composition as $k => $v) {
                    $parts[] = $k.' '.$this->num($v, 2);
                }
                $lines[] = '- '.($fr ? 'Composition' : 'Composition').' : '.implode(' / ', $parts);
            } elseif (is_string($composition) && $composition !== '') {
                $lines[] = '- '.($fr ? 'Composition' : 'Composition').' : '.$composition;
            }

            $prices = array_values((array) ($p['prices'] ?? []));
            $current = null;
            foreach ($prices as $price) {
                if (! empty($price['current'])) {
                    $current = $price;
                    break;
                }
            }
            $current ??= $prices[0] ?? null;
            if ($current !== null) {
                $lines[] = '- '.($fr ? 'Prix actuel' : 'Current price').' : **'.$this->num($current['price_per_unit'], 3)
                    .' TND/'.($current['unit'] ?? $p['unit'] ?? '').'**'
                    .($current['effective_from'] ?? null ? ($fr ? ' (depuis le ' : ' (since ').$current['effective_from'].')' : '');
            } else {
                $lines[] = '- '.($fr ? 'Prix' : 'Price').' : '.($fr ? 'aucun prix enregistré' : 'no price recorded');
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $r */
    private function renderPlots(array $r, bool $fr): ?string
    {
        $plots = array_values((array) ($r['plots'] ?? []));
        if ($plots === [] || count($plots) > 12) {
            return null;
        }

        $lines = [];
        foreach ($plots as $p) {
            $lines[] = '**'.($fr ? 'Parcelle ' : 'Plot ').$p['name'].'**';
            $lines[] = '- '.($fr ? 'Surface' : 'Area').' : **'.$this->num($p['surface_area_ha'], 2).' ha**';
            if (! empty($p['crop_type'])) {
                $lines[] = '- '.($fr ? 'Culture' : 'Crop').' : '.$p['crop_type']
                    .(! empty($p['variety']) ? ' — '.$p['variety'] : '');
            }
            $last = (array) ($p['last_operation'] ?? []);
            if ($last !== []) {
                $labels = $fr
                    ? ['irrigation' => 'irrigation', 'fertilization' => 'fertilisation', 'phytosanitary' => 'traitement', 'harvest' => 'récolte']
                    : ['irrigation' => 'irrigation', 'fertilization' => 'fertilization', 'phytosanitary' => 'treatment', 'harvest' => 'harvest'];
                $parts = [];
                foreach ($last as $type => $date) {
                    $parts[] = ($labels[$type] ?? $type).' '.$date;
                }
                $lines[] = '- '.($fr ? 'Dernières opérations' : 'Last operations').' : '.implode(', ', $parts);
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * " du 01/08/2026 au 31/08/2026" / " (toutes périodes)". Read from the
     * tool's own applied_filters so the printed period is the one that was
     * actually queried, never the one we guessed.
     *
     * @param  array<string, mixed>  $r
     */
    private function period(array $r, bool $fr): string
    {
        $filters = (array) ($r['applied_filters'] ?? []);
        $from = $filters['date_from'] ?? (($r['window'] ?? [])['from'] ?? null);
        $to = $filters['date_to'] ?? (($r['window'] ?? [])['to'] ?? null);

        if ($from === null && $to === null) {
            return $fr ? ' (toutes périodes confondues)' : ' (all periods)';
        }
        if ($from !== null && $to !== null) {
            return $fr ? ' du '.$from.' au '.$to : ' from '.$from.' to '.$to;
        }
        if ($from !== null) {
            return $fr ? ' depuis le '.$from : ' since '.$from;
        }

        return $fr ? " jusqu'au ".$to : ' up to '.$to;
    }

    private function num(mixed $value, int $decimals): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return number_format((float) $value, $decimals, ',', ' ');
    }

    /** @param array<int, array{role?: string, content?: string}> $messages */
    private function lastUserQuestion(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return trim((string) ($messages[$i]['content'] ?? ''));
            }
        }

        return '';
    }

    private function needsReasoning(string $question): bool
    {
        $q = mb_strtolower($question);
        $q = strtr($q, ['à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c', "'" => ' ', '’' => ' ']);
        foreach (self::NEEDS_REASONING as $needle) {
            if (str_contains($q, $needle)) {
                return true;
            }
        }

        return false;
    }
}
