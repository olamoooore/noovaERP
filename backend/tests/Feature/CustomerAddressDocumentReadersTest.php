<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Tests\TenantTestCase;

/**
 * ADR-003B W2b: every document reader uses the one customer address rule
 * (CustomerAddressResolver, rule R4) instead of reading the older free-text
 * `customers.billing_address` / `shipping_address` columns directly.
 *
 * The customer below has its addresses saved the current way (structured
 * `customer_addresses` rows) and NO older free-text address. Before W2b the
 * printed quotation's "Bill To" (`customer.billing_address`, used by the
 * seeded ADR-004 reference template), a sales order / credit note without
 * an address snapshot, the customer statement for a non-default billing
 * address, and a delivery-order import all came out blank.
 *
 * Every test goes through the real HTTP routes.
 */
class CustomerAddressDocumentReadersTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->mockFeatureGate();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Address Readers Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'Acme Hospital',
            'code' => 'ACME-'.Str::random(5),
            'billing_address' => null,
            'shipping_address' => null,
        ]);
        $this->customer->addresses()->create([
            'type' => 'billing', 'street' => '1 Finance Road', 'city' => 'Kuala Lumpur', 'zip' => '50000', 'country' => 'MY', 'is_default' => true,
        ]);
        $this->customer->addresses()->create([
            'type' => 'shipping', 'street' => '22 Warehouse Way', 'city' => 'Shah Alam', 'zip' => '40000', 'country' => 'MY', 'is_default' => true,
        ]);
    }

    public function test_printed_quotation_bill_to_uses_the_customers_saved_address(): void
    {
        $quotation = Quotation::create([
            'quotation_number' => 'QT-W2B-'.Str::random(5),
            'quotation_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);
        $this->publishedTemplate('quotation', '<div>BILLTO[{{ customer.billing_address }}] DOC[{{ invoice.billing_address }}] SHIP[{{ invoice.shipping_address }}]</div>');

        $html = $this->actingAs($this->admin)->get("/api/v1/invoices/{$quotation->id}/html?as=quotation")->assertOk()->getContent();

        $this->assertSectionContains('BILLTO', '1 Finance Road', $html);
        $this->assertSectionContains('DOC', '1 Finance Road', $html);
        $this->assertSectionContains('SHIP', '22 Warehouse Way', $html);
    }

    public function test_printed_sales_order_without_snapshot_uses_the_customers_saved_address(): void
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
            'currency_code' => 'MYR',
            'created_by' => $this->admin->id,
        ]);
        $this->publishedTemplate('sales_order', '<div>BILLTO[{{ customer.billing_address }}] DOC[{{ sales_order.billing_address }}] SHIP[{{ sales_order.shipping_address }}]</div>');

        $html = $this->actingAs($this->admin)->get("/api/v1/sales/orders/{$order->id}/html")->assertOk()->getContent();

        $this->assertSectionContains('BILLTO', '1 Finance Road', $html);
        $this->assertSectionContains('DOC', '1 Finance Road', $html);
        $this->assertSectionContains('SHIP', '22 Warehouse Way', $html);
    }

    public function test_printed_credit_note_uses_the_customers_saved_billing_address(): void
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100,
            'paid_amount' => 0,
            'balance' => 100,
            'billing_address_snapshot' => null,
            'created_by' => $this->admin->id,
        ]);
        $creditNote = \App\Models\CustomerCreditNote::create([
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'created_by' => $this->admin->id,
            'reason' => 'Damaged goods',
        ]);
        $this->publishedTemplate('credit_note', '<div>BILLTO[{{ credit_note.billing_address }}]</div>', 'ar_credit_note');

        $html = $this->actingAs($this->admin)->get("/api/v1/credit-notes/{$creditNote->id}/html")->assertOk()->getContent();

        $this->assertSectionContains('BILLTO', '1 Finance Road', $html);
    }

    public function test_statement_uses_the_customers_saved_billing_address_even_when_not_default(): void
    {
        $this->customer->addresses()->where('type', 'billing')->update(['is_default' => false]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/accounting/customers/{$this->customer->id}/statement?format=json")
            ->assertOk();

        $this->assertStringContainsString('1 Finance Road', (string) $response->json('customer.billing_address'));
    }

    public function test_delivery_order_import_fills_the_customers_saved_delivery_address(): void
    {
        $number = 'DO-W2B-'.Str::random(5);
        $csv = "delivery_order_number,customer_code,delivery_date,description,quantity\n"
            ."{$number},{$this->customer->code},".now()->toDateString().",Widget,1\n";

        $this->actingAs($this->admin)->post('/api/v1/sales/delivery-orders/import', [
            'file' => UploadedFile::fake()->createWithContent('delivery-orders.csv', $csv),
        ])->assertSuccessful();

        $order = DeliveryOrder::where('delivery_order_number', $number)->firstOrFail();
        $this->assertStringContainsString('22 Warehouse Way', (string) $order->shipping_address);
    }

    private function assertSectionContains(string $section, string $needle, string $html): void
    {
        $this->assertSame(1, preg_match('/'.$section.'\[(.*?)\]/s', $html, $match), "Section {$section} missing from the rendered document.");
        $this->assertStringContainsString($needle, $match[1], "{$section} must show the customer's saved address.");
    }

    private function publishedTemplate(string $documentType, string $html, ?string $canonicalType = null): void
    {
        $template = DocumentTemplate::create([
            'document_type' => $documentType,
            'name' => 'W2b '.$documentType.' Template',
            'mode' => DocumentTemplate::MODE_CODE,
            'html' => $html,
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'published_version' => 1,
        ]);
        $template->versions()->create([
            'template_id' => $template->id,
            'version' => 1,
            'html' => $template->html,
            'is_published' => true,
        ]);
        DocumentTemplateAssignment::create([
            'document_type' => $documentType,
            'template_type_code' => $canonicalType,
            'template_id' => $template->id,
            'scope_type' => 'tenant',
            'scope_value' => null,
            'priority' => 10,
            'is_active' => true,
        ]);
    }

    private function mockFeatureGate(): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')->andReturnUsing(fn (string $tenantId, string $key) => [
            'key' => $key, 'is_entitled' => true, 'source' => 'tier_included', 'name' => strtoupper($key),
        ]);
        $mock->shouldReceive('all')->andReturn([]);
        $mock->shouldReceive('invalidate')->andReturnNull();
        app()->instance(FeatureGateService::class, $mock);
    }
}
