<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Database\Models\Domain;
use App\Models\TenantSelectedItem;
use App\SuperAdmin\Application\EntitlementService;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$sourceEmail = 'admin@demo.com';
$targetEmail = 'admin@demo4.com';
$targetDomain = 'demo4.localhost';
$targetCompany = 'Demo 4 Corp';
$targetName = 'Demo 4 Admin';
$password = 'password';

try {
    // 1. Check existing
    $existingUser = DB::table('users')->where('email', $targetEmail)->first();
    if ($existingUser) {
        echo json_encode(['status' => 'exists', 'login_email' => $targetEmail]);
        exit;
    }

    $sourceUser = DB::table('users')->where('email', $sourceEmail)->first();
    if (!$sourceUser) {
        throw new Exception("Source user $sourceEmail not found");
    }
    
    $columns = Schema::getColumnListing('user_company_access');
    $tenantIdColumn = in_array('tenant_id', $columns) ? 'tenant_id' : (in_array('company_id', $columns) ? 'company_id' : null);
    
    if (!$tenantIdColumn) {
         throw new Exception("Could not find tenant identifier column in user_company_access.");
    }

    $sourceTenantId = DB::table('user_company_access')
        ->where('user_id', $sourceUser->id)
        ->where('is_default', true)
        ->value($tenantIdColumn);
        
    if (!$sourceTenantId) {
        throw new Exception("Source tenant not found for $sourceEmail");
    }

    // 2. Create Tenant
    $tenantId = (string) \Illuminate\Support\Str::uuid();
    $tenantData = [
        'id' => $tenantId,
        'name' => $targetCompany,
        'email' => $targetEmail,
        'plan_id' => 'trial',
        'trial_ends_at' => now()->addDays(14),
        'data' => ['company_name' => $targetCompany]
    ];
    
    $tenant = Tenant::create($tenantData);
    
    Domain::create([
        'domain' => $targetDomain,
        'tenant_id' => $tenantId,
    ]);

    // 3. Create Public User - Let DB assign ID if it is bigint
    $publicUserData = [
        'name' => $targetName,
        'email' => $targetEmail,
        'password' => Hash::make($password),
        'created_at' => now(),
        'updated_at' => now(),
    ];
    
    // Check if ID is string/uuid or bigint
    $userIdCol = Schema::getColumnType('users', 'id');
    if ($userIdCol === 'string' || $userIdCol === 'guid' || $userIdCol === 'uuid') {
        $publicUserData['id'] = (string) \Illuminate\Support\Str::uuid();
    }
    
    $userId = DB::table('users')->insertGetId($publicUserData);

    DB::table('user_company_access')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $userId,
        $tenantIdColumn => $tenantId,
        'role' => 'owner',
        'is_default' => true,
    ]);

    // 4. Initialize Tenancy
    tenancy()->initialize($tenant);

    // 5. Build Tenant DB (Seed)
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);

    $tenantUser = \App\Models\User::updateOrCreate(
        ['email' => $targetEmail],
        ['name' => $targetName, 'password' => Hash::make($password)]
    );
    // Sync the ID if it's bigint and was auto-generated above
    if (isset($userId)) {
        $tenantUser->id = $userId;
        $tenantUser->save();
    }
    
    $ownerRole = \App\Models\Role::where('name', 'Owner')->first();
    if ($ownerRole) {
        $tenantUser->roles()->sync([$ownerRole->id]);
    }
    
    DB::connection('tenant')->table('features')->update(['is_active' => true]);
    DB::connection('tenant')->table('tenant_features')->update(['is_active' => true]);

    tenancy()->end();

    // 6. Parity: Selected Items
    $sourceItems = DB::table('tenant_selected_items')->where('tenant_id', $sourceTenantId)->get();
    if ($sourceItems->isEmpty()) {
        $activeCatalogItems = DB::table('catalog_items')->where('is_active', true)->get();
        foreach ($activeCatalogItems as $item) {
            DB::table('tenant_selected_items')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $tenantId,
                'catalog_item_id' => $item->id,
                'override_include' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    } else {
        foreach ($sourceItems as $item) {
            $newItem = (array)$item;
            $newItem['id'] = (string) \Illuminate\Support\Str::uuid();
            $newItem['tenant_id'] = $tenantId;
            DB::table('tenant_selected_items')->insert($newItem);
        }
    }

    // 7. Rebuild Entitlements
    app(EntitlementService::class)->rebuild($tenantId);

    // 8. Verification
    $getSourceModules = function($tid) {
        return DB::table('tenant_entitlements')
            ->join('catalog_items', 'tenant_entitlements.catalog_item_id', '=', 'catalog_items.id')
            ->where('tenant_entitlements.tenant_id', $tid)
            ->pluck('catalog_items.slug')
            ->toArray();
    };

    $sourceModules = $getSourceModules($sourceTenantId);
    $targetModules = $getSourceModules($tenantId);

    echo json_encode([
        'status' => 'success',
        'tenant_id' => $tenantId,
        'domain' => $targetDomain,
        'login_email' => $targetEmail,
        'password' => $password,
        'source_module_count' => count($sourceModules),
        'target_module_count' => count($targetModules),
        'missing_modules' => array_values(array_diff($sourceModules, $targetModules)),
        'extra_modules' => array_values(array_diff($targetModules, $sourceModules)),
    ]);

} catch (\Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'tenant_id' => $tenantId ?? null
    ]);
}
