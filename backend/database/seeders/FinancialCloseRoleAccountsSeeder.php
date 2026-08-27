<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds capability-role users (Accountant + Manager) into the Demo Corp tenant
 * so that Financial Close escalation routing (FC-011) has real targets.
 *
 * Creates each user in BOTH:
 *   - public schema (central login + user_company_access.role), and
 *   - tenant schema (Spatie role assignment via model_has_roles)
 * so the accounts can log in and be resolved by escalation routing.
 *
 * Run: FINANCIAL_CLOSE_ACCOUNTANT_PASSWORD='...' FINANCIAL_CLOSE_MANAGER_PASSWORD='...' \
 *      php artisan db:seed --class=FinancialCloseRoleAccountsSeeder
 */
class FinancialCloseRoleAccountsSeeder extends Seeder
{
    private const TENANT_ID = 'e255e52c-a4a6-4158-954a-af04408e9386';

    public function run(): void
    {
        $accounts = $this->configuredAccounts();
        $tenant = Tenant::findOrFail(self::TENANT_ID);

        // Create central users first (public schema) and collect their IDs.
        $centralUserIds = [];
        foreach ($accounts as $account) {
            $centralUserIds[$account['email']] = $this->upsertCentralUser($account);
        }

        $tenant->run(function () use ($centralUserIds, $accounts) {
            foreach ($accounts as $account) {
                $this->upsertTenantRoleUser($account, $centralUserIds[$account['email']]);
            }
        });

        $this->command->info('Financial Close capability accounts seeded:');
        foreach ($accounts as $account) {
            $this->command->info("  - {$account['email']} ({$account['role']})");
        }
    }

    /**
     * Read demo-account passwords from the operator's environment.
     *
     * No usable credential is checked into source. The seeder fails closed
     * when either password is missing instead of silently creating an account
     * with a known/default password.
     *
     * @return array<int, array{email:string,name:string,role:string,password:string}>
     */
    private function configuredAccounts(): array
    {
        $accounts = [
            [
                'email' => 'accountant@demo.com',
                'name' => 'Demo Accountant',
                'role' => 'accountant',
                'password' => env('FINANCIAL_CLOSE_ACCOUNTANT_PASSWORD'),
            ],
            [
                'email' => 'manager@demo.com',
                'name' => 'Demo Manager',
                'role' => 'manager',
                'password' => env('FINANCIAL_CLOSE_MANAGER_PASSWORD'),
            ],
        ];

        foreach ($accounts as $account) {
            if (! is_string($account['password']) || $account['password'] === '') {
                throw new \RuntimeException(
                    'FinancialCloseRoleAccountsSeeder requires '.
                    ($account['role'] === 'accountant'
                        ? 'FINANCIAL_CLOSE_ACCOUNTANT_PASSWORD'
                        : 'FINANCIAL_CLOSE_MANAGER_PASSWORD').
                    ' to be set. No default/demo password is permitted.'
                );
            }
        }

        return $accounts;
    }

    /**
     * Create/update the central (public schema) user and grant tenant access.
     * Returns the central user id.
     */
    private function upsertCentralUser(array $account): int
    {
        DB::statement('SET search_path TO public');

        $user = User::where('email', $account['email'])->first();

        if (! $user) {
            $user = User::create([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => Hash::make($account['password']),
            ]);
        } else {
            $user->update([
                'name' => $account['name'],
                'password' => Hash::make($account['password']),
            ]);
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

        return (int) $user->id;
    }

    /**
     * Create/update the tenant-schema user and assign the Spatie role.
     */
    private function upsertTenantRoleUser(array $account, int $centralUserId): void
    {
        // Mirror the central user id into the tenant schema so auth resolves cleanly.
        $tenantUser = User::find($centralUserId);

        if (! $tenantUser) {
            User::create([
                'id' => $centralUserId,
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => Hash::make($account['password']),
            ]);
        } else {
            $tenantUser->update([
                'name' => $account['name'],
                'password' => Hash::make($account['password']),
            ]);
        }

        $spatieRoleName = ucfirst($account['role']);

        // Ensure the Spatie role exists in this tenant schema.
        $roleId = DB::table('roles')
            ->where('name', $spatieRoleName)
            ->where('guard_name', 'web')
            ->value('id');

        if (! $roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => $spatieRoleName,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('model_has_roles')->updateOrInsert(
            [
                'role_id' => $roleId,
                'model_type' => User::class,
                'model_id' => $centralUserId,
            ],
            []
        );
    }
}
