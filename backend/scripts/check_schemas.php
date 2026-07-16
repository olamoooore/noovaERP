<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

// Check what tables exist in each schema
echo "=== ACEDEMO schema tables ===\n";
$t1 = Tenant::where('email', 'admin@acedemo.com')->first();
$schema1 = 'tenant'.$t1->id;
$tables1 = DB::select('SELECT tablename FROM pg_tables WHERE schemaname = ? ORDER BY tablename', [$schema1]);
echo "Schema: {$schema1}\n";
echo 'Table count: '.count($tables1)."\n";
foreach ($tables1 as $t) {
    echo "  - {$t->tablename}\n";
}

echo "\n=== ASCT schema tables ===\n";
$t2 = Tenant::where('email', 'admin@asct.com')->first();
$schema2 = 'tenant'.$t2->id;
$tables2 = DB::select('SELECT tablename FROM pg_tables WHERE schemaname = ? ORDER BY tablename', [$schema2]);
echo "Schema: {$schema2}\n";
echo 'Table count: '.count($tables2)."\n";
foreach ($tables2 as $t) {
    echo "  - {$t->tablename}\n";
}

// Also check if there's a schema without the 'tenant' prefix
$schema2b = $t2->id;
$tables2b = DB::select('SELECT tablename FROM pg_tables WHERE schemaname = ? ORDER BY tablename', [$schema2b]);
echo "\nSchema (no prefix): {$schema2b}\n";
echo 'Table count: '.count($tables2b)."\n";
foreach ($tables2b as $t) {
    echo "  - {$t->tablename}\n";
}

echo "\nDone.\n";
