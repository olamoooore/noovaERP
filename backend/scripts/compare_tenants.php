<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "=== ACEDEMO schema ===\n";
$t1 = Tenant::where('email', 'admin@acedemo.com')->first();
tenancy()->initialize($t1);
$sp1 = DB::select('SHOW search_path');
echo "search_path: " . json_encode($sp1) . "\n";
$users1 = User::all();
foreach ($users1 as $u) {
    echo "User: {$u->id}|{$u->name}|{$u->email}\n";
}
echo "Products: " . DB::table('products')->count() . "\n";
echo "Invoices: " . DB::table('invoices')->count() . "\n";
echo "Customers: " . DB::table('customers')->count() . "\n";
tenancy()->end();

echo "\n=== ASCT schema ===\n";
$t2 = Tenant::where('email', 'admin@asct.com')->first();
tenancy()->initialize($t2);
$sp2 = DB::select('SHOW search_path');
echo "search_path: " . json_encode($sp2) . "\n";
$users2 = User::all();
foreach ($users2 as $u) {
    echo "User: {$u->id}|{$u->name}|{$u->email}\n";
}
echo "Products: " . DB::table('products')->count() . "\n";
echo "Invoices: " . DB::table('invoices')->count() . "\n";
echo "Customers: " . DB::table('customers')->count() . "\n";
tenancy()->end();

echo "\nDone.\n";
