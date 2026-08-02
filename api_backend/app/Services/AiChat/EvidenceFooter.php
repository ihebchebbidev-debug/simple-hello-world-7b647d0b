<?php

declare(strict_types=1);

namespace App\Services\AiChat;

/**
 * Deterministic "how this answer was obtained" footer.
 *
 * The model is asked to state its scope, but a prompt rule is not a
 * guarantee. This builds the verification line from the tool calls that
 * ACTUALLY ran this turn (metric, plot/scope, period), so every answer
 * carries checkable provenance regardless of what the model wrote.
 */
final class EvidenceFooter
{
    /** Tools that read no farm data — never worth a provenance line. */
    private const IGNORED = ['plan', 'resolve_date_range', 'search_catalog', 'list_plots', 'list_campaigns'];

    /** @var array<string, array{fr: string, en: string}> */
    private const METRICS = [
        'water_per_ha'          => ['fr' => 'Eau (m³, m³/ha)',            'en' => 'Water (m³, m³/ha)'],
        'irrigation_history'    => ['fr' => 'Irrigations (détail)',       'en' => 'Irrigations (detail)'],
        'nutrient_per_ha'       => ['fr' => 'Fertilisation (unités/ha)',  'en' => 'Fertilization (units/ha)'],
        'fertilization_history' => ['fr' => 'Fertilisations (détail)',    'en' => 'Fertilizations (detail)'],
        'product_usage'         => ['fr' => 'Utilisations du produit',     'en' => 'Product usage'],
        'treatments'            => ['fr' => 'Traitements phytosanitaires','en' => 'Phytosanitary treatments'],
        'harvest_history'       => ['fr' => 'Récolte (kg, kg/ha)',        'en' => 'Harvest (kg, kg/ha)'],
        'cost_per_ha'           => ['fr' => 'Coûts (TND, TND/ha)',        'en' => 'Costs (TND, TND/ha)'],
        'plot_info'             => ['fr' => 'Fiche parcelle',             'en' => 'Plot record'],
        'product_info'          => ['fr' => 'Fiche produit',              'en' => 'Product record'],
        'get_overview'          => ['fr' => 'Vue d’ensemble',             'en' => 'Overview'],
        'get_operations'        => ['fr' => 'Opérations (détail)',        'en' => 'Operations (detail)'],
        'aggregate_operations'  => ['fr' => 'Agrégation d’opérations',    'en' => 'Aggregated operations'],
        'compare_periods'       => ['fr' => 'Comparaison de périodes',    'en' => 'Period comparison'],
        'recent_operations'     => ['fr' => 'Opérations récentes',        'en' => 'Recent operations'],
    ];

    /**
     * @param  array<int, array{name: string, args: array<string, mixed>, result: array<string, mixed>}>  $calls
     */
    public function build(array $calls, string $locale): string
    {
        $fr = str_starts_with(strtolower($locale), 'fr');
        $lines = [];

        foreach ($calls as $call) {
            $name = (string) ($call['name'] ?? '');
            if ($name === '' || in_array($name, self::IGNORED, true)) {
                continue;
            }
            if (($call['result']['ok'] ?? true) === false) {
                continue;
            }

            $args    = (array) ($call['args'] ?? []);
            $filters = (array) ($call['result']['applied_filters'] ?? []);

            $line = ($fr ? 'Métrique : ' : 'Metric: ').$this->metric($name, $fr)
                .' · '.($fr ? 'Portée : ' : 'Scope: ').$this->scope($args, $filters, $fr)
                .' · '.($fr ? 'Période : ' : 'Period: ').$this->period($args, $filters, $fr);

            $lines[$line] = true;   // dedupe identical scopes
        }

        if ($lines === []) {
            return '';
        }

        $lines = array_slice(array_keys($lines), 0, 4);
        $title = $fr ? 'Vérification' : 'Verification';

        return "\n\n---\n**{$title}**\n".implode("\n", array_map(
            static fn (string $l): string => '- '.$l,
            $lines,
        ));
    }

    private function metric(string $name, bool $fr): string
    {
        return self::METRICS[$name][$fr ? 'fr' : 'en'] ?? str_replace('_', ' ', $name);
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $filters
     */
    private function scope(array $args, array $filters, bool $fr): string
    {
        $resolved = array_values(array_filter(
            (array) ($filters['resolved_plots'] ?? []),
            static fn ($p) => is_string($p) && $p !== '',
        ));

        if ($resolved !== []) {
            $label = count($resolved) > 3
                ? implode(', ', array_slice($resolved, 0, 3)).' +'.(count($resolved) - 3)
                : implode(', ', $resolved);
            $prefix = count($resolved) > 1 ? ($fr ? 'Parcelles ' : 'Plots ') : ($fr ? 'Parcelle ' : 'Plot ');
            $out = $prefix.$label;
        } else {
            $plot = $this->str($args['plot'] ?? $args['plot_id'] ?? null);
            $crop = $this->str($args['crop'] ?? null);
            if ($plot !== null) {
                $out = ($fr ? 'Parcelle ' : 'Plot ').$plot;
            } elseif ($crop !== null) {
                $out = ($fr ? 'Culture ' : 'Crop ').$crop;
            } else {
                $out = $fr ? 'Toutes les parcelles' : 'All plots';
            }
        }

        $extra = [];
        foreach (['product' => ['fr' => 'produit', 'en' => 'product'],
                  'pest'    => ['fr' => 'bioagresseur', 'en' => 'pest'],
                  'type'    => ['fr' => 'type', 'en' => 'type']] as $key => $label) {
            $value = $this->str($args[$key] ?? null);
            if ($value !== null) {
                $extra[] = $label[$fr ? 'fr' : 'en'].' '.$value;
            }
        }

        $excluded = array_values(array_filter(
            (array) ($args['exclude_plots'] ?? []),
            static fn ($p) => is_string($p) && $p !== '',
        ));
        if ($excluded !== []) {
            $extra[] = ($fr ? 'hors ' : 'excl. ').implode(', ', $excluded);
        }

        return $extra === [] ? $out : $out.' ('.implode(', ', $extra).')';
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $filters
     */
    private function period(array $args, array $filters, bool $fr): string
    {
        $from = $this->str($filters['date_from'] ?? null) ?? $this->str($args['from'] ?? null);
        $to   = $this->str($filters['date_to'] ?? null)   ?? $this->str($args['to'] ?? null);

        if ($from !== null && $to !== null) {
            return $from === $to ? $from : $from.' → '.$to;
        }
        if ($from !== null) {
            return ($fr ? 'depuis le ' : 'since ').$from;
        }
        if ($to !== null) {
            return ($fr ? "jusqu'au " : 'up to ').$to;
        }

        return $fr ? 'toutes périodes' : 'all time';
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
