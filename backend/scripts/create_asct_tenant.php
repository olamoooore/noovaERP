<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

// Check if tenant already exists
$existing = Tenant::where('email', 'admin@asct.com')->first();
if ($existing) {
    echo "ASCT tenant already exists: {$existing->id}\n";
    
    // Ensure user_company_access exists
    DB::statement('SET search_path TO public');
    $publicUser = User::where('email', 'admin@asct.com')->first();
    if ($publicUser) {
        $hasAccess = DB::table('user_company_access')
            ->where('user_id', $publicUser->id)
            ->where('tenant_id', $existing->id)
            ->exists();
        if (!$hasAccess) {
            DB::table('user_company_access')->insert([
                'user_id' => $publicUser->id,
                'tenant_id' => $existing->id,
                'role' => 'owner',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            echo "Fixed: Added missing user_company_access record\n";
        } else {
            echo "user_company_access already exists\n";
        }
    }
    exit(0);
}

// Create tenant
$tenant = Tenant::create([
    'id' => Str::uuid(),
    'name' => 'ASCT',
    'email' => 'admin@asct.com',
    'plan' => 'trial',
    'trial_ends_at' => now()->addDays(14),
]);

// Create domain
$tenant->domains()->create([
    'domain' => 'asct.localhost',
]);

echo "Tenant created: {$tenant->id}\n";
echo "Domain: asct.localhost\n";

// Create user in public schema (for authentication/login)
DB::statement('SET search_path TO public');
$publicUser = User::where('email', 'admin@asct.com')->first();
if (!$publicUser) {
    $publicUser = User::create([
        'name' => 'ASCT Admin',
        'email' => 'admin@asct.com',
        'password' => Hash::make('password'),
    ]);
    echo "Public user created: {$publicUser->email}\n";
} else {
    echo "Public user already exists: {$publicUser->email}\n";
}

// Link user to tenant via user_company_access
DB::table('user_company_access')->updateOrInsert(
    ['user_id' => $publicUser->id, 'tenant_id' => $tenant->id],
    ['role' => 'owner', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
);
echo "user_company_access linked\n";

// Initialize tenant context. Schema creation and tenant migrations now happen
// automatically during Tenant::create().
tenancy()->initialize($tenant);

// Create admin user in tenant schema
$user = User::create([
    'name' => 'ASCT Admin',
    'email' => 'admin@asct.com',
    'password' => Hash::make('password'),
]);

echo "Tenant admin user created: {$user->email}\n";

// Assign owner role
$ownerRole = \Spatie\Permission\Models\Role::firstOrCreate(
    ['name' => 'Owner', 'guard_name' => 'web']
);
$user->assignRole($ownerRole);
echo "Owner role assigned\n";

// Enable all features
$now = now();
foreach (\App\Models\Feature::query()->get() as $feature) {
    \App\Models\TenantFeature::query()->updateOrCreate(
        ['feature_id' => $feature->id],
        ['is_enabled' => true, 'enabled_at' => $now, 'expires_at' => null]
    );
}
echo "All features enabled\n";

tenancy()->end();
echo "Done!\n";
