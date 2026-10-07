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

    /** #1004: rich-text description persists sanitized and travels to the sales-order line. */
    public function test_invoice_line_rich_text_description_is_sanitized_and_stored(): void
    {
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => '44444444-4444-4444-4444-444444444444']));
        $this->bindTenantContext($tenant->id);

        $user = User::create([
            'name' => 'Rich Admin',
            'email' => 'admin-rich@test.local',
            'password' => bcrypt('password'),
        ]);
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = Customer::factory()->create();
        $product = Product::factory()->create([
            'track_inventory' => false,
            'cost' => 10,
            'price' => 25,
        ]);

        $rich = '<p style="text-align:center"><b>Bold</b> <i>italic</i> <u>under</u>'
            .'<span style="color:#b91c1c;font-family:Georgia;font-size:14px">red serif</span>'
            .'<script>alert(1)</script><img src=x onerror=alert(1)></p>'
            .'<ul><li>First</li></ul>';

        $response = $this->actingAs($user)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => '2026-04-19',
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Rich line',
                'description_html' => $rich,
                'quantity' => 1,
                'unit_price' => 25,
                'tax_rate' => 0,
            ]],
        ]);

        $response->assertCreated();

        $line = \App\Models\InvoiceLine::where('invoice_id', $response->json('id'))->firstOrFail();
        $this->assertStringContainsString('<b>Bold</b>', $line->description_html);
        $this->assertStringContainsString('<u>under</u>', $line->description_html);
        $this->assertStringContainsString('color:#b91c1c', $line->description_html);
        $this->assertStringContainsString('font-family:georgia', $line->description_html);
        $this->assertStringContainsString('font-size:14px', $line->description_html);
        $this->assertStringContainsString('text-align:center', $line->description_html);
        $this->assertStringContainsString('<ul><li>First</li></ul>', $line->description_html);
        $this->assertStringNotContainsString('<script', $line->description_html);
        $this->assertStringNotContainsString('onerror', $line->description_html);
        $this->assertStringNotContainsString('<img', $line->description_html);
        // The plain description stays the search/export copy.
        $this->assertSame('Rich line', $line->description);

        // The sanitized HTML travelled through the sales-order line too.
        $this->assertDatabaseHas('sales_order_lines', [
            'description' => 'Rich line',
        ]);
        $salesLine = \Modules\Sales\Models\SalesOrderLine::where('description', 'Rich line')->firstOrFail();
        $this->assertStringContainsString('<b>Bold</b>', $salesLine->description_html);
        $this->assertStringNotContainsString('<script', $salesLine->description_html);
    }

    /** Shared tenant + admin bootstrap for the #1004/#1005 text-row tests. */
    private function bootTextRowTenant(string $tenantId, string $email): array
    {
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => $tenantId]));
        $this->bindTenantContext($tenant->id);

        $user = User::create([
            'name' => 'Text Row Admin',
            'email' => $email,
            'password' => bcrypt('password'),
        ]);
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = Customer::factory()->create();
        $product = Product::factory()->create([
            'track_inventory' => false,
            'cost' => 10,
            'price' => 25,
        ]);

        return [$user, $customer, $product];
    }

    /** #1004: section/note rich text survives the invoice -> sales order -> invoice create path. */
    public function test_section_and_note_rich_text_survives_invoice_create(): void
    {
        [$user, $customer, $product] = $this->bootTextRowTenant('66666666-6666-6666-6666-666666666601', 'admin-rich-rows@test.local');

        $response = $this->actingAs($user)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => '2026-04-19',
            'lines' => [
                ['line_type' => 'section', 'description' => 'Consumables', 'description_html' => '<p><strong>Consumables</strong></p>', 'quantity' => 0, 'unit_price' => 0, 'tax_rate' => 0, 'discount_amount' => 0],
                ['product_id' => $product->id, 'description' => 'Item', 'quantity' => 2, 'unit_price' => 25, 'tax_rate' => 0],
                ['line_type' => 'note', 'description' => 'Delivered in two batches', 'description_html' => '<p><strong>Delivered</strong> in two batches</p>', 'quantity' => 0, 'unit_price' => 0, 'tax_rate' => 0, 'discount_amount' => 0],
            ],
        ]);

        $response->assertCreated();
        $invoiceId = $response->json('id');

        foreach (['section' => 'Consumables', 'note' => 'Delivered'] as $type => $text) {
            $line = \App\Models\InvoiceLine::where('invoice_id', $invoiceId)->where('line_type', $type)->firstOrFail();
            $this->assertNotNull($line->description_html, "{$type} row lost its rich text on create");
            $this->assertStringContainsString("<strong>{$text}</strong>", $line->description_html);
        }

        $show = $this->actingAs($user)->getJson("/api/v1/invoices/{$invoiceId}");
        $show->assertOk();
        $lines = collect($show->json('lines') ?? $show->json('data.lines'));
        $section = $lines->firstWhere('line_type', 'section');
        $note = $lines->firstWhere('line_type', 'note');
        $this->assertStringContainsString('<strong>Consumables</strong>', (string) ($section['description_html'] ?? ''));
        $this->assertStringContainsString('<strong>Delivered</strong>', (string) ($note['description_html'] ?? ''));
    }

    /** #1005: a note/section row posted without quantity/unit_price is saved as a zero-value row, not a 500. */
    public function test_note_and_section_rows_without_quantity_or_price_are_accepted(): void
    {
        [$user, $customer, $product] = $this->bootTextRowTenant('66666666-6666-6666-6666-666666666602', 'admin-text-noqty@test.local');

        $response = $this->actingAs($user)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => '2026-04-19',
            'lines' => [
                ['line_type' => 'section', 'description' => 'Equipment'],
                ['product_id' => $product->id, 'description' => 'Item', 'quantity' => 2, 'unit_price' => 25, 'tax_rate' => 0],
                ['line_type' => 'note', 'description' => 'Handle with care'],
            ],
        ]);

        $response->assertCreated();
        $invoiceId = $response->json('id');

        $note = \App\Models\InvoiceLine::where('invoice_id', $invoiceId)->where('line_type', 'note')->firstOrFail();
        $this->assertSame('Handle with care', $note->description);
        $this->assertEquals(0, (float) $note->line_total);

        $section = \App\Models\InvoiceLine::where('invoice_id', $invoiceId)->where('line_type', 'section')->firstOrFail();
        $this->assertSame('Equipment', $section->description);
        $this->assertEquals(0, (float) $section->line_total);
    }
}
