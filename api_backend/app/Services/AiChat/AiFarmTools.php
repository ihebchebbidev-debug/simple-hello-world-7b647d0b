<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * High-level, question-shaped read-only tools.
 *
 * The free models we run are weak at arithmetic and at chaining 3 lookups
 * inside a 2-iteration budget, so every tool here does the entity resolution
 * (plot name → id), the date windowing and the per-hectare math in SQL and
 * returns a ready-to-quote answer payload.
 *
 * Used as a trait by {@see AiToolRegistry}.
 */
trait AiFarmTools
{
    /** @return array<int, array<string, mixed>> */
    private function farmDefinitions(): array
    {
        $plot    = ['type' => 'string', 'description' => 'Plot name (e.g. "P1", "Parcelle 3") or plot UUID.'];
        $crop    = ['type' => 'string', 'description' => 'Crop type filter, substring (e.g. "vigne").'];
        $exclude = ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Plot names or UUIDs to exclude (e.g. ["P1"]).'];
        $from    = ['type' => 'string', 'description' => 'Start date YYYY-MM-DD or a natural phrase ("juillet 2026", "aujourd\'hui").'];
        $to      = ['type' => 'string', 'description' => 'End date YYYY-MM-DD or natural phrase. Omit both for all-time / "jusqu\'à ce jour".'];

        return [
            $this->fn('plot_info', 'Identity card of one or more plots: id, name, surface_area_ha, crop_type, variety, active campaign, and the last operation date of each type. Use for "quelle est la surface de la parcelle X".', [
                'plot' => $plot,
                'crop' => $crop,
            ]),

            $this->fn('water_per_ha', 'Irrigation water received per hectare. Returns, per plot: total m³, m³/ha, number of irrigations, cost; plus the average m³/ha across the selected plots. Use for "quantité d\'eau/ha reçue par la parcelle X" (today, a date range, or a whole crop with exclusions).', [
                'plot'          => $plot,
                'crop'          => $crop,
                'exclude_plots' => $exclude,
                'from'          => $from,
                'to'            => $to,
            ]),

            $this->fn('nutrient_per_ha', 'Fertilization nutrient balance: kg of N, P, K applied and units/ha (kg/ha) per plot over a window. Note: magnesium (Mg) is NOT tracked in the catalog. Use for "combien d\'unités/ha de N P K a reçu la parcelle X".', [
                'plot'          => $plot,
                'crop'          => $crop,
                'exclude_plots' => $exclude,
                'nutrient'      => ['type' => 'string', 'enum' => ['n', 'p', 'k', 'all'], 'description' => 'Restrict to one nutrient; default all.'],
                'from'          => $from,
                'to'            => $to,
            ]),

            $this->fn('treatments', 'Phytosanitary treatment log with product name, chemical composition, dose, water volume, volume/ha and cost. Filter by target pest (mildiou, oïdium, cicadelle…) and/or product. Use for treatment counts, dates, chronology, last treatment, product compositions and volume/ha.', [
                'plot'       => $plot,
                'crop'       => $crop,
                'pest'       => ['type' => 'string', 'description' => 'Target pest substring, matched on target_pest and remarks (e.g. "mildiou").'],
                'product'    => ['type' => 'string', 'description' => 'Pesticide name substring.'],
                'from'       => $from,
                'to'         => $to,
                'order'      => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'asc = chronological, desc = most recent first (default).'],
                'limit'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => 40],
            ]),

            $this->fn('fertilization_history', 'Fertilization log with product name, N-P-K %, quantity, quantity/ha and cost. Filter by product name (e.g. "acides aminés") to count applications or find the last fertilization date.', [
                'plot'    => $plot,
                'crop'    => $crop,
                'product' => ['type' => 'string', 'description' => 'Fertilizer name substring.'],
                'from'    => $from,
                'to'      => $to,
                'order'   => ['type' => 'string', 'enum' => ['asc', 'desc']],
                'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 40],
            ]),

            $this->fn('irrigation_history', 'Individual irrigation events: date, m³, m³/ha, cost. Use for "les dates des 3 dernières irrigations".', [
                'plot'  => $plot,
                'crop'  => $crop,
                'from'  => $from,
                'to'    => $to,
                'order' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 40],
            ]),

            $this->fn('harvest_history', 'Harvest events plus the first and last harvest date, total kg, kg/ha and labour cost. Use for "entre quelles dates a été récoltée la parcelle X".', [
                'plot'  => $plot,
                'crop'  => $crop,
                'from'  => $from,
                'to'    => $to,
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 40],
            ]),

            $this->fn('cost_per_ha', 'Cost breakdown per plot by operation type (irrigation, fertilization, phytosanitary, harvest labour) in TND, with total cost and cost/ha. Use for "coût/ha de la parcelle X" or "coût/ha en traitement".', [
                'plot' => $plot,
                'crop' => $crop,
                'type' => ['type' => 'string', 'enum' => ['irrigation', 'fertilization', 'phytosanitary', 'harvest', 'all']],
                'from' => $from,
                'to'   => $to,
            ]),

            $this->fn('product_info', 'Look up a fertilizer or pesticide by name: unit, composition (N-P-K % for fertilizers, chemical_composition for pesticides) and current + historical price per unit. Use for "quel est le prix de X" and "quelle est la composition de Y".', [
                'query' => ['type' => 'string', 'description' => 'Product name, 2+ chars.'],
                'kind'  => ['type' => 'string', 'enum' => ['fertilizer', 'pesticide', 'any']],
            ], ['query']),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null  null when the name is not a farm tool
     */
    private function callFarm(string $name, array $args): ?array
    {
        return match ($name) {
            'plot_info'             => $this->toolPlotInfo($args),
            'water_per_ha'          => $this->toolWaterPerHa($args),
            'nutrient_per_ha'       => $this->toolNutrientPerHa($args),
            'treatments'            => $this->toolTreatments($args),
            'fertilization_history' => $this->toolFertilizationHistory($args),
            'irrigation_history'    => $this->toolIrrigationHistory($args),
            'harvest_history'       => $this->toolHarvestHistory($args),
            'cost_per_ha'           => $this->toolCostPerHa($args),
            'product_info'          => $this->toolProductInfo($args),
            default                 => null,
        };
    }

    // ─── Plot resolution ────────────────────────────────────────────────

    /**
     * Normalise a plot label for fuzzy matching: lowercase, strip accents,
     * drop the "parcelle"/"plot"/"bloc" prefix and every non-alphanumeric
     * character, then collapse "p 1" → "p1".
     */
    private static function normLabel(string $raw): string
    {
        $s = mb_strtolower(trim($raw));
        $s = strtr($s, [
            'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'î'=>'i','ï'=>'i','í'=>'i',
            'ô'=>'o','ö'=>'o','ó'=>'o','õ'=>'o',
            'ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u',
            'ç'=>'c','ñ'=>'n',
        ]);
        $s = preg_replace('/\b(parcelle|parcel|plot|bloc|block|la|le|de|du)\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9]+/u', '', $s) ?? $s;

        return (string) $s;
    }

    /**
     * Resolve the plot selection from `plot` / `crop` / `exclude_plots`.
     *
     * Matching is tiered so a human label always lands on the right row:
     *   1. exact id / exact name
     *   2. normalised label equality ("Parcelle P1" == "p1")
     *   3. SQL substring
     *   4. normalised substring, then closest similarity ≥ 60%
     *
     * @param  array<string, mixed>  $args
     * @return array<int, object>
     */
    private function resolvePlots(array $args): array
    {
        if (! Schema::hasTable('plots')) {
            return [];
        }
        $base = fn () => DB::table('plots')
            ->select('id', 'name', 'surface_area_ha', 'crop_type', 'variety', 'is_active');

        $plot = trim((string) ($args['plot'] ?? ''));
        $crop = trim((string) ($args['crop'] ?? ''));

        $applyCrop = function ($q) use ($crop) {
            if ($crop !== '') {
                $q->whereRaw('LOWER(crop_type) LIKE ?', ['%'.mb_strtolower($crop).'%']);
            }
            return $q;
        };

        if ($plot === '') {
            $rows = $applyCrop($base())->orderBy('name')->limit(80)->get()->all();
        } else {
            $needle = mb_strtolower($plot);
            $q = $applyCrop($base())->where(function ($w) use ($plot, $needle) {
                $w->where('id', $plot)
                  ->orWhereRaw('LOWER(name) = ?', [$needle])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%']);
            });
            $rows = $q->orderBy('name')->limit(80)->get()->all();

            if ($rows === []) {
                // Fuzzy pass over the whole (crop-filtered) plot list.
                $all    = $applyCrop($base())->orderBy('name')->limit(300)->get()->all();
                $target = self::normLabel($plot);

                if ($target !== '') {
                    $exact = array_values(array_filter(
                        $all,
                        static fn ($r) => self::normLabel((string) $r->name) === $target,
                    ));
                    if ($exact !== []) {
                        $rows = $exact;
                    } else {
                        $contains = array_values(array_filter($all, static function ($r) use ($target) {
                            $n = self::normLabel((string) $r->name);
                            return $n !== '' && (str_contains($n, $target) || str_contains($target, $n));
                        }));
                        if ($contains !== []) {
                            $rows = $contains;
                        } else {
                            $best = null;
                            $bestScore = 0.0;
                            foreach ($all as $r) {
                                $score = 0.0;
                                similar_text($target, self::normLabel((string) $r->name), $score);
                                if ($score > $bestScore) {
                                    $bestScore = $score;
                                    $best = $r;
                                }
                            }
                            if ($best !== null && $bestScore >= 60.0) {
                                $rows = [$best];
                            }
                        }
                    }
                }
            }
        }

        $excluded = array_filter(array_map(
            static fn ($v) => self::normLabel((string) $v),
            (array) ($args['exclude_plots'] ?? []),
        ));
        if ($excluded !== []) {
            $rows = array_values(array_filter($rows, static function ($r) use ($excluded) {
                return ! in_array(self::normLabel((string) $r->name), $excluded, true)
                    && ! in_array(mb_strtolower((string) $r->id), array_map('mb_strtolower', $excluded), true);
            }));
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{0: array<int, object>, 1: array<string, string>, 2: array<string,mixed>|null}
     *         plots, {plot_id: name}, error payload
     */
    private function plotScope(array $args): array
    {
        $plots = $this->resolvePlots($args);
        if ($plots === []) {
            // Self-correction: hand the model the real plot names right away so
            // it can retry in the SAME round instead of burning an iteration.
            $available = Schema::hasTable('plots')
                ? DB::table('plots')->orderBy('name')->limit(40)->pluck('name')->all()
                : [];

            return [[], [], [
                'error'           => 'plot_not_found',
                'hint'            => 'No plot matched. Retry this same tool with one of `available_plots` (exact name), or ask the user which plot they mean.',
                'asked'           => $args['plot'] ?? ($args['crop'] ?? null),
                'available_plots' => $available,
            ]];
        }
        $names = [];
        foreach ($plots as $p) {
            $names[(string) $p->id] = (string) $p->name;
        }
        return [$plots, $names, null];
    }


    /** @param array<string,mixed> $args */
    private function windowFrom(array $args): array
    {
        $from = ! empty($args['from']) ? $this->safeDate($args['from'], 'from') : null;
        $to   = ! empty($args['to']) ? $this->safeDate($args['to'], 'to') : null;
        return [$from, $to];
    }

    private function applyWindow(mixed $q, string $table, ?string $from, ?string $to): mixed
    {
        if ($from !== null) $q->where($table.'.operation_date', '>=', $from);
        if ($to !== null)   $q->where($table.'.operation_date', '<=', $to);
        return $q;
    }

    private static function ha(object $plot): float
    {
        $a = (float) ($plot->surface_area_ha ?? 0);
        return $a > 0 ? $a : 0.0;
    }

    private static function perHa(float $value, float $ha): ?float
    {
        return $ha > 0 ? round($value / $ha, 2) : null;
    }

    // ─── Tools ──────────────────────────────────────────────────────────

    /** @param array<string,mixed> $args */
    private function toolPlotInfo(array $args): array
    {
        [$plots, , $err] = $this->plotScope($args);
        if ($err) return $err;

        $out = [];
        foreach (array_slice($plots, 0, 20) as $p) {
            $last = [];
            foreach (self::OP_TABLE as $type => $table) {
                if (! Schema::hasTable($table)) continue;
                $d = DB::table($table)->where('plot_id', $p->id)->max('operation_date');
                if ($d) $last[$type] = (string) $d;
            }
            $out[] = [
                'id'              => $p->id,
                'name'            => $p->name,
                'surface_area_ha' => (float) $p->surface_area_ha,
                'crop_type'       => $p->crop_type,
                'variety'         => $p->variety,
                'is_active'       => (bool) $p->is_active,
                'last_operation'  => $last,
            ];
        }
        return ['plots' => $out, 'count' => count($out)];
    }

    /** @param array<string,mixed> $args */
    private function toolWaterPerHa(array $args): array
    {
        [$plots, $names, $err] = $this->plotScope($args);
        if ($err) return $err;
        [$from, $to] = $this->windowFrom($args);

        $ids = array_map(static fn ($p) => $p->id, $plots);
        $q = DB::table('irrigation_operations')
            ->selectRaw('plot_id, COALESCE(SUM(water_quantity),0) AS qty, COUNT(*) AS n, COALESCE(SUM(water_quantity * price_at_entry),0) AS cost')
            ->whereIn('plot_id', $ids)
            ->groupBy('plot_id');
        $this->applyWindow($q, 'irrigation_operations', $from, $to);
        $agg = collect($q->get())->keyBy('plot_id');

        $rows = [];
        $sumPerHa = 0.0; $withArea = 0;
        foreach ($plots as $p) {
            $a = $agg->get((string) $p->id);
            $qty = (float) ($a->qty ?? 0);
            $ha  = self::ha($p);
            $perHa = self::perHa($qty, $ha);
            if ($perHa !== null) { $sumPerHa += $perHa; $withArea++; }
            $rows[] = [
                'plot'            => $names[(string) $p->id],
                'surface_area_ha' => $ha,
                'irrigations'     => (int) ($a->n ?? 0),
                'total_m3'        => round($qty, 2),
                'm3_per_ha'       => $perHa,
                'cost_tnd'        => round((float) ($a->cost ?? 0), 2),
            ];
        }

        return [
            'window'           => ['from' => $from, 'to' => $to],
            'unit'             => 'm3/ha',
            'plots'            => array_slice($rows, 0, 40),
            'plot_count'       => count($rows),
            'average_m3_per_ha' => $withArea > 0 ? round($sumPerHa / $withArea, 2) : null,
            'excluded'         => array_values((array) ($args['exclude_plots'] ?? [])),
        ];
    }

    /** @param array<string,mixed> $args */
    private function toolNutrientPerHa(array $args): array
    {
        [$plots, $names, $err] = $this->plotScope($args);
        if ($err) return $err;
        [$from, $to] = $this->windowFrom($args);

        $ids = array_map(static fn ($p) => $p->id, $plots);
        $q = DB::table('fertilization_operations')
            ->selectRaw('plot_id,
                COALESCE(SUM(quantity_applied * n_at_entry / 100.0),0) AS n_kg,
                COALESCE(SUM(quantity_applied * p_at_entry / 100.0),0) AS p_kg,
                COALESCE(SUM(quantity_applied * k_at_entry / 100.0),0) AS k_kg,
                COALESCE(SUM(quantity_applied),0) AS qty,
                COUNT(*) AS n')
            ->whereIn('plot_id', $ids)
            ->groupBy('plot_id');
        $this->applyWindow($q, 'fertilization_operations', $from, $to);
        $agg = collect($q->get())->keyBy('plot_id');

        $wanted = (string) ($args['nutrient'] ?? 'all');
        $rows = [];
        foreach ($plots as $p) {
            $a = $agg->get((string) $p->id);
            $ha = self::ha($p);
            $row = [
                'plot'            => $names[(string) $p->id],
                'surface_area_ha' => $ha,
                'applications'    => (int) ($a->n ?? 0),
                'total_product_kg' => round((float) ($a->qty ?? 0), 2),
            ];
            foreach (['n', 'p', 'k'] as $nut) {
                if ($wanted !== 'all' && $wanted !== $nut) continue;
                $kg = (float) ($a->{$nut.'_kg'} ?? 0);
                $row[strtoupper($nut).'_kg']        = round($kg, 2);
                $row[strtoupper($nut).'_units_ha'] = self::perHa($kg, $ha);
            }
            $rows[] = $row;
        }

        return [
            'window'   => ['from' => $from, 'to' => $to],
            'unit'     => 'kg/ha (fertilising units per hectare)',
            'nutrient' => $wanted,
            'plots'    => array_slice($rows, 0, 40),
            'note'     => 'Magnesium (Mg) is not tracked: the fertilizer catalog only stores N, P and K percentages.',
        ];
    }

    /** @param array<string,mixed> $args */
    private function toolTreatments(array $args): array
    {
        [$plots, $names, $err] = $this->plotScope($args);
        if ($err) return $err;
        [$from, $to] = $this->windowFrom($args);

        $ids   = array_map(static fn ($p) => $p->id, $plots);
        $areas = [];
        foreach ($plots as $p) $areas[(string) $p->id] = self::ha($p);

        $q = DB::table('phytosanitary_operations as po')
            ->leftJoin('pesticides as pe', 'pe.id', '=', 'po.pesticide_id')
            ->select(
                'po.id', 'po.plot_id', 'po.operation_date', 'po.quantity_applied',
                'po.water_volume_l', 'po.target_pest', 'po.remarks', 'po.price_at_entry',
                'pe.name as product', 'pe.unit as product_unit', 'pe.chemical_composition',
            )
            ->whereIn('po.plot_id', $ids);
        $this->applyWindow($q, 'po', $from, $to);

        if (! empty($args['pest'])) {
            $likes = array_map(
                static fn (string $term): string => '%'.mb_strtolower($term).'%',
                $this->pestSearchTerms((string) $args['pest']),
            );
            $q->where(function ($w) use ($likes) {
                foreach ($likes as $like) {
                    $w->orWhereRaw('LOWER(po.target_pest) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(COALESCE(po.remarks, \'\')) LIKE ?', [$like]);
                }
            });
        }
        if (! empty($args['product'])) {
            $q->whereRaw('LOWER(pe.name) LIKE ?', ['%'.mb_strtolower((string) $args['product']).'%']);
        }

        $total = (clone $q)->count();
        $order = strtolower((string) ($args['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $limit = max(1, min(40, (int) ($args['limit'] ?? 20)));
        $raw = $q->orderBy('po.operation_date', $order)->limit($limit)->get();

        $rows = [];
        foreach ($raw as $r) {
            $ha  = $areas[(string) $r->plot_id] ?? 0.0;
            $vol = $r->water_volume_l !== null ? (float) $r->water_volume_l : null;
            $rows[] = [
                'date'            => (string) $r->operation_date,
                'plot'            => $names[(string) $r->plot_id] ?? null,
                'product'         => $r->product,
                'composition'     => $r->chemical_composition,
                'dose'            => round((float) $r->quantity_applied, 3),
                'dose_unit'       => $r->product_unit,
                'dose_per_ha'     => self::perHa((float) $r->quantity_applied, $ha),
                'water_volume_l'  => $vol,
                'volume_l_per_ha' => $vol !== null ? self::perHa($vol, $ha) : null,
                'target_pest'     => $r->target_pest,
                'cost_tnd'        => round((float) $r->quantity_applied * (float) $r->price_at_entry, 2),
            ];
        }

        return [
            'window'          => ['from' => $from, 'to' => $to],
            'pest'            => $args['pest'] ?? null,
            'treatment_count' => $total,
            'order'           => $order,
            'rows'            => $rows,
            'returned'        => count($rows),
        ];
    }

    /** @return array<int, string> */
    private function pestSearchTerms(string $raw): array
    {
        $needle = trim(mb_strtolower($raw));
        $terms = [$needle];

        $norm = self::normLabel($needle);
        $aliases = [
            'mildiou' => ['mildiou', 'mildew', 'plasmopara', 'downy mildew'],
            'oidium'  => ['oïdium', 'oidium', 'powdery mildew', 'erysiphe', 'uncinula'],
            'botrytis'=> ['botrytis', 'pourriture grise', 'gray mold', 'grey mold'],
            'ceratite'=> ['cératite', 'ceratite', 'ceratitis capitata', 'mouche méditerranéenne'],
        ];
        foreach ($aliases as $key => $values) {
            if ($norm !== '' && (str_contains($norm, $key) || str_contains($key, $norm))) {
                $terms = array_merge($terms, $values);
            }
        }

        return array_values(array_unique(array_filter($terms, static fn ($term) => trim($term) !== '')));
    }

    /** @param array<string,mixed> $args */
    private function toolFertilizationHistory(array $args): array
    {
        [$plots, $names, $err] = $this->plotScope($args);
        if ($err) return $err;
        [$from, $to] = $this->windowFrom($args);

        $ids = array_map(static fn ($p) => $p->id, $plots);
        $areas = [];
        foreach ($plots as $p) $areas[(string) $p->id] = self::ha($p);

        $q = DB::table('fertilization_operations as fo')
            ->leftJoin('fertilizers as f', 'f.id', '=', 'fo.fertilizer_id')
            ->select(
                'fo.plot_id', 'fo.operation_date', 'fo.quantity_applied', 'fo.price_at_entry',
                'fo.n_at_entry', 'fo.p_at_entry', 'fo.k_at_entry',
                'f.name as product', 'f.unit as product_unit',
            )
            ->whereIn('fo.plot_id', $ids);
        $this->applyWindow($q, 'fo', $from, $to);
        if (! empty($args['product'])) {
            $q->whereRaw('LOWER(f.name) LIKE ?', ['%'.mb_strtolower((string) $args['product']).'%']);
        }

        $total = (clone $q)->count();
        $order = strtolower((string) ($args['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $limit = max(1, min(40, (int) ($args['limit'] ?? 20)));
        $raw = $q->orderBy('fo.operation_date', $order)->limit($limit)->get();

        $rows = [];
        foreach ($raw as $r) {
            $ha = $areas[(string) $r->plot_id] ?? 0.0;
            $rows[] = [
                'date'         => (string) $r->operation_date,
                'plot'         => $names[(string) $r->plot_id] ?? null,
                'product'      => $r->product,
                'npk_percent'  => [(float) $r->n_at_entry, (float) $r->p_at_entry, (float) $r->k_at_entry],
                'quantity'     => round((float) $r->quantity_applied, 2),
                'unit'         => $r->product_unit,
                'quantity_per_ha' => self::perHa((float) $r->quantity_applied, $ha),
                'cost_tnd'     => round((float) $r->quantity_applied * (float) $r->price_at_entry, 2),
            ];
        }

        return [
            'window'            => ['from' => $from, 'to' => $to],
            'product'           => $args['product'] ?? null,
            'application_count' => $total,
            'order'             => $order,
            'rows'              => $rows,
        ];
    }

    /** @param array<string,mixed> $args */
    private function toolIrrigationHistory(array $args): array
    {
        [$plots, $names, $err] = $this->plotScope($args);
        if ($err) return $err;
        [$from, $to] = $this->windowFrom($args);

        $ids = array_map(static fn ($p) => $p->id, $plots);
        $areas = [];
        foreach ($plots as $p) $areas[(string) $p->id] = self::ha($p);

        $q = DB::table('irrigation_operations')
            ->select('plot_id', 'operation_date', 'water_quantity', 'unit_at_entry', 'price_at_entry')
            ->whereIn('plot_id', $ids);
        $this->applyWindow($q, 'irrigation_operations', $from, $to);

        $total = (clone $q)->count();
        $order = strtolower((string) ($args['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $limit = max(1, min(40, (int) ($args['limit'] ?? 10)));
        $raw = $q->orderBy('operation_date', $order)->limit($limit)->get();

        $rows = [];
        foreach ($raw as $r) {
            $ha = $areas[(string) $r->plot_id] ?? 0.0;
            $rows[] = [
                'date'      => (string) $r->operation_date,
                'plot'      => $names[(string) $r->plot_id] ?? null,
                'quantity'  => round((float) $r->water_quantity, 2),
                'unit'      => $r->unit_at_entry,
                'per_ha'    => self::perHa((float) $r->water_quantity, $ha),
                'cost_tnd'  => round((float) $r->water_quantity * (float) $r->price_at_entry, 2),
            ];
        }

        return [
            'window'           => ['from' => $from, 'to' => $to],
            'irrigation_count' => $total,
            'order'            => $order,
            'rows'             => $rows,
        ];
    }

    /** @param array<string,mixed> $args */
    private function toolHarvestHistory(array $args): array
    {
        [$plots, $names, $err] = $this->plotScope($args);
        if ($err) return $err;
        [$from, $to] = $this->windowFrom($args);

        $ids = array_map(static fn ($p) => $p->id, $plots);
        $areas = [];
        foreach ($plots as $p) $areas[(string) $p->id] = self::ha($p);
        $totalHa = array_sum($areas);

        $q = DB::table('harvest_operations')
            ->select('plot_id', 'operation_date', 'quantity_harvested', 'num_workers', 'days_worked', 'daily_rate_at_entry')
            ->whereIn('plot_id', $ids);
        $this->applyWindow($q, 'harvest_operations', $from, $to);

        $limit = max(1, min(40, (int) ($args['limit'] ?? 20)));
        $raw = $q->orderBy('operation_date')->limit($limit)->get();

        $rows = []; $sumKg = 0.0; $cost = 0.0; $first = null; $last = null;
        foreach ($raw as $r) {
            $ha = $areas[(string) $r->plot_id] ?? 0.0;
            $kg = (float) $r->quantity_harvested;
            $c  = (float) $r->num_workers * (float) $r->days_worked * (float) $r->daily_rate_at_entry;
            $sumKg += $kg; $cost += $c;
            $d = (string) $r->operation_date;
            $first = $first === null ? $d : min($first, $d);
            $last  = $last === null ? $d : max($last, $d);
            $rows[] = [
                'date'      => $d,
                'plot'      => $names[(string) $r->plot_id] ?? null,
                'kg'        => round($kg, 2),
                'kg_per_ha' => self::perHa($kg, $ha),
                'workers'   => (int) $r->num_workers,
                'days'      => (float) $r->days_worked,
                'labour_cost_tnd' => round($c, 2),
            ];
        }

        return [
            'window'         => ['from' => $from, 'to' => $to],
            'harvest_count'  => count($rows),
            'first_harvest'  => $first,
            'last_harvest'   => $last,
            'total_kg'       => round($sumKg, 2),
            'kg_per_ha'      => self::perHa($sumKg, $totalHa),
            'labour_cost_tnd' => round($cost, 2),
            'rows'           => $rows,
        ];
    }

    /** @param array<string,mixed> $args */
    private function toolCostPerHa(array $args): array
    {
        [$plots, $names, $err] = $this->plotScope($args);
        if ($err) return $err;
        [$from, $to] = $this->windowFrom($args);

        $ids  = array_map(static fn ($p) => $p->id, $plots);
        $want = (string) ($args['type'] ?? 'all');

        $costExpr = [
            'irrigation'    => 'water_quantity * price_at_entry',
            'fertilization' => 'quantity_applied * price_at_entry',
            'phytosanitary' => 'quantity_applied * price_at_entry',
            'harvest'       => 'num_workers * days_worked * daily_rate_at_entry',
        ];

        $byPlot = [];
        foreach ($costExpr as $type => $expr) {
            if ($want !== 'all' && $want !== $type) continue;
            $table = self::OP_TABLE[$type];
            if (! Schema::hasTable($table)) continue;
            $q = DB::table($table)
                ->selectRaw("plot_id, COALESCE(SUM($expr),0) AS cost")
                ->whereIn('plot_id', $ids)
                ->groupBy('plot_id');
            $this->applyWindow($q, $table, $from, $to);
            foreach ($q->get() as $r) {
                $byPlot[(string) $r->plot_id][$type] = round((float) $r->cost, 2);
            }
        }

        $rows = []; $grand = 0.0; $grandHa = 0.0;
        foreach ($plots as $p) {
            $ha = self::ha($p);
            $costs = $byPlot[(string) $p->id] ?? [];
            $total = array_sum($costs);
            $grand += $total; $grandHa += $ha;
            $rows[] = [
                'plot'            => $names[(string) $p->id],
                'surface_area_ha' => $ha,
                'by_type_tnd'     => $costs,
                'total_tnd'       => round($total, 2),
                'cost_per_ha_tnd' => self::perHa($total, $ha),
            ];
        }

        return [
            'window'   => ['from' => $from, 'to' => $to],
            'scope'    => $want,
            'currency' => 'TND',
            'plots'    => array_slice($rows, 0, 40),
            'overall'  => [
                'total_tnd'       => round($grand, 2),
                'cost_per_ha_tnd' => self::perHa($grand, $grandHa),
            ],
        ];
    }

    /** @param array<string,mixed> $args */
    private function toolProductInfo(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if (mb_strlen($query) < 2) return ['error' => 'query_too_short'];
        $kind = (string) ($args['kind'] ?? 'any');
        $like = '%'.mb_strtolower($query).'%';
        $out  = [];

        if (($kind === 'any' || $kind === 'fertilizer') && Schema::hasTable('fertilizers')) {
            $rows = DB::table('fertilizers')
                ->select('id', 'name', 'unit', 'n_percent', 'p_percent', 'k_percent', 'is_active')
                ->whereRaw('LOWER(name) LIKE ?', [$like])->limit(5)->get();
            foreach ($rows as $r) {
                $out[] = [
                    'kind'        => 'fertilizer',
                    'id'          => $r->id,
                    'name'        => $r->name,
                    'unit'        => $r->unit,
                    'composition' => ['N%' => (float) $r->n_percent, 'P%' => (float) $r->p_percent, 'K%' => (float) $r->k_percent],
                    'prices'      => $this->priceHistoryFor('fertilizer', (string) $r->id),
                ];
            }
        }
        if (($kind === 'any' || $kind === 'pesticide') && Schema::hasTable('pesticides')) {
            $rows = DB::table('pesticides')
                ->select('id', 'name', 'unit', 'chemical_composition', 'is_active')
                ->whereRaw('LOWER(name) LIKE ?', [$like])->limit(5)->get();
            foreach ($rows as $r) {
                $out[] = [
                    'kind'        => 'pesticide',
                    'id'          => $r->id,
                    'name'        => $r->name,
                    'unit'        => $r->unit,
                    'composition' => $r->chemical_composition,
                    'prices'      => $this->priceHistoryFor('pesticide', (string) $r->id),
                ];
            }
        }

        if ($out === []) {
            return ['error' => 'product_not_found', 'query' => $query];
        }
        return ['query' => $query, 'products' => $out, 'count' => count($out)];
    }

    /** @return array<int, array<string, mixed>> */
    private function priceHistoryFor(string $entityType, string $entityId): array
    {
        if (! Schema::hasTable('price_history')) return [];
        $rows = DB::table('price_history')
            ->select('price_per_unit', 'unit', 'effective_from', 'effective_to')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderByDesc('effective_from')
            ->limit(5)
            ->get();
        return array_map(static fn ($r) => [
            'price_per_unit' => (float) $r->price_per_unit,
            'unit'           => $r->unit,
            'effective_from' => (string) $r->effective_from,
            'effective_to'   => $r->effective_to ? (string) $r->effective_to : null,
            'current'        => $r->effective_to === null,
        ], $rows->all());
    }
}
