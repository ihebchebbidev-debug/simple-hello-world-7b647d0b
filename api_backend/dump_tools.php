<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r = $app->make(App\Services\AiChat\AiToolRegistry::class);
$defs = $r->definitions();
foreach ($defs as $d) {
  $f = $d['function'] ?? $d;
  echo $f['name'], ' :: ', json_encode(array_keys((array)($f["parameters"]["properties"] ?? []))), ' req=', json_encode($f['parameters']['required'] ?? []), "\n";
}
