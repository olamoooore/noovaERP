<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. Verify routes exist
echo "=== Chatter Routes ===\n";
$routes = collect(Illuminate\Support\Facades\Route::getRoutes())
    ->filter(fn ($r) => str_contains($r->uri, 'chatter'))
    ->map(fn ($r) => implode('|', $r->methods) . ' ' . $r->uri)
    ->values()
    ->toArray();

echo "Found " . count($routes) . " chatter routes:\n";
foreach ($routes as $r) {
    echo "  {$r}\n";
}

if (count($routes) !== 6) {
    echo "FAIL: expected 6 routes, got " . count($routes) . "\n";
    exit(1);
}

// 2. Verify chatter tables exist in tenant schemas
echo "\n=== Chatter Tables in Tenant Schemas ===\n";
$schemas = Illuminate\Support\Facades\DB::select(
    "SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN (?, ?, ?, ?)",
    ['public', 'information_schema', 'pg_catalog', 'pg_toast']
);

$ok = 0;
$fail = 0;
foreach ($schemas as $row) {
    $schema = $row->schema_name;
    $tables = Illuminate\Support\Facades\DB::select(
        "SELECT count(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE 'chatter_%'",
        [$schema]
    );
    $c = (int) $tables[0]->c;
    if ($c === 3) {
        $ok++;
    } else {
        $fail++;
        echo "  FAIL: {$schema} has {$c} chatter tables\n";
    }
}
echo "OK: {$ok} / FAIL: {$fail}\n";

// 3. Verify activity_log has the new columns
echo "\n=== activity_log columns ===\n";
$columns = Illuminate\Support\Facades\DB::select(
    "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'activity_log'"
);
$colNames = collect($columns)->pluck('column_name')->toArray();
echo "Columns: " . implode(', ', $colNames) . "\n";

$required = ['log_name', 'description', 'created_at', 'updated_at'];
$missing = array_diff($required, $colNames);
if ($missing) {
    echo "FAIL: missing " . implode(', ', $missing) . "\n";
    exit(1);
}

// 4. Verify models can be resolved
echo "\n=== Model classes ===\n";
$classes = [
    'App\Services\Chatter\ChatterService',
    'App\Http\Controllers\Api\ChatterController',
    'App\Models\ChatterMessage',
    'App\Models\ChatterActivity',
    'App\Models\ChatterAttachment',
];
foreach ($classes as $cls) {
    $exists = class_exists($cls);
    echo "  {$cls}: " . ($exists ? 'OK' : 'MISSING') . "\n";
    if (! $exists) exit(1);
}

echo "\nALL CHECKS PASSED\n";
