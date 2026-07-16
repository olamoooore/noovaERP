<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Models\Domain;

// Configuration
$targetEmail = 'admin@demo5.com';
$targetDomain = 'demo5.localhost';
$targetCompany = 'Demo 5 Corp';
$targetName = 'Demo 5 Admin';
$password = 'password';
$sourceEmail = 'admin@demo.com';

try {
    echo "Starting tenant creation for {$targetEmail}...\n";

    // Check if already exists
    $existingUser = DB::table('users')->where('email', $targetEmail)->first();
    if ($existingUser) {
        echo "ERROR: User {$targetEmail} already exists\n";
        exit(1);
    }

    // Get source tenant
    $sourceUser = DB::table('users')->where('email', $sourceEmail)->first();
    if (! $sourceUser) {
        throw new Exception("Source user {$sourceEmail} not found");
    }

    $sourceTenantId = DB::table('user_company_access')
        ->where('user_id', $sourceUser->id)
        ->where('is_default', true)
        ->value('tenant_id');

    if (! $sourceTenantId) {
        throw new Exception('Source tenant not found');
    }

    echo "Source tenant ID: {$sourceTenantId}\n";

    // Create tenant
    $tenantId = (string) Str::uuid();
    echo "Creating tenant with ID: {$tenantId}\n";

    $tenant = Tenant::create([
        'id' => $tenantId,
        'name' => $targetCompany,
        'email' => $targetEmail,
        'plan' => 'trial',
        'trial_ends_at' => now()->addDays(14),
    ]);

    echo "Tenant created\n";

    // Create domain
    Domain::create([
        'domain' => $targetDomain,
        'tenant_id' => $tenantId,
    ]);

    echo "Domain created: {$targetDomain}\n";

    // Create public user
    DB::statement('SET search_path TO public');
    $publicUser = User::create([
        'name' => $targetName,
        'email' => $targetEmail,
        'password' => Hash::make($password),
    ]);

    echo "Public user created: {$publicUser->email}\n";

    // Link user to tenant
    DB::table('user_company_access')->insert([
        'user_id' => $publicUser->id,
        'tenant_id' => $tenantId,
        'role' => 'owner',
        'is_default' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    echo "user_company_access created\n";

    // Initialize tenancy
    echo "Initializing tenancy...\n";
    tenancy()->initialize($tenant);

    // Run tenant migrations only (skip automatic seeding which has issues)
    echo "Running tenant migrations...\n";
    \Illuminate\Support\Facades\Artisan::call('tenants:migrate', [
        '--tenants' => [$tenantId],
    ]);
    echo \Illuminate\Support\Facades\Artisan::output();

    // Create tenant user FIRST (needed for fiscal period seeder)
    $tenantUser = User::create([
        'name' => $targetName,
        'email' => $targetEmail,
        'password' => Hash::make($password),
    ]);

    echo "Tenant user created: {$tenantUser->id}\n";

    // Assign owner role
    $ownerRole = \Spatie\Permission\Models\Role::where('name', 'Owner')->first();
    if ($ownerRole) {
        $tenantUser->roles()->sync([$ownerRole->id]);
        echo "Owner role assigned\n";
    }

    // Now run seeders
    echo "Running database seeder...\n";
    \Illuminate\Support\Facades\Artisan::call('db:seed', [
        '--class' => \Database\Seeders\DatabaseSeeder::class,
        '--force' => true,
    ]);
    echo \Illuminate\Support\Facades\Artisan::output();

    // Enable all features (while tenancy is still initialized)
    DB::table('tenant_features')->update(['is_enabled' => true]);
    echo "All features enabled\n";

    tenancy()->end();

    // Copy tenant_selected_items from source
    echo "Copying module selections from source tenant...\n";
    $sourceItems = DB::table('tenant_selected_items')
        ->where('tenant_id', $sourceTenantId)
        ->get();

    foreach ($sourceItems as $item) {
        DB::table('tenant_selected_items')->insert([
            'tenant_id' => $tenantId,
            'catalog_item_id' => $item->catalog_item_id,
            'selection_type' => $item->selection_type,
            'notes' => $item->notes,
            'selected_by_admin_id' => $publicUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    echo 'Copied '.count($sourceItems)." selections\n";

    // Rebuild entitlements
    echo "Rebuilding entitlements...\n";
    app(\App\SuperAdmin\Application\EntitlementService::class)->rebuild($tenantId);
    echo "Entitlements rebuilt\n";

    // Verify
    $sourceModules = DB::table('tenant_entitlements')
        ->join('catalog_items', 'tenant_entitlements.catalog_item_id', '=', 'catalog_items.id')
        ->where('tenant_entitlements.tenant_id', $sourceTenantId)
        ->pluck('catalog_items.key')
        ->toArray();

    $targetModules = DB::table('tenant_entitlements')
        ->join('catalog_items', 'tenant_entitlements.catalog_item_id', '=', 'catalog_items.id')
        ->where('tenant_entitlements.tenant_id', $tenantId)
        ->pluck('catalog_items.key')
        ->toArray();

    echo "\n=== TENANT CREATED SUCCESSFULLY ===\n";
    echo "Tenant ID: {$tenantId}\n";
    echo "Domain: {$targetDomain}\n";
    echo "Email: {$targetEmail}\n";
    echo "Password: {$password}\n";
    echo 'Source modules: '.count($sourceModules)."\n";
    echo 'Target modules: '.count($targetModules)."\n";

    $missing = array_diff($sourceModules, $targetModules);
    if (! empty($missing)) {
        echo 'WARNING: Missing modules: '.implode(', ', $missing)."\n";
    } else {
        echo "All modules matched!\n";
    }

} catch (\Exception $e) {
    echo 'ERROR: '.$e->getMessage()."\n";
    echo $e->getTraceAsString()."\n";
    exit(1);
}
