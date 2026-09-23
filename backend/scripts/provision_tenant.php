<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\SuperAdmin\Application\EntitlementService;
use App\SuperAdmin\Domain\TenantSelectedItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Models\Domain;

// Usage: php scripts/provision_tenant.php "<Company>" "<admin email>" "<Admin Name>" "<domain>" "<password>" ["<role: owner|admin|manager|sales|accountant|cashier|viewer>"]
$company  = $argv[1] ?? 'Acme Production';
$email    = $argv[2] ?? 'admin@acme-production';
$name     = $argv[3] ?? $company.' Admin';
$domain   = $argv[4] ?? 'acme-production.localhost';
$password = $argv[5] ?? 'password';
$roleArg  = $argv[6] ?? 'owner';
$userRole = UserRole::tryFrom(strtolower($roleArg));
if (! $userRole) {
    fwrite(STDERR, "Invalid role '{$roleArg}'. Valid: ".implode(', ', UserRole::values()).PHP_EOL);
    exit(2);
}

function out(string $msg): void
{
    echo $msg.PHP_EOL;
}

try {
    if (DB::table('users')->where('email', $email)->exists()) {
        out(json_encode(['status' => 'exists', 'message' => "public user $email already exists"]));
        exit(0);
    }
    if (DB::table('tenants')->where('name', $company)->exists()) {
        out(json_encode(['status' => 'exists', 'message' => "tenant $company already exists"]));
        exit(0);
    }

    // 1. Tenant (CreateDatabase -> MigrateDatabase -> SeedDatabase run synchronously via TenantCreated pipeline)
    $tenantId = (string) Str::uuid();
    $tenant = Tenant::create([
        'id' => $tenantId,
        'name' => $company,
        'email' => $email,
        'plan' => 'trial',
        'trial_ends_at' => now()->addDays(30),
        'data' => ['company_name' => $company],
    ]);
    out("tenant created: {$tenant->id}");

    // The TenantCreated pipeline (CreateDatabase/MigrateDatabase/SeedDatabase) can leave
    // the tenant context active, which silently redirects central writes into the tenant
    // schema. Force the central schema and assert it before every central write below.
    if (tenancy()->initialized) {
        tenancy()->end();
    }
    DB::purge('pgsql');
    DB::statement('SET search_path TO public');
    if (DB::selectOne('SELECT current_schema() AS s')->s !== 'public') {
        throw new RuntimeException('failed to restore public search_path after tenant creation');
    }

    Domain::create(['domain' => $domain, 'tenant_id' => $tenant->id]);
    out("domain created: $domain");

    // 2. Central user + company access (users.id and user_company_access.id are bigint: let DB assign)
    $userId = DB::table('users')->insertGetId([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    if (! DB::table('users')->where('id', $userId)->where('email', $email)->exists()) {
        throw new RuntimeException("user insert did not land in public.users (got id={$userId})");
    }
    out("public user created: id=$userId");

    DB::table('user_company_access')->insert([
        'user_id' => $userId,
        'tenant_id' => $tenant->id,
        'role' => $userRole->value,
        'is_default' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    out("user_company_access linked (role={$userRole->value}, is_default=true)");

    // 3. Tenant-schema user + Owner role
    tenancy()->initialize($tenant);

    $schema = DB::selectOne('select current_schema() as s')->s;
    if ($schema !== $tenant->id) {
        throw new RuntimeException("expected tenant schema {$tenant->id}, got {$schema}");
    }
    out("tenant schema active: $schema");

    $tenantUser = User::firstOrCreate(
        ['email' => $email],
        ['name' => $name, 'password' => Hash::make($password)]
    );
    $ownerRole = \Spatie\Permission\Models\Role::where('name', $userRole->spatieName())->first();
    if (! $ownerRole) {
        // Roles/permissions are not part of DatabaseSeeder; seed them explicitly.
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'Database\Seeders\RolePermissionSeeder', '--force' => true]);
        $ownerRole = \Spatie\Permission\Models\Role::where('name', $userRole->spatieName())->first();
    }
    if ($ownerRole) {
        $tenantUser->syncRoles([$ownerRole->name]);
        out("tenant user id={$tenantUser->id} created, {$ownerRole->name} role assigned");
    } else {
        out("WARNING: {$userRole->spatieName()} role not found in tenant schema");
    }

    tenancy()->end();

    // 4. All-module access: override_include selection for every catalog item (incl. inactive 'accounting', matching acme-production)
    DB::purge('pgsql');
    DB::statement('SET search_path TO public');
    if (DB::selectOne('SELECT current_schema() AS s')->s !== 'public') {
        throw new RuntimeException('failed to restore public search_path before entitlement writes');
    }
    $catalogIds = DB::table('catalog_items')->orderBy('id')->pluck('id');
    $now = now();
    foreach ($catalogIds as $catalogItemId) {
        DB::table('tenant_selected_items')->insert([
            'tenant_id' => $tenant->id,
            'catalog_item_id' => $catalogItemId,
            'selection_type' => TenantSelectedItem::TYPE_OVERRIDE_INCLUDE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    out('tenant_selected_items: '.count($catalogIds).' override_include rows');

    // 5. Rebuild entitlements
    $job = app(EntitlementService::class)->rebuild($tenant->id, null, 'provision_'.Str::slug($company, '_').'_all_modules');
    out("entitlement rebuild job: status={$job->status}, items={$job->items_processed}");

    // 6. Verification
    $entitled = DB::table('tenant_entitlements')
        ->join('catalog_items', 'catalog_items.id', '=', 'tenant_entitlements.catalog_item_id')
        ->where('tenant_entitlements.tenant_id', $tenant->id)
        ->where('tenant_entitlements.is_entitled', true)
        ->orderBy('catalog_items.key')
        ->pluck('catalog_items.key')
        ->all();
    $notEntitled = DB::table('tenant_entitlements')
        ->join('catalog_items', 'catalog_items.id', '=', 'tenant_entitlements.catalog_item_id')
        ->where('tenant_entitlements.tenant_id', $tenant->id)
        ->where('tenant_entitlements.is_entitled', false)
        ->pluck('catalog_items.key')
        ->all();
    $schemaExists = (bool) DB::selectOne('select 1 from information_schema.schemata where schema_name = ?', [$tenant->id]);
    $migrationCount = $schemaExists ? DB::table($tenant->id.'.migrations')->count() : 0;

    out(json_encode([
        'status' => 'success',
        'tenant_id' => $tenant->id,
        'domain' => $domain,
        'login_email' => $email,
        'password' => $password,
        'role' => $userRole->value,
        'schema_exists' => $schemaExists,
        'tenant_migrations' => $migrationCount,
        'entitled_modules' => $entitled,
        'entitled_count' => count($entitled),
        'not_entitled' => $notEntitled,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} catch (\Throwable $e) {
    out(json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'at' => $e->getFile().':'.$e->getLine(),
        'tenant_id' => $tenantId ?? null,
    ]));
    exit(1);
}
