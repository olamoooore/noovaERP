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

    public function test_downgrade_to_essential_preserves_existing_active_warehouses_and_blocks_new_activation(): void
    {
        $tenantId = (string) Str::uuid();
        $this->seedTenantWithActiveTier($tenantId, 'growth');

        $this->postJson('/api/v1/warehouses', [
            'name' => 'Warehouse A',
            'code' => 'WH-A',
            'is_active' => true,
        ])->assertCreated();

        $this->postJson('/api/v1/warehouses', [
            'name' => 'Warehouse B',
            'code' => 'WH-B',
            'is_active' => true,
        ])->assertCreated();

        $this->assertSame(2, DB::table('warehouses')->where('is_active', true)->count());

        // Downgrade tenant to essential tier by writing a newer active subscription row.
        $this->addActiveTierSubscription($tenantId, 'essential');

        // Existing active warehouses must remain untouched (no destructive auto-deactivation).
        $this->assertSame(2, DB::table('warehouses')->where('is_active', true)->count());

        // Restricted mode: cannot activate additional warehouses beyond essential-tier cap.
        $inactive = $this->postJson('/api/v1/warehouses', [
            'name' => 'Warehouse C',
            'code' => 'WH-C',
            'is_active' => false,
        ]);
        $inactive->assertCreated();

        $activationAttempt = $this->putJson('/api/v1/warehouses/' . $inactive->json('id'), [
            'is_active' => true,
        ]);

        $activationAttempt->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);

        $this->assertSame(2, DB::table('warehouses')->where('is_active', true)->count());
    }

    public function test_warehouse_with_stock_cannot_be_deactivated(): void
    {
        $tenantId = (string) Str::uuid();
        $this->seedTenantWithActiveTier($tenantId, 'growth');

        $warehouse = $this->postJson('/api/v1/warehouses', [
            'name' => 'Stocked Warehouse',
            'code' => 'WH-STOCK',
            'is_active' => true,
        ]);
        $warehouse->assertCreated();
        $warehouseId = (int) $warehouse->json('id');

        $productId = $this->createProductId();
        DB::table('stock_levels')->insert([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
            'max_quantity' => null,
            'bin_location' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->putJson('/api/v1/warehouses/' . $warehouseId, [
            'is_active' => false,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);

        $this->assertTrue((bool) DB::table('warehouses')->where('id', $warehouseId)->value('is_active'));
    }

    public function test_warehouse_with_stock_cannot_be_deleted(): void
    {
        $tenantId = (string) Str::uuid();
        $this->seedTenantWithActiveTier($tenantId, 'growth');

        $warehouse = $this->postJson('/api/v1/warehouses', [
            'name' => 'Delete Guard Warehouse',
            'code' => 'WH-DEL',
            'is_active' => true,
        ]);
        $warehouse->assertCreated();
        $warehouseId = (int) $warehouse->json('id');

        $productId = $this->createProductId();
        DB::table('stock_levels')->insert([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'quantity' => 5,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
            'max_quantity' => null,
            'bin_location' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson('/api/v1/warehouses/' . $warehouseId);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse']);

        $this->assertDatabaseHas('warehouses', ['id' => $warehouseId]);
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

        $this->addActiveTierSubscription($tenantId, $tierKey);

        // Bind tenant context so tenant('id') is available in controller enforcement.
        $this->bindTenantContext($tenantId);
    }

    private function addActiveTierSubscription(string $tenantId, string $tierKey): void
    {

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
    }

    private function createProductId(): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => 'WH001 Test Product',
            'sku' => 'WH001-' . Str::upper(Str::random(8)),
            'track_inventory' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
