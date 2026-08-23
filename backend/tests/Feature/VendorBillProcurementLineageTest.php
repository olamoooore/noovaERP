<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\ChartOfAccount;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\ThreeWayMatchService;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\Supplier;
use Tests\TenantTestCase;

class VendorBillProcurementLineageTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Bind a tenant so AuditTrailObserver/SystemLog (tenant()->id) and
        // the vendor-bill API have a tenant context to work with.
        $tenantId = 'cccc3333-cccc-3333-cccc-333333333333';
        Tenant::withoutEvents(function () use ($tenantId) {
            return Tenant::create([
                'id' => $tenantId,
                'name' => 'Vendor Bill Lineage Test Tenant',
                'email' => 'vendor-lineage@example.com',
                'plan' => 'standard',
            ]);
        });
        $this->bindTenantContext($tenantId);

        // NOTE: This class previously wiped the DB and ran a hand-picked
        // migration list in setUp (plus db:wipe in tearDown). That fought
        // with TenantTestCase's RefreshDatabase machinery, was extremely
        // slow (30 sequential migrate calls per test), missed later columns
        // like vendors.code, and deadlocked long PHPUnit runs.
        // TenantTestCase already provisions the FULL tenant schema,
        // including purchase orders, goods receipts, bills, vendors, and
        // settings — everything these lineage tests need.
    }

    public function test_store_rejects_procurement_bill_without_line_level_lineage(): void
    {
        $user = User::create([
            'name' => 'Tenant User',
            'email' => 'procurement@test.local',
            'password' => bcrypt('password'),
        ]);

        $vendor = Vendor::factory()->create();
        $account = ChartOfAccount::factory()->create([
            'type' => 'expense',
            'subtype' => 'operating_expense',
        ]);
        $warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $supplier = Supplier::create([
            'name' => 'Procurement Supplier',
            'code' => 'SUP-LINEAGE-001',
            'email' => 'supplier@test.local',
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'barcode' => '1234567890123',
            'track_inventory' => true,
            'cost' => 15,
            'price' => 25,
            'status' => 'active',
        ]);

        $purchaseOrder = PurchaseOrder::create([
            'po_number' => 'PO-LINEAGE-001',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'subtotal' => 60,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => 60,
            'currency' => 'USD',
            'created_by' => $user->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'description' => 'Tracked product',
            'quantity_ordered' => 4,
            'quantity_received' => 0,
            'unit_cost' => 15,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_percent' => 0,
            'line_total' => 60,
        ]);

        $response = $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->postJson('/api/v1/accounting/vendor-bills', [
                'vendor_id' => $vendor->id,
                'purchase_order_id' => $purchaseOrder->id,
                'bill_date' => now()->toDateString(),
                'lines' => [[
                    'account_id' => $account->id,
                    'description' => 'Invoice line with no PO lineage',
                    'quantity' => 4,
                    'unit_price' => 15,
                    'tax_rate' => 0,
                ]],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Three-way match validation failed: Bill line 1 is missing purchase order lineage for PO PO-LINEAGE-001.');
        $this->assertDatabaseCount('bills', 0);
    }

    public function test_three_way_match_rejects_linked_bill_with_missing_goods_receipt_lineage(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $supplier = Supplier::create([
            'name' => 'Procurement Supplier',
            'code' => 'SUP-LINEAGE-002',
            'email' => 'supplier2@test.local',
            'is_active' => true,
        ]);
        $vendor = Vendor::factory()->create();
        $user = User::create([
            'name' => 'Tenant User',
            'email' => 'grn@test.local',
            'password' => bcrypt('password'),
        ]);
        $account = ChartOfAccount::factory()->create([
            'type' => 'expense',
            'subtype' => 'operating_expense',
        ]);
        $product = Product::create([
            'name' => 'Test Product 2',
            'sku' => 'TEST-002',
            'barcode' => '1234567890124',
            'track_inventory' => true,
            'cost' => 12,
            'price' => 20,
            'status' => 'active',
        ]);

        $purchaseOrder = PurchaseOrder::create([
            'po_number' => 'PO-LINEAGE-002',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'subtotal' => 36,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => 36,
            'currency' => 'USD',
            'created_by' => $user->id,
        ]);

        $purchaseOrderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'description' => 'Tracked product',
            'quantity_ordered' => 3,
            'quantity_received' => 0,
            'unit_cost' => 12,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_percent' => 0,
            'line_total' => 36,
        ]);

        $goodsReceipt = GoodsReceipt::create([
            'grn_number' => 'GRN-LINEAGE-001',
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $warehouse->id,
            'received_by' => $user->id,
            'received_date' => now()->toDateString(),
            'status' => 'confirmed',
        ]);

        GoodsReceiptLine::create([
            'goods_receipt_id' => $goodsReceipt->id,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'product_id' => $product->id,
            'quantity_ordered' => 3,
            'quantity_received' => 3,
            'quantity_accepted' => 3,
            'quantity_rejected' => 0,
            'quantity_putaway' => 3,
        ]);

        $bill = Bill::create([
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $purchaseOrder->id,
            'goods_receipt_id' => $goodsReceipt->id,
            'bill_date' => now()->toDateString(),
            'status' => 'draft',
            'discount_amount' => 0,
            'subtotal' => 36,
            'tax_amount' => 0,
            'total' => 36,
            'paid_amount' => 0,
            'balance' => 36,
            'created_by' => $user->id,
        ]);

        $line = $bill->lines()->create([
            'account_id' => $account->id,
            'description' => 'Missing GRN lineage',
            'quantity' => 3,
            'unit_price' => 12,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 36,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'goods_receipt_line_id' => null,
        ]);

        $result = app(ThreeWayMatchService::class)->validate(
            $bill->load(['lines', 'purchaseOrder.items.product', 'goodsReceipt.lines.product'])
        );

        $this->assertFalse($result['valid']);
        $this->assertContains(
            "Bill line {$line->id} is missing goods receipt lineage for GRN {$goodsReceipt->grn_number}.",
            $result['errors']
        );
    }
}
