<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\ChartOfAccount;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\ThreeWayMatchService;
use Illuminate\Support\Facades\Artisan;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\Supplier;
use Tests\TestCase;

class VendorBillProcurementLineageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:wipe', ['--force' => true]);
        Artisan::call('migrate:install');

        foreach ([
            database_path('migrations/tenant/2024_01_01_000001_create_users_table.php'),
            database_path('migrations/tenant/2024_01_01_100001_create_chart_of_accounts_table.php'),
            database_path('migrations/tenant/2024_01_01_100002_create_journal_entries_table.php'),
            database_path('migrations/tenant/2025_12_15_150000_fix_journal_balance_trigger.php'),
            database_path('migrations/tenant/2025_12_17_000001_create_settings_table.php'),
            database_path('migrations/tenant/2025_12_17_000002_create_taxes_table.php'),
            database_path('migrations/tenant/2025_12_18_231016_create_warehouses_table.php'),
            database_path('migrations/tenant/2025_12_15_150010_create_product_categories_table.php'),
            database_path('migrations/tenant/2024_01_01_200001_create_products_table.php'),
            database_path('migrations/tenant/2024_01_01_200002_create_customers_table.php'),
            database_path('migrations/tenant/2024_01_01_200005_create_stock_movements_table.php'),
            database_path('migrations/tenant/2026_01_01_000001_enhance_inventory_module.php'),
            database_path('migrations/tenant/2025_01_31_000001_enhance_product_module_comprehensive.php'),
            database_path('migrations/tenant/2025_01_31_200000_fix_product_audit_logs_columns.php'),
            database_path('migrations/tenant/2025_12_15_150020_add_category_to_products_table.php'),
            database_path('migrations/tenant/2026_04_01_080000_backfill_customer_and_pos_tenant_features.php'),
            database_path('migrations/tenant/2025_12_29_000001_create_vendors_table.php'),
            database_path('migrations/tenant/2024_01_01_200003_create_invoices_table.php'),
            database_path('migrations/tenant/2024_01_01_200004_create_payments_table.php'),
            database_path('migrations/tenant/2026_01_05_000001_create_bills_table.php'),
            database_path('migrations/tenant/2026_01_05_000002_create_bill_lines_table.php'),
            database_path('migrations/tenant/2026_03_30_000001_add_partial_status_to_bills.php'),
            database_path('migrations/tenant/2026_04_02_210000_add_enterprise_document_metadata_fields.php'),
            database_path('migrations/tenant/2026_04_14_203813_add_procurement_linkage_and_goods_receipts.php'),
            database_path('migrations/tenant/2026_04_15_000001_add_po_grn_links_to_bill_lines.php'),
            database_path('migrations/2026_04_19_000001_add_bill_type_to_bills_table.php'),
        ] as $migrationPath) {
            Artisan::call('migrate', [
                '--path' => $migrationPath,
                '--realpath' => true,
                '--force' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Artisan::call('db:wipe', ['--force' => true]);

        parent::tearDown();
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
