<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * App-wide fuzzy lookup: "what does this word mean in this database?".
 *
 * `search_catalog` only knows about products and pests, so a user word that is
 * actually a plot ("P1"), a season ("2024-2025"), a crop ("vigne"), a person
 * ("Mohamed"), an active ingredient, or a word buried in an operation note
 * used to come back as "not found" — the assistant then told the farmer the
 * data did not exist, when in reality it was simply in another table.
 *
 * `global_search` sweeps every entity table at once with the same
 * accent/abbreviation/typo-tolerant scorer used for the catalogue, and returns
 * matches grouped by entity together with the tool to call next. It is the
 * disambiguation step before answering, never the answer itself.
 *
 * Used as a trait by {@see AiToolRegistry}; relies on the scoring helpers
 * (`foldText`, `searchTokens`) defined there.
 */
trait AiSearchTools
{
    /**
     * Entity map: table => [kind, label column, extra text columns to match,
     * columns to show, next tool to call].
     *
     * @var array<string, array<string, mixed>>
     */
    private const SEARCHABLE = [
        'fertilizers' => [
            'kind' => 'fertilizer',
            'label' => 'name',
            'text' => ['name', 'unit'],
            'show' => ['id', 'name', 'unit', 'n_percent', 'p_percent', 'k_percent', 'mg_percent', 'ca_percent', 's_percent', 'is_active'],
            'next' => 'product_info / fertilization_history (product: "<name>")',
        ],
        'pesticides' => [
            'kind' => 'pesticide',
            'label' => 'name',
            'text' => ['name', 'unit', 'chemical_composition'],
            'show' => ['id', 'name', 'unit', 'chemical_composition', 'is_active'],
            'next' => 'product_info / treatments (product: "<name>")',
        ],
        'pests' => [
            'kind' => 'pest',
            'label' => 'name',
            'text' => ['name', 'scientific_name', 'category'],
            'show' => ['id', 'name', 'scientific_name', 'category'],
            'next' => 'treatments (pest: "<name>")',
        ],
        'plots' => [
            'kind' => 'plot',
            'label' => 'name',
            'text' => ['name', 'crop_type', 'variety'],
            'show' => ['id', 'name', 'surface_area_ha', 'crop_type', 'variety', 'is_active'],
            'next' => 'plot_info / cost_per_ha / water_per_ha (plot: "<name>")',
        ],
        'campaigns' => [
            'kind' => 'campaign',
            'label' => 'name',
            'text' => ['name'],
            'show' => ['id', 'name', 'start_date', 'end_date', 'is_active'],
            'next' => 'pass campaign_id, or resolve_date_range on the season name',
        ],
        'users' => [
            'kind' => 'user',
            'label' => 'name',
            'text' => ['name', 'email', 'role'],
            'show' => ['id', 'name', 'email', 'role'],
            'next' => 'describe_data + run_sql for what this person recorded',
        ],
        'labor_config' => [
            'kind' => 'labor_config',
            'label' => 'name',
            'text' => ['name', 'label', 'role'],
            'show' => ['id', 'name', 'label', 'role', 'daily_rate', 'is_active'],
            'next' => 'cost_per_ha (harvest labour) or run_sql',
        ],
    ];

    /** Free-text columns on operations, searched when `include_notes` is on. */
    private const NOTE_COLUMNS = [
        'irrigation_operations'    => ['notes'],
        'fertilization_operations' => ['notes'],
        'phytosanitary_operations' => ['notes'],
        'harvest_operations'       => ['notes'],
    ];

    /** @return array<int, array<string, mixed>> */
    private function searchDefinitions(): array
    {
        return [
            $this->fn(
                'global_search',
                'APP-WIDE LOOKUP — find what a word the user typed refers to ANYWHERE in the app: fertilizers, pesticides (including active ingredients), '
                .'pests, plots, crops and varieties, campaigns/seasons, users, labour rates, and optionally the free-text notes on operations. '
                .'Fuzzy: accents, abbreviations (Mg = magnésium), word order, plurals and typos are tolerated. '
                .'Call this FIRST whenever a name in the question does not obviously map to one entity, or whenever a typed tool returned nothing for a name — '
                .'a name missing from one table is usually present in another, so an empty typed result is a reason to run `global_search`, never a reason to say the data does not exist. '
                .'Each hit tells you the entity kind and which tool to call next.',
                [
                    'query'         => ['type' => 'string', 'description' => 'The user\'s wording, 2-60 chars.'],
                    'kinds'         => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => ['fertilizer', 'pesticide', 'pest', 'plot', 'campaign', 'user', 'labor_config']],
                        'description' => 'Optional: restrict to these entity kinds. Omit to search everything.',
                    ],
                    'include_notes' => ['type' => 'boolean', 'description' => 'Also scan operation notes for the term (slower, use when the word looks like a free-text remark).'],
                    'limit'         => ['type' => 'integer', 'minimum' => 1, 'maximum' => 15, 'description' => 'Max hits per entity kind (default 5).'],
                ],
                ['query'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    private function callSearch(string $name, array $args): ?array
    {
        return $name === 'global_search' ? $this->toolGlobalSearch($args) : null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function toolGlobalSearch(array $args): array
    {
        $q = trim((string) ($args['query'] ?? ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return ['error' => 'query_too_short'];
        }

        $limit = max(1, min(15, (int) ($args['limit'] ?? 5)));
        $kinds = array_filter(array_map('strval', (array) ($args['kinds'] ?? [])));
        $needle = $this->foldText($q);
        $tokens = $this->searchTokens($needle);

        $groups = [];
        $totalHits = 0;

        foreach (self::SEARCHABLE as $table => $spec) {
            if ($kinds !== [] && ! in_array($spec['kind'], $kinds, true)) continue;
            if (! Schema::hasTable($table)) continue;

            $textCols = array_values(array_filter($spec['text'], static fn ($c) => Schema::hasColumn($table, $c)));
            if ($textCols === []) continue;
            $showCols = array_values(array_filter($spec['show'], static fn ($c) => Schema::hasColumn($table, $c)));

            $rows = DB::table($table)->limit(2000)->get()->all();
            $scored = [];

            foreach ($rows as $row) {
                $r = (array) $row;
                $parts = [];
                foreach ($textCols as $c) {
                    $parts[] = $this->foldText((string) ($r[$c] ?? ''));
                }
                $hay = trim(implode(' ', array_filter($parts)));
                if ($hay === '') continue;

                $score = $this->scoreHaystack($needle, $tokens, $hay, $this->foldText((string) ($r[$spec['label']] ?? '')));
                if ($score <= 0) continue;

                $shown = [];
                foreach ($showCols as $c) {
                    $shown[$c] = $r[$c] ?? null;
                }
                $scored[] = ['score' => $score, 'entity' => $shown];
            }

            if ($scored === []) continue;

            usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
            $totalHits += count($scored);
            $groups[] = [
                'kind'        => $spec['kind'],
                'total'       => count($scored),
                'matches'     => array_map(static fn ($s) => $s['entity'], array_slice($scored, 0, $limit)),
                'next_tool'   => $spec['next'],
            ];
        }

        $out = [
            'query'        => $q,
            'groups'       => $groups,
            'total_hits'   => $totalHits,
            'kinds_found'  => array_map(static fn ($g) => $g['kind'], $groups),
            'usage_note'   => 'These are candidates, not an answer. Pick the entity that matches the user\'s intent, then call the tool named in next_tool with its exact stored name. If several kinds match the same word, disambiguate in your answer or ask.',
        ];

        if (! empty($args['include_notes'])) {
            $out['note_matches'] = $this->searchOperationNotes($needle, $limit);
        }

        if ($totalHits === 0) {
            $out['no_match_note'] = 'Nothing scored in the entity tables. Before telling the user the data does not exist: retry with a shorter/simpler term, try include_notes: true, and check `describe_data` — the term may live in a table this tool does not index (notifications, feedback, sync queue, audit log).';
            $out['inventory'] = $this->searchInventory();
        }

        return $out;
    }

    /**
     * Same scorer as the catalogue search: exact, substring, token coverage,
     * then typo-tolerant similarity on the label.
     *
     * @param  array<int, string>  $tokens
     */
    private function scoreHaystack(string $needle, array $tokens, string $hay, string $label): float
    {
        if ($label !== '' && $label === $needle) return 100.0;
        if (str_contains($hay, $needle)) return 90.0;

        $hayTokens = $this->searchTokens($hay);
        if ($tokens !== [] && $hayTokens !== []) {
            $hit = 0;
            foreach ($tokens as $t) {
                foreach ($hayTokens as $h) {
                    if ($t === $h
                        || (mb_strlen($t) >= 4 && str_contains($h, $t))
                        || (mb_strlen($h) >= 4 && str_contains($t, $h))) {
                        $hit++;
                        continue 2;
                    }
                }
            }
            $coverage = $hit / count($tokens);
            if ($coverage >= 0.5) {
                return round(45 + ($coverage * 30), 1);
            }
        }

        if ($label !== '') {
            similar_text($needle, $label, $pct);
            if ($pct >= 75) return round($pct * 0.6, 1);
        }

        return 0.0;
    }

    /**
     * Free-text sweep over operation notes — where technicians record the
     * things no column models (incidents, brands, weather, remarks).
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchOperationNotes(string $needle, int $limit): array
    {
        $hits = [];
        foreach (self::NOTE_COLUMNS as $table => $cols) {
            if (! Schema::hasTable($table)) continue;
            foreach ($cols as $col) {
                if (! Schema::hasColumn($table, $col)) continue;
                $rows = DB::table($table)
                    ->whereRaw('LOWER('.$col.') LIKE ?', ['%'.$needle.'%'])
                    ->orderByDesc('operation_date')
                    ->limit($limit)
                    ->get([DB::raw('id'), DB::raw('plot_id'), DB::raw('operation_date'), DB::raw($col.' as note')])
                    ->all();
                foreach ($rows as $r) {
                    $a = (array) $r;
                    $hits[] = [
                        'operation_type' => str_replace('_operations', '', $table),
                        'id'             => $a['id'] ?? null,
                        'plot_id'        => $a['plot_id'] ?? null,
                        'date'           => $a['operation_date'] ?? null,
                        'note'           => mb_substr((string) ($a['note'] ?? ''), 0, 200),
                    ];
                }
            }
        }

        return array_slice($hits, 0, $limit * 2);
    }

    /**
     * What actually exists, so a zero-hit search still gives the model real
     * options to propose instead of an unverified "not in the database".
     *
     * @return array<string, mixed>
     */
    private function searchInventory(): array
    {
        $inv = [];
        foreach (self::SEARCHABLE as $table => $spec) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $spec['label'])) continue;
            if ($spec['kind'] === 'user') continue; // names of people are not a helpful suggestion list
            $names = DB::table($table)->limit(120)->pluck($spec['label'])->filter()->values()->all();
            if ($names !== []) $inv[$spec['kind']] = $names;
        }

        return $inv;
    }
}
