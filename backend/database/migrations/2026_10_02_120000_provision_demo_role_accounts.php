<?php

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Provision the demo role accounts for the Demo Corp tenant
 * (e255e52c-a4a6-4158-954a-af04408e9386), requested for role-gating
 * walkthroughs:
 *
 *   superadmin@demo.com  platform_super_admin (+ tenant admin access)
 *   owner@demo.com       Owner
 *   sales@demo.com       Sales
 *   account@demo.com     Accountant
 *   admin@demo.com       Admin (already existed; access/password ensured)
 *
 * Password for every account: "password" (demo-only credentials).
 *
 * Production-only by explicit request — local/dev databases are untouched.
 * Idempotent: safe to re-run; it only creates/updates these five accounts.
 *
 * It also re-runs RolePermissionSeeder inside the demo tenant so its seeded
 * roles converge to the current permission matrix (the tenant predates some
 * matrix changes, e.g. Sales previously held reports.view/accounting.view).
 * Roles the tenant has explicitly customized are skipped by the seeder.
 */
return new class extends Migration
{
    /**
     * The tenant->run() calls execute on a separate connection, so the
     * migration must not be wrapped in the landlord transaction.
     */
    public $withinTransaction = false;

    private const TENANT_ID = 'e255e52c-a4a6-4158-954a-af04408e9386';

    private const PASSWORD = 'password';

    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $tenant = Tenant::find(self::TENANT_ID);
        if (! $tenant) {
            return;
        }

        // Central accounts live in the public schema.
        DB::statement('SET search_path TO public');

        foreach ($this->accounts() as $account) {
            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make(self::PASSWORD),
                ]
            );

            // The privileged platform role may only be set through the
            // audited assignAdminRole() path (User::$role is guarded).
            if (($account['platform_super_admin'] ?? false) && $user->role !== 'platform_super_admin') {
                $user->assignAdminRole(
                    'platform_super_admin',
                    actorId: null,
                    reason: 'demo role account provisioning (migration)'
                );
            }

            DB::table('user_company_access')->updateOrInsert(
                ['user_id' => $user->id, 'tenant_id' => self::TENANT_ID],
                [
                    'role' => $account['role'],
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->runInTenant($tenant);
        $this->restoreLandlordSession();

        // Clean out the record an earlier version of this migration leaked
        // into the tenant schema (it was written while the tenant connection
        // was active). Central migrations must only live in public.migrations.
        DB::statement(
            'DELETE FROM "'.self::TENANT_ID.'".migrations WHERE migration = ?',
            ['2026_10_02_120000_provision_demo_role_accounts']
        );
    }

    public function down(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $tenant = Tenant::find(self::TENANT_ID);
        if (! $tenant) {
            return;
        }

        // Only the accounts this migration introduced are removed; the
        // pre-existing admin@demo.com is left untouched.
        $emails = ['superadmin@demo.com', 'owner@demo.com', 'sales@demo.com', 'account@demo.com'];

        DB::statement('SET search_path TO public');

        $ids = DB::table('public.users')->whereIn('email', $emails)->pluck('id');
        DB::table('public.user_company_access')
            ->where('tenant_id', self::TENANT_ID)
            ->whereIn('user_id', $ids)
            ->delete();

        DB::table('public.users')->whereIn('email', $emails)->delete();

        $tenant->run(function () use ($emails) {
            User::query()->whereIn('email', $emails)->delete();
        });

        $this->restoreLandlordSession();
    }

    /**
     * Mirror the accounts into the tenant schema, assign the matching Spatie
     * role, and converge the tenant's role permissions to the current matrix.
     */
    private function runInTenant(Tenant $tenant): void
    {
        $tenant->run(function () {
            foreach ($this->accounts() as $account) {
                $tenantUser = User::query()->updateOrCreate(
                    ['email' => $account['email']],
                    [
                        'name' => $account['name'],
                        'password' => Hash::make(self::PASSWORD),
                    ]
                );

                $roleName = Str::title(str_replace(['-', '_'], ' ', $account['role']));
                $role = Role::firstOrCreate([
                    'name' => $roleName,
                    'guard_name' => 'web',
                ]);

                if (! $tenantUser->hasRole($role)) {
                    $tenantUser->assignRole($role);
                }
            }

            // Converge roles to the current PERMISSION_MATRIX (grants AND
            // revokes) so stale grants like Sales holding reports.view are
            // removed. Tenant-customized roles are skipped by the seeder.
            (new RolePermissionSeeder)->run();
        });
    }

    /**
     * tenancy()->end() leaves the landlord connection's session pointing at
     * the tenant schema, which made the migration repository WRITE the
     * migration record into the tenant schema. Purge + reset so the record
     * lands in public.migrations.
     */
    private function restoreLandlordSession(): void
    {
        DB::purge(config('database.default'));
        DB::reconnect(config('database.default'));
        DB::statement('SET search_path TO public');
    }

    /**
     * @return array<int, array{email: string, name: string, role: string, platform_super_admin?: bool}>
     */
    private function accounts(): array
    {
        return [
            [
                'email' => 'superadmin@demo.com',
                'name' => 'Demo Super Admin',
                'role' => 'admin',
                'platform_super_admin' => true,
            ],
            [
                'email' => 'owner@demo.com',
                'name' => 'Demo Owner',
                'role' => 'owner',
            ],
            [
                'email' => 'sales@demo.com',
                'name' => 'Demo Sales',
                'role' => 'sales',
            ],
            [
                'email' => 'account@demo.com',
                'name' => 'Demo Accountant',
                'role' => 'accountant',
            ],
            [
                'email' => 'admin@demo.com',
                'name' => 'Admin User',
                'role' => 'admin',
            ],
        ];
    }
};
