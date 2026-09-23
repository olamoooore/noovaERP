<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\Supplier;
use Modules\Inventory\Models\PurchaseOrderItem;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #134 — ProductSupplier's procurement data (cost/last_cost, MOQ,
 * order_multiple) was captured but never consumed: receipts never updated
 * last_cost and PO lines never validated against MOQ/order multiples.
 */
class HR134ProductSupplierConstraintsTest extends TenantTestCase
{
    private User $user;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '13413413-4141-5151-6161-717181911121',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'suppliers', 'warehouses', 'products', 'product_suppliers',
            'purchase_orders', 'purchase_order_items', 'goods_receipts', 'goods_receipt_lines',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->user);

        // Entitlement gate is orthogonal to this issue; the controller-level
        // constraint validation is what is under test.
        $this->withoutMiddleware([\App\Http\Middleware\EnsureInventoryAccess::class]);

        Vendor::factory()->create();
        ChartOfAccount::factory()->create(['type' => 'expense', 'subtype' => 'operating_expense']);

        $this->warehouse = Warehouse::create([
            'name' => 'HR134 Warehouse', 'code' => 'HR134-'.uniqid(), 'is_active' => true,
        ]);
        $this->supplier = Supplier::create([
            'name' => 'HR134 Supplier', 'code' => 'HR134-'.Str::upper(Str::random(6)), 'is_active' => true,
        ]);
        $this->product = Product::factory()->create([
            'name' => 'HR134 Widget', 'sku' => 'HR134-'.uniqid(),
            'cost' => 10.00, 'price' => 20.00, 'track_inventory' => true,
        ]);
    }

    private function supplierLink(int $moq = 10, int $multiple = 5): ProductSupplier
    {
        return ProductSupplier::create([
            'product_id' => $this->product->id,
            'supplier_id' => $this->supplier->id,
            'cost' => 5.00,
            'last_cost' => null,
            'moq' => $moq,
            'order_multiple' => $multiple,
            'is_active' => true,
        ]);
    }

    private function poPayload(int $quantity, float $unitCost = 7.00): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'description' => 'HR134 line',
                'quantity_ordered' => $quantity,
                'unit_cost' => $unitCost,
            ]],
        ];
    }

    private function orderedPo(float $qty, float $unitCost): PurchaseOrder
    {
        $subtotal = $qty * $unitCost;

        $po = PurchaseOrder::create([
            'po_number' => 'PO-HR134-'.Str::upper(Str::random(6)),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'subtotal' => $subtotal,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => $subtotal,
            'currency' => 'USD',
            'created_by' => $this->user->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'description' => 'HR134 item',
            'quantity_ordered' => $qty,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_percent' => 0,
            'line_total' => $subtotal,
        ]);

        return $po->fresh('items');
    }

    public function test_goods_receipt_confirm_updates_product_supplier_last_cost(): void
    {
        $link = $this->supplierLink();
        $po = $this->orderedPo(10, 7.50);

        $service = app(GoodsReceiptService::class);
        $grn = $service->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => now()->toDateString(),
            'lines' => [[
                'purchase_order_item_id' => $po->items->first()->id,
                'quantity_received' => 10,
                'quantity_accepted' => 10,
                'quantity_rejected' => 0,
            ]],
        ]);

        $service->confirm($grn);

        $fresh = $link->fresh();
        $this->assertSame(7.50, round((float) $fresh->last_cost, 2), 'last_cost must reflect the real purchase price.');
        $this->assertSame(now()->toDateString(), $fresh->last_purchase_date?->toDateString());
    }

    public function test_purchase_order_below_moq_is_rejected_with_a_suggested_quantity(): void
    {
        $this->supplierLink(moq: 10, multiple: 5);

        $response = $this->postJson('/api/v1/purchase-orders', $this->poPayload(7));

        $response->assertStatus(422);
        $this->assertStringContainsString('Suggested quantity: 10', (string) ($response->json('errors')['items.0.quantity_ordered'][0] ?? ''));
    }

    public function test_purchase_order_violating_order_multiple_is_rejected(): void
    {
        $this->supplierLink(moq: 10, multiple: 5);

        $response = $this->postJson('/api/v1/purchase-orders', $this->poPayload(12));

        $response->assertStatus(422);
        $this->assertStringContainsString('Suggested quantity: 15', (string) ($response->json('errors')['items.0.quantity_ordered'][0] ?? ''));
    }

    public function test_compliant_purchase_order_is_created(): void
    {
        $this->supplierLink(moq: 10, multiple: 5);

        $this->postJson('/api/v1/purchase-orders', $this->poPayload(15))->assertCreated();
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_products_without_a_supplier_link_are_unaffected(): void
    {
        $this->postJson('/api/v1/purchase-orders', $this->poPayload(3))->assertCreated();
    }
}
