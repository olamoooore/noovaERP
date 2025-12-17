<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Feature;
use App\Models\TenantFeature;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class DemoTenantSeeder extends Seeder
{
    public function run()
    {
        $tenant = Tenant::where('email', 'admin@demo.com')->first();

        if (!$tenant) {
            $tenant = Tenant::create([
                'id' => Str::uuid(),
                'name' => 'Demo Corp',
                'email' => 'admin@demo.com',
                'plan' => 'trial',
            ]);
            
            $tenant->domains()->create([
                'domain' => 'demo.localhost',
            ]);
        }

        $tenant->run(function () {
            $user = User::where('email', 'admin@demo.com')->first();
            if (!$user) {
                User::create([
                    'name' => 'Admin User',
                    'email' => 'admin@demo.com',
                    'password' => bcrypt('password'),
                ]);
            } else {
                $user->update([
                    'password' => bcrypt('password'),
                ]);
            }

            Artisan::call('db:seed', [
                '--class' => \Database\Seeders\DatabaseSeeder::class,
                '--force' => true,
            ]);

            // Dev-only convenience: enable ALL modules for the demo tenant.
            // This makes feature-gated APIs accessible while building the React admin UI.
            $now = now();
            foreach (Feature::query()->get() as $feature) {
                TenantFeature::query()->updateOrCreate(
                    ['feature_id' => $feature->id],
                    ['is_enabled' => true, 'enabled_at' => $now, 'expires_at' => null]
                );
            }
        });

        $this->command->info('Demo tenant created successfully.');
        $this->command->info('URL: http://demo.localhost:5173');
        $this->command->info('User: admin@demo.com');
        $this->command->info('Pass: password');
    }
}
