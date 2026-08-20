<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Modules\Sales\Models\SalesInvoiceLink;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

class AdminInvoiceCreationFlowTest extends TenantTestCase
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
            database_path('migrations/tenant/2025_12_15_150010_create_product_categories_table.php'),
            database_path('migrations/tenant/2024_01_01_200001_create_products_table.php'),
            database_path('migrations/tenant/2024_01_01_200002_create_customers_table.php'),
            database_path('migrations/2025_01_31_000002_enhance_customer_and_pos_modules.php'),
            database_path('migrations/tenant/2024_01_01_200005_create_stock_movements_table.php'),
            database_path('migrations/tenant/2025_01_31_000001_enhance_product_module_comprehensive.php'),
            database_path('migrations/tenant/2025_12_15_150020_add_category_to_products_table.php'),
            database_path('migrations/tenant/2024_01_01_200003_create_invoices_table.php'),
            database_path('migrations/tenant/2024_01_01_200004_create_payments_table.php'),
            database_path('migrations/tenant/2025_12_29_000001_create_vendors_table.php'),
            database_path('migrations/tenant/2026_01_05_000001_create_bills_table.php'),
            database_path('migrations/tenant/2026_01_05_000002_create_bill_lines_table.php'),
            database_path('migrations/tenant/2026_03_30_000001_add_partial_status_to_bills.php'),
            database_path('migrations/tenant/2026_04_02_200000_make_invoices_customer_id_nullable.php'),
            database_path('migrations/tenant/2025_12_18_231016_create_warehouses_table.php'),
            database_path('migrations/tenant/2026_04_02_210000_add_enterprise_document_metadata_fields.php'),
            database_path('migrations/tenant/2026_01_01_000001_enhance_inventory_module.php'),
            database_path('migrations/tenant/2026_04_14_203813_add_procurement_linkage_and_goods_receipts.php'),
            database_path('migrations/tenant/2026_04_15_000001_add_po_grn_links_to_bill_lines.php'),
            database_path('migrations/2026_04_19_000001_add_bill_type_to_bills_table.php'),
            database_path('migrations/tenant/2026_04_15_100001_create_sales_orders_tables.php'),
            database_path('migrations/tenant/2026_04_15_100002_create_sales_links_and_invoice_channels.php'),
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

    public function test_admin_invoice_endpoint_creates_sales_order_invoice_and_link_with_overrides(): void
    {
        $user = User::create([
            'name' => 'Invoice Admin',
            'email' => 'admin-invoice@test.local',
            'password' => bcrypt('password'),
        ]);

        $customer = Customer::factory()->create([
            'payment_terms' => 45,
            'billing_address' => 'Original Billing Address',
            'shipping_address' => 'Original Shipping Address',
        ]);

        $product = Product::factory()->create([
            'track_inventory' => false,
            'cost' => 10,
            'price' => 25,
        ]);

        $response = $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->postJson('/api/v1/accounting/invoices', [
                'customer_id' => $customer->id,
                'invoice_date' => '2026-04-19',
                'due_date' => '2026-05-04',
                'customer_reference' => 'PO-7788',
                'internal_reference' => 'MANUAL-INV-01',
                'attention_to' => 'Accounts Payable',
                'contact_email' => 'billing@example.com',
                'contact_phone' => '+1-555-0100',
                'billing_address_snapshot' => 'Override Billing Address',
                'shipping_address_snapshot' => 'Override Shipping Address',
                'company_registration_number' => 'REG-123',
                'tax_registration_number' => 'TAX-456',
                'notes' => 'Manual invoice note',
                'terms' => 'Pay within 15 days',
                'discount_amount' => 7.50,
                'lines' => [[
                    'product_id' => $product->id,
                    'description' => 'Manual admin invoice line',
                    'quantity' => 2,
                    'unit_price' => 25,
                    'tax_rate' => 10,
                    'discount_amount' => 5,
                ]],
            ]);

        $response->assertCreated();

        $invoiceId = $response->json('id');
        $salesOrderId = $response->json('sales_order_id');

        $this->assertNotNull($invoiceId);
        $this->assertNotNull($salesOrderId);

        $salesOrder = SalesOrder::with(['lines', 'statusHistory'])->findOrFail($salesOrderId);
        $link = SalesInvoiceLink::where('sales_order_id', $salesOrderId)
            ->where('invoice_id', $invoiceId)
            ->first();

        $this->assertSame('admin', $salesOrder->channel);
        $this->assertSame('invoiced', $salesOrder->status);
        $this->assertSame((int) $customer->id, (int) $salesOrder->customer_id);
        $this->assertCount(1, $salesOrder->lines);
        $this->assertEquals('2.0000', $salesOrder->lines->first()->quantity);
        $this->assertEquals('50.00', $salesOrder->subtotal);
        $this->assertNotNull($link);
        $this->assertEquals('50.00', $link->invoiced_value);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'customer_id' => $customer->id,
            'sales_order_id' => $salesOrderId,
            'invoice_date' => '2026-04-19',
            'due_date' => '2026-05-04',
            'customer_reference' => 'PO-7788',
            'internal_reference' => 'MANUAL-INV-01',
            'attention_to' => 'Accounts Payable',
            'contact_email' => 'billing@example.com',
            'contact_phone' => '+1-555-0100',
            'billing_address_snapshot' => 'Override Billing Address',
            'shipping_address_snapshot' => 'Override Shipping Address',
            'company_registration_number' => 'REG-123',
            'tax_registration_number' => 'TAX-456',
            'notes' => 'Manual invoice note',
            'terms' => 'Pay within 15 days',
            'discount_amount' => 7.50,
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('sales_invoice_links', [
            'sales_order_id' => $salesOrderId,
            'invoice_id' => $invoiceId,
            'link_type' => 'primary',
            'link_state' => 'active',
            'created_by' => $user->id,
        ]);

        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoiceId,
            'product_id' => $product->id,
            'description' => 'Manual admin invoice line',
            'quantity' => 2,
            'unit_price' => 25,
            'discount_amount' => 5,
        ]);
    }
}
