<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

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
    /**
     * How the last `resolvePlots()` call matched the requested label.
     * Surfaced in `applied_filters` so the assistant can disclose a guess
     * instead of silently answering about a different plot.
     */
    private ?string $plotMatchNote = null;

    /** Pre-aggregated daily rollup — the fast path for period/stat questions. */
    private ?AiDailyRollup $rollup = null;

    private function rollup(): AiDailyRollup
    {
        return $this->rollup ??= new AiDailyRollup();
    }

    /** @return array<int, array<string, mixed>> */
    private function farmDefinitions(): array
    {
        $plot    = ['type' => 'string', 'description' => 'Plot name (e.g. "P1", "Parcelle 3") or plot UUID.'];
        $crop    = ['type' => 'string', 'description' => 'Crop type filter, substring (e.g. "vigne").'];
        $exclude = ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Plot names or UUIDs to exclude (e.g. ["P1"]).'];
        $from    = ['type' => 'string', 'description' => 'Start date YYYY-MM-DD or a natural phrase ("juillet 2026", "aujourd\'hui").'];
        $to      = ['type' => 'string', 'description' => 'End date YYYY-MM-DD or natural phrase. Omit both for all-time / "jusqu\'à ce jour".'];
        $campaign = ['type' => 'string', 'description' => 'Campaign / season name, id, or "active"/"en cours". When given and from/to are omitted, the window becomes the campaign window (start_date to end_date). Use for "campagne 2024-2025", "cette saison".'];

        return [
            $this->fn('plot_info', 'Identity card of one or more plots: id, name, surface_area_ha, crop_type, variety, active campaign, and the last operation date of each type. Use for "quelle est la surface de la parcelle X".', [
                'plot' => $plot,
                'crop' => $crop,
            ]),

            $this->fn('water_per_ha', 'AGGREGATE ONLY. Irrigation water received per hectare. Returns, per plot: total m³, m³/ha, number of irrigations, cost; plus the average m³/ha across the selected plots. Use for "quantité d\'eau/ha reçue par la parcelle X" (today, a date range, or a whole crop with exclusions). Do NOT use this when the user asks for "le détail", "la liste", "les dates" or a per-irrigation breakdown — call irrigation_history instead (or both, and reconcile the totals).', [
                'plot'          => $plot,
                'crop'          => $crop,
                'exclude_plots' => $exclude,
                'from'          => $from,
                'to'            => $to,
                'campaign'            => $campaign,
            ]),

            $this->fn('nutrient_per_ha', 'Fertilization nutrient balance: kg of N, P, K, Mg, Ca, S applied and units/ha (kg/ha) per plot over a window. Use for "combien d\'unités/ha d\'azote / de magnésium a reçu la parcelle X".', [
                'plot'          => $plot,
                'crop'          => $crop,
                'exclude_plots' => $exclude,
                'nutrient'      => ['type' => 'string', 'enum' => ['n', 'p', 'k', 'mg', 'ca', 's', 'all'], 'description' => 'Restrict to one nutrient; default all.'],

                'from'          => $from,
                'to'            => $to,
                'campaign'            => $campaign,
            ]),

            $this->fn('treatments', 'Phytosanitary treatment log with product name, chemical composition, dose, water volume, volume/ha and cost. Filter by target pest (mildiou, oïdium, cicadelle…) and/or product. Use for treatment counts, dates, chronology, last treatment, product compositions and volume/ha.', [
                'plot'       => $plot,
                'crop'       => $crop,
                'pest'       => ['type' => 'string', 'description' => 'Target pest substring, matched on target_pest and remarks (e.g. "mildiou").'],
                'product'    => ['type' => 'string', 'description' => 'Pesticide name substring.'],
                'from'       => $from,
                'to'         => $to,
                'campaign'         => $campaign,
                'order'      => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'asc = chronological, desc = most recent first (default).'],
                'limit'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => 40],
            ]),

            $this->fn('fertilization_history', 'Fertilization log with product name, N-P-K %, quantity, quantity/ha and cost. Filter by product name (e.g. "acides aminés") to count applications or find the last fertilization date.', [
                'plot'    => $plot,
                'crop'    => $crop,
                'product' => ['type' => 'string', 'description' => 'Fertilizer name substring.'],
                'from'    => $from,
                'to'      => $to,
                'campaign'      => $campaign,
                'order'   => ['type' => 'string', 'enum' => ['asc', 'desc']],
                'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 40],
            ]),

            $this->fn('product_usage', 'Cross-catalog product usage log. Resolves a product or ingredient-family query against fertilizer names and pesticide names/compositions, then counts matching fertilization and phytosanitary applications. Use for generic "combien de fois avons-nous utilisé X", especially biostimulants such as amino acids whose operation type is not explicit.', [
                'plot'    => $plot,
                'crop'    => $crop,
                'query'   => ['type' => 'string', 'description' => 'Product name or distinctive family substring, e.g. "Naturamin" or "amin".'],
                'from'    => $from,
                'to'      => $to,
                'campaign'      => $campaign,
                'order'   => ['type' => 'string', 'enum' => ['asc', 'desc']],
                'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 80],
            ], ['query']),

            $this->fn('irrigation_history', 'Individual irrigation events, one row each: date, m³, m³/ha, cost. Use for "les dates des 3 dernières irrigations" and for ANY request for "le détail"/"la liste" of irrigations over a period. Rows are capped by `limit`; always compare `returned_rows` with `irrigation_count` and tell the user when the list is truncated.', [
                'plot'  => $plot,
                'crop'  => $crop,
                'from'  => $from,
                'to'    => $to,
                'campaign'    => $campaign,
                'order' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 40],
            ]),

            $this->fn('harvest_history', 'Harvest events plus the first and last harvest date, total kg, kg/ha and labour cost. Use for "entre quelles dates a été récoltée la parcelle X".', [
                'plot'  => $plot,
                'crop'  => $crop,
                'from'  => $from,
                'to'    => $to,
                'campaign'    => $campaign,
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 40],
            ]),

            $this->fn('cost_per_ha', 'Cost breakdown per plot by operation type (irrigation, fertilization, phytosanitary, harvest labour) in TND, with total cost and cost/ha. Use for "coût/ha de la parcelle X" or "coût/ha en traitement".', [
                'plot' => $plot,
                'crop' => $crop,
                'type' => ['type' => 'string', 'enum' => ['irrigation', 'fertilization', 'phytosanitary', 'harvest', 'all']],
                'from' => $from,
                'to'   => $to,
                'campaign'   => $campaign,
            ]),

            $this->fn('product_info', 'Look up a fertilizer or pesticide by name: unit, composition (N-P-K % for fertilizers, chemical_composition for pesticides) and current + historical price per unit. Use for "quel est le prix de X" and "quelle est la composition de Y".', [
                'query' => ['type' => 'string', 'description' => 'Product name, 2+ chars.'],
                'kind'  => ['type' => 'string', 'enum' => ['fertilizer', 'pesticide', 'any']],
            ], ['query']),

            $this->fn('campaign_compare', 'Compare one metric between two campaigns / seasons ("2024-2025" vs "2025-2026"), optionally restricted to a plot or a crop. Returns both campaign windows, their totals, their per-hectare values and the delta + percent change. Use for any season-over-season question ("a-t-on consommé plus d\'eau que la saison dernière ?").', [
                'campaign_a' => ['type' => 'string', 'description' => 'First campaign name or id, or "active" for the current season.'],
                'campaign_b' => ['type' => 'string', 'description' => 'Second campaign name or id. Omit to auto-pick the campaign immediately preceding campaign_a.'],
                'metric'     => ['type' => 'string', 'enum' => ['water_m3', 'fertilizer_qty', 'treatments_count', 'harvest_kg', 'cost_tnd'], 'description' => 'Default cost_tnd.'],
                'plot'       => $plot,
                'crop'       => $crop,
            ], ['campaign_a']),

            $this->fn('data_quality', 'Audit the RECORDS THEMSELVES rather than the agronomy: operations whose cost resolves to 0 (missing price snapshot AND no price_history row), operations with a null/zero quantity, plots with no surface_area_ha (which makes every per-hectare figure impossible), operations not attached to any campaign, mixed water units, future-dated rows and likely duplicates. Call this whenever a figure looks too low/too round, when a per-ha value is null, or when the user asks "les donnees sont-elles completes / fiables ?". Also call it BEFORE blaming the data in an answer.', [
                'plot'   => $plot,
                'crop'   => $crop,
                'from'   => $from,
                'to'     => $to,
                'campaign' => $campaign,
                'checks' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['missing_price', 'missing_quantity', 'missing_surface', 'missing_campaign', 'unit_mismatch', 'future_dated', 'duplicates']], 'description' => 'Restrict to some checks; default all.'],
            ]),

            $this->fn('sync_status', 'Mobile-app synchronisation health from the `postings` queue: counts by status (pending / failed / synced), the oldest pending submission, failure samples with their error message, and per-operation-type pending counts. Use for "y a-t-il des donnees non synchronisees ?" and, crucially, to WARN the user that a total may be incomplete when pending or failed postings exist.', [
                'status' => ['type' => 'string', 'enum' => ['pending', 'failed', 'synced', 'all'], 'description' => 'Default all.'],
                'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            ]),
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
            'product_usage'         => $this->toolProductUsage($args),
            'irrigation_history'    => $this->toolIrrigationHistory($args),
            'harvest_history'       => $this->toolHarvestHistory($args),
            'cost_per_ha'           => $this->toolCostPerHa($args),
            'product_info'          => $this->toolProductInfo($args),
            'campaign_compare'      => $this->toolCampaignCompare($args),
            'data_quality'          => $this->toolDataQuality($args),
            'sync_status'           => $this->toolSyncStatus($args),
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
        $this->plotMatchNote = null;

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
                                $this->plotMatchNote = sprintf(
                                    'No exact match for "%s". Answered about "%s" (fuzzy similarity %d%%). Tell the user which plot you used and ask them to confirm.',
                                    $plot,
                                    (string) $best->name,
                                    (int) round($bestScore),
                                );
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
        // NOTE: deliberately does NOT reuse safeDate(), which falls back to
        // today's date when parsing fails. For a reporting window that
        // fallback is dangerous: the user asks for June and silently gets a
        // single day. Dropping the bound widens the window instead, and
        // appliedFilters() reports the discrepancy back to the model.
        $window = [
            ! empty($args['from']) ? $this->boundOrNull($args['from'], 'from') : null,
            ! empty($args['to']) ? $this->boundOrNull($args['to'], 'to') : null,
        ];

        // A campaign is a named season window. Explicit from/to always win —
        // the user asked for a narrower slice inside that season.
        $this->campaignNote = null;
        if (! empty($args['campaign'])) {
            $c = $this->resolveCampaign((string) $args['campaign']);
            if ($c === null) {
                $this->campaignNote = 'Campaign "'.$args['campaign'].'" was not found; the window was NOT restricted to a campaign. Call list_campaigns to get the real names.';
            } else {
                $this->campaignNote = 'Scoped to campaign "'.$c->name.'" ('.$c->start_date.' → '.$c->end_date.').';
                $window[0] ??= $c->start_date;
                $window[1] ??= $c->end_date;
            }
        }

        return $window;
    }

    /** Set by windowFrom(); surfaced through appliedFilters(). */
    private ?string $campaignNote = null;

    /**
     * Resolve a campaign label ("2024-2025", "active", "en cours", a uuid)
     * to its row. Matching mirrors resolvePlots(): exact id, exact name,
     * normalised equality, then substring.
     */
    private function resolveCampaign(string $label): ?object
    {
        if (! Schema::hasTable('campaigns')) return null;
        $raw = trim($label);
        if ($raw === '') return null;

        $base = fn () => DB::table('campaigns')->select('id', 'name', 'start_date', 'end_date', 'is_active');

        if (in_array(mb_strtolower($raw), ['active', 'current', 'en cours', 'actuelle', 'cette saison', 'this season'], true)) {
            return $base()->where('is_active', true)->orderByDesc('start_date')->first();
        }

        $hit = $base()->where('id', $raw)->first() ?? $base()->where('name', $raw)->first();
        if ($hit) return $hit;

        $all  = $base()->orderByDesc('start_date')->limit(60)->get();
        $norm = self::normLabel($raw);
        foreach ($all as $c) {
            if (self::normLabel((string) $c->name) === $norm) return $c;
        }
        foreach ($all as $c) {
            if ($norm !== '' && str_contains(self::normLabel((string) $c->name), $norm)) return $c;
        }

        return null;
    }

    /** The campaign whose window ends just before $c starts. */
    private function previousCampaign(object $c): ?object
    {
        return DB::table('campaigns')
            ->select('id', 'name', 'start_date', 'end_date', 'is_active')
            ->where('start_date', '<', $c->start_date)
            ->orderByDesc('start_date')
            ->first();
    }

    private function boundOrNull(mixed $v, string $edge): ?string
    {
        $raw = trim((string) $v);
        if ($raw === '') return null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            try { return Carbon::parse($raw)->toDateString(); }
            catch (Throwable) { return null; }
        }

        $parsed = $this->dates->parse($raw);
        if ($parsed !== null) {
            return $edge === 'to' ? $parsed['to'] : $parsed['from'];
        }

        try { return Carbon::parse($raw)->toDateString(); }
        catch (Throwable) { return null; }
    }

    private function applyWindow(mixed $q, string $table, ?string $from, ?string $to): mixed
    {
        if ($from !== null) $q->where($table.'.operation_date', '>=', $from);
        if ($to !== null)   $q->where($table.'.operation_date', '<=', $to);
        return $q;
    }

    /**
     * Litre-denominated unit labels written into `unit_at_entry` by
     * OperationPriceResolver::n(). The active water unit is a per-farm
     * setting that can change mid-season, and the value is snapshotted on
     * every row — so a plain SUM(water_quantity) silently adds litres to
     * cubic metres and reports the result as m³.
     */
    private const LITRE_UNITS = ['l', 'lt', 'ltr', 'liter', 'liters', 'litre', 'litres'];

    /** SQL expression normalising `water_quantity` to cubic metres. */
    private static function m3Expr(string $col = 'water_quantity', string $unitCol = 'unit_at_entry'): string
    {
        $list = "'".implode("','", self::LITRE_UNITS)."'";

        return "CASE WHEN LOWER(TRIM(COALESCE($unitCol, 'm3'))) IN ($list)
                     THEN ($col) / 1000.0 ELSE ($col) END";
    }

    /** PHP-side twin of {@see m3Expr} for single rows. */
    private static function toM3(float $qty, ?string $unit): float
    {
        return in_array(mb_strtolower(trim((string) ($unit ?? 'm3'))), self::LITRE_UNITS, true)
            ? $qty / 1000.0
            : $qty;
    }

    /**
     * Echo back exactly what the query filtered on, so the assistant can
     * state its scope instead of implying it read every record. Without
     * this the model confidently claims exhaustiveness it cannot verify.
     *
     * @param  array<string,mixed>   $args
     * @param  array<string,string>  $names  plot_id => resolved name
     * @return array<string,mixed>
     */
    private function appliedFilters(array $args, ?string $from, ?string $to, array $names): array
    {
        $out = [
            'requested_plot'  => $args['plot'] ?? ($args['crop'] ?? null),
            'resolved_plots'  => array_values($names),
            'date_from'       => $from,
            'date_to'         => $to,
            'bounds'          => 'inclusive on both ends (operation_date >= date_from AND <= date_to)',
            'campaign_scope'  => $this->campaignNote
                ?? 'none — every campaign is included. The Reports screen filters by campaign, so a figure shown there can legitimately differ.',
        ];

        if ($this->plotMatchNote !== null) {
            $out['plot_match_warning'] = $this->plotMatchNote;
        }

        if (($args['from'] ?? null) !== null && $from === null) {
            $out['date_warning'] = 'The requested start date could not be parsed and was ignored — the window is wider than the user asked for.';
        }

        return $out;
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
        $totalPlots = count($plots);
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
        return [
            'plots'          => $out,
            'count'          => $totalPlots,
            'total_matching' => $totalPlots,
            'returned_rows'  => count($out),
            'truncated'      => $totalPlots > count($out),
            'count_note'     => 'total_matching is the TRUE number of matching plots; plots may be truncated. Never count the rows yourself.',
        ];
    }

    /**
     * Unit-price SQL expression, IDENTICAL to the one the Reports screen uses
     * (ReportController::productionCost). Without the price_history fallback
     * the assistant reports a *lower* cost than the report for any operation
     * whose price snapshot is missing or zero.
     *
     * @param  string|null  $entityFk  FK column carrying the priced entity
     *                                 (fertilizer_id / pesticide_id); null for
     *                                 farm-global prices (water, labor).
     */
    private static function priceExpr(string $entityType, string $fallbackColumn, ?string $entityFk = null): string
    {
        $scope = $entityFk !== null ? "AND ph.entity_id = op.$entityFk" : '';

        return "(
            COALESCE(
                NULLIF(op.$fallbackColumn, 0),
                (SELECT ph.price_per_unit FROM price_history ph
                  WHERE ph.entity_type = '$entityType' $scope
                    AND ph.effective_from <= op.operation_date
                  ORDER BY ph.effective_from DESC, ph.id DESC LIMIT 1),
                (SELECT ph.price_per_unit FROM price_history ph
                  WHERE ph.entity_type = '$entityType' $scope
                  ORDER BY ph.effective_from ASC, ph.id ASC LIMIT 1),
                0
            )
        )";
    }

    /** @param array<string,mixed> $args */
    private function toolWaterPerHa(array $args): array
    {
        [$plots, $names, $err] = $this->plotScope($args);
        if ($err) return $err;
        [$from, $to] = $this->windowFrom($args);

        $ids = array_map(static fn ($p) => $p->id, $plots);

        // Volumes come from the pre-aggregated daily rollup when it is fresh
        // (units normalised at build time → a repeated question can never
        // yield a different total). Money NEVER does: costs are always
        // recomputed with the same price expression as the Reports screen, on
        // the RAW recorded quantity, because `price_at_entry` is denominated
        // in the recorded unit (a litre price × m³ volume is 1000× too small).
        $source = 'daily_rollup';
        $agg = $this->rollup()->aggregate('irrigation', array_map('strval', $ids), $from, $to);
        if ($agg === null) {
            $source = 'live_scan';
            $m3 = self::m3Expr('op.water_quantity', 'op.unit_at_entry');
            $q = DB::table('irrigation_operations as op')
                ->selectRaw("op.plot_id AS plot_id,
                    COALESCE(SUM($m3),0) AS qty,
                    COUNT(*) AS ops,
                    COUNT(*) FILTER (WHERE op.water_quantity IS NULL) AS missing_qty,
                    COUNT(*) FILTER (WHERE op.price_at_entry IS NULL) AS missing_price,
                    COUNT(DISTINCT LOWER(TRIM(COALESCE(op.unit_at_entry, 'm3')))) AS unit_variants,
                    MIN(op.operation_date) AS first_date,
                    MAX(op.operation_date) AS last_date")
                ->whereIn('op.plot_id', $ids)
                ->groupBy('op.plot_id');
            $this->applyWindow($q, 'op', $from, $to);
            $agg = [];
            foreach ($q->get() as $r) {
                $agg[(string) $r->plot_id] = [
                    'ops'           => (int) $r->ops,
                    'qty'           => (float) $r->qty,
                    'cost'          => 0.0,
                    'missing_qty'   => (int) $r->missing_qty,
                    'missing_price' => (int) $r->missing_price,
                    'unit_variants' => (int) $r->unit_variants,
                    'first_date'    => $r->first_date ? Carbon::parse((string) $r->first_date)->toDateString() : null,
                    'last_date'     => $r->last_date ? Carbon::parse((string) $r->last_date)->toDateString() : null,
                ];
            }
        }

        foreach ($this->irrigationCostByPlot($ids, $from, $to) as $pid => $cost) {
            $agg[$pid] ??= [];
            $agg[$pid]['cost'] = $cost;
        }

        $rows = [];
        $sumPerHa = 0.0; $withArea = 0;
        $totalM3 = 0.0; $totalHa = 0.0; $totalCost = 0.0;
        foreach ($plots as $p) {
            $a = $agg[(string) $p->id] ?? [];
            $qty = (float) ($a['qty'] ?? 0);
            $ha  = self::ha($p);
            $perHa = self::perHa($qty, $ha);
            if ($perHa !== null) { $sumPerHa += $perHa; $withArea++; $totalHa += $ha; }
            $totalM3 += $qty;
            $totalCost += (float) ($a['cost'] ?? 0);
            $row = [
                'plot'            => $names[(string) $p->id],
                'surface_area_ha' => $ha,
                'irrigations'     => (int) ($a['ops'] ?? 0),
                'total_m3'        => round($qty, 2),
                'm3_per_ha'       => $perHa,
                'cost_tnd'        => round((float) ($a['cost'] ?? 0), 2),
                'first_date'      => $a['first_date'] ?? null,
                'last_date'       => $a['last_date'] ?? null,
            ];

            // Data-quality flags. The model MUST relay these instead of
            // presenting a partial total as an exhaustive one.
            $warnings = [];
            if ((int) ($a['missing_qty'] ?? 0) > 0) {
                $warnings[] = (int) $a['missing_qty'].' irrigation(s) have no recorded volume and count as 0 m³.';
            }
            if ((int) ($a['unit_variants'] ?? 0) > 1) {
                $warnings[] = 'Mixed source units on this plot; volumes were converted to m³ before summing.';
            }
            if ($warnings !== []) $row['warnings'] = $warnings;

            $rows[] = $row;
        }

        return [
            'result_kind'      => 'aggregate',
            'applied_filters'  => $this->appliedFilters($args, $from, $to, $names),
            'computed_from'    => $source,
            'unit'             => 'm3/ha',
            'plots'            => array_slice($rows, 0, 40),
            'plot_count'       => count($rows),
            'total_m3'         => round($totalM3, 2),
            'total_cost_tnd'   => round($totalCost, 2),
            // Two different, both-legitimate "averages". Quote the weighted one
            // for a farm/crop figure; the unweighted one only if the user asks
            // for "la moyenne des parcelles".
            'weighted_m3_per_ha' => self::perHa($totalM3, $totalHa),
            'average_m3_per_ha' => $withArea > 0 ? round($sumPerHa / $withArea, 2) : null,
            'average_note'     => 'weighted_m3_per_ha = total m³ / total ha (matches the Reports screen). average_m3_per_ha = unweighted mean of the per-plot m³/ha.',
            'cost_basis'       => 'price_at_entry, falling back to the price_history row effective at the operation date — identical to the Reports/Production-cost screen.',
            'excluded'         => array_values((array) ($args['exclude_plots'] ?? [])),
            'reconcile_hint'   => 'These are summed totals, not a listing. If the user asks for the detail, disputes the number, or quotes a different figure, call irrigation_history with the SAME plot and window and show the individual rows.',
        ];
    }

    /**
     * Irrigation cost per plot, computed exactly like the Reports screen:
     * RAW recorded quantity × resolved unit price. Never the m³-normalised
     * quantity — the price is denominated in the unit that was recorded.
     *
     * @param  array<int, mixed>  $ids
     * @return array<string, float>
     */
    private function irrigationCostByPlot(array $ids, ?string $from, ?string $to): array
    {
        if ($ids === [] || ! Schema::hasTable('irrigation_operations')) {
            return [];
        }
        $expr = self::priceExpr('water', 'price_at_entry');
        $q = DB::table('irrigation_operations as op')
            ->selectRaw("op.plot_id AS plot_id, COALESCE(SUM(op.water_quantity * $expr),0) AS cost")
            ->whereIn('op.plot_id', $ids)
            ->groupBy('op.plot_id');
        $this->applyWindow($q, 'op', $from, $to);

        $out = [];
        foreach ($q->get() as $r) {
            $out[(string) $r->plot_id] = (float) $r->cost;
        }

        return $out;
    }


    /** Nutrients the app snapshots on every fertilization row. */
    private const NUTRIENTS = ['n', 'p', 'k', 'mg', 'ca', 's'];

    /** @param array<string,mixed> $args */
    private function toolNutrientPerHa(array $args): array
    {
        [$plots, $names, $err] = $this->plotScope($args);
        if ($err) return $err;
        [$from, $to] = $this->windowFrom($args);

        // Older databases may predate the Mg/Ca/S snapshot columns — select
        // only what exists so the tool degrades instead of throwing.
        $tracked = array_values(array_filter(
            self::NUTRIENTS,
            static fn (string $nut): bool => Schema::hasColumn('fertilization_operations', $nut.'_at_entry'),
        ));

        $ids = array_map(static fn ($p) => $p->id, $plots);
        $select = ['plot_id', 'COALESCE(SUM(quantity_applied),0) AS qty', 'COUNT(*) AS n'];
        foreach ($tracked as $nut) {
            $select[] = "COALESCE(SUM(quantity_applied * {$nut}_at_entry / 100.0),0) AS {$nut}_kg";
        }

        $q = DB::table('fertilization_operations')
            ->selectRaw(implode(",\n                ", $select))
            ->whereIn('plot_id', $ids)
            ->groupBy('plot_id');
        $this->applyWindow($q, 'fertilization_operations', $from, $to);
        $agg = collect($q->get())->keyBy('plot_id');

        $wanted = mb_strtolower((string) ($args['nutrient'] ?? 'all'));
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
            foreach ($tracked as $nut) {
                if ($wanted !== 'all' && $wanted !== $nut) continue;
                $kg = (float) ($a->{$nut.'_kg'} ?? 0);
                $row[strtoupper($nut).'_kg']       = round($kg, 2);
                $row[strtoupper($nut).'_units_ha'] = self::perHa($kg, $ha);
            }
            $rows[] = $row;
        }

        return [
            'window'    => ['from' => $from, 'to' => $to],
            'applied_filters' => $this->appliedFilters($args, $from, $to, $names),
            'unit'      => 'kg/ha (fertilising units per hectare)',
            'nutrient'  => $wanted,
            'tracked_nutrients' => array_map('strtoupper', $tracked),
            'plots'     => array_slice($rows, 0, 40),
            'formula'   => 'units = SUM(quantity_applied × nutrient_%_at_entry / 100) — the same formula as the Fertilization report.',
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
    private function toolProductUsage(array $args): array
    {
        [$plots, $names, $err] = $this->plotScope($args);
        if ($err) return $err;

        $needle = trim(mb_strtolower((string) ($args['query'] ?? '')));
        if (mb_strlen($needle) < 2) return ['error' => 'query_too_short'];

        // A query can name a product ("Naturamin") OR an ingredient family
        // ("acides aminés"). Families are expanded into every spelling and
        // commercial marker we may encounter in the two catalogs, so the
        // answer is identical whichever plot — or the whole farm — is asked
        // about.
        $terms = self::expandProductQuery($needle);

        [$from, $to] = $this->windowFrom($args);
        $plotIds = array_map(static fn ($p) => $p->id, $plots);
        $rows = [];
        $anyLike = static function ($where, array $columns) use ($terms): void {
            foreach ($columns as $col) {
                foreach ($terms as $term) {
                    $where->orWhereRaw("LOWER(COALESCE($col, '')) LIKE ?", ['%'.$term.'%']);
                }
            }
        };

        if (Schema::hasTable('fertilizers') && Schema::hasTable('fertilization_operations')) {
            $cols = ['product.name'];
            foreach (['chemical_composition', 'composition', 'description', 'notes'] as $extra) {
                if (Schema::hasColumn('fertilizers', $extra)) {
                    $cols[] = 'product.'.$extra;
                }
            }
            $q = DB::table('fertilization_operations as op')
                ->join('fertilizers as product', 'product.id', '=', 'op.fertilizer_id')
                ->whereIn('op.plot_id', $plotIds)
                ->where(fn ($w) => $anyLike($w, $cols))
                ->select('op.operation_date', 'op.plot_id', 'op.quantity_applied', 'product.name as product');
            $this->applyWindow($q, 'op', $from, $to);
            foreach ($q->get() as $row) {
                $rows[] = [
                    'date' => (string) $row->operation_date,
                    'plot' => $names[(string) $row->plot_id] ?? null,
                    'product' => $row->product,
                    'operation_type' => 'fertilization',
                    'quantity' => round((float) $row->quantity_applied, 3),
                ];
            }
        }

        if (Schema::hasTable('pesticides') && Schema::hasTable('phytosanitary_operations')) {
            $cols = ['product.name'];
            foreach (['chemical_composition', 'description', 'notes'] as $extra) {
                if (Schema::hasColumn('pesticides', $extra)) {
                    $cols[] = 'product.'.$extra;
                }
            }
            $q = DB::table('phytosanitary_operations as op')
                ->join('pesticides as product', 'product.id', '=', 'op.pesticide_id')
                ->whereIn('op.plot_id', $plotIds)
                ->where(fn ($w) => $anyLike($w, $cols))
                ->select('op.operation_date', 'op.plot_id', 'op.quantity_applied', 'product.name as product');
            $this->applyWindow($q, 'op', $from, $to);
            foreach ($q->get() as $row) {
                $rows[] = [
                    'date' => (string) $row->operation_date,
                    'plot' => $names[(string) $row->plot_id] ?? null,
                    'product' => $row->product,
                    'operation_type' => 'phytosanitary',
                    'quantity' => round((float) $row->quantity_applied, 3),
                ];
            }
        }

        $order = strtolower((string) ($args['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        usort($rows, static fn (array $a, array $b): int => $order === 'asc'
            ? strcmp($a['date'], $b['date'])
            : strcmp($b['date'], $a['date']));
        $total = count($rows);
        $limit = max(1, min(80, (int) ($args['limit'] ?? 40)));

        $products = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['product'],
            $rows,
        )));

        // Per-plot breakdown so "sur quelles parcelles / combien de fois
        // partout" is answerable from a single call.
        $byPlot = [];
        foreach ($rows as $row) {
            $key = (string) ($row['plot'] ?? '—');
            $byPlot[$key] = ($byPlot[$key] ?? 0) + 1;
        }
        arsort($byPlot);

        return [
            'window' => ['from' => $from, 'to' => $to],
            'applied_filters' => $this->appliedFilters($args, $from, $to, $names),
            'query' => $needle,
            'search_terms' => $terms,
            'matched_products' => $products,
            'usage_count' => $total,
            'usage_by_plot' => $byPlot,
            'plots_in_scope' => count($plotIds),
            'rows' => array_slice($rows, 0, $limit),
            'returned_rows' => min($total, $limit),
            'truncated' => $total > $limit,
        ];
    }

    /**
     * Expand an ingredient-family query into every spelling stored in the
     * catalogs. Unknown queries are returned as-is (single substring).
     *
     * @return array<int, string>
     */
    private static function expandProductQuery(string $needle): array
    {
        $families = [
            'amino' => [
                'amin', 'acide amine', 'acides amines', 'amino acid', 'aminoacid',
                'aminoacide', 'aa libre', 'peptide', 'hydrolysat', 'protein',
            ],
            'biostimulant' => ['biostimul', 'stimul', 'algue', 'extrait'],
        ];

        $flat = self::deaccent($needle);
        foreach ($families as $terms) {
            foreach ($terms as $term) {
                if (str_contains($flat, $term) || str_contains($term, $flat)) {
                    return array_values(array_unique(array_merge([$flat], $terms)));
                }
            }
        }

        return [$flat];
    }

    private static function deaccent(string $s): string
    {
        return mb_strtolower(strtr($s, [
            'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'î'=>'i','ï'=>'i','í'=>'i',
            'ô'=>'o','ö'=>'o','ó'=>'o','õ'=>'o',
            'ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u',
            'ç'=>'c','ñ'=>'n',
        ]));
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
        $m3 = self::m3Expr();
        $windowTotal = (float) ((clone $q)->selectRaw("COALESCE(SUM($m3),0) AS t")->value('t') ?? 0);
        $order = strtolower((string) ($args['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        // A "détail du 15 au 30 juin" question must not silently drop rows,
        // so an explicit window defaults to the full cap rather than 10.
        $default = ($from !== null || $to !== null) ? 40 : 10;
        $limit = max(1, min(40, (int) ($args['limit'] ?? $default)));
        $raw = $q->orderBy('operation_date', $order)->limit($limit)->get();

        $rows = [];
        foreach ($raw as $r) {
            $ha = $areas[(string) $r->plot_id] ?? 0.0;
            $qtyM3 = self::toM3((float) $r->water_quantity, $r->unit_at_entry);
            $rows[] = [
                'date'      => (string) $r->operation_date,
                'plot'      => $names[(string) $r->plot_id] ?? null,
                'quantity_m3'    => round($qtyM3, 2),
                'recorded_value' => round((float) $r->water_quantity, 2),
                'recorded_unit'  => $r->unit_at_entry,
                'per_ha'         => self::perHa($qtyM3, $ha),
                // Price is denominated in the RECORDED unit, so the cost uses
                // the raw value — not the m³-normalised one.
                'cost_tnd'       => round((float) $r->water_quantity * (float) $r->price_at_entry, 2),

            ];
        }

        // Per-day totals from the pre-aggregated rollup. They cover the WHOLE
        // window even when the row listing is truncated, so a "détail" answer
        // can stay complete and its total can be checked against the listing.
        $daily = $this->rollup()->daily('irrigation', array_map('strval', $ids), $from, $to, 62);

        $out = [
            'result_kind'      => 'listing',
            'applied_filters'  => $this->appliedFilters($args, $from, $to, $names),
            'irrigation_count' => $total,
            'returned_rows'    => count($rows),
            'truncated'        => $total > count($rows),
            'window_total_m3'  => round($windowTotal, 2),
            'order'            => $order,
            'rows'             => $rows,
        ];

        if ($daily !== null && $daily !== []) {
            $out['daily_totals'] = array_slice($daily, 0, 62);
            $out['daily_totals_note'] = 'Pre-aggregated per-day totals for the full window (not truncated). Use these when `truncated` is true.';
        }

        return $out;
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

        // Window-wide aggregates FIRST. Summing only the listed rows made the
        // totals silently wrong as soon as the listing hit `limit`.
        $totals = (clone $q)->selectRaw(
            'COUNT(*) AS n,
             COALESCE(SUM(quantity_harvested),0) AS kg,
             COALESCE(SUM(num_workers * days_worked * daily_rate_at_entry),0) AS cost,
             MIN(operation_date) AS first_date,
             MAX(operation_date) AS last_date',
        )->first();

        $count = (int) ($totals->n ?? 0);
        $sumKg = (float) ($totals->kg ?? 0);
        $cost  = (float) ($totals->cost ?? 0);
        $first = $totals?->first_date ? (string) $totals->first_date : null;
        $last  = $totals?->last_date ? (string) $totals->last_date : null;

        $limit = max(1, min(40, (int) ($args['limit'] ?? 20)));
        $raw = $q->orderBy('operation_date')->limit($limit)->get();

        $rows = [];
        foreach ($raw as $r) {
            $ha = $areas[(string) $r->plot_id] ?? 0.0;
            $kg = (float) $r->quantity_harvested;
            $c  = (float) $r->num_workers * (float) $r->days_worked * (float) $r->daily_rate_at_entry;
            $rows[] = [
                'date'      => (string) $r->operation_date,
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
            'applied_filters' => $this->appliedFilters($args, $from, $to, $names),
            'harvest_count'  => $count,
            'returned_rows'  => count($rows),
            'truncated'      => $count > count($rows),
            'first_harvest'  => $first,
            'last_harvest'   => $last,
            'total_kg'       => round($sumKg, 2),
            'kg_per_ha'      => self::perHa($sumKg, $totalHa),
            'labour_cost_tnd' => round($cost, 2),
            'cost_per_kg_tnd' => $sumKg > 0 ? round($cost / $sumKg, 3) : null,
            'totals_note'    => 'Totals cover the WHOLE window even when `rows` is truncated.',
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

        // Same expressions as ReportController::productionCost — including the
        // price_history fallback — so the assistant and the Production-cost
        // report can never disagree. Water uses the RAW recorded quantity
        // because price_at_entry is denominated in the recorded unit.
        $costExpr = [
            'irrigation'    => 'op.water_quantity * '.self::priceExpr('water', 'price_at_entry'),
            'fertilization' => 'op.quantity_applied * '.self::priceExpr('fertilizer', 'price_at_entry', 'fertilizer_id'),
            'phytosanitary' => 'op.quantity_applied * '.self::priceExpr('pesticide', 'price_at_entry', 'pesticide_id'),
            'harvest'       => 'op.num_workers * op.days_worked * '.self::priceExpr('labor', 'daily_rate_at_entry'),
        ];

        $byPlot = [];
        foreach ($costExpr as $type => $expr) {
            if ($want !== 'all' && $want !== $type) continue;
            $table = self::OP_TABLE[$type];
            if (! Schema::hasTable($table)) continue;
            $q = DB::table($table.' as op')
                ->selectRaw("op.plot_id AS plot_id, COALESCE(SUM($expr),0) AS cost")
                ->whereIn('op.plot_id', $ids)
                ->groupBy('op.plot_id');
            $this->applyWindow($q, 'op', $from, $to);
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
            'applied_filters' => $this->appliedFilters($args, $from, $to, $names),
            'scope'    => $want,
            'currency' => 'TND',
            'plots'    => array_slice($rows, 0, 40),
            'overall'  => [
                'total_tnd'       => round($grand, 2),
                'cost_per_ha_tnd' => self::perHa($grand, $grandHa),
            ],
            'formulas' => [
                'irrigation'    => 'SUM(water_quantity × unit price)',
                'fertilization' => 'SUM(quantity_applied × unit price)',
                'phytosanitary' => 'SUM(quantity_applied × unit price)',
                'harvest'       => 'SUM(num_workers × days_worked × daily rate)',
                'cost_per_ha'   => 'total ÷ surface_area_ha',
            ],
            'cost_basis' => 'price_at_entry, falling back to the price_history row effective at the operation date — identical to the Production-cost report.',
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
        return [
            'query'         => $query,
            'products'      => $out,
            'count'         => count($out),
            'returned_rows' => count($out),
            'truncated'     => count($out) >= 10,
            'count_note'    => 'products is capped at 5 per kind; if truncated is true, more products match than are listed.',
        ];
    }

    /**
     * Season-over-season comparison of one metric between two campaigns.
     *
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    private function toolCampaignCompare(array $args): array
    {
        if (! Schema::hasTable('campaigns')) {
            return ['error' => 'campaigns_unavailable'];
        }

        $a = $this->resolveCampaign((string) ($args['campaign_a'] ?? ''));
        if ($a === null) {
            return [
                'error'              => 'campaign_not_found',
                'asked'              => $args['campaign_a'] ?? null,
                'available_campaigns' => DB::table('campaigns')->orderByDesc('start_date')->limit(20)->pluck('name')->all(),
            ];
        }

        $b = ! empty($args['campaign_b'])
            ? $this->resolveCampaign((string) $args['campaign_b'])
            : $this->previousCampaign($a);

        if ($b === null) {
            return [
                'error'              => 'comparison_campaign_not_found',
                'campaign_a'         => $a->name,
                'asked'              => $args['campaign_b'] ?? '(previous campaign)',
                'available_campaigns' => DB::table('campaigns')->orderByDesc('start_date')->limit(20)->pluck('name')->all(),
            ];
        }

        // Plot scope is optional here: no plot/crop means the whole farm.
        $plots = ($args['plot'] ?? $args['crop'] ?? null) !== null ? $this->resolvePlots($args) : [];
        $ids   = array_map(static fn ($p) => (string) $p->id, $plots);
        $ha    = array_sum(array_map(static fn ($p) => self::ha($p), $plots));

        $metric = (string) ($args['metric'] ?? 'cost_tnd');

        $sideA = $this->campaignMetric($metric, $a, $ids);
        $sideB = $this->campaignMetric($metric, $b, $ids);

        $delta   = round($sideA - $sideB, 2);
        $percent = $sideB > 0 ? round(($delta / $sideB) * 100, 1) : null;

        $shape = static fn (object $c, float $v) => [
            'campaign'  => $c->name,
            'window'    => ['from' => (string) $c->start_date, 'to' => (string) $c->end_date],
            'value'     => round($v, 2),
            'per_ha'    => $ha > 0 ? round($v / $ha, 2) : null,
        ];

        return [
            'metric'          => $metric,
            'unit'            => self::METRIC_UNIT[$metric] ?? '',
            'a'               => $shape($a, $sideA),
            'b'               => $shape($b, $sideB),
            'delta'           => $delta,
            'percent_change'  => $percent,
            'direction'       => $delta > 0 ? 'higher in A' : ($delta < 0 ? 'lower in A' : 'equal'),
            'plot_scope'      => $ids === [] ? 'whole farm (all plots)' : array_map(static fn ($p) => $p->name, $plots),
            'surface_area_ha' => $ha > 0 ? round($ha, 2) : null,
            'note'            => 'Campaign windows come from campaigns.start_date/end_date and are inclusive. Operations are matched on operation_date inside that window.',
        ];
    }

    private const METRIC_UNIT = [
        'water_m3'         => 'm3',
        'fertilizer_qty'   => 'recorded fertilizer unit (kg/L as entered)',
        'treatments_count' => 'applications',
        'harvest_kg'       => 'kg',
        'cost_tnd'         => 'TND',
    ];

    /** @param array<int,string> $plotIds */
    private function campaignMetric(string $metric, object $c, array $plotIds): float
    {
        $from = (string) $c->start_date;
        $to   = (string) $c->end_date;

        $scoped = function (string $table, mixed $q) use ($plotIds, $from, $to) {
            if ($plotIds !== []) $q->whereIn($table.'.plot_id', $plotIds);
            $this->applyWindow($q, $table, $from, $to);
            return $q;
        };

        switch ($metric) {
            case 'water_m3':
                if (! Schema::hasTable('irrigation_operations')) return 0.0;
                $q = DB::table('irrigation_operations as op')
                    ->selectRaw('COALESCE(SUM('.self::m3Expr('op.water_quantity', 'op.unit_at_entry').'),0) AS v');
                return (float) $scoped('op', $q)->value('v');

            case 'fertilizer_qty':
                if (! Schema::hasTable('fertilization_operations')) return 0.0;
                $q = DB::table('fertilization_operations as op')
                    ->selectRaw('COALESCE(SUM(op.quantity_applied),0) AS v');
                return (float) $scoped('op', $q)->value('v');

            case 'treatments_count':
                if (! Schema::hasTable('phytosanitary_operations')) return 0.0;
                $q = DB::table('phytosanitary_operations as op')->selectRaw('COUNT(*) AS v');
                return (float) $scoped('op', $q)->value('v');

            case 'harvest_kg':
                if (! Schema::hasTable('harvest_operations')) return 0.0;
                $q = DB::table('harvest_operations as op')
                    ->selectRaw('COALESCE(SUM(op.quantity_harvested),0) AS v');
                return (float) $scoped('op', $q)->value('v');

            case 'cost_tnd':
            default:
                $exprs = [
                    'irrigation_operations'    => 'op.water_quantity * '.self::priceExpr('water', 'price_at_entry'),
                    'fertilization_operations' => 'op.quantity_applied * '.self::priceExpr('fertilizer', 'price_at_entry', 'fertilizer_id'),
                    'phytosanitary_operations' => 'op.quantity_applied * '.self::priceExpr('pesticide', 'price_at_entry', 'pesticide_id'),
                    'harvest_operations'       => 'op.num_workers * op.days_worked * '.self::priceExpr('labor', 'daily_rate_at_entry'),
                ];
                $total = 0.0;
                foreach ($exprs as $table => $expr) {
                    if (! Schema::hasTable($table)) continue;
                    $q = DB::table($table.' as op')->selectRaw("COALESCE(SUM($expr),0) AS v");
                    $total += (float) $scoped('op', $q)->value('v');
                }
                return $total;
        }
    }

    // ─── Step 2: data quality & sync audit ──────────────────────────────

    /**
     * Columns carrying the priced quantity + its price snapshot, per
     * operation type. A row is "unpriced" when the snapshot is null/0 AND
     * price_history has nothing for that entity — those rows silently drop
     * out of every cost figure, which is the single most common reason the
     * assistant under-reports a cost versus the Reports screen.
     *
     * @var array<string, array{0:string,1:string,2:string,3:string|null}>
     *      type => [qty column, price column, price entity_type, entity FK]
     */
    private const QUALITY_PRICE = [
        'irrigation'    => ['water_quantity', 'price_at_entry', 'water', null],
        'fertilization' => ['quantity_applied', 'price_at_entry', 'fertilizer', 'fertilizer_id'],
        'phytosanitary' => ['quantity_applied', 'price_at_entry', 'pesticide', 'pesticide_id'],
        'harvest'       => ['quantity_harvested', 'daily_rate_at_entry', 'labor', null],
    ];

    /**
     * Record-level audit of the operations in scope.
     *
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    private function toolDataQuality(array $args): array
    {
        [$from, $to] = $this->windowFrom($args);

        // Plot scope is optional here: "are my data complete?" is usually a
        // farm-wide question, so an unmatched label must not abort the audit.
        $plotIds = null;
        $names   = [];
        if (! empty($args['plot']) || ! empty($args['crop'])) {
            $plots = $this->resolvePlots($args);
            foreach ($plots as $p) {
                $names[(string) $p->id] = (string) $p->name;
            }
            $plotIds = array_keys($names);
            if ($plotIds === []) {
                return [
                    'error'           => 'plot_not_found',
                    'asked'           => $args['plot'] ?? ($args['crop'] ?? null),
                    'available_plots' => Schema::hasTable('plots')
                        ? DB::table('plots')->orderBy('name')->limit(40)->pluck('name')->all()
                        : [],
                ];
            }
        }

        $requested = array_values(array_filter(
            (array) ($args['checks'] ?? []),
            static fn ($c) => is_string($c) && $c !== '',
        ));
        $wants = static fn (string $check): bool => $requested === [] || in_array($check, $requested, true);

        $scope = function (string $table) use ($plotIds, $from, $to) {
            $q = DB::table($table.' as op');
            if ($plotIds !== null) $q->whereIn('op.plot_id', $plotIds);
            if ($from !== null)    $q->where('op.operation_date', '>=', $from);
            if ($to !== null)      $q->where('op.operation_date', '<=', $to);
            return $q;
        };

        $issues   = [];
        $checked  = [];
        $totalOps = 0;

        foreach (self::QUALITY_PRICE as $type => [$qtyCol, $priceCol, $entityType, $entityFk]) {
            $table = self::OP_TABLE[$type];
            if (! Schema::hasTable($table)) continue;

            $checked[] = $type;
            $totalOps += (int) $scope($table)->count();

            // Unpriced rows: the same COALESCE chain the cost tools use, so a
            // row flagged here is exactly a row contributing 0 TND.
            if ($wants('missing_price') && Schema::hasColumn($table, $priceCol)) {
                $expr = self::priceExpr($entityType, $priceCol, $entityFk);
                $rows = $scope($table)
                    ->whereRaw("COALESCE($expr, 0) = 0")
                    ->selectRaw('op.id, op.plot_id, op.operation_date')
                    ->orderByDesc('op.operation_date')
                    ->limit(5)
                    ->get()
                    ->all();
                $count = (int) $scope($table)->whereRaw("COALESCE($expr, 0) = 0")->count();
                if ($count > 0) {
                    $issues[] = [
                        'check'   => 'missing_price',
                        'type'    => $type,
                        'count'   => $count,
                        'impact'  => 'These operations are costed at 0 TND: every cost / cost per ha figure for this type is UNDER-estimated.',
                        'fix'     => 'Add a price in Configuration → '.$entityType.' (price history) covering their operation_date.',
                        'samples' => $this->qualitySamples($rows, $names),
                    ];
                }
            }

            if ($wants('missing_quantity') && Schema::hasColumn($table, $qtyCol)) {
                $count = (int) $scope($table)->whereRaw("COALESCE(op.$qtyCol, 0) = 0")->count();
                if ($count > 0) {
                    $rows = $scope($table)
                        ->whereRaw("COALESCE(op.$qtyCol, 0) = 0")
                        ->selectRaw('op.id, op.plot_id, op.operation_date')
                        ->orderByDesc('op.operation_date')->limit(5)->get()->all();
                    $issues[] = [
                        'check'   => 'missing_quantity',
                        'type'    => $type,
                        'count'   => $count,
                        'column'  => $qtyCol,
                        'impact'  => 'Recorded operations with no quantity: the count is right but the volume/quantity total is too low.',
                        'samples' => $this->qualitySamples($rows, $names),
                    ];
                }
            }

            if ($wants('missing_campaign') && Schema::hasColumn($table, 'campaign_id')) {
                $count = (int) $scope($table)->whereNull('op.campaign_id')->count();
                if ($count > 0) {
                    $issues[] = [
                        'check'  => 'missing_campaign',
                        'type'   => $type,
                        'count'  => $count,
                        'impact' => 'These rows belong to no campaign. They appear in date-based answers but vanish from any campaign-filtered report — a frequent source of "the AI and the report disagree".',
                    ];
                }
            }

            if ($wants('future_dated')) {
                $today = now()->toDateString();
                $count = (int) $scope($table)->where('op.operation_date', '>', $today)->count();
                if ($count > 0) {
                    $issues[] = [
                        'check'  => 'future_dated',
                        'type'   => $type,
                        'count'  => $count,
                        'impact' => 'Operations dated after '.$today.' — likely a typo in the year or month; they inflate all-time totals and are excluded from "until today" windows.',
                    ];
                }
            }

            if ($wants('duplicates') && Schema::hasColumn($table, $qtyCol)) {
                $dupCol = $entityFk !== null && Schema::hasColumn($table, $entityFk) ? "op.$entityFk" : "'-'";
                $dups = $scope($table)
                    ->selectRaw("op.plot_id, op.operation_date, $dupCol as entity, op.$qtyCol as qty, COUNT(*) as n")
                    ->groupByRaw("op.plot_id, op.operation_date, $dupCol, op.$qtyCol")
                    ->havingRaw('COUNT(*) > 1')
                    ->orderByDesc('n')
                    ->limit(5)
                    ->get()
                    ->all();
                if ($dups !== []) {
                    $issues[] = [
                        'check'   => 'duplicates',
                        'type'    => $type,
                        'count'   => count($dups),
                        'impact'  => 'Same plot + same date + same product + same quantity recorded more than once — possibly a double sync from the mobile app. Totals may be over-stated.',
                        'samples' => array_map(fn ($d) => [
                            'plot'       => $names[(string) $d->plot_id] ?? $d->plot_id,
                            'date'       => (string) $d->operation_date,
                            'quantity'   => (float) $d->qty,
                            'occurrences' => (int) $d->n,
                        ], $dups),
                    ];
                }
            }
        }

        // Plots with no surface: every per-hectare answer for them is null.
        if ($wants('missing_surface') && Schema::hasTable('plots')) {
            $q = DB::table('plots')->whereRaw('COALESCE(surface_area_ha, 0) <= 0');
            if ($plotIds !== null) $q->whereIn('id', $plotIds);
            $bad = $q->orderBy('name')->limit(10)->pluck('name')->all();
            if ($bad !== []) {
                $issues[] = [
                    'check'  => 'missing_surface',
                    'count'  => count($bad),
                    'plots'  => $bad,
                    'impact' => 'No surface area: m3/ha, kg/ha and coût/ha are returned as null for these plots and they are excluded from per-hectare averages.',
                    'fix'    => 'Set surface_area_ha on the plot form.',
                ];
            }
        }

        // Mixed water units inside one window make a raw SUM meaningless.
        if ($wants('unit_mismatch')
            && Schema::hasTable('irrigation_operations')
            && Schema::hasColumn('irrigation_operations', 'unit_at_entry')) {
            $units = $scope('irrigation_operations')
                ->selectRaw("LOWER(TRIM(COALESCE(op.unit_at_entry, 'm3'))) as u, COUNT(*) as n")
                ->groupByRaw("LOWER(TRIM(COALESCE(op.unit_at_entry, 'm3')))")
                ->get()
                ->all();
            if (count($units) > 1) {
                $issues[] = [
                    'check'  => 'unit_mismatch',
                    'type'   => 'irrigation',
                    'units'  => array_map(static fn ($u) => ['unit' => (string) $u->u, 'operations' => (int) $u->n], $units),
                    'impact' => 'Irrigation volumes were recorded in more than one unit. Our tools convert litres to m3 automatically, so the assistant figures are correct — but a raw export or a hand-made sum would be wrong.',
                ];
            }
        }

        return [
            'window'            => ['from' => $from, 'to' => $to],
            'scope'             => $plotIds === null ? 'whole farm' : array_values($names),
            'operations_in_scope' => $totalOps,
            'types_checked'     => $checked,
            'checks_run'        => $requested === [] ? 'all' : $requested,
            'issues'            => $issues,
            'issue_count'       => count($issues),
            'verdict'           => $issues === []
                ? 'No data-quality problem detected in this scope — the figures can be quoted as-is.'
                : 'Problems found. Quote the figures, then state which of them are affected and how (under- or over-estimated).',
            'applied_filters'   => $this->appliedFilters($args, $from, $to, $names),
        ];
    }

    /**
     * @param  array<int, object>    $rows
     * @param  array<string, string> $names
     * @return array<int, array<string, mixed>>
     */
    private function qualitySamples(array $rows, array $names): array
    {
        return array_map(static fn ($r) => [
            'id'   => (string) $r->id,
            'plot' => $names[(string) $r->plot_id] ?? (string) $r->plot_id,
            'date' => (string) $r->operation_date,
        ], $rows);
    }

    /**
     * Mobile sync queue health. Pending or failed postings mean the database
     * is behind what the technicians actually recorded, so any total quoted
     * from it is provisional.
     *
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    private function toolSyncStatus(array $args): array
    {
        if (! Schema::hasTable('postings')) {
            return [
                'available' => false,
                'note'      => 'No mobile sync queue in this database — every recorded operation is already stored server-side.',
            ];
        }

        $limit  = max(1, min(20, (int) ($args['limit'] ?? 5)));
        $filter = (string) ($args['status'] ?? 'all');

        $byStatus = DB::table('postings')
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->get();

        $counts = [];
        foreach ($byStatus as $r) {
            $counts[(string) $r->status] = (int) $r->n;
        }

        $pending = ($counts['pending'] ?? 0) + ($counts['processing'] ?? 0);
        $failed  = $counts['failed'] ?? 0;

        $byType = DB::table('postings')
            ->when($filter !== 'all', fn ($q) => $q->where('status', $filter))
            ->selectRaw('operation_type, status, COUNT(*) as n')
            ->groupBy('operation_type', 'status')
            ->get()
            ->map(static fn ($r) => [
                'operation_type' => (string) $r->operation_type,
                'status'         => (string) $r->status,
                'count'          => (int) $r->n,
            ])->all();

        $oldestPending = DB::table('postings')
            ->whereIn('status', ['pending', 'processing'])
            ->min('submitted_at');

        $lastSynced = Schema::hasColumn('postings', 'synced_at')
            ? DB::table('postings')->max('synced_at')
            : null;

        $failures = $failed > 0
            ? DB::table('postings')
                ->where('status', 'failed')
                ->select('id', 'operation_type', 'error_message', 'retry_count', 'submitted_at')
                ->orderByDesc('submitted_at')
                ->limit($limit)
                ->get()
                ->map(static fn ($r) => [
                    'id'             => (string) $r->id,
                    'operation_type' => (string) $r->operation_type,
                    'error'          => $r->error_message !== null ? mb_substr((string) $r->error_message, 0, 200) : null,
                    'retries'        => (int) $r->retry_count,
                    'submitted_at'   => (string) $r->submitted_at,
                ])->all()
            : [];

        return [
            'available'        => true,
            'counts_by_status' => $counts,
            'pending'          => $pending,
            'failed'           => $failed,
            'by_operation_type' => $byType,
            'oldest_pending_submitted_at' => $oldestPending !== null ? (string) $oldestPending : null,
            'last_synced_at'   => $lastSynced !== null ? (string) $lastSynced : null,
            'failure_samples'  => $failures,
            'data_completeness' => ($pending + $failed) === 0
                ? 'Queue empty — the database reflects everything submitted from the mobile app.'
                : 'Warning: '.$pending.' pending and '.$failed.' failed submission(s). Any total quoted now may be incomplete; say so in the answer.',
        ];
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
