<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Modules\ModuleManager;
use App\SuperAdmin\Application\EntitlementService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantSelectedItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('email', 'admin@demo.com')->first();

        if (! $tenant) {
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

        // Ensure public schema user + user_company_access exist (for login)
        DB::statement('SET search_path TO public');
        $publicUser = User::where('email', 'admin@demo.com')->first();
        if (! $publicUser) {
            $publicUser = User::create([
                'name' => 'Admin User',
                'email' => 'admin@demo.com',
                'password' => Hash::make('password'),
            ]);
        }
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $publicUser->id, 'tenant_id' => $tenant->id],
            ['role' => 'admin', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->syncCanonicalModuleCatalog();
        $this->grantCanonicalDemoAccess($tenant->id, $publicUser->id);

        $tenant->run(function () {
            $user = User::where('email', 'admin@demo.com')->first();
            if (! $user) {
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

    private function syncCanonicalModuleCatalog(): void
    {
        $moduleType = CatalogItemType::query()->updateOrCreate(
            ['key' => 'module'],
            [
                'name' => 'Module',
                'description' => 'Top-level ERP module synced from module manifests.',
                'sort_order' => 10,
            ]
        );

        foreach (app(ModuleManager::class)->all() as $index => $module) {
            CatalogItem::query()->updateOrCreate(
                ['key' => $module->key],
                [
                    'name' => $module->name,
                    'description' => $module->isCore()
                        ? 'Core NoovaERP module.'
                        : 'NoovaERP add-on module.',
                    'type_id' => $moduleType->id,
                    'parent_id' => null,
                    'is_active' => true,
                    'sort_order' => ($index + 1) * 10,
                    'metadata' => ['module_type' => $module->type],
                    'effective_from' => today(),
                    'effective_to' => null,
                    'source' => 'module_manifest',
                ]
            );
        }
    }

    private function grantCanonicalDemoAccess(string $tenantId, int $adminId): void
    {
        $moduleItems = CatalogItem::query()
            ->whereHas('type', fn ($query) => $query->where('key', 'module'))
            ->get(['id']);

        foreach ($moduleItems as $item) {
            TenantSelectedItem::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'catalog_item_id' => $item->id],
                [
                    'selection_type' => TenantSelectedItem::TYPE_OVERRIDE_INCLUDE,
                    'notes' => 'Demo tenant bootstrap: grant all modules.',
                    'selected_by_admin_id' => $adminId,
                ]
            );
        }

        app(EntitlementService::class)->rebuild(
            tenantId: $tenantId,
            adminId: $adminId,
            reason: 'demo_tenant_bootstrap'
        );
    }
}
