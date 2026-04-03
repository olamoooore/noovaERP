<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'superadmin@noovaerp.com'],
            ['name' => 'Super Admin', 'password' => Hash::make('password'), 'role' => 'platform_super_admin']
        );
    }
}
