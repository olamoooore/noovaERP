<?php

/**
 * Fix ASCT tenant: create its PostgreSQL schema and run all tenant migrations.
 * This is needed because the TenancyServiceProvider doesn't have a JobPipeline
 * to auto-create schemas on Tenant::create(). The schema must be created manually.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

$tenant = Tenant::where('email', 'admin@asct.com')->first();
if (!$tenant) {
    echo "ERROR: ASCT tenant not found\n";
    exit(1);
}

echo "Tenant: {$tenant->id} | {$tenant->name}\n";

// Check if schema already exists
$schemaName = $tenant->id;
$exists = DB::select("SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?", [$schemaName]);

if (empty($exists)) {
    echo "Creating schema: {$schemaName}\n";
    DB::statement("CREATE SCHEMA \"{$schemaName}\"");
    echo "Schema created.\n";
} else {
    echo "Schema already exists.\n";
}

// Run tenant migrations for this specific tenant
echo "Running tenant migrations...\n";
Artisan::call('tenants:migrate', [
    '--tenants' => [$tenant->id],
]);
echo Artisan::output();

// Now initialize tenant context and seed admin user + features
echo "Initializing tenant context...\n";
tenancy()->initialize($tenant);

// Check if admin user already exists in tenant schema
$existingUser = \App\Models\User::where('email', 'admin@asct.com')->first();
if (!$existingUser) {
    $user = \App\Models\User::create([
        'name' => 'ASCT Admin',
        'email' => 'admin@asct.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
    ]);
    echo "Tenant admin user created: {$user->email}\n";

    $ownerRole = \Spatie\Permission\Models\Role::firstOrCreate(
        ['name' => 'Owner', 'guard_name' => 'web']
    );
    $user->assignRole($ownerRole);
    echo "Owner role assigned.\n";
} else {
    echo "Tenant admin user already exists: {$existingUser->email}\n";
}

// Seed features
Artisan::call('db:seed', [
    '--class' => \Database\Seeders\DatabaseSeeder::class,
    '--force' => true,
]);
echo "Database seeder run.\n";

// Enable all features for this tenant
$now = now();
$count = 0;
foreach (\App\Models\Feature::query()->get() as $feature) {
    \App\Models\TenantFeature::query()->updateOrCreate(
        ['feature_id' => $feature->id],
        ['is_enabled' => true, 'enabled_at' => $now, 'expires_at' => null]
    );
    $count++;
}
echo "Enabled {$count} features.\n";

tenancy()->end();

// Verify public schema user + user_company_access
DB::statement('SET search_path TO public');
$publicUser = \App\Models\User::where('email', 'admin@asct.com')->first();
if (!$publicUser) {
    $publicUser = \App\Models\User::create([
        'name' => 'ASCT Admin',
        'email' => 'admin@asct.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
    ]);
    echo "Public user created.\n";
}

$hasAccess = DB::table('user_company_access')
    ->where('user_id', $publicUser->id)
    ->where('tenant_id', $tenant->id)
    ->exists();

if (!$hasAccess) {
    DB::table('user_company_access')->insert([
        'user_id' => $publicUser->id,
        'tenant_id' => $tenant->id,
        'role' => 'owner',
        'is_default' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    echo "user_company_access record created.\n";
} else {
    echo "user_company_access already exists.\n";
}

echo "\nDone! ASCT tenant is fully set up.\n";
echo "Login: admin@asct.com / password\n";
