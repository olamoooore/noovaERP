<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== ALL SCHEMAS ===" . PHP_EOL;
$schemas = DB::select("SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('public','information_schema','pg_catalog','pg_toast') ORDER BY schema_name");
foreach($schemas as $s) echo "  " . $s->schema_name . PHP_EOL;

echo PHP_EOL . "=== ALL TENANTS ===" . PHP_EOL;
$tenants = App\Models\Tenant::with('domains')->get();
foreach($tenants as $t) {
    echo $t->id . " | " . $t->name . " | " . $t->email . " | domains: " . $t->domains->pluck('domain')->join(', ') . PHP_EOL;
}

echo PHP_EOL . "=== SCHEMA vs TENANT MAPPING ===" . PHP_EOL;
$schemaNames = array_map(fn($s) => $s->schema_name, $schemas);
foreach($tenants as $t) {
    // Check all naming patterns
    $hasUuid = in_array($t->id, $schemaNames);
    $hasTenantPrefix = in_array("tenant" . $t->id, $schemaNames);
    $hasTenantUnderscore = in_array("tenant_" . $t->id, $schemaNames);
    $status = $hasUuid ? "HAS SCHEMA (uuid)" : ($hasTenantPrefix ? "HAS SCHEMA (tenant prefix)" : ($hasTenantUnderscore ? "HAS SCHEMA (tenant_ prefix)" : "NO SCHEMA"));
    echo "  " . $t->name . " (" . $t->id . "): " . $status . PHP_EOL;
}

echo PHP_EOL . "=== TABLE COUNTS PER SCHEMA ===" . PHP_EOL;
foreach($schemas as $s) {
    $cnt = DB::select("SELECT count(*) as cnt FROM pg_tables WHERE schemaname = ?", [$s->schema_name]);
    echo "  " . $s->schema_name . ": " . $cnt[0]->cnt . " tables" . PHP_EOL;
}

echo PHP_EOL . "=== user_company_access ===" . PHP_EOL;
DB::statement('SET search_path TO public');
$uca = DB::table('user_company_access')->get();
foreach($uca as $r) {
    $user = DB::table('users')->where('id', $r->user_id)->first();
    $tenant = App\Models\Tenant::find($r->tenant_id);
    echo "  user: " . ($user ? $user->email : 'MISSING') . " -> tenant: " . ($tenant ? $tenant->name : 'MISSING') . " | role: " . $r->role . " | default: " . $r->is_default . PHP_EOL;
}
