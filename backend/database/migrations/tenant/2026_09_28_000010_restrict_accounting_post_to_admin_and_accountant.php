<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Posting sell-side documents (invoice post/send/bulk-post, delivery-order
     * confirm, quotation confirm) is restricted to Owner/Admin/Accountant.
     *
     * The updated RolePermissionSeeder matrix only affects freshly seeded
     * tenants, so existing schemas need the capability revoked from Manager
     * (and re-asserted for Accountant if an older seed missed it).
     */
    public function up(): void
    {
        $permission = Permission::where('name', 'accounting.post')->where('guard_name', 'web')->first();
        if (! $permission) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $manager = Role::where('name', UserRole::MANAGER->spatieName())->where('guard_name', 'web')->first();
        if ($manager) {
            $manager->revokePermissionTo($permission);
        }

        $accountant = Role::where('name', UserRole::ACCOUNTANT->spatieName())->where('guard_name', 'web')->first();
        if ($accountant && ! $accountant->hasPermissionTo($permission)) {
            $accountant->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'accounting.post')->where('guard_name', 'web')->first();
        if (! $permission) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $manager = Role::where('name', UserRole::MANAGER->spatieName())->where('guard_name', 'web')->first();
        if ($manager && ! $manager->hasPermissionTo($permission)) {
            $manager->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
