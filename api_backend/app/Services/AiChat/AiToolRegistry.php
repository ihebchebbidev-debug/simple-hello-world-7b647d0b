<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only data tools exposed to the AI assistant as OpenAI-style functions.
 *
 * Each tool is a pure SQL/Eloquent lookup: no writes, no side effects,
 * every result capped to keep round-trip tokens bounded.
 */
final class AiToolRegistry
{
    public function __construct(
        private readonly NaturalDateParser $dates = new NaturalDateParser(),
    ) {}

    private const OPERATION_TYPES = ['irrigation', 'fertilization', 'phytosanitary', 'harvest'];

    private const OP_TABLE = [
        'irrigation'    => 'irrigation_operations',
        'fertilization' => 'fertilization_operations',
        'phytosanitary' => 'phytosanitary_operations',
        'harvest'       => 'harvest_operations',
    ];

    private const OP_QTY_COL = [
        'irrigation'    => 'water_quantity',
        'fertilization' => 'quantity_applied',
        'phytosanitary' => 'quantity_applied',
        'harvest'       => 'quantity_harvested',
    ];

    /** @return array<int, array<string, mixed>> OpenAI-style function tool defs. */
    public function definitions(): array
    {
        $ops = self::OPERATION_TYPES;

        return [
            $this->fn('plan', 'Announce a short 1-4 step plan BEFORE calling any data tools. Do this only once per turn, and only for questions that need multiple lookups.', [
                'steps' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '1 to 4 short steps (max 120 chars each)'],
            ], ['steps']),

            $this->fn('get_overview', 'Small KPI snapshot: totals, active campaign, current month. Cheap, safe default when the user asks broad status questions.', []),

            $this->fn('list_plots', 'List plots (id, name, area_ha, crop_type, is_active). Filter by crop or active flag.', [
                'crop'   => ['type' => 'string', 'description' => 'Optional crop_type substring filter'],
                'active' => ['type' => 'boolean', 'description' => 'Only active plots when true'],
                'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60],
            ]),

            $this->fn('list_campaigns', 'List campaigns (id, name, start_date, end_date, is_active).', [
                'status' => ['type' => 'string', 'enum' => ['active', 'past', 'all']],
            ]),

            $this->fn('get_operations', 'Recent rows for one operation type on optional plot/campaign/date filters.', [
                'type'        => ['type' => 'string', 'enum' => $ops],
                'plot_id'     => ['type' => 'string'],
                'campaign_id' => ['type' => 'string'],
                'from'        => ['type' => 'string', 'description' => 'ISO date YYYY-MM-DD'],
                'to'          => ['type' => 'string', 'description' => 'ISO date YYYY-MM-DD'],
                'limit'       => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            ], ['type']),

            $this->fn('aggregate_operations', 'Grouped aggregation over one operation type. Windows: day/week/month/quarter/year/campaign/plot/crop/product. Metrics: sum_quantity, sum_cost, count.', [
                'type'        => ['type' => 'string', 'enum' => $ops],
                'group_by'    => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'quarter', 'year', 'campaign', 'plot', 'crop', 'product']],
                'metric'      => ['type' => 'string', 'enum' => ['sum_quantity', 'sum_cost', 'count']],
                'from'        => ['type' => 'string'],
                'to'          => ['type' => 'string'],
                'plot_id'     => ['type' => 'string'],
                'campaign_id' => ['type' => 'string'],
                'crop'        => ['type' => 'string'],
            ], ['type', 'group_by', 'metric']),

            $this->fn('compare_periods', 'Compare one metric between two arbitrary windows (YoY, MoM…). Returns totals + delta + percent change.', [
                'type'          => ['type' => 'string', 'enum' => $ops],
                'metric'        => ['type' => 'string', 'enum' => ['sum_quantity', 'sum_cost', 'count']],
                'period_a_from' => ['type' => 'string'],
                'period_a_to'   => ['type' => 'string'],
                'period_b_from' => ['type' => 'string'],
                'period_b_to'   => ['type' => 'string'],
                'plot_id'       => ['type' => 'string'],
                'crop'          => ['type' => 'string'],
            ], ['type', 'metric', 'period_a_from', 'period_a_to', 'period_b_from', 'period_b_to']),

            $this->fn('search_catalog', 'Search fertilizers, pesticides, or pests by name or scientific name (case-insensitive substring).', [
                'kind'  => ['type' => 'string', 'enum' => ['fertilizer', 'pesticide', 'pest']],
                'query' => ['type' => 'string', 'description' => '2-60 chars, matched against name and scientific_name'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            ], ['kind', 'query']),

            $this->fn('recent_operations', 'Latest activity across all 4 operation types.', [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            ]),

            $this->fn('resolve_date_range', 'Turn a natural-language date phrase (FR or EN) into a concrete {from, to} window. Supports: "today"/"aujourd\'hui", "last week"/"semaine dernière", "this month"/"ce mois", "last july"/"juillet dernier", "juillet 2024", "Q2 2024"/"2e trimestre 2024", "2024", "last 30 days"/"30 derniers jours", "YTD"/"depuis le début de l\'année", "this season"/"cette saison" (uses active campaign), "last season". Call this FIRST whenever the user mentions a period, then pass from/to to the other tools.', [
                'phrase' => ['type' => 'string', 'description' => 'The raw date expression from the user (FR or EN).'],
            ], ['phrase']),
        ];
    }

    /**
     * Dispatch a tool call by name. Never throws — errors become part of the
     * result payload so the model can react in the next round.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function call(string $name, array $args): array
    {
        try {
            $data = match ($name) {
                'plan'                 => $this->toolPlan($args),
                'get_overview'         => $this->toolOverview(),
                'list_plots'           => $this->toolListPlots($args),
                'list_campaigns'       => $this->toolListCampaigns($args),
                'get_operations'       => $this->toolGetOperations($args),
                'aggregate_operations' => $this->toolAggregate($args),
                'compare_periods'      => $this->toolComparePeriods($args),
                'search_catalog'       => $this->toolSearchCatalog($args),
                'recent_operations'    => $this->toolRecentOperations($args),
                'resolve_date_range'   => $this->toolResolveDate($args),
                default                => ['error' => 'unknown_tool', 'name' => $name],
            };
            return array_merge([
                'ok'           => ! isset($data['error']),
                'generated_at' => now()->toIso8601String(),
                'currency'     => 'MAD',
            ], $data);
        } catch (Throwable $e) {
            return [
                'ok'    => false,
                'error' => 'tool_failed',
                'name'  => $name,
                'detail' => mb_substr($e->getMessage(), 0, 200),
            ];
        }
    }

    // ─── Tools ──────────────────────────────────────────────────────────

    /** @param array<string,mixed> $args */
    private function toolPlan(array $args): array
    {
        $steps = array_values(array_filter(
            (array) ($args['steps'] ?? []),
            static fn ($s) => is_string($s) && trim($s) !== '',
        ));
        $steps = array_slice(array_map(
            static fn (string $s): string => mb_substr(trim($s), 0, 120),
            $steps,
        ), 0, 4);
        return ['plan' => $steps];
    }

    private function toolOverview(): array
    {
        $plots = Schema::hasTable('plots') ? (int) DB::table('plots')->count() : 0;
        $activePlots = Schema::hasTable('plots') && Schema::hasColumn('plots', 'is_active')
            ? (int) DB::table('plots')->where('is_active', true)->count()
            : $plots;
        $activeCampaign = Schema::hasTable('campaigns') && Schema::hasColumn('campaigns', 'is_active')
            ? DB::table('campaigns')->where('is_active', true)->select('id', 'name', 'start_date', 'end_date')->first()
            : null;
        $counts = [];
        foreach (self::OP_TABLE as $type => $table) {
            if (Schema::hasTable($table)) {
                $counts[$type] = (int) DB::table($table)->count();
            }
        }
        return [
            'plots'           => ['total' => $plots, 'active' => $activePlots],
            'active_campaign' => $activeCampaign,
            'operation_counts' => $counts,
            'this_month_start' => now()->startOfMonth()->toDateString(),
        ];
    }

    /** @param array<string,mixed> $args */
    private function toolListPlots(array $args): array
    {
        if (! Schema::hasTable('plots')) return ['plots' => []];
        $q = DB::table('plots')->select('id', 'name', 'surface_area_ha', 'crop_type', 'is_active');
        if (! empty($args['crop'])) {
            $q->whereRaw('LOWER(crop_type) LIKE ?', ['%'.mb_strtolower((string) $args['crop']).'%']);
        }
        if (isset($args['active'])) {
            $q->where('is_active', (bool) $args['active']);
        }
        $limit = max(1, min(60, (int) ($args['limit'] ?? 40)));
        $rows = $q->orderBy('name')->limit($limit)->get()->all();
        return ['plots' => $rows, 'count' => count($rows)];
    }

    /** @param array<string,mixed> $args */
    private function toolListCampaigns(array $args): array
    {
        if (! Schema::hasTable('campaigns')) return ['campaigns' => []];
        $status = (string) ($args['status'] ?? 'all');
        $q = DB::table('campaigns')->select('id', 'name', 'start_date', 'end_date', 'is_active');
        if ($status === 'active') $q->where('is_active', true);
        if ($status === 'past')   $q->where('is_active', false);
        return ['campaigns' => $q->orderByDesc('start_date')->limit(40)->get()->all()];
    }

    /** @param array<string,mixed> $args */
    private function toolGetOperations(array $args): array
    {
        $type = (string) ($args['type'] ?? '');
        $table = self::OP_TABLE[$type] ?? null;
        if ($table === null || ! Schema::hasTable($table)) {
            return ['error' => 'invalid_type', 'type' => $type];
        }
        $q = DB::table($table)->select('*');
        if (! empty($args['plot_id']))     $q->where('plot_id', (string) $args['plot_id']);
        if (! empty($args['campaign_id'])) $q->where('campaign_id', (string) $args['campaign_id']);
        if (! empty($args['from']))        $q->where('operation_date', '>=', $this->safeDate($args['from'], 'from'));
        if (! empty($args['to']))          $q->where('operation_date', '<=', $this->safeDate($args['to'], 'to'));
        $limit = max(1, min(50, (int) ($args['limit'] ?? 20)));
        $rows = $q->orderByDesc('operation_date')->limit($limit)->get()->all();
        return ['type' => $type, 'rows' => $rows, 'count' => count($rows)];
    }

    /** @param array<string,mixed> $args */
    private function toolAggregate(array $args): array
    {
        $type = (string) ($args['type'] ?? '');
        $table = self::OP_TABLE[$type] ?? null;
        if ($table === null || ! Schema::hasTable($table)) {
            return ['error' => 'invalid_type', 'type' => $type];
        }
        $groupBy = (string) ($args['group_by'] ?? 'month');
        $metric  = (string) ($args['metric'] ?? 'count');
        $qtyCol  = self::OP_QTY_COL[$type];

        [$groupExpr, $groupLabel, $joinPlots] = $this->groupExpression($table, $groupBy);
        $metricExpr = match ($metric) {
            'sum_quantity' => "COALESCE(SUM($table.$qtyCol), 0)",
            'sum_cost'     => "COALESCE(SUM($table.price_at_entry * $table.$qtyCol), 0)",
            default        => "COUNT(*)",
        };
        // Harvest has no price_at_entry — fall back to daily_rate.
        if ($metric === 'sum_cost' && $type === 'harvest') {
            $metricExpr = "COALESCE(SUM($table.daily_rate_at_entry * $table.days_worked * $table.num_workers), 0)";
        }

        $sql = "SELECT $groupExpr AS bucket, $metricExpr AS value FROM $table";
        $bindings = [];
        if ($joinPlots) $sql .= " LEFT JOIN plots ON plots.id = $table.plot_id";

        $where = [];
        if (! empty($args['plot_id']))     { $where[] = "$table.plot_id = ?";     $bindings[] = (string) $args['plot_id']; }
        if (! empty($args['campaign_id'])) { $where[] = "$table.campaign_id = ?"; $bindings[] = (string) $args['campaign_id']; }
        if (! empty($args['from']))        { $where[] = "$table.operation_date >= ?"; $bindings[] = $this->safeDate($args['from'], 'from'); }
        if (! empty($args['to']))          { $where[] = "$table.operation_date <= ?"; $bindings[] = $this->safeDate($args['to'], 'to'); }
        if (! empty($args['crop']) && $joinPlots) {
            $where[] = "LOWER(plots.crop_type) LIKE ?";
            $bindings[] = '%'.mb_strtolower((string) $args['crop']).'%';
        }
        if ($where !== []) $sql .= ' WHERE '.implode(' AND ', $where);
        $sql .= " GROUP BY bucket ORDER BY bucket DESC LIMIT 60";

        $rows = DB::select($sql, $bindings);
        $buckets = array_map(static fn ($r) => [
            'bucket' => $r->bucket,
            'value'  => is_numeric($r->value) ? (float) $r->value : $r->value,
        ], $rows);

        return [
            'type'       => $type,
            'group_by'   => $groupBy,
            'group_kind' => $groupLabel,
            'metric'     => $metric,
            'unit'       => $this->metricUnit($type, $metric),
            'buckets'    => $buckets,
            'count'      => count($buckets),
        ];
    }

    /** @param array<string,mixed> $args */
    private function toolComparePeriods(array $args): array
    {
        $mkArgs = static fn (string $from, string $to) => [
            'type'     => $args['type'] ?? '',
            'group_by' => 'year',
            'metric'   => $args['metric'] ?? 'count',
            'from'     => $from,
            'to'       => $to,
            'plot_id'  => $args['plot_id'] ?? null,
            'crop'     => $args['crop'] ?? null,
        ];
        $a = $this->toolAggregate($mkArgs((string) $args['period_a_from'], (string) $args['period_a_to']));
        $b = $this->toolAggregate($mkArgs((string) $args['period_b_from'], (string) $args['period_b_to']));
        $sum = static function (array $agg): float {
            $t = 0.0;
            foreach ($agg['buckets'] ?? [] as $bk) $t += (float) ($bk['value'] ?? 0);
            return $t;
        };
        $va = $sum($a); $vb = $sum($b);
        $delta = $va - $vb;
        $pct = $vb == 0.0 ? null : round(($delta / $vb) * 100, 2);
        return [
            'type'   => $args['type'] ?? '',
            'metric' => $args['metric'] ?? '',
            'unit'   => $a['unit'] ?? null,
            'period_a' => ['from' => $args['period_a_from'], 'to' => $args['period_a_to'], 'value' => $va],
            'period_b' => ['from' => $args['period_b_from'], 'to' => $args['period_b_to'], 'value' => $vb],
            'delta'  => $delta,
            'pct_change' => $pct,
        ];
    }

    /** @param array<string,mixed> $args */
    private function toolSearchCatalog(array $args): array
    {
        $kind = (string) ($args['kind'] ?? '');
        $q    = trim((string) ($args['query'] ?? ''));
        if ($q === '' || mb_strlen($q) < 2) return ['error' => 'query_too_short'];
        $table = ['fertilizer' => 'fertilizers', 'pesticide' => 'pesticides', 'pest' => 'pests'][$kind] ?? null;
        if ($table === null || ! Schema::hasTable($table)) return ['error' => 'invalid_kind'];
        $limit = max(1, min(20, (int) ($args['limit'] ?? 10)));
        $like = '%'.mb_strtolower($q).'%';
        $query = DB::table($table)->whereRaw('LOWER(name) LIKE ?', [$like]);
        if (Schema::hasColumn($table, 'scientific_name')) {
            $query->orWhereRaw('LOWER(scientific_name) LIKE ?', [$like]);
        }
        $rows = $query->limit($limit)->get()->all();
        return ['kind' => $kind, 'query' => $q, 'results' => $rows, 'count' => count($rows)];
    }

    /** @param array<string,mixed> $args */
    private function toolRecentOperations(array $args): array
    {
        $limit = max(1, min(20, (int) ($args['limit'] ?? 10)));
        $all = [];
        foreach (self::OP_TABLE as $type => $table) {
            if (! Schema::hasTable($table)) continue;
            $rows = DB::table($table)
                ->select('id', 'plot_id', 'operation_date')
                ->orderByDesc('operation_date')
                ->limit($limit)
                ->get()
                ->all();
            foreach ($rows as $r) {
                $all[] = ['type' => $type, 'id' => $r->id, 'plot_id' => $r->plot_id, 'date' => $r->operation_date];
            }
        }
        usort($all, static fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
        return ['recent' => array_slice($all, 0, $limit)];
    }

    /** @param array<string,mixed> $args */
    private function toolResolveDate(array $args): array
    {
        $phrase = trim((string) ($args['phrase'] ?? ''));
        if ($phrase === '') {
            return ['error' => 'missing_phrase'];
        }
        $parsed = $this->dates->parse($phrase);
        if ($parsed === null) {
            return [
                'error'  => 'unparseable',
                'phrase' => $phrase,
                'hint'   => 'Try phrasings like "last july", "juillet 2024", "this month", "last 30 days", "Q2 2024", "this season".',
            ];
        }
        return array_merge(['phrase' => $phrase], $parsed);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * @param  array<string, array<string, mixed>>  $props
     * @param  array<int, string>                   $required
     * @return array<string, mixed>
     */
    private function fn(string $name, string $description, array $props, array $required = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $name,
                'description' => $description,
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => $props ?: (object) [],
                    'required'   => $required,
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /** @return array{0:string,1:string,2:bool} SQL expression, human label, needs plots join */
    private function groupExpression(string $table, string $groupBy): array
    {
        return match ($groupBy) {
            'day'      => ["DATE($table.operation_date)", 'day', false],
            'week'     => ["DATE_TRUNC('week', $table.operation_date)::date", 'week', false],
            'month'    => ["TO_CHAR($table.operation_date, 'YYYY-MM')", 'month', false],
            'quarter'  => ["TO_CHAR(DATE_TRUNC('quarter', $table.operation_date), 'YYYY-\"Q\"Q')", 'quarter', false],
            'year'     => ["EXTRACT(YEAR FROM $table.operation_date)::text", 'year', false],
            'plot'     => ["$table.plot_id", 'plot_id', false],
            'campaign' => ["$table.campaign_id", 'campaign_id', false],
            'crop'     => ["COALESCE(plots.crop_type, 'unknown')", 'crop_type', true],
            'product'  => ["$table.".($table === 'fertilization_operations' ? 'fertilizer_id' : 'pesticide_id'), 'product_id', false],
            default    => ["TO_CHAR($table.operation_date, 'YYYY-MM')", 'month', false],
        };
    }

    private function metricUnit(string $type, string $metric): string
    {
        if ($metric === 'sum_cost')   return 'MAD';
        if ($metric === 'count')      return 'ops';
        return match ($type) {
            'irrigation'    => 'm3',
            'fertilization' => 'kg',
            'phytosanitary' => 'kg_or_L',
            'harvest'       => 'kg',
            default         => '',
        };
    }

    private function safeDate(mixed $v, string $edge = 'from'): string
    {
        $raw = trim((string) $v);
        if ($raw === '') return now()->toDateString();
        // ISO fast path — avoids invoking the NL parser for the common case.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            try { return Carbon::parse($raw)->toDateString(); }
            catch (Throwable) { return now()->toDateString(); }
        }
        // Natural-language parsing (FR/EN). Pick the matching edge so a
        // phrase like "last july" expands to the whole month on both bounds.
        $parsed = $this->dates->parse($raw);
        if ($parsed !== null) {
            return $edge === 'to' ? $parsed['to'] : $parsed['from'];
        }
        try { return Carbon::parse($raw)->toDateString(); }
        catch (Throwable) { return now()->toDateString(); }
    }
}