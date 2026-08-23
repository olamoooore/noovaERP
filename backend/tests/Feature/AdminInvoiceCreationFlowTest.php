<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesInvoiceLink;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

class AdminInvoiceCreationFlowTest extends TenantTestCase
{
    public function test_admin_invoice_endpoint_creates_sales_order_invoice_and_link_with_overrides(): void
    {
        // The TenantTestCase already runs ALL tenant migrations (including
        // POS and Sales) inside the test transaction, so we don't need a
        // custom setUp/tearDown that wipes the DB.

        $tenant = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => '33333333-3333-3333-3333-333333333333']);
        });
        $this->bindTenantContext($tenant->id);

        $user = User::create([
            'name' => 'Invoice Admin',
            'email' => 'admin-invoice@test.local',
            'password' => bcrypt('password'),
        ]);

        // Grant tenant-scoped accounting write access.
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
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
            ->actingAs($user)
            ->postJson('/api/v1/invoices', [
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
        // Sales order is created via the SalesOrderService during invoice creation
        // and linked to the invoice. Look it up via the relationship.
        $invoice = \App\Models\Invoice::with('salesOrder')->find($invoiceId);
        $salesOrderId = $invoice?->sales_order_id;

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
        $this->assertEquals('49.50', $salesOrder->subtotal);
        $this->assertNotNull($link);
        $this->assertEquals('54.00', $link->invoiced_value);

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
            'company_registration_number' => 'REG-123',
            'tax_registration_number' => 'TAX-456',
            'notes' => 'Manual invoice note',
            'terms' => 'Pay within 15 days',
            'discount_amount' => '7.50',
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
