<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Accounting\CountryConfiguration;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\MalaysiaCountrySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #769 (A9) AC2/AC4 — applying a country pack must be atomic and its
 * failures visible. The controller used to catch-and-log any seeding failure
 * and still return 200 with `seeded: false`, after the tenant row and the
 * CountryConfiguration had already been written.
 *
 * Real HTTP through the router.
 */
class CountryPluginSettingsFailureTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->withMiddleware(
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
        );

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tenantRow(): object
    {
        return DB::connection('pgsql')->table('tenants')->where('id', $this->tenantId)->first();
    }

    public function test_a_seeder_failure_returns_an_error_and_changes_nothing(): void
    {
        $this->app->instance(MalaysiaCountrySeeder::class, new class extends MalaysiaCountrySeeder
        {
            public function run(): void
            {
                throw new \RuntimeException('seeder exploded');
            }
        });

        $this->actingAs($this->admin)
            ->putJson('/api/v1/accounting/tenant/country-plugin-settings', [
                'tenant_country_code' => 'MY',
                'country_plugin_enabled' => true,
                'seed_country_data' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Country data could not be applied; nothing was changed.')
            ->assertJsonPath('detail', 'seeder exploded');

        // Nothing half-applied: the tenant row and CountryConfiguration are
        // exactly as they were before the request.
        $tenant = $this->tenantRow();
        $this->assertNull($tenant->tenant_country_code, 'the tenant country must not be switched');
        $this->assertFalse((bool) $tenant->country_plugin_enabled, 'the plugin flag must not be flipped');
        $this->assertSame(0, CountryConfiguration::query()->count(), 'no partial CountryConfiguration may survive');
    }

    public function test_a_successful_update_applies_in_one_go(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/accounting/tenant/country-plugin-settings', [
                'tenant_country_code' => 'MY',
                'country_plugin_enabled' => true,
                'seed_country_data' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.tenant_country_code', 'MY')
            ->assertJsonPath('data.seeded', false);

        $tenant = $this->tenantRow();
        $this->assertSame('MY', $tenant->tenant_country_code);
        $this->assertTrue((bool) $tenant->country_plugin_enabled);
        $this->assertSame(1, CountryConfiguration::query()
            ->where('tenant_id', $this->tenantId)
            ->where('country_code', 'MY')
            ->count());
    }
}
