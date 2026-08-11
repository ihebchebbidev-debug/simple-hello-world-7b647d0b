<?php
// Local-only QA harness: exercises every AI tool against a seeded Postgres.
// Not part of the app runtime.

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$fails = [];
$log = [];

$reg = $app->make(App\Services\AiChat\AiToolRegistry::class);

$calls = [
    ['get_overview', []],
    ['list_plots', []],
    ['list_plots', ['crop' => 'vigne', 'active' => true, 'limit' => 5]],
    ['list_campaigns', []],
    ['list_campaigns', ['status' => 'active']],
    ['recent_operations', ['limit' => 5]],
    ['resolve_date_range', ['phrase' => 'en deux mille vingt-six']],
    ['resolve_date_range', ['phrase' => 'le mois dernier']],
];
foreach (['irrigation', 'fertilization', 'phytosanitary', 'harvest'] as $t) {
    $calls[] = ['get_operations', ['type' => $t, 'limit' => 5]];
    $calls[] = ['get_operations', ['type' => $t, 'from' => '2026-01-01', 'to' => '2026-12-31', 'limit' => 5]];
    foreach (['plot', 'month', 'campaign', 'crop', 'day', 'year'] as $g) {
        foreach (['quantity', 'cost', 'count', 'water_m3', 'harvest_kg'] as $m) {
            $calls[] = ['aggregate_operations', ['type' => $t, 'group_by' => $g, 'metric' => $m]];
        }
    }
    $calls[] = ['compare_periods', ['type' => $t, 'metric' => 'cost',
        'period_a_from' => '2025-01-01', 'period_a_to' => '2025-12-31',
        'period_b_from' => '2026-01-01', 'period_b_to' => '2026-12-31']];
}
foreach (['fertilizer', 'pesticide', 'pest'] as $k) {
    $calls[] = ['search_catalog', ['kind' => $k, 'query' => 'a']];
}
$plotVariants = [[], ['plot' => 'B11'], ['plot' => 'b 11'], ['crop' => 'vigne']];
foreach ($plotVariants as $pv) {
    foreach (['plot_info', 'water_per_ha', 'treatments', 'fertilization_history', 'irrigation_history', 'harvest_history', 'cost_per_ha', 'data_quality'] as $tool) {
        $calls[] = [$tool, $pv];
        $calls[] = [$tool, $pv + ['from' => '2026-01-01', 'to' => '2026-12-31']];
        $calls[] = [$tool, $pv + ['campaign' => '2025-2026']];
    }
    $calls[] = ['nutrient_per_ha', $pv + ['nutrient' => 'N']];
    $calls[] = ['nutrient_per_ha', $pv + ['nutrient' => 'P']];
    $calls[] = ['nutrient_per_ha', $pv + ['nutrient' => 'K']];
    $calls[] = ['product_usage', $pv + ['query' => 'uree']];
    $calls[] = ['locate_data', $pv + ['query' => 'irrigation']];
}
$calls[] = ['cost_per_ha', ['type' => 'irrigation']];
$calls[] = ['cost_per_ha', ['type' => 'fertilization']];
$calls[] = ['cost_per_ha', ['type' => 'phytosanitary']];
$calls[] = ['cost_per_ha', ['type' => 'harvest']];
$calls[] = ['cost_per_ha', ['type' => 'all']];
$calls[] = ['product_info', ['query' => 'uree']];
$calls[] = ['product_info', ['query' => 'cuivre', 'kind' => 'pesticide']];
$calls[] = ['campaign_compare', ['campaign_a' => '2025-2026']];
$calls[] = ['campaign_compare', ['campaign_a' => '2025-2026', 'campaign_b' => '2024-2025', 'metric' => 'harvest_kg']];
$calls[] = ['data_quality', ['checks' => ['missing_price', 'missing_quantity', 'missing_campaign', 'future_dated', 'duplicates', 'missing_surface', 'unit_mismatch']]];
$calls[] = ['sync_status', []];
$calls[] = ['sync_status', ['status' => 'failed', 'limit' => 5]];
$calls[] = ['global_search', ['query' => 'b11']];
$calls[] = ['global_search', ['query' => 'uree', 'include_notes' => true]];
$calls[] = ['describe_data', []];
foreach (['plots', 'irrigation_operations', 'harvest_operations', 'users', 'postings'] as $t) {
    $calls[] = ['describe_data', ['table' => $t]];
}
$calls[] = ['run_sql', ['sql' => 'SELECT name, surface_area_ha FROM plots ORDER BY name', 'purpose' => 'smoke']];
$calls[] = ['run_sql', ['sql' => "SELECT p.name, COALESCE(SUM(h.quantity_harvested),0) AS kg FROM plots p LEFT JOIN harvest_operations h ON h.plot_id = p.id GROUP BY p.name ORDER BY kg DESC", 'purpose' => 'smoke2']];
$calls[] = ['run_sql', ['sql' => 'SELECT password FROM users', 'purpose' => 'must be blocked']];
$calls[] = ['run_sql', ['sql' => 'DELETE FROM plots', 'purpose' => 'must be blocked']];

foreach ($calls as [$name, $args]) {
    $t0 = microtime(true);
    try {
        $res = $reg->call($name, $args);
    } catch (Throwable $e) {
        $fails[] = [$name, $args, 'EXCEPTION: '.$e->getMessage()];
        continue;
    }
    $ms = (int) ((microtime(true) - $t0) * 1000);
    $err = $res['error'] ?? null;
    $line = sprintf('%-22s %-4dms %s %s', $name, $ms, ($res['ok'] ?? false) ? 'ok' : 'NOT_OK', $err ? '['.$err.']' : '');
    $log[] = $line.' args='.json_encode($args, JSON_UNESCAPED_UNICODE);
    if ($err === 'tool_failed' || str_starts_with((string) $err, 'unknown_tool')) {
        $fails[] = [$name, $args, (string) $err];
    }
}

// Other AI subsystems that hit the DB.
$extra = [
    'AiContextBuilder' => function () use ($app) {
        $b = $app->make(App\Services\AiChat\AiContextBuilder::class);
        foreach (get_class_methods($b) as $m) {
            $r = new ReflectionMethod($b, $m);
            if (! $r->isPublic() || $r->isStatic() || $r->getNumberOfRequiredParameters() > 1) {
                continue;
            }
            if ($r->getNumberOfRequiredParameters() === 1) {
                $p = $r->getParameters()[0];
                $type = (string) $p->getType();
                if (! str_contains($type, 'string')) {
                    continue;
                }
                $b->{$m}('Combien de quantités récoltées dans la parcelle B11 en 2026 ?');
            } else {
                $b->{$m}();
            }
        }
        return 'ok';
    },
    'AiFastAnswers' => function () use ($app) {
        $fa = $app->make(App\Services\AiChat\AiFastAnswers::class);
        $qs = [
            'Combien de quantités récoltées dans la parcelle B11 en 2026 ?',
            'combien de parcelles ai-je ?',
            'quelle est la surface totale ?',
            "combien d'eau sur B11 en 2026 ?",
            'coût total 2026',
        ];
        foreach (get_class_methods($fa) as $m) {
            $r = new ReflectionMethod($fa, $m);
            if (! $r->isPublic() || $r->getNumberOfRequiredParameters() !== 1) {
                continue;
            }
            $type = (string) $r->getParameters()[0]->getType();
            if (! str_contains($type, 'string')) {
                continue;
            }
            foreach ($qs as $q) {
                $fa->{$m}($q);
            }
        }
        return 'ok';
    },
    'AiDailyRollup' => function () use ($app) {
        $ru = $app->make(App\Services\AiChat\AiDailyRollup::class);
        foreach (get_class_methods($ru) as $m) {
            $r = new ReflectionMethod($ru, $m);
            if (! $r->isPublic() || $r->getNumberOfRequiredParameters() !== 0 || $r->isStatic()) {
                continue;
            }
            $ru->{$m}();
        }
        return 'ok';
    },
];
foreach ($extra as $label => $fn) {
    try {
        $fn();
        $log[] = sprintf('%-22s        ok  (subsystem)', $label);
    } catch (Throwable $e) {
        $fails[] = [$label, [], 'EXCEPTION: '.get_class($e).': '.$e->getMessage()];
    }
}

echo implode("\n", $log), "\n\n";
echo '=== FAILURES: '.count($fails)." ===\n";
foreach ($fails as [$n, $a, $e]) {
    echo $n.' args='.json_encode($a, JSON_UNESCAPED_UNICODE).' -> '.$e."\n";
}
echo 'total calls: '.count($calls)."\n";
