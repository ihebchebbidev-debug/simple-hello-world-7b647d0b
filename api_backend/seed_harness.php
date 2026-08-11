<?php
// Local-only QA seeder for the harness. Not part of the app runtime.

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Insert only the columns that exist; NOT NULL columns get a typed default. */
function ins(string $table, array $row): void
{
    if (! Schema::hasTable($table)) {
        echo "skip missing table $table\n";
        return;
    }
    $cols = Schema::getColumnListing($table);
    $row = array_intersect_key($row, array_flip($cols));
    if (isset($row['id']) && Schema::getColumnType($table, 'id') !== 'uuid') {
        unset($row['id']);
    }

    foreach (DB::select('
        select column_name, data_type, is_nullable, column_default
          from information_schema.columns
         where table_schema = current_schema() and table_name = ?', [$table]) as $c) {
        $n = $c->column_name;
        if (array_key_exists($n, $row) || $c->is_nullable === 'YES' || $c->column_default !== null) {
            continue;
        }
        $row[$n] = match (true) {
            str_contains($c->data_type, 'uuid')      => (string) Str::uuid(),
            str_contains($c->data_type, 'timestamp') => now(),
            $c->data_type === 'date'                 => now()->toDateString(),
            str_contains($c->data_type, 'bool')      => true,
            in_array($c->data_type, ['integer', 'bigint', 'smallint', 'numeric', 'double precision', 'real'], true) => 1,
            default                                  => 'x',
        };
    }
    DB::table($table)->insert($row);
}

$now = now();
$uuid = fn () => (string) Str::uuid();

foreach (array_reverse([
    'plots', 'campaigns', 'irrigation_operations', 'fertilization_operations',
    'phytosanitary_operations', 'harvest_operations', 'fertilizers', 'pesticides',
    'pests', 'price_history', 'water_config', 'labor_config', 'postings',
    'notifications', 'feedback_reports', 'system_logs', 'users', 'user_roles',
]) as $t) {
    if (Schema::hasTable($t)) {
        DB::statement('TRUNCATE TABLE '.$t.' CASCADE');
    }
}

$userId = $uuid();
ins('users', ['id' => $userId, 'name' => 'Tech Ali', 'email' => 'ali@example.com', 'password' => bcrypt('x'), 'role' => 'technician', 'created_at' => $now, 'updated_at' => $now]);
ins('user_roles', ['id' => $uuid(), 'user_id' => $userId, 'role' => 'admin', 'assigned_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

$campA = $uuid();
$campB = $uuid();
ins('campaigns', ['id' => $campA, 'name' => '2024-2025', 'start_date' => '2024-09-01', 'end_date' => '2025-08-31', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now]);
ins('campaigns', ['id' => $campB, 'name' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-08-31', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

$plots = [];
foreach ([['B11', 'vigne', 3.5], ['B12', 'vigne', null], ['A1', 'olivier', 12.25], ['C3', 'agrumes', 0]] as [$name, $crop, $ha]) {
    $id = $uuid();
    $plots[$name] = $id;
    ins('plots', ['id' => $id, 'name' => $name, 'crop_type' => $crop, 'variety' => 'std', 'surface_area_ha' => $ha, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
}

$fertA = $uuid();
$fertB = $uuid();
ins('fertilizers', ['id' => $fertA, 'name' => 'Urée 46', 'unit' => 'kg', 'price' => 2.4, 'current_price' => 2.4, 'nitrogen_percentage' => 46, 'phosphorus_percentage' => 0, 'potassium_percentage' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
ins('fertilizers', ['id' => $fertB, 'name' => 'NPK 15-15-15', 'unit' => 'kg', 'price' => 3.1, 'current_price' => 3.1, 'nitrogen_percentage' => 15, 'phosphorus_percentage' => 15, 'potassium_percentage' => 15, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

$pest = $uuid();
ins('pesticides', ['id' => $pest, 'name' => 'Bouillie cuivre', 'unit' => 'l', 'price' => 18.5, 'current_price' => 18.5, 'chemical_composition' => 'cuivre, acides aminés libres', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
$pestId = $uuid();
ins('pests', ['id' => $pestId, 'name' => 'Mildiou', 'scientific_name' => 'Plasmopara viticola', 'created_at' => $now, 'updated_at' => $now]);

ins('water_config', ['id' => $uuid(), 'unit' => 'm3', 'price_per_unit' => 0.35, 'created_at' => $now, 'updated_at' => $now]);
ins('labor_config', ['id' => $uuid(), 'daily_rate' => 25, 'created_at' => $now, 'updated_at' => $now]);
ins('price_history', ['id' => $uuid(), 'product_type' => 'fertilizer', 'product_id' => $fertA, 'price' => 2.2, 'valid_from' => '2025-01-01', 'valid_to' => '2026-12-31', 'created_at' => $now, 'updated_at' => $now]);

$dates = ['2025-03-15', '2025-11-02', '2026-01-20', '2026-02-14', '2026-06-30', '2027-01-01'];
foreach ($dates as $i => $d) {
    $camp = $d < '2025-09-01' ? $campA : $campB;
    $plotId = $plots[['B11', 'B11', 'B11', 'B12', 'A1', 'C3'][$i]];

    ins('irrigation_operations', [
        'id' => $uuid(), 'plot_id' => $plotId, 'campaign_id' => $i === 4 ? null : $camp,
        'operation_date' => $d, 'water_quantity' => $i === 3 ? 0 : 120 + $i * 10,
        'unit_at_entry' => $i === 2 ? 'litre' : 'm3', 'price_at_entry' => $i === 1 ? null : 0.35,
        'notes' => 'irrigation goutte à goutte', 'user_id' => $userId, 'created_by' => $userId,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    ins('fertilization_operations', [
        'id' => $uuid(), 'plot_id' => $plotId, 'campaign_id' => $camp, 'operation_date' => $d,
        'fertilizer_id' => $i % 2 ? $fertB : $fertA, 'quantity' => 50 + $i,
        'quantity_applied' => 50 + $i, 'unit_at_entry' => 'kg',
        'price_at_entry' => $i === 2 ? null : 2.4,
        'npk_n_at_entry' => 46, 'npk_p_at_entry' => 0, 'npk_k_at_entry' => 0,
        'nitrogen_percentage_at_entry' => 46, 'phosphorus_percentage_at_entry' => 0,
        'potassium_percentage_at_entry' => 0, 'notes' => 'apport azoté',
        'user_id' => $userId, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
    ]);
    ins('phytosanitary_operations', [
        'id' => $uuid(), 'plot_id' => $plotId, 'campaign_id' => $camp, 'operation_date' => $d,
        'pesticide_id' => $pest, 'pest_id' => $pestId, 'dose' => 2.5, 'quantity_applied' => 2.5,
        'water_volume_l' => 400, 'unit_at_entry' => 'l', 'price_at_entry' => 18.5,
        'notes' => 'traitement mildiou', 'user_id' => $userId, 'created_by' => $userId,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    ins('harvest_operations', [
        'id' => $uuid(), 'plot_id' => $plotId, 'campaign_id' => $camp, 'operation_date' => $d,
        'quantity_harvested' => 1000 + $i * 250, 'num_workers' => 4, 'days_worked' => 2,
        'daily_rate' => $i === 0 ? null : 25, 'unit_at_entry' => 'kg', 'notes' => 'récolte',
        'user_id' => $userId, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

// Duplicate row (data_quality duplicates check).
$dup = DB::table('irrigation_operations')->first();
if ($dup) {
    $row = (array) $dup;
    $row['id'] = $uuid();
    DB::table('irrigation_operations')->insert($row);
}

foreach (['pending', 'failed', 'synced'] as $st) {
    ins('postings', ['id' => $uuid(), 'status' => $st, 'operation_type' => 'irrigation', 'type' => 'irrigation', 'payload' => json_encode(['x' => 1]), 'error_message' => $st === 'failed' ? 'timeout' : null, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
}
ins('notifications', ['id' => $uuid(), 'type' => 'info', 'title' => 'Bienvenue', 'body' => 'test', 'is_read' => false, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
ins('feedback_reports', ['id' => $uuid(), 'user_id' => $userId, 'message' => 'bug', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
ins('system_logs', ['id' => $uuid(), 'action' => 'update', 'subject' => 'plots', 'user_id' => $userId, 'actor_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);

echo "seeded\n";
foreach (['plots', 'campaigns', 'irrigation_operations', 'fertilization_operations', 'phytosanitary_operations', 'harvest_operations'] as $t) {
    echo $t, ' = ', DB::table($t)->count(), "\n";
}
