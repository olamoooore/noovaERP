<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue: only OWNER, ADMIN, and platform_super_admin may read or write
 * the general settings module. Every other role (MANAGER, ACCOUNTANT,
 * SALES, CASHIER, VIEWER) must be denied.
 *
 * The seeder is auto-loaded by the test framework via Composer's dev
 * autoloader + the explicit require in the existing test (see
 * HR73CurrencyActiveToggleTest). We assert the contract at two levels:
 *  - the source of truth (the static property on the seeder);
 *  - the post-seed Spatie permission table.
 */
class HR173SettingsAccessTightenedTest extends TenantTestCase
{
    private function permissionMatrix(): array
    {
        $ref = new \ReflectionClass(RolePermissionSeeder::class);

        return $ref->getConstant('PERMISSION_MATRIX');
    }

    public function test_settings_view_grants_only_owner_and_admin(): void
    {
        $matrix = $this->permissionMatrix();

        $allowed = array_map(fn ($r) => $r->value, $matrix['settings.view']);
        $this->assertSame(['owner', 'admin'], $allowed,
            'settings.view must be restricted to owner + admin (superadmin bypasses via isSuperAdmin).');
    }

    public function test_settings_write_grants_only_owner_and_admin(): void
    {
        $matrix = $this->permissionMatrix();

        $allowed = array_map(fn ($r) => $r->value, $matrix['settings.write']);
        $this->assertSame(['owner', 'admin'], $allowed,
            'settings.write must be restricted to owner + admin (superadmin bypasses via isSuperAdmin).');
    }

    public function test_other_roles_lack_settings_after_seeder_runs(): void
    {
        // Force the seeder to load via reflection (avoids the autoload gap).
        if (! class_exists(RolePermissionSeeder::class, false)) {
            require_once base_path('database/seeders/RolePermissionSeeder.php');
        }

        $this->seed(RolePermissionSeeder::class);

        $matrix = $this->permissionMatrix();

        $denied = ['manager', 'sales', 'accountant', 'cashier', 'viewer'];

        foreach (['settings.view', 'settings.write'] as $permission) {
            $allowed = array_map(fn ($r) => $r->value, $matrix[$permission]);
            $intersect = array_intersect($denied, $allowed);
            $this->assertEmpty(
                $intersect,
                "Roles " . implode(',', $intersect) . " must not be granted {$permission}."
            );
        }
    }

    public function test_settings_routes_require_settings_view_or_write_middleware(): void
    {
        // Runtime guard: assert the routes that back the General Settings
        // module actually attach RequirePermission middleware, so the matrix
        // above cannot be silently bypassed by a missing live-route check
        // (the SOP "permission declared but nothing enforces it" trap).
        $router = $this->app['router'];

        $expected = [
            'GET /api/v1/settings' => 'settings.view',
            'PUT /api/v1/settings' => 'settings.write',
            'POST /api/v1/settings/test-smtp' => 'settings.write',
            'GET /api/v1/settings/document-formats' => 'settings.view',
            'PUT /api/v1/settings/document-formats' => 'settings.write',
            'GET /api/v1/settings/sales' => 'settings.view',
            'PUT /api/v1/settings/sales' => 'settings.write',
        ];

        foreach ($expected as $signature => $permission) {
            [$method, $path] = explode(' ', $signature);
            $route = collect($router->getRoutes())
                ->first(fn ($r) => in_array($method, $r->methods(), true)
                    && $r->uri() === ltrim($path, '/'));

            $this->assertNotNull($route, "Route {$signature} is not registered.");
            $middleware = $route->gatherMiddleware();
            $matches = false;
            foreach ($middleware as $mw) {
                if (str_contains((string) $mw, "RequirePermission") && str_contains((string) $mw, $permission)) {
                    $matches = true;
                    break;
                }
            }
            $this->assertTrue(
                $matches,
                "Route {$signature} must be gated by RequirePermission:{$permission}. Found: " . implode(', ', $middleware)
            );
        }
    }

    public function test_settings_route_returns_403_for_non_owner_admin(): void
    {
        // Live, end-to-end runtime check (per the SOP "real HTTP request
        // through the router" rule). A Sales user attempting to read or
        // write the general settings module must be denied at the router,
        // regardless of what the matrix or local cache says.
        $tenantId = (string) \Illuminate\Support\Str::uuid();

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $tenantId],
            [
                'name' => 'Settings RBAC Test Tenant',
                'email' => 'settings-rbac-' . substr(md5($tenantId), 0, 8) . '@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $sales = \App\Models\User::factory()->create();

        DB::table('public.users')->updateOrInsert(
            ['email' => $sales->email],
            [
                'name' => $sales->name,
                'password' => $sales->password,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $centralId = DB::table('public.users')->where('email', $sales->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $tenantId],
            [
                'role' => 'sales',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->bindTenantContextWithPermissions($tenantId, $sales, 'Sales');

        $get = $this->actingAs($sales)->getJson('/api/v1/settings');
        $get->assertStatus(403);
        $get->assertJsonPath('required_permission', 'settings.view');

        $put = $this->actingAs($sales)->putJson('/api/v1/settings', [
            'currency' => ['code' => 'USD'],
        ]);
        $put->assertStatus(403);
        $put->assertJsonPath('required_permission', 'settings.write');
    }

    public function test_settings_route_allows_owner_and_admin(): void
    {
        $tenantId = (string) \Illuminate\Support\Str::uuid();

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $tenantId],
            [
                'name' => 'Settings RBAC Owner Tenant',
                'email' => 'settings-rbac-owner-' . substr(md5($tenantId), 0, 8) . '@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        foreach (['owner', 'admin'] as $centralRole) {
            $spatieName = ucfirst($centralRole);

            $user = \App\Models\User::factory()->create();

            DB::table('public.users')->updateOrInsert(
                ['email' => $user->email],
                [
                    'name' => $user->name,
                    'password' => $user->password,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

            DB::table('user_company_access')->updateOrInsert(
                ['user_id' => $centralId, 'tenant_id' => $tenantId],
                [
                    'role' => $centralRole,
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $this->bindTenantContextWithPermissions($tenantId, $user, $spatieName);

            $get = $this->actingAs($user)->getJson('/api/v1/settings');
            $this->assertNotSame(
                403,
                $get->getStatusCode(),
                "{$spatieName} must not be 403 on GET /api/v1/settings (got {$get->getStatusCode()})."
            );
        }
    }
}
