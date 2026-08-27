<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SUPERADMIN_PASSWORD');
        if (! is_string($password) || $password === '') {
            throw new \RuntimeException(
                'SuperAdminSeeder requires SUPERADMIN_PASSWORD to be set. No default/demo password is permitted.'
            );
        }

        $user = User::query()->updateOrCreate(
            ['email' => 'superadmin@noovaerp.com'],
            ['name' => 'Super Admin', 'password' => Hash::make($password)]
        );

        // `role` is intentionally not mass-assignable on User. The only
        // legitimate privileged provisioning path is the restricted method,
        // which records the assignment in the audit log.
        $user->assignAdminRole(
            'platform_super_admin',
            actorId: null,
            reason: 'explicit SuperAdminSeeder provisioning'
        );
    }
}
