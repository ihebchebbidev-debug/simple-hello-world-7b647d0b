<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Open-ended read access to the farm database.
 *
 * The typed tools in {@see AiFarmTools} cover the questions the farm asks
 * every day, and they must stay the first choice: they resolve plot names,
 * snapshot prices and do the per-hectare arithmetic in SQL. But a typed
 * catalogue can never cover EVERY question ("quel technicien a saisi le plus
 * d'opérations en juin", "quelles parcelles n'ont jamais été fertilisées",
 * "combien de notifications non lues"), and the assistant answering "I don't
 * have a tool for that" about data sitting in its own database is the failure
 * mode this trait exists to remove.
 *
 * Two tools:
 *   - `describe_data` — the schema, in domain language, so the model knows
 *     exactly which tables, columns and enums exist before it writes SQL.
 *   - `run_sql` — a hard-guarded read-only SELECT escape hatch.
 *
 * `run_sql` is defence-in-depth, not trust: single statement, SELECT/WITH
 * only, whitelisted tables, no DML/DDL keywords, no system catalogs, an
 * enforced outer LIMIT and a statement timeout, all inside a transaction that
 * is always rolled back.
 *
 * Used as a trait by {@see AiToolRegistry}.
 */
trait AiSqlTools
{
    /** Hard cap on rows returned to the model, whatever `limit` asks for. */
    private const SQL_MAX_ROWS = 200;

    /** Statement timeout for ad-hoc SQL. Generous: accuracy over latency. */
    private const SQL_TIMEOUT_MS = 20000;

    /**
     * Every table the assistant may read, with what it means to the farm.
     * A table absent from this map is invisible to `run_sql` — that is how
     * credentials (`users.password`), tokens and audit internals stay out of
     * reach even though the schema is otherwise open.
     *
     * @var array<string, string>
     */
    private const READABLE_TABLES = [
        'plots' => 'The parcels. id (uuid), name ("P1"), surface_area_ha (hectares — null here makes every per-ha figure impossible), crop_type ("vigne", "olivier"…), variety, is_active.',
        'campaigns' => 'Seasons: name ("2024-2025"), start_date, end_date, is_active. A campaign is NOT a civil year — it usually straddles two.',
        'irrigation_operations' => 'One row per irrigation: plot_id, campaign_id, operation_date, water_quantity (in the unit from water_config, normally m³), price_at_entry (TND per unit, frozen when the row was written), notes.',
        'fertilization_operations' => 'One row per fertilization: plot_id, campaign_id, operation_date, fertilizer_id, quantity, price_at_entry, and the N/P/K percentages frozen at entry time (npk_* / *_at_entry columns) so historical nutrient maths never shifts when a product is re-formulated.',
        'phytosanitary_operations' => 'One row per treatment: plot_id, campaign_id, operation_date, pesticide_id, pest_id, dose, water_volume_l (LITRES — this is spray water, never irrigation water), price_at_entry.',
        'harvest_operations' => 'One row per harvest: plot_id, campaign_id, operation_date, quantity_harvested (kg), num_workers, days_worked, daily_rate (TND) — labour cost = num_workers * days_worked * daily_rate.',
        'fertilizers' => 'Fertilizer catalogue: name, unit, current price, and the N-P-K (+ Mg/Ca/S where tracked) composition percentages.',
        'pesticides' => 'Pesticide catalogue: name, unit, current price, chemical_composition (the active-ingredient text — search it for ingredient families such as amino acids).',
        'pests' => 'Pest / disease reference: name and scientific_name (mildiou, oïdium, cératite…).',
        'price_history' => 'Historical unit prices per product, with validity dates. Used to cost an operation whose price_at_entry is missing.',
        'water_config' => 'Irrigation pricing and units: the unit irrigation quantities are recorded in (m³ by default) and the price per unit.',
        'labor_config' => 'Default labour rates used for harvest costing when a row carries no daily_rate.',
        'postings' => 'The mobile app sync queue. status (pending/failed/synced), payload type, error message, timestamps. Pending or failed rows mean the database is BEHIND what technicians recorded — totals may be incomplete.',
        'notifications' => 'In-app notifications: type, title, body, read flag, created_at.',
        'feedback_reports' => 'User-submitted feedback / bug reports from the app.',
        'ai_conversations' => 'Assistant conversation threads (metadata only).',
        'ai_feedback' => 'Thumbs up/down the users left on assistant answers.',
        'backup_snapshots' => 'Database backup snapshot log: when a backup ran and whether it succeeded.',
        'system_logs' => 'Application audit log: action, subject, actor id, created_at. Use for "who changed what / when".',
        'users' => 'App accounts. READ ONLY the safe columns: id, name, email, role, created_at. Never select password, remember_token or any *_token column — those are blocked.',
    ];

    /**
     * Columns that must never reach the model, whatever the query asks for.
     * Matched case-insensitively against the raw SQL and the result keys.
     *
     * @var array<int, string>
     */
    private const BLOCKED_COLUMNS = [
        'password', 'password_hash', 'remember_token', 'api_token', 'access_token',
        'refresh_token', 'secret', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    /** @return array<int, array<string, mixed>> */
    private function sqlDefinitions(): array
    {
        return [
            $this->fn(
                'describe_data',
                'SCHEMA MAP — every table and column the assistant can read, in domain language, with row counts and the date range each table covers. '
                .'Call this BEFORE `run_sql`, and whenever a question mentions something no typed tool covers (users, notifications, sync queue, audit log, feedback, backups) '
                .'so you can see whether the data exists at all instead of telling the user you have no access to it.',
                [
                    'table' => ['type' => 'string', 'description' => 'Optional: one table name for full column detail. Omit for the whole map.'],
                ],
            ),

            $this->fn(
                'run_sql',
                'READ-ONLY SQL ESCAPE HATCH — run one SELECT against the farm database when NO typed tool can answer the question. '
                .'This is what makes "I do not have access to that" almost never a valid answer: if the data is in the schema from `describe_data`, it can be queried here. '
                .'Rules enforced by the server: a single SELECT (or WITH … SELECT), only whitelisted tables, no writes, results capped. '
                .'ALWAYS prefer a typed tool when one fits — they already snapshot prices, resolve plot names and compute per-hectare values, and hand-written SQL that skips price_at_entry or surface_area_ha will silently produce wrong money and wrong per-ha figures. '
                .'Use `run_sql` for the long tail: cross-table questions, "which plots have never…", counts over users/notifications/postings/system_logs, unusual groupings, sanity cross-checks of a typed tool result.',
                [
                    'sql'     => ['type' => 'string', 'description' => 'One SELECT statement. No semicolon, no CTE writes. Use ISO dates. Prefer explicit column lists over SELECT *.'],
                    'purpose' => ['type' => 'string', 'description' => 'One short sentence: what this query is meant to establish. Logged for audit.'],
                    'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::SQL_MAX_ROWS, 'description' => 'Max rows to return (default 50, hard cap '.self::SQL_MAX_ROWS.'). Aggregate in SQL rather than pulling rows and counting them yourself.'],
                ],
                ['sql'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    private function callSql(string $name, array $args): ?array
    {
        return match ($name) {
            'describe_data' => $this->toolDescribeData($args),
            'run_sql'       => $this->toolRunSql($args),
            default         => null,
        };
    }

    // ─── describe_data ──────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function toolDescribeData(array $args): array
    {
        $only = trim((string) ($args['table'] ?? ''));

        $tables = [];
        foreach (self::READABLE_TABLES as $table => $meaning) {
            if ($only !== '' && $table !== $only) {
                continue;
            }
            if (! Schema::hasTable($table)) {
                continue;
            }

            $entry = ['table' => $table, 'meaning' => $meaning];

            try {
                $entry['row_count'] = (int) DB::table($table)->count();
            } catch (Throwable) {
                $entry['row_count'] = null;
            }

            $columns = array_values(array_filter(
                Schema::getColumnListing($table),
                fn (string $c): bool => ! $this->isBlockedColumn($c),
            ));

            // The full map would be thousands of tokens of column types; the
            // detail only matters for the table the model is about to query.
            if ($only !== '') {
                $entry['columns'] = array_map(function (string $c) use ($table): array {
                    try {
                        $type = Schema::getColumnType($table, $c);
                    } catch (Throwable) {
                        $type = 'unknown';
                    }

                    return ['name' => $c, 'type' => $type];
                }, $columns);

                $entry['sample_row'] = $this->safeSampleRow($table);
            } else {
                $entry['columns'] = $columns;
            }

            $entry['date_range'] = $this->tableDateRange($table, $columns);

            $tables[] = $entry;
        }

        if ($only !== '' && $tables === []) {
            return [
                'error'            => 'unknown_table',
                'asked'            => $only,
                'readable_tables'  => array_keys(self::READABLE_TABLES),
                'note'             => 'That table is not readable. Only the tables listed here exist for the assistant; do not tell the user data is missing before checking this list.',
            ];
        }

        return [
            'tables'         => $tables,
            'currency'       => 'TND',
            'joins'          => [
                '*_operations.plot_id → plots.id',
                '*_operations.campaign_id → campaigns.id (may be NULL on legacy rows — those vanish from campaign-filtered reports)',
                'fertilization_operations.fertilizer_id → fertilizers.id',
                'phytosanitary_operations.pesticide_id → pesticides.id',
                'phytosanitary_operations.pest_id → pests.id',
            ],
            'costing_rules'  => [
                'irrigation / fertilization / phytosanitary cost = quantity * price_at_entry (the price frozen on the row). Fall back to price_history only when price_at_entry is NULL.',
                'harvest labour cost = num_workers * days_worked * daily_rate.',
                'Nutrient kg = quantity * <nutrient>_pct_at_entry / 100 — always the *_at_entry percentage, never the catalogue value today.',
                'Per hectare = value / plots.surface_area_ha. Across several plots use SUM(value) / SUM(surface_area_ha), not the mean of the ratios.',
                'irrigation_operations.water_quantity is in the water_config unit (m³). phytosanitary_operations.water_volume_l is LITRES of spray water — never add the two.',
            ],
            'how_to_use'     => 'Prefer a typed tool when one fits. When none does, write one SELECT and run it with `run_sql`. If a query returns nothing, widen it (drop the date filter, drop the campaign filter) before telling the user the data does not exist.',
        ];
    }

    /**
     * First and last date covered by a table, so the model can tell "no data
     * in this window" apart from "this table is empty".
     *
     * @param  array<int, string>  $columns
     * @return array<string, mixed>|null
     */
    private function tableDateRange(string $table, array $columns): ?array
    {
        $col = null;
        foreach (['operation_date', 'date', 'start_date', 'created_at'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $col = $candidate;
                break;
            }
        }
        if ($col === null) {
            return null;
        }

        try {
            $row = DB::table($table)
                ->selectRaw("MIN({$col}) AS first_at, MAX({$col}) AS last_at")
                ->first();
        } catch (Throwable) {
            return null;
        }

        if ($row === null || ($row->first_at ?? null) === null) {
            return null;
        }

        return ['column' => $col, 'first' => (string) $row->first_at, 'last' => (string) $row->last_at];
    }

    /** @return array<string, mixed>|null */
    private function safeSampleRow(string $table): ?array
    {
        try {
            $row = DB::table($table)->limit(1)->first();
        } catch (Throwable) {
            return null;
        }
        if ($row === null) {
            return null;
        }

        $out = [];
        foreach ((array) $row as $k => $v) {
            if ($this->isBlockedColumn((string) $k)) {
                continue;
            }
            $out[$k] = is_scalar($v) || $v === null ? $v : '…';
        }

        return $out;
    }

    // ─── run_sql ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function toolRunSql(array $args): array
    {
        $raw   = trim((string) ($args['sql'] ?? ''));
        $limit = max(1, min(self::SQL_MAX_ROWS, (int) ($args['limit'] ?? 50)));

        if ($raw === '') {
            return ['error' => 'empty_sql', 'note' => 'Provide one SELECT statement.'];
        }

        $rejection = $this->rejectUnsafeSql($raw);
        if ($rejection !== null) {
            return $rejection;
        }

        $sql = rtrim(trim($this->stripSqlComments($raw)), "; \t\n\r");

        \Illuminate\Support\Facades\Log::info('ai.run_sql', [
            'purpose' => mb_substr((string) ($args['purpose'] ?? ''), 0, 200),
            'sql'     => mb_substr($sql, 0, 2000),
        ]);

        try {
            $rows = $this->runReadOnly($sql, $limit);
        } catch (Throwable $e) {
            // The driver message is the model's only way to fix its own SQL,
            // so it goes back — but table/column names only, never a stack.
            return [
                'error'     => 'sql_failed',
                'db_message' => mb_substr($e->getMessage(), 0, 400),
                'note'      => 'Fix the query and retry. Call `describe_data` with the table name to check the real column names before guessing again. Never report this error to the user as "no data".',
            ];
        }

        $rows = array_map(function (array $r): array {
            foreach (array_keys($r) as $k) {
                if ($this->isBlockedColumn((string) $k)) {
                    unset($r[$k]);
                }
            }

            return $r;
        }, $rows);

        $truncated = count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'sql'           => $sql,
            'returned_rows' => count($rows),
            'truncated'     => $truncated,
            'rows'          => $rows,
            'note'          => $truncated
                ? 'The result was cut at the row cap. Do NOT add these rows up as if they were the whole set — re-run with an aggregate (COUNT/SUM) instead.'
                : 'These rows are the complete result of this query.',
            'reminder'      => 'Hand-written SQL bypasses the typed tools\' costing and per-hectare rules. Money must use price_at_entry, per-ha must divide by plots.surface_area_ha.',
        ];
    }

    /**
     * Run a vetted SELECT with a row cap, a statement timeout, and a
     * transaction that is always rolled back.
     *
     * @return array<int, array<string, mixed>>
     */
    private function runReadOnly(string $sql, int $limit): array
    {
        $connection = DB::connection();
        $driver     = $connection->getDriverName();

        // Cap in the wrapper rather than trusting the model's own LIMIT.
        $wrapped = 'SELECT * FROM ('.$sql.') AS ai_readonly_query LIMIT '.($limit + 1);

        $connection->beginTransaction();

        try {
            if ($driver === 'pgsql') {
                $connection->statement('SET LOCAL statement_timeout = '.self::SQL_TIMEOUT_MS);
                $connection->statement('SET LOCAL transaction_read_only = on');
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                $connection->statement('SET SESSION MAX_EXECUTION_TIME = '.self::SQL_TIMEOUT_MS);
            }

            $result = $connection->select($wrapped);
        } finally {
            // Nothing here should ever have written; rolling back guarantees it.
            $connection->rollBack();
        }

        return array_map(static fn ($r): array => (array) $r, $result);
    }

    /**
     * Static safety gate. Returns a rejection payload, or null when the SQL
     * is acceptable. Every rejection explains itself so the model can rewrite
     * the query instead of giving up on the question.
     *
     * @return array<string, mixed>|null
     */
    private function rejectUnsafeSql(string $raw): ?array
    {
        $sql = $this->stripSqlComments($raw);
        $bare = rtrim(trim($sql), "; \t\n\r");
        $lower = mb_strtolower($bare);

        if ($bare === '') {
            return ['error' => 'empty_sql'];
        }

        // One statement only: a trailing semicolon was already trimmed, so any
        // remaining one means a second statement is being smuggled in.
        if (str_contains($bare, ';')) {
            return $this->sqlRejected('multiple_statements', 'Send exactly one SELECT, with no semicolon.');
        }

        if (preg_match('/^\s*(select|with)\b/i', $bare) !== 1) {
            return $this->sqlRejected('not_a_select', 'Only SELECT (or WITH … SELECT) is allowed. This assistant never modifies data.');
        }

        $forbidden = [
            'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
            'grant', 'revoke', 'merge', 'copy', 'vacuum',
            'lock', 'reset', 'listen', 'notify', 'prepare',
            'execute', 'refresh', 'reindex', 'begin', 'commit', 'rollback',
            'savepoint', 'pg_sleep', 'pg_read_file', 'pg_ls_dir', 'lo_import',
            'lo_export', 'dblink', 'load_file', 'outfile', 'dumpfile', 'sleep',
        ];
        foreach ($forbidden as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $lower) === 1) {
                return $this->sqlRejected('forbidden_keyword', 'The keyword "'.$word.'" is not allowed. Use a plain read-only SELECT.');
            }
        }

        if (preg_match('/\binto\s+(outfile|dumpfile|@|\w)/i', $lower) === 1) {
            return $this->sqlRejected('forbidden_keyword', 'SELECT … INTO is not allowed.');
        }

        if (preg_match('/\b(information_schema|pg_catalog|pg_[a-z_]+|mysql\.|sys\.|sqlite_)\w*/i', $lower) === 1) {
            return $this->sqlRejected(
                'system_catalog',
                'System catalogs are not readable. Call `describe_data` for the schema instead — it lists every table and column you can query.',
            );
        }

        foreach (self::BLOCKED_COLUMNS as $col) {
            if (preg_match('/\b'.preg_quote($col, '/').'\b/i', $lower) === 1) {
                return $this->sqlRejected('blocked_column', 'The column "'.$col.'" holds credentials and is never readable.');
            }
        }

        // Table whitelist. CTE aliases defined in the same query are fine.
        $cte = [];
        if (preg_match_all('/(?:with|,)\s+([a-z_][a-z0-9_]*)\s+as\s*\(/i', $lower, $m) > 0) {
            $cte = $m[1];
        }

        preg_match_all('/\b(?:from|join)\s+([a-z_][a-z0-9_."]*)/i', $lower, $tm);
        $unknown = [];
        foreach ($tm[1] ?? [] as $ref) {
            $table = trim(str_replace('"', '', $ref));
            if (str_contains($table, '.')) {
                $parts = explode('.', $table);
                $table = (string) end($parts);
            }
            if ($table === '' || in_array($table, $cte, true)) {
                continue;
            }
            if (! array_key_exists($table, self::READABLE_TABLES)) {
                $unknown[] = $table;
            }
        }

        if ($unknown !== []) {
            return $this->sqlRejected(
                'table_not_readable',
                'These tables are not readable: '.implode(', ', array_unique($unknown)).'.',
                array_keys(self::READABLE_TABLES),
            );
        }

        return null;
    }

    /**
     * @param  array<int, string>|null  $readable
     * @return array<string, mixed>
     */
    private function sqlRejected(string $error, string $note, ?array $readable = null): array
    {
        $out = [
            'error' => $error,
            'note'  => $note.' Rewrite the query — do NOT tell the user the data is unavailable because of this.',
        ];
        if ($readable !== null) {
            $out['readable_tables'] = $readable;
        }

        return $out;
    }

    private function stripSqlComments(string $sql): string
    {
        $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;
        $sql = preg_replace('/#[^\n]*/', ' ', $sql) ?? $sql;

        return $sql;
    }

    private function isBlockedColumn(string $column): bool
    {
        $c = mb_strtolower($column);
        foreach (self::BLOCKED_COLUMNS as $blocked) {
            if (str_contains($c, $blocked)) {
                return true;
            }
        }

        return str_ends_with($c, '_token');
    }
}
