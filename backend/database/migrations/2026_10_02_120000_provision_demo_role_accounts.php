<?php

use App\Models\Tenant;
use App\Models\User;
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
 *   superadmin@demo.com  platform_super_admin + tenant admin access
 *   owner@demo.com       tenant Owner
 *   sales@demo.com       tenant Sales
 *   account@demo.com     tenant Accountant
 *   admin@demo.com       tenant Admin (already existed; access/password ensured)
 *
 * Password for every account: "password" (demo-only credentials).
 *
 * Production-only by explicit request — local/dev databases are untouched.
 * Idempotent: safe to re-run; it only creates/updates these five accounts.
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

        // Mirror the accounts into the tenant schema and assign the matching
        // Spatie role, so the first login already reports the correct role and
        // permissions (rather than relying on the first-request sync).
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
        });
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
