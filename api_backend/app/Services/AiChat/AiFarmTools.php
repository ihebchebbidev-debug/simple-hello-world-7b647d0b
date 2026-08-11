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
     * Minimum `similar_text()` score for a fuzzy plot match to be usable.
     * The old 60% let "P14" answer with P4's figures; below this the tool
     * asks the user rather than reporting the wrong plot's numbers.
     */
    private const PLOT_MATCH_MIN_SCORE = 82.0;

    /** Required lead over the runner-up; a closer race is a coin flip. */
    private const PLOT_MATCH_MIN_GAP = 8.0;

    /**
     * How the last `resolvePlots()` call matched the requested label.
     * Surfaced in `applied_filters` so the assistant can disclose a guess
     * instead of silently answering about a different plot.
     */
    private ?string $plotMatchNote = null;

    /**
     * Stop the tool and ask which plot was meant.
     *
     * @param  array<int, object>  $candidates
     * @throws AiClarificationNeeded
     */
    private function needsPlotChoice(string $asked, array $candidates): never
    {
        $options = array_values(array_unique(array_map(
            static function (object $r): string {
                $area = $r->surface_area_ha ?? null;
                $crop = trim((string) ($r->crop_type ?? ''));

                return (string) $r->name
                    .($crop !== '' ? ' — '.$crop : '')
                    .($area !== null ? ' — '.$area.' ha' : '');
            },
            array_slice($candidates, 0, 8),
        )));

        throw new AiClarificationNeeded(
            'ambiguous_plot',
            $asked,
            $options,
            sprintf('"%s" matches several plots. Which one do you mean?', $asked),
        );
    }


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

            $this->fn('cost_per_ha', 'Cost breakdown per plot by operation type (irrigation, fertilization, phytosanitary, harvest labour) in TND, with total cost and cost/ha. Use for "coût/ha de la parcelle X", "coût/ha en traitement", or "coût du traitement contre la cératite" (pass `pest`).', [
                'plot' => $plot,
                'crop' => $crop,
                'type' => ['type' => 'string', 'enum' => ['irrigation', 'fertilization', 'phytosanitary', 'harvest', 'all']],
                'pest' => ['type' => 'string', 'description' => 'Restrict the cost to treatments targeting this pest/disease (e.g. "cératite", "mildiou"). Implies type=phytosanitary.'],
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

            $this->fn('locate_data', 'DISCOVERY TOOL — finds WHERE something is recorded when you do not know which table, plot, product or period holds it. Give free-text keywords (a product, an active ingredient, a pest, a plot, a remark, anything the user said) and it scans irrigation, fertilization, phytosanitary and harvest records plus the fertilizer/pesticide/pest catalogs, returning for each place: how many rows match, the first and last date, which plots, sample rows, and `use_tool` — the tool to call next for the real figures. It ALWAYS also reports the all-time match count, so a period filter that hides existing data is visible instead of being reported as "aucun enregistrement". Call this WHENEVER a typed lookup returned nothing, the question is unusual or multi-hop, or the user names something you cannot map to a tool argument. Never answer "there is no data" before this tool has come back empty all-time.', [
                'query' => ['type' => 'string', 'description' => 'Free-text keywords from the question (product, ingredient, pest, plot, remark…). All words must appear, in any order; accents/case/punctuation are ignored.'],
                'plot'  => $plot,
                'crop'  => $crop,
                'from'  => $from,
                'to'    => $to,
                'campaign' => $campaign,
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
            'product_usage'         => $this->toolProductUsage($args),
            'irrigation_history'    => $this->toolIrrigationHistory($args),
            'harvest_history'       => $this->toolHarvestHistory($args),
            'cost_per_ha'           => $this->toolCostPerHa($args),
            'product_info'          => $this->toolProductInfo($args),
            'campaign_compare'      => $this->toolCampaignCompare($args),
            'data_quality'          => $this->toolDataQuality($args),
            'sync_status'           => $this->toolSyncStatus($args),
            'locate_data'           => $this->toolLocateData($args),
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
            $folded = self::foldText($plot);
            $nameFold = self::sqlFold('name');
            $q = $applyCrop($base())->where(function ($w) use ($plot, $needle, $folded, $nameFold) {
                if (self::looksLikeUuid($plot)) $w->where('id', $plot);
                $w->orWhereRaw('LOWER(name) = ?', [$needle])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%'])
                  // Folded pass in SQL: "P-4", "p 4" and "P4" are one plot.
                  ->orWhereRaw($nameFold.' = ?', [$folded])
                  ->orWhereRaw($nameFold.' LIKE ?', ['%'.$folded.'%']);
            });
            $rows = $q->orderBy('name')->limit(80)->get()->all();

            // "P1" also LIKE-matches P10, P11, P12… Answering about all of
            // them (or about the first) is the single most damaging silent
            // error this assistant can make, so an inexact multi-hit is only
            // accepted when exactly one row matches the label exactly.
            if (count($rows) > 1) {
                $target = self::normLabel($plot);
                $exact  = array_values(array_filter(
                    $rows,
                    static fn ($r) => self::normLabel((string) $r->name) === $target,
                ));
                if (count($exact) === 1) {
                    $rows = $exact;
                } else {
                    $this->needsPlotChoice($plot, $rows);
                }
            }

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
                        if (count($contains) === 1) {
                            $rows = $contains;
                        } elseif (count($contains) > 1) {
                            $this->needsPlotChoice($plot, $contains);
                        } else {
                            // Ranked fuzzy pass. A near-tie between two plots
                            // is a coin flip on which numbers get reported —
                            // ask instead. A weak best match is not a match.
                            $scored = [];
                            foreach ($all as $r) {
                                $score = 0.0;
                                similar_text($target, self::normLabel((string) $r->name), $score);
                                $scored[] = ['row' => $r, 'score' => $score];
                            }
                            usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

                            $best   = $scored[0] ?? null;
                            $second = $scored[1] ?? null;

                            if ($best !== null && $best['score'] >= self::PLOT_MATCH_MIN_SCORE) {
                                if ($second !== null && $best['score'] - $second['score'] < self::PLOT_MATCH_MIN_GAP) {
                                    $this->needsPlotChoice($plot, [$best['row'], $second['row']]);
                                }
                                $rows = [$best['row']];
                                $this->plotMatchNote = sprintf(
                                    'No exact match for "%s". Answered about "%s" (fuzzy similarity %d%%). Say which plot you used and ask the user to confirm.',
                                    $plot,
                                    (string) $best['row']->name,
                                    (int) round($best['score']),
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
            ! empty($args['from']) ? $this->boundOrClarify($args['from'], 'from') : null,
            ! empty($args['to']) ? $this->boundOrClarify($args['to'], 'to') : null,
        ];

        // A campaign is a named season window. Explicit from/to always win —
        // the user asked for a narrower slice inside that season.
        $this->campaignNote = null;
        $this->campaignAmbiguity = null;
        $this->campaignId = null;
        if (! empty($args['campaign'])) {
            $c = $this->resolveCampaign((string) $args['campaign']);
            if ($c === null) {
                $this->campaignNote = 'Campaign "'.$args['campaign'].'" was not found; the window was NOT restricted to a campaign. Call list_campaigns to get the real names.';
            } else {
                $this->campaignId = (string) $c->id;
                $this->campaignNote = 'Scoped to campaign "'.$c->name.'" ('.$c->start_date.' → '.$c->end_date.'). Rows explicitly attached to this campaign are included even when their date falls outside that window; rows with no campaign are matched on date.';
                if ($this->campaignAmbiguity !== null) {
                    $this->campaignNote .= ' WARNING: the label "'.$args['campaign'].'" matched several campaigns ('
                        .implode(', ', $this->campaignAmbiguity)
                        .'). The one above was picked. If the answer is empty or surprising, say which season was used and offer the others.';
                }
                $window[0] ??= $c->start_date;
                $window[1] ??= $c->end_date;
            }
        }

        return $window;
    }

    /** Set by windowFrom(); surfaced through appliedFilters(). */
    private ?string $campaignNote = null;

    /** Resolved campaign id for the current call; drives campaign-aware scoping. */
    private ?string $campaignId = null;

    /** Candidate campaign labels when the requested label was ambiguous. */
    private ?array $campaignAmbiguity = null;

    /**
     * Resolve a campaign label ("2024-2025", "active", "en cours", a uuid)
     * to its row. Matching mirrors resolvePlots(): exact id, exact name,
     * normalised equality, then substring.
     *
     * A bare year ("campagne 2026") is intentionally NOT resolved by blind
     * substring order: "2026" matches both "2025-2026" and "2026-2027", and
     * silently picking the first one produced answers like "aucun traitement
     * enregistré" for an operation that exists three weeks outside the
     * chosen window. Bare years therefore prefer the campaign whose window
     * actually covers most of that calendar year, and every other candidate
     * is reported through `campaign_scope`.
     */
    /** True when the string is a uuid, i.e. safe to compare against a uuid column. */
    public static function looksLikeUuid(string $v): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($v));
    }

    private function resolveCampaign(string $label): ?object
    {
        if (! Schema::hasTable('campaigns')) return null;
        $raw = trim($label);
        if ($raw === '') return null;

        $base = fn () => DB::table('campaigns')->select('id', 'name', 'start_date', 'end_date', 'is_active');

        if (in_array(mb_strtolower($raw), ['active', 'current', 'en cours', 'actuelle', 'cette saison', 'this season'], true)) {
            return $base()->where('is_active', true)->orderByDesc('start_date')->first();
        }

        // NEVER hand a non-uuid label to a uuid column: Postgres aborts the
        // whole query with `invalid input syntax for type uuid: "2026"`, the
        // tool call fails, and the model ends up explaining a SQL error to the
        // user instead of answering.
        $hit = (self::looksLikeUuid($raw) ? $base()->where('id', $raw)->first() : null)
            ?? $base()->where('name', $raw)->first();
        if ($hit) return $hit;

        $all  = $base()->orderByDesc('start_date')->limit(60)->get();
        $norm = self::normLabel($raw);
        foreach ($all as $c) {
            if (self::normLabel((string) $c->name) === $norm) return $c;
        }

        $matches = [];
        foreach ($all as $c) {
            if ($norm !== '' && str_contains(self::normLabel((string) $c->name), $norm)) {
                $matches[] = $c;
            }
        }
        if ($matches === []) return null;

        if (count($matches) === 1) {
            return $matches[0];
        }

        // Several seasons carry this label. A bare year like "2026" spans both
        // 2025-2026 and 2026-2027, and picking "the one covering the most days"
        // silently answers about a season the user never named. Ask.
        throw new AiClarificationNeeded(
            'ambiguous_campaign',
            $raw,
            array_map(
                static fn (object $c): string => $c->name.' ('.$c->start_date.' → '.$c->end_date.')',
                array_slice($matches, 0, 6),
            ),
            sprintf('"%s" matches several campaigns. Which season should I use?', $raw),
        );
    }

    /**
     * When a scoped query comes back empty, look just outside the date window
     * before letting the model assert "aucun enregistrement". Returns the
     * count and the date range of the rows the window excluded, so the answer
     * becomes "rien sur cette campagne, mais 1 le 29/07/2026" instead of a
     * flat — and misleading — no.
     *
     * @param  mixed  $qNoWindow  the same query WITHOUT the date filters
     * @return array<string,mixed>|null
     */
    private function outsideWindowProbe(mixed $qNoWindow, string $dateColumn, ?string $from, ?string $to): ?array
    {
        if ($from === null && $to === null) return null;

        try {
            $total = (int) (clone $qNoWindow)->count();
        } catch (Throwable) {
            return null;
        }
        if ($total === 0) {
            return [
                'outside_window_count' => 0,
                'note' => 'No matching record exists for this plot/filter in ANY period — the empty result is not a window artefact.',
            ];
        }

        $first = (clone $qNoWindow)->reorder()->orderBy($dateColumn)->value($dateColumn);
        $last  = (clone $qNoWindow)->reorder()->orderByDesc($dateColumn)->value($dateColumn);

        return [
            'outside_window_count' => $total,
            'all_time_first_date'  => $first !== null ? (string) $first : null,
            'all_time_last_date'   => $last !== null ? (string) $last : null,
            'note' => 'The window returned 0 rows, but '.$total.' matching record(s) exist outside it ('
                .(string) $first.' → '.(string) $last.'). NEVER answer a plain "aucun enregistrement": state that nothing matches the requested period/campaign AND give these dates, then ask whether the other period should be used.',
        ];
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

    /**
     * Resolve a requested date bound, or stop and ask.
     *
     * Dropping an unparseable bound silently WIDENS the window — the user asks
     * about "ce trimestre agricole" and gets all-time totals presented as the
     * answer to a scoped question. A date the user explicitly supplied must
     * either resolve or be queried back.
     */
    private function boundOrClarify(mixed $v, string $edge): ?string
    {
        $raw = trim((string) $v);
        if ($raw === '') return null;

        $bound = $this->boundOrNull($raw, $edge);
        if ($bound !== null) return $bound;

        throw new AiClarificationNeeded(
            'unparsed_date',
            $raw,
            ['JJ/MM/AAAA — e.g. 01/06/2026', 'a month, e.g. "juin 2026"', 'a campaign name, e.g. "2025-2026"'],
            sprintf('I could not read the period "%s". Which exact dates should I cover?', $raw),
        );
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

    /**
     * Restrict a query to the reporting window.
     *
     * When the window came from a resolved campaign AND the underlying table
     * carries `campaign_id`, the row's own campaign membership wins over its
     * date: an operation explicitly attached to campaign X belongs to that
     * campaign even if it was recorded a few days outside the season window
     * (late data entry, a season boundary moved after the fact…). Rows with
     * no campaign_id still fall back to the date window. This kills the whole
     * "the record exists but the campaign window excluded it" bug class
     * instead of patching it per tool.
     */
    private function applyWindow(mixed $q, string $table, ?string $from, ?string $to, ?string $realTable = null): mixed
    {
        $campaignScoped = $this->campaignId !== null
            && $realTable !== null
            && Schema::hasTable($realTable)
            && Schema::hasColumn($realTable, 'campaign_id');

        if ($campaignScoped) {
            $cid = $this->campaignId;
            $q->where(function ($w) use ($table, $cid, $from, $to) {
                $w->where($table.'.campaign_id', $cid)
                  ->orWhere(function ($d) use ($table, $from, $to) {
                      $d->whereNull($table.'.campaign_id');
                      if ($from !== null) $d->where($table.'.operation_date', '>=', $from);
                      if ($to !== null)   $d->where($table.'.operation_date', '<=', $to);
                  });
            });
            return $q;
        }

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
            $this->applyWindow($q, 'op', $from, $to, 'irrigation_operations');
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
            // THE per-hectare figure to quote. Choosing between a weighted and
            // an unweighted mean is an agronomic decision, not a wording one,
            // and a small model gets it wrong roughly half the time — so the
            // server decides and hands back a single number.
            'per_ha'           => count($rows) === 1
                ? ($rows[0]['m3_per_ha'] ?? null)
                : self::perHa($totalM3, $totalHa),
            'per_ha_method'    => count($rows) === 1 ? 'single_plot' : 'weighted (total m³ ÷ total ha)',
            'per_ha_rule'      => 'Quote `per_ha` and nothing else as THE m³/ha figure. `weighted_m3_per_ha` and '
                .'`average_m3_per_ha` are provided for reconciliation only; mention `average_m3_per_ha` solely if '
                .'the user explicitly asks for the unweighted mean of the plots.',
            'weighted_m3_per_ha' => self::perHa($totalM3, $totalHa),
            'average_m3_per_ha' => $withArea > 0 ? round($sumPerHa / $withArea, 2) : null,
            'average_note'     => 'weighted_m3_per_ha = total m³ / total ha (matches the Reports screen). average_m3_per_ha = unweighted mean of the per-plot m³/ha.',
            'cost_basis'       => 'price_at_entry, falling back to the price_history row effective at the operation date — identical to the Reports/Production-cost screen.',
            'excluded'         => array_values((array) ($args['exclude_plots'] ?? [])),
            // Truthful description of what the totals cover, so the answer can
            // never mix "toutes campagnes confondues" with a filtered window.
            'coverage'         => $from === null && $to === null && empty($args['campaign'])
                ? 'No date or campaign filter was applied: these totals cover every recorded irrigation. The first/last dates per plot are the extent of the DATA, not a filter — do not present them as a period restriction, and do not claim a campaign scope.'
                : 'These totals cover ONLY the requested window/campaign. State that period explicitly and never say "toutes campagnes confondues".',
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
        $this->applyWindow($q, 'op', $from, $to, 'irrigation_operations');

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
        $this->applyWindow($q, 'fertilization_operations', $from, $to, 'fertilization_operations');
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

        if (! empty($args['pest'])) {
            $terms = $this->pestSearchTerms((string) $args['pest']);
            $q->where(function ($w) use ($terms) {
                self::whereMatchesAnyTerm($w, ['po.target_pest', 'po.remarks'], $terms);
            });
        }
        if (! empty($args['product'])) {
            // Folded token match: "naturamin gold" also finds "Naturamin-Gold",
            // and a product named only in the composition string still matches.
            $product = (string) $args['product'];
            $q->where(function ($w) use ($product) {
                self::whereMatchesAllTokens($w, ['pe.name', 'pe.chemical_composition'], $product);
            });
        }


        // Cloned BEFORE the date filter so an empty window can be explained
        // with the dates that do exist instead of a flat "aucun traitement".
        $qNoWindow = clone $q;
        $this->applyWindow($q, 'po', $from, $to, 'phytosanitary_operations');

        $total = (clone $q)->count();
        // Window-wide cost, not the sum of the (possibly truncated) listing.
        // `select()` replaces the listing's columns; `selectRaw()` would append
        // them next to SUM() and Postgres would reject the query (no GROUP BY).
        $windowCost = (float) ((clone $q)
            ->select(DB::raw('COALESCE(SUM(po.quantity_applied * po.price_at_entry),0) AS c'))
            ->value('c') ?? 0);

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

        $out = [
            'window'          => ['from' => $from, 'to' => $to],
            'applied_filters' => $this->appliedFilters($args, $from, $to, $names),
            'pest'            => $args['pest'] ?? null,
            'treatment_count' => $total,
            'returned_rows'   => count($rows),
            'truncated'       => $total > count($rows),
            'total_cost_tnd'  => round($windowCost, 2),
            'order'           => $order,
            'rows'            => $rows,
            'returned'        => count($rows),
            // This tool answers a LISTING question. A "quels traitements…"
            // question must be answered with the count and the individual
            // rows (date, product, dose), not with a cost total alone.
            'answer_shape'    => 'listing',
            'answer_rule'     => 'State `treatment_count` and list each row (date, product, dose, dose/ha). Quote `total_cost_tnd` only if the user asked about cost.',
        ];

        if ($total === 0) {
            $probe = $this->outsideWindowProbe($qNoWindow, 'po.operation_date', $from, $to);
            if ($probe !== null) $out['empty_result_diagnostic'] = $probe;

            // A pest/product filter returning 0 is the classic false negative:
            // the treatment exists but was recorded under another pest label
            // (or none at all). Show what IS recorded so the answer can never
            // claim "aucun traitement" when the plot was in fact treated.
            $ctx = $this->phytoFilterContext($ids, $args);
            if ($ctx !== null) $out['filter_context'] = $ctx;
        }

        return $out;

    }

    /**
     * All-time, filter-free picture of the phytosanitary log for a plot scope.
     * Attached whenever a pest/product filter returns nothing, so the model
     * distinguishes "this plot was never treated" from "it was treated, but
     * not against the pest you named".
     *
     * @param  array<int, mixed>  $ids
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    private function phytoFilterContext(array $ids, array $args): ?array
    {
        if ($ids === [] || (empty($args['pest']) && empty($args['product']))) {
            return null;
        }
        if (! Schema::hasTable('phytosanitary_operations')) {
            return null;
        }

        try {
            $base = DB::table('phytosanitary_operations as po')
                ->leftJoin('pesticides as pe', 'pe.id', '=', 'po.pesticide_id')
                ->whereIn('po.plot_id', $ids);

            $count = (int) (clone $base)->count();
            if ($count === 0) {
                return [
                    'unfiltered_treatment_count' => 0,
                    'note' => 'No phytosanitary treatment at all is recorded for this plot scope, in any period. The plot was genuinely never treated.',
                ];
            }

            $pests = (clone $base)->whereNotNull('po.target_pest')
                ->distinct()->orderBy('po.target_pest')->limit(20)->pluck('po.target_pest')->all();
            $products = (clone $base)->whereNotNull('pe.name')
                ->distinct()->orderBy('pe.name')->limit(20)->pluck('pe.name')->all();

            return [
                'unfiltered_treatment_count' => $count,
                'recorded_target_pests'      => array_values(array_filter(array_map('strval', $pests))),
                'recorded_products'          => array_values(array_filter(array_map('strval', $products))),
                'note' => 'The requested pest/product matched nothing, but '.$count.' treatment(s) ARE recorded on this plot scope. Say that no treatment targeting the requested pest is recorded, then name the pests/products that WERE applied. Never answer a bare "aucun traitement".',
            ];
        } catch (Throwable) {
            return null;
        }
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
        if (! empty($args['product'])) {
            $product = (string) $args['product'];
            $q->where(function ($w) use ($product) {
                self::whereMatchesAllTokens($w, ['f.name'], $product);
            });
        }

        $qNoWindow = clone $q;
        $this->applyWindow($q, 'fo', $from, $to, 'fertilization_operations');

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

        $out = [
            'window'            => ['from' => $from, 'to' => $to],
            'applied_filters'   => $this->appliedFilters($args, $from, $to, $names),
            'product'           => $args['product'] ?? null,
            'application_count' => $total,
            'returned_rows'     => count($rows),
            'truncated'         => $total > count($rows),
            'order'             => $order,
            'rows'              => $rows,
        ];

        if ($total === 0) {
            $probe = $this->outsideWindowProbe($qNoWindow, 'fo.operation_date', $from, $to);
            if ($probe !== null) $out['empty_result_diagnostic'] = $probe;
        }

        return $out;
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
        /** @var array<string,mixed> $probeQueries */
        $probeQueries = [];
        $anyLike = static function ($where, array $columns) use ($terms): void {
            // Each family term is a complete alternative spelling, and both
            // sides are folded so "Acides Aminés" matches "acide amine".
            self::whereMatchesAnyTerm($where, $columns, $terms);
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
            $probeQueries['fertilization'] = clone $q;
            $this->applyWindow($q, 'op', $from, $to, 'fertilization_operations');
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
            $probeQueries['phytosanitary'] = clone $q;
            $this->applyWindow($q, 'op', $from, $to, 'phytosanitary_operations');
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

        $out = [
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

        if ($total === 0) {
            foreach ($probeQueries as $kind => $probeQ) {
                $probe = $this->outsideWindowProbe($probeQ, 'op.operation_date', $from, $to);
                if ($probe !== null && (int) ($probe['outside_window_count'] ?? 0) > 0) {
                    $out['empty_result_diagnostic'] = ['operation_type' => $kind] + $probe;
                    break;
                }
            }
        }

        return $out;
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

        $flat = self::foldText($needle);
        foreach ($families as $terms) {
            foreach ($terms as $term) {
                if (str_contains($flat, $term) || str_contains($term, $flat)) {
                    return array_values(array_unique(array_merge([$flat], $terms)));
                }
            }
        }

        // Not a family: the folded phrase alone. Folding already absorbs case,
        // accents and punctuation ("Naturamin-Gold" ≡ "naturamin gold");
        // splitting into OR'd words here would match any product containing
        // "gold" and silently widen the answer.
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

    // ─── Text search: fold BOTH sides before comparing ──────────────────
    //
    // `LOWER(name) LIKE '%naturamin gold%'` misses "Naturamin-Gold" and
    // "Acides Aminés" the moment the catalog spells a product with accents,
    // a hyphen or a plural. Every product / pest lookup therefore compares a
    // folded column against folded tokens: accents stripped, punctuation
    // turned into spaces, and each word matched independently (AND) so word
    // order and filler words stop mattering.

    /** Accent + punctuation folding applied identically in PHP and in SQL. */
    private const SEARCH_FOLD = [
        'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'î'=>'i','ï'=>'i','í'=>'i',
        'ô'=>'o','ö'=>'o','ó'=>'o','õ'=>'o',
        'ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u',
        'ç'=>'c','ñ'=>'n',
        '-'=>' ','_'=>' ','.'=>' ',','=>' ','/'=>' ','('=>' ',')'=>' ','+'=>' ','&'=>' ',"'"=>' ','’'=>' ','"'=>' ',
    ];

    /** Words that carry no selectivity in a product / pest name. */
    private const SEARCH_STOPWORDS = [
        'de', 'du', 'des', 'la', 'le', 'les', 'un', 'une', 'et', 'au', 'aux',
        'pour', 'contre', 'sur', 'the', 'of', 'and', 'for',
    ];

    private static function sqlLiteral(string $s): string
    {
        return "'".str_replace("'", "''", $s)."'";
    }

    /**
     * SQL expression folding a column the same way {@see foldText} folds the
     * needle. Portable REPLACE() nesting — no pgsql-only translate().
     */
    private static function sqlFold(string $col): string
    {
        $expr = "LOWER(COALESCE($col, ''))";
        foreach (self::SEARCH_FOLD as $from => $to) {
            $expr = 'REPLACE('.$expr.', '.self::sqlLiteral($from).', '.self::sqlLiteral($to).')';
        }
        return $expr;
    }

    private static function foldText(string $s): string
    {
        $s = strtr(mb_strtolower(trim($s)), self::SEARCH_FOLD);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Split a needle into the words a row must contain. Long words lose a
     * trailing "s" so "traitements"/"aminés" match the singular spelling.
     *
     * @return array<int, string>
     */
    private static function searchTokens(string $needle): array
    {
        $tokens = [];
        foreach (explode(' ', self::foldText($needle)) as $token) {
            if ($token === '' || mb_strlen($token) < 2) continue;
            if (in_array($token, self::SEARCH_STOPWORDS, true)) continue;
            if (mb_strlen($token) > 4 && str_ends_with($token, 's')) {
                $token = mb_substr($token, 0, -1);
            }
            $tokens[] = $token;
        }
        if ($tokens === []) {
            $flat = self::foldText($needle);
            if ($flat !== '') $tokens[] = $flat;
        }
        return array_values(array_unique($tokens));
    }

    /**
     * `(colA has every token) OR (colB has every token) …` — the match a human
     * means by "les traitements Naturamin".
     *
     * @param  array<int, string>  $columns
     */
    private static function whereMatchesAllTokens(mixed $where, array $columns, string $needle): void
    {
        $tokens = self::searchTokens($needle);
        if ($tokens === []) return;

        foreach ($columns as $col) {
            $expr = self::sqlFold($col);
            $where->orWhere(static function ($w) use ($expr, $tokens): void {
                foreach ($tokens as $token) {
                    $w->whereRaw($expr.' LIKE ?', ['%'.$token.'%']);
                }
            });
        }
    }

    /**
     * `any column contains any of these folded terms` — used for pest synonym
     * lists, where each term is already a complete alternative spelling.
     *
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $terms
     */
    private static function whereMatchesAnyTerm(mixed $where, array $columns, array $terms): void
    {
        foreach ($columns as $col) {
            $expr = self::sqlFold($col);
            foreach ($terms as $term) {
                $folded = self::foldText($term);
                if ($folded === '') continue;
                $where->orWhereRaw($expr.' LIKE ?', ['%'.$folded.'%']);
            }
        }
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
        $qNoWindow = clone $q;
        $this->applyWindow($q, 'irrigation_operations', $from, $to, 'irrigation_operations');

        $total = (clone $q)->count();
        $m3 = self::m3Expr();
        // select() (replaces the listing columns), never selectRaw() (appends
        // them beside SUM() → Postgres GROUP BY error, whole tool call lost).
        $windowTotal = (float) ((clone $q)->select(DB::raw("COALESCE(SUM($m3),0) AS t"))->value('t') ?? 0);
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

        if ($total === 0) {
            $probe = $this->outsideWindowProbe($qNoWindow, 'operation_date', $from, $to);
            if ($probe !== null) $out['empty_result_diagnostic'] = $probe;
        }

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
        $qNoWindow = clone $q;
        $this->applyWindow($q, 'harvest_operations', $from, $to, 'harvest_operations');

        // Window-wide aggregates FIRST. Summing only the listed rows made the
        // totals silently wrong as soon as the listing hit `limit`.
        // `select()` (not `selectRaw()`) on purpose: selectRaw APPENDS to the
        // listing's column list, so Postgres saw plot_id/operation_date next to
        // COUNT/SUM with no GROUP BY and failed the whole tool call — which is
        // what pushed the agent into extra rounds and retries.
        $totals = (clone $q)->select(DB::raw(
            'COUNT(*) AS n,
             COALESCE(SUM(quantity_harvested),0) AS kg,
             COALESCE(SUM(num_workers * days_worked * daily_rate_at_entry),0) AS cost,
             MIN(operation_date) AS first_date,
             MAX(operation_date) AS last_date',
        ))->first();

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

        $out = [
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

        if ($count === 0) {
            $probe = $this->outsideWindowProbe($qNoWindow, 'operation_date', $from, $to);
            if ($probe !== null) $out['empty_result_diagnostic'] = $probe;
        }

        return $out;
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

        // "Coût du traitement contre la cératite" is a cost question WITH a
        // pest filter: without it the tool answered on the whole phyto cost
        // (or on nothing at all), which is how a real treatment ended up
        // reported as "0 TND".
        $pest = ! empty($args['pest']) ? (string) $args['pest'] : null;
        $pestTerms = $pest !== null ? $this->pestSearchTerms($pest) : [];


        $byPlot = [];
        $pestProbe = null;
        foreach ($costExpr as $type => $expr) {
            if ($want !== 'all' && $want !== $type) continue;
            if ($pest !== null && $type !== 'phytosanitary') continue; // a pest only exists on treatments
            $table = self::OP_TABLE[$type];
            if (! Schema::hasTable($table)) continue;
            $q = DB::table($table.' as op')
                ->selectRaw("op.plot_id AS plot_id, COALESCE(SUM($expr),0) AS cost")
                ->whereIn('op.plot_id', $ids)
                ->groupBy('op.plot_id');
            if ($pestTerms !== [] && $type === 'phytosanitary') {
                $q->where(function ($w) use ($pestTerms) {
                    self::whereMatchesAnyTerm($w, ['op.target_pest', 'op.remarks'], $pestTerms);
                });
            }


            $this->applyWindow($q, 'op', $from, $to, $table);
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

        $out = [
            'window'   => ['from' => $from, 'to' => $to],
            'applied_filters' => $this->appliedFilters($args, $from, $to, $names),
            'scope'    => $want,
            'pest'     => $pest,
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

        // A 0 TND answer is only trustworthy once we've checked outside the
        // window: otherwise a mis-resolved campaign turns a real treatment
        // into "aucun traitement enregistré".
        if ($grand <= 0.0) {
            $probeType = $pest !== null ? 'phytosanitary' : ($want !== 'all' ? $want : null);
            $probeTable = $probeType !== null ? (self::OP_TABLE[$probeType] ?? null) : null;
            if ($probeTable !== null && Schema::hasTable($probeTable)) {
                $probeQ = DB::table($probeTable.' as op')->whereIn('op.plot_id', $ids);
                if ($pestTerms !== []) {
                    $probeQ->where(function ($w) use ($pestTerms) {
                        self::whereMatchesAnyTerm($w, ['op.target_pest', 'op.remarks'], $pestTerms);
                    });
                }

                $probe = $this->outsideWindowProbe($probeQ, 'op.operation_date', $from, $to);
                if ($probe !== null) $out['empty_result_diagnostic'] = $probe;
            }
            if ($pest !== null) {
                $ctx = $this->phytoFilterContext($ids, $args);
                if ($ctx !== null) $out['filter_context'] = $ctx;
            }
        }

        return $out;


    }

    /** @param array<string,mixed> $args */
    private function toolProductInfo(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if (mb_strlen($query) < 2) return ['error' => 'query_too_short'];
        $kind = (string) ($args['kind'] ?? 'any');
        $match = static fn ($q, array $cols) => $q->where(
            static fn ($w) => self::whereMatchesAllTokens($w, $cols, $query),
        );
        $out  = [];

        if (($kind === 'any' || $kind === 'fertilizer') && Schema::hasTable('fertilizers')) {
            $rows = $match(
                DB::table('fertilizers')
                    ->select('id', 'name', 'unit', 'n_percent', 'p_percent', 'k_percent', 'is_active'),
                ['name'],
            )->limit(5)->get();
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
            $rows = $match(
                DB::table('pesticides')
                    ->select('id', 'name', 'unit', 'chemical_composition', 'is_active'),
                ['name', 'chemical_composition'],
            )->limit(5)->get();

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
            $this->applyWindow($q, $table, $from, $to, $table);
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
                // Only group by a REAL column: Postgres rejects a string literal
                // in GROUP BY ("non-integer constant in GROUP BY"), which used to
                // fail this whole audit for tables with no product/entity FK.
                $hasEntity = $entityFk !== null && Schema::hasColumn($table, $entityFk);
                $dupCol    = $hasEntity ? "op.$entityFk" : null;
                $dups = $scope($table)
                    ->select(DB::raw(
                        'op.plot_id, op.operation_date'
                        .($dupCol !== null ? ", $dupCol as entity" : '')
                        .", op.$qtyCol as qty, COUNT(*) as n",
                    ))
                    ->groupByRaw('op.plot_id, op.operation_date'.($dupCol !== null ? ", $dupCol" : '').", op.$qtyCol")
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
    /**
     * Cross-table discovery: "where is this recorded?".
     *
     * The typed tools all need to know in advance WHICH table answers the
     * question. A harder question ("a-t-on traité au soufre après la grêle ?")
     * names something that maps to no tool argument, the typed lookup comes
     * back empty and the assistant used to conclude "aucun enregistrement".
     * This tool scans every operation table and catalog for the keywords, and
     * always reports the all-time count next to the windowed one so a bad
     * period filter is visible as a filter, not as missing data.
     *
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    private function toolLocateData(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if (mb_strlen($query) < 2) return ['error' => 'query_too_short'];
        if (self::searchTokens($query) === []) {
            return ['error' => 'query_has_no_searchable_word', 'asked' => $query];
        }

        [$from, $to] = $this->windowFrom($args);

        // Plot scope is a soft filter here: discovery must never abort on an
        // unresolved label, or the "where is it?" question fails exactly when
        // it is most needed.
        $plotIds = null;
        $plotNote = null;
        if (! empty($args['plot']) || ! empty($args['crop'])) {
            $plots = $this->resolvePlots($args);
            $plotIds = array_map(static fn ($p) => (string) $p->id, $plots);
            if ($plotIds === []) {
                $plotIds = null;
                $plotNote = 'The plot/crop filter "'.($args['plot'] ?? $args['crop']).'" matched no plot, so the search ran over the WHOLE farm. Say which plots the hits belong to rather than assuming the requested one.';
            }
        }

        // table => [alias, join, searchable columns, tool to call next]
        $sources = [
            'phytosanitary' => [
                'table' => 'phytosanitary_operations',
                'join'  => ['pesticides', 'pesticide_id'],
                'cols'  => ['j.name', 'j.chemical_composition', 'op.target_pest', 'op.remarks'],
                'tool'  => 'treatments',
            ],
            'fertilization' => [
                'table' => 'fertilization_operations',
                'join'  => ['fertilizers', 'fertilizer_id'],
                'cols'  => ['j.name', 'op.remarks'],
                'tool'  => 'fertilization_history',
            ],
            'irrigation' => [
                'table' => 'irrigation_operations',
                'join'  => null,
                'cols'  => ['op.remarks'],
                'tool'  => 'irrigation_history',
            ],
            'harvest' => [
                'table' => 'harvest_operations',
                'join'  => null,
                'cols'  => ['op.remarks'],
                'tool'  => 'harvest_history',
            ],
        ];

        $plotNames = Schema::hasTable('plots')
            ? DB::table('plots')->pluck('name', 'id')->all()
            : [];

        $found = [];
        $totalInWindow = 0;
        $totalAllTime  = 0;

        foreach ($sources as $type => $src) {
            $table = $src['table'];
            if (! Schema::hasTable($table)) continue;

            $cols = [];
            foreach ($src['cols'] as $col) {
                [$prefix, $name] = explode('.', $col, 2);
                if ($prefix === 'op') {
                    if (Schema::hasColumn($table, $name)) $cols[] = $col;
                } elseif ($src['join'] !== null && Schema::hasColumn($src['join'][0], $name)) {
                    $cols[] = $col;
                }
            }
            // The plot name itself is searchable: "P4" as a free-text keyword
            // must land on the plot, not on nothing.
            if (Schema::hasTable('plots')) $cols[] = 'p.name';
            if ($cols === []) continue;

            $base = function () use ($table, $src, $cols, $query, $plotIds) {
                $q = DB::table($table.' as op');
                if ($src['join'] !== null && Schema::hasTable($src['join'][0])) {
                    $q->leftJoin($src['join'][0].' as j', 'j.id', '=', 'op.'.$src['join'][1]);
                }
                if (Schema::hasTable('plots')) {
                    $q->leftJoin('plots as p', 'p.id', '=', 'op.plot_id');
                }
                if ($plotIds !== null) $q->whereIn('op.plot_id', $plotIds);
                $q->where(function ($w) use ($cols, $query) {
                    self::whereMatchesAllTokens($w, $cols, $query);
                });
                return $q;
            };

            $allTime = (int) $base()->count();
            if ($allTime === 0) continue;

            $windowed = $base();
            if ($from !== null) $windowed->where('op.operation_date', '>=', $from);
            if ($to !== null)   $windowed->where('op.operation_date', '<=', $to);
            $inWindow = ($from === null && $to === null) ? $allTime : (int) $windowed->count();

            $totalAllTime  += $allTime;
            $totalInWindow += $inWindow;

            $span = $base()
                ->selectRaw('MIN(op.operation_date) AS first_date, MAX(op.operation_date) AS last_date')
                ->first();

            $samples = [];
            $sampleQ = $base()
                ->select('op.plot_id', 'op.operation_date')
                ->orderByDesc('op.operation_date')
                ->limit(5);
            foreach ($sampleQ->get() as $r) {
                $samples[] = [
                    'date' => (string) $r->operation_date,
                    'plot' => $plotNames[$r->plot_id] ?? (string) $r->plot_id,
                ];
            }

            $plotsHit = $base()
                ->select('op.plot_id')
                ->distinct()
                ->limit(20)
                ->pluck('plot_id')
                ->map(static fn ($id) => $plotNames[$id] ?? (string) $id)
                ->values()
                ->all();

            $found[] = [
                'record_type'         => $type,
                'matches_in_window'   => $inWindow,
                'matches_all_time'    => $allTime,
                'first_date'          => $span->first_date ?? null,
                'last_date'           => $span->last_date ?? null,
                'plots'               => $plotsHit,
                'recent_samples'      => $samples,
                'use_tool'            => $src['tool'],
                'next_step'           => 'Call `'.$src['tool'].'` with these plots and a window that covers '
                    .($span->first_date ?? '?').' → '.($span->last_date ?? '?').' to get the real figures.',
            ];
        }

        // Catalog side: the thing may exist as a product/pest that was simply
        // never applied — a very different answer from "no data".
        $catalog = [];
        foreach ([['fertilizers', 'fertilizer', ['name']], ['pesticides', 'pesticide', ['name', 'chemical_composition']], ['pests', 'pest', ['name', 'scientific_name']]] as [$table, $kind, $cols]) {
            if (! Schema::hasTable($table)) continue;
            $cols = array_values(array_filter($cols, static fn ($c) => Schema::hasColumn($table, $c)));
            if ($cols === []) continue;
            $rows = DB::table($table)
                ->where(function ($w) use ($cols, $query) {
                    self::whereMatchesAllTokens($w, $cols, $query);
                })
                ->limit(5)->pluck('name')->all();
            foreach ($rows as $name) {
                $catalog[] = ['kind' => $kind, 'name' => (string) $name];
            }
        }

        $windowLabel = ($from === null && $to === null) ? 'all time' : (($from ?? '…').' → '.($to ?? '…'));

        return [
            'query'            => $query,
            'window'           => ['from' => $from, 'to' => $to, 'label' => $windowLabel],
            'plot_note'        => $plotNote,
            'found_in'         => $found,
            'catalog_matches'  => $catalog,
            'total_in_window'  => $totalInWindow,
            'total_all_time'   => $totalAllTime,
            'verdict'          => $this->locateVerdict($totalAllTime, $totalInWindow, $catalog, $windowLabel),
            'answer_rule'      => 'This tool only LOCATES data. Do not quote its counts as the final answer: call `use_tool` for each hit and read the figures there.',
        ];
    }

    /** @param array<int, array<string, string>> $catalog */
    private function locateVerdict(int $allTime, int $inWindow, array $catalog, string $windowLabel): string
    {
        if ($allTime === 0) {
            return $catalog === []
                ? 'Nothing matches these keywords anywhere in the farm records or the catalogs. Only now may you say the data does not exist — and say which keywords you searched.'
                : 'The product/pest EXISTS in the catalog but was never recorded on any operation. Say exactly that: it is known but never applied — not "no data".';
        }
        if ($inWindow === 0) {
            return 'Records EXIST ('.$allTime.' all-time) but NONE fall inside '.$windowLabel
                .'. Never answer "aucun enregistrement": say the period is empty, then give the real dates from `first_date`/`last_date` and offer those figures.';
        }
        if ($inWindow < $allTime) {
            return $inWindow.' of '.$allTime.' matching records fall inside '.$windowLabel
                .'. Answer on the window, and state that figure is the window, not the whole history.';
        }
        return $allTime.' matching records, all inside '.$windowLabel.'. Call the `use_tool` of each hit for the figures.';
    }

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
