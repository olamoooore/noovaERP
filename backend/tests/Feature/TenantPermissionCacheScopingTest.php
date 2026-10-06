<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Tenancy\TenantPermissionCacheScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Tenancy;
use Tests\TenantTestCase;

/**
 * Spatie caches the whole permission catalogue under one key, but each tenant
 * schema has its own permission rows/ids. A shared cache makes
 * Role::hasPermissionTo() resolve a permission name to another tenant's id and
 * deny valid permissions (403 on customers.view for the Sales role after a
 * re-seed). The TenancyInitialized listener must scope the key per tenant.
 */
class TenantPermissionCacheScopingTest extends TenantTestCase
{
    public function test_permission_cache_is_scoped_per_tenant_and_survives_permission_id_drift(): void
    {
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        // Warm the cache with the current permission ids.
        $registrar->getPermissions();

        $oldId = (int) Permission::where('name', 'customers.view')->value('id');
        $roleIds = DB::table('role_has_permissions')->where('permission_id', $oldId)->pluck('role_id')->all();

        // Simulate a re-seed: the permission row is replaced with a new id
        // without Spatie's cache-invalidation events firing.
        DB::table('permissions')->where('id', $oldId)->delete();
        DB::table('permissions')->insert([
            'name' => 'customers.view',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $newId = (int) DB::table('permissions')->where('name', 'customers.view')->value('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $newId]);
        }

        $this->assertNotSame($oldId, $newId);

        $this->assertFalse(
            Role::findByName('Sales', 'web')->hasPermissionTo('customers.view'),
            'Precondition: the stale cache resolves the permission name to the old id.'
        );

        // Every real request initializes tenancy, which must scope the key.
        app(TenantPermissionCacheScope::class)->handle(new TenancyInitialized(app(Tenancy::class)));

        $this->assertSame(
            config('permission.cache.key').'.'.$tenant->id,
            app(PermissionRegistrar::class)->cacheKey,
        );
        $this->assertTrue(
            Role::findByName('Sales', 'web')->hasPermissionTo('customers.view'),
            'After tenancy init the permission must resolve against this tenant schema.'
        );
    }
}
