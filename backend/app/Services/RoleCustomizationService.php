<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Records which tenant roles have had their permission set edited through
 * the Team Members → Roles & Permissions matrix.
 *
 * RolePermissionSeeder treats the seeder matrix as the source of truth and
 * converges roles to it on every run (grants AND revokes). Without this
 * marker, re-seeding a tenant (tenants:seed on a deploy) would silently
 * revert every tenant customization. Marked roles are skipped by the
 * seeder; a role whose set matches the matrix again has its marker cleared.
 *
 * Stored in the tenant-schema `settings` table so it travels with the
 * tenant and needs no extra migration.
 */
class RoleCustomizationService
{
    public const SETTINGS_KEY = 'rbac.customized_roles';

    /**
     * @return string[] role values (e.g. ['manager', 'sales'])
     */
    public function customizedRoles(): array
    {
        try {
            $raw = DB::table('settings')->where('key', self::SETTINGS_KEY)->value('value');
        } catch (\Throwable $e) {
            return [];
        }

        if (! $raw) {
            return [];
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    public function isCustomized(string $role): bool
    {
        return in_array($role, $this->customizedRoles(), true);
    }

    public function markCustomized(string $role): void
    {
        $roles = $this->customizedRoles();
        if (in_array($role, $roles, true)) {
            return;
        }
        $roles[] = $role;
        $this->write($roles);
    }

    public function clearCustomized(string $role): void
    {
        $roles = array_values(array_diff($this->customizedRoles(), [$role]));
        $this->write($roles);
    }

    private function write(array $roles): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => self::SETTINGS_KEY],
            ['value' => json_encode(array_values(array_unique($roles))), 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
