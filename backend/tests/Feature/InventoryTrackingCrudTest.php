<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Lot/batch and serial CRUD from the Inventory Tracking page.
 *
 * Real HTTP through the router: Viewer (no inventory.write) is denied, an
 * inventory-write role can edit descriptive fields, and deletion is guarded
 * so stock-bearing, reserved, serial-linked or consumed lots cannot vanish.
 */
class InventoryTrackingCrudTest extends TenantTestCase
{
    protected User $viewer;

    protected User $manager;

    protected Product $product;

    protected Warehouse $warehouse;

    protected ProductBatch $batch;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        // Entitlement + role gating is covered by dedicated suites; this test
        // exercises the tracking controller and its RequirePermission routes.
        $this->withoutMiddleware(\App\Http\Middleware\EnsureInventoryAccess::class);

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $tenant = Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Tracking CRUD Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->viewer = $this->makeUser('viewer', UserRole::VIEWER);
        $this->manager = $this->makeUser('manager', UserRole::MANAGER);

        $this->warehouse = Warehouse::create([
            'name' => 'Tracking Warehouse',
            'code' => 'TRK-'.uniqid(),
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Tracking Item',
            'sku' => 'TRK-'.uniqid(),
            'track_inventory' => true,
            'tracking_method' => Product::TRACKING_BATCH,
            'price' => 10,
            'cost' => 5,
        ]);

        $this->batch = ProductBatch::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => '2607S08',
            'lot_number' => '2607S08',
            'quantity' => 5,
            'quantity_reserved' => 0,
            'status' => ProductBatch::STATUS_AVAILABLE,
        ]);
    }

    private function makeUser(string $accessRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole->spatieName());

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $accessRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_viewer_cannot_edit_or_delete_tracking_records(): void
    {
        $this->actingAs($this->viewer)->putJson("/api/v1/inventory/tracking/batches/{$this->batch->id}", [
            'batch_number' => 'HACKED',
            'status' => 'available',
        ])->assertStatus(403);

        $this->actingAs($this->viewer)->deleteJson("/api/v1/inventory/tracking/batches/{$this->batch->id}")
            ->assertStatus(403);

        $this->assertSame('2607S08', $this->batch->fresh()->batch_number);
    }

    public function test_manager_can_update_a_lot_batch(): void
    {
        $response = $this->actingAs($this->manager)->putJson("/api/v1/inventory/tracking/batches/{$this->batch->id}", [
            'batch_number' => '2607S08-A',
            'lot_number' => 'LOT-A',
            'warehouse_id' => $this->warehouse->id,
            'status' => 'quarantine',
            'expiry_date' => '2029-06-07',
            'manufacturing_date' => '2026-06-01',
            'supplier_batch_number' => 'SUP-9',
            'cost' => 4.25,
            'notes' => 'Recalled pending QC',
        ]);

        $response->assertOk();

        $fresh = $this->batch->fresh();
        $this->assertSame('2607S08-A', $fresh->batch_number);
        $this->assertSame('LOT-A', $fresh->lot_number);
        $this->assertSame('quarantine', $fresh->status);
        $this->assertSame('2029-06-07', $fresh->expiry_date?->toDateString());
        $this->assertSame('SUP-9', $fresh->supplier_batch_number);

        // Editing descriptive fields must never move stock.
        $this->assertSame(5, (int) $fresh->quantity);
    }

    public function test_batch_delete_is_blocked_while_it_holds_stock(): void
    {
        $this->actingAs($this->manager)->deleteJson("/api/v1/inventory/tracking/batches/{$this->batch->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete this lot/batch because it still has stock on hand. Adjust the stock to zero first.');

        $this->assertDatabaseHas('product_batches', ['id' => $this->batch->id]);

        $this->batch->update(['quantity' => 0]);

        $this->actingAs($this->manager)->deleteJson("/api/v1/inventory/tracking/batches/{$this->batch->id}")
            ->assertOk();

        $this->assertDatabaseMissing('product_batches', ['id' => $this->batch->id]);
    }

    public function test_batch_delete_is_blocked_when_serials_reference_it(): void
    {
        $this->batch->update(['quantity' => 0]);

        ProductSerial::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_id' => $this->batch->id,
            'serial_number' => 'SN-'.uniqid(),
            'status' => ProductSerial::STATUS_AVAILABLE,
        ]);

        $this->actingAs($this->manager)->deleteJson("/api/v1/inventory/tracking/batches/{$this->batch->id}")
            ->assertStatus(422);
    }

    public function test_manager_can_update_and_delete_an_available_serial(): void
    {
        $serial = ProductSerial::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_id' => $this->batch->id,
            'serial_number' => 'SN-EDIT-'.uniqid(),
            'status' => ProductSerial::STATUS_AVAILABLE,
        ]);

        $this->actingAs($this->manager)->putJson("/api/v1/inventory/tracking/serials/{$serial->id}", [
            'serial_number' => $serial->serial_number,
            'warehouse_id' => $this->warehouse->id,
            'batch_id' => $this->batch->id,
            'status' => 'defective',
            'notes' => 'Screen cracked',
        ])->assertOk();

        $this->assertSame('defective', $serial->fresh()->status);

        $this->actingAs($this->manager)->deleteJson("/api/v1/inventory/tracking/serials/{$serial->id}")
            ->assertOk();

        $this->assertDatabaseMissing('product_serials', ['id' => $serial->id]);
    }

    public function test_sold_serial_cannot_be_deleted(): void
    {
        $serial = ProductSerial::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'serial_number' => 'SN-SOLD-'.uniqid(),
            'status' => ProductSerial::STATUS_SOLD,
            'sale_reference' => 'INV-2026-0001',
            'sold_at' => now(),
        ]);

        $this->actingAs($this->manager)->deleteJson("/api/v1/inventory/tracking/serials/{$serial->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('product_serials', ['id' => $serial->id]);
    }
}