<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * D4 (Oct-2026 invoice review): the tenant picks an industry field profile
 * for invoice entry (medical/trading/services/general). It must round-trip
 * through the sales settings API and default to 'medical' so existing
 * (medical) tenants keep their case fields untouched.
 */
class SalesSettingsFieldProfileTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Field Profile Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_field_profile_defaults_to_medical_and_round_trips(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.invoice.field_profile', 'medical');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/settings/sales', [
                'invoice' => ['field_profile' => 'trading'],
            ])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.invoice.field_profile', 'trading');

        $this->assertSame('trading', Setting::getValue('sales.invoice.field_profile'));
    }

    public function test_field_profile_rejects_unknown_values(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/settings/sales', [
                'invoice' => ['field_profile' => 'pharma'],
            ])
            ->assertStatus(422);
    }
}
