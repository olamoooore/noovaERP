<?php

namespace Tests\Feature;

use App\SuperAdmin\Domain\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class RM002SubscriptionTierWarehouseModelTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    public function test_essential_tier_blocks_second_active_warehouse(): void
    {
        $tenantId = (string) Str::uuid();
        $this->seedTenantWithActiveTier($tenantId, 'essential');

        $first = $this->postJson('/api/v1/warehouses', [
            'name' => 'Main Warehouse',
            'code' => 'WH-MAIN',
            'is_active' => true,
        ]);

        $first->assertCreated();

        $second = $this->postJson('/api/v1/warehouses', [
            'name' => 'Overflow Warehouse',
            'code' => 'WH-2',
            'is_active' => true,
        ]);

        $second->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);

        $this->assertDatabaseCount('warehouses', 1);
    }

    public function test_growth_tier_allows_multiple_active_warehouses(): void
    {
        $tenantId = (string) Str::uuid();
        $this->seedTenantWithActiveTier($tenantId, 'growth');

        $first = $this->postJson('/api/v1/warehouses', [
            'name' => 'Warehouse A',
            'code' => 'WH-A',
            'is_active' => true,
        ]);

        $second = $this->postJson('/api/v1/warehouses', [
            'name' => 'Warehouse B',
            'code' => 'WH-B',
            'is_active' => true,
        ]);

        $first->assertCreated();
        $second->assertCreated();

        $this->assertDatabaseCount('warehouses', 2);
        $this->assertSame(2, DB::table('warehouses')->where('is_active', true)->count());
    }

    private function seedTenantWithActiveTier(string $tenantId, string $tierKey): void
    {
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Tier Test Tenant',
            'email' => 'tier-test@example.com',
            'plan' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $planFamilyId = DB::table('plan_families')->insertGetId([
            'key' => $tierKey,
            'name' => strtoupper($tierKey),
            'description' => 'Test plan tier',
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ResolveActiveTier reads the family by joining through plan_versions.id.
        $planVersionId = DB::table('plan_versions')->insertGetId([
            'plan_family_id' => $planFamilyId,
            'version_label' => 'v1',
            'description' => 'Test version',
            'is_active' => true,
            'is_publicly_listed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Subscription::create([
            'tenant_id' => $tenantId,
            'plan_version_id' => $planVersionId,
            'status' => 'active',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);

        // Bind tenant context so tenant('id') is available in controller enforcement.
        $this->bindTenantContext($tenantId);
    }
}
