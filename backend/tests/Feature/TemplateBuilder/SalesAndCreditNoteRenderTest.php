<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Modules\TemplateBuilder\Models\GeneratedDocument;
use Tests\TenantTestCase;

/**
 * Issue #615 (TP5) — sales order / order confirmation / pro forma invoice and
 * credit note were registered ADR-004 template types with no data source, so a
 * template could be designed but never printed (#405, #531). These are
 * router-level tests: the render endpoints are exercised through the real
 * router → middleware → controller → data source → renderer path.
 */
class SalesAndCreditNoteRenderTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    private ?Invoice $invoice = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Sales Render Tenant',
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

        $this->customer = Customer::factory()->create(['name' => 'Render Customer']);
        $this->product = Product::factory()->create([
            'track_inventory' => false,
            'name' => 'Render Widget',
            'price' => 100,
            'cost' => 60,
        ]);
    }

    public function test_sales_order_pdf_renders_through_the_render_route(): void
    {
        $order = $this->salesOrder();
        $this->publishedTemplate('sales_order', '<div>SO {{ sales_order.number }}</div>');

        $response = $this->actingAs($this->admin)->get("/api/v1/sales/orders/{$order->id}/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_sales_order_html_renders_the_sales_order_number(): void
    {
        $order = $this->salesOrder();
        $this->publishedTemplate('sales_order', '<div>SALES ORDER {{ sales_order.number }}</div>');

        $response = $this->actingAs($this->admin)->get("/api/v1/sales/orders/{$order->id}/html");

        $response->assertOk();
        $this->assertStringContainsString('SALES ORDER', $response->getContent());
        $this->assertStringContainsString($order->sales_order_number, $response->getContent());
    }

    public function test_order_confirmation_is_rendered_by_its_own_data_source_and_route(): void
    {
        $order = $this->salesOrder();
        // The confirmation has its own registered type/template; it renders the
        // same sales-order record through the shared source implementation.
        $this->publishedTemplate('sales_order_confirmation', '<div>ORDER CONFIRMATION {{ sales_order.number }}</div>');

        $response = $this->actingAs($this->admin)->get("/api/v1/sales/orders/{$order->id}/confirmation/html");

        $response->assertOk();
        $this->assertStringContainsString('ORDER CONFIRMATION', $response->getContent());
        $this->assertStringContainsString($order->sales_order_number, $response->getContent());
    }

    public function test_proforma_invoice_is_rendered_by_its_own_data_source_and_route(): void
    {
        $order = $this->salesOrder();
        $this->publishedTemplate('sales_proforma_invoice', '<div>PRO FORMA INVOICE {{ sales_order.number }}</div>');

        $response = $this->actingAs($this->admin)->get("/api/v1/sales/orders/{$order->id}/proforma/html");

        $response->assertOk();
        $this->assertStringContainsString('PRO FORMA INVOICE', $response->getContent());
        $this->assertStringContainsString($order->sales_order_number, $response->getContent());
    }

    public function test_credit_note_pdf_renders_through_the_render_route(): void
    {
        $creditNote = $this->creditNote();
        $this->publishedTemplate('credit_note', '<div>CREDIT NOTE {{ credit_note.number }}</div>', 'ar_credit_note');

        $response = $this->actingAs($this->admin)->get("/api/v1/credit-notes/{$creditNote->id}/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_credit_note_html_renders_the_credit_note_number_and_original_invoice(): void
    {
        $creditNote = $this->creditNote();
        $this->publishedTemplate(
            'credit_note',
            '<div>CREDIT NOTE {{ credit_note.number }} AGAINST {{ credit_note.invoice_number }}</div>',
            'ar_credit_note',
        );

        $response = $this->actingAs($this->admin)->get("/api/v1/credit-notes/{$creditNote->id}/html");

        $response->assertOk();
        $this->assertStringContainsString($creditNote->credit_note_number, $response->getContent());
        $this->assertStringContainsString((string) $this->invoice?->invoice_number, $response->getContent());
    }

    public function test_credit_note_render_matches_an_assignment_registered_under_the_canonical_key_only(): void
    {
        $creditNote = $this->creditNote();
        $template = $this->publishedTemplate('credit_note', '<div>CANONICAL {{ credit_note.number }}</div>');

        // An ADR-004-era assignment may only carry the canonical
        // `ar_credit_note` code (the runtime data-source key is `credit_note`).
        // The single key map must resolve the pair in both directions.
        DocumentTemplateAssignment::where('template_id', $template->id)->update([
            'document_type' => 'ar_credit_note',
            'template_type_code' => 'ar_credit_note',
        ]);

        $response = $this->actingAs($this->admin)->get("/api/v1/credit-notes/{$creditNote->id}/html");

        $response->assertOk();
        $this->assertStringContainsString('CANONICAL', $response->getContent());
        $this->assertStringContainsString($creditNote->credit_note_number, $response->getContent());
    }

    public function test_cashier_cannot_render_a_sales_order(): void
    {
        $order = $this->salesOrder();
        $this->publishedTemplate('sales_order', '<div>SO {{ sales_order.number }}</div>');

        $response = $this->actingAs($this->cashier())->get("/api/v1/sales/orders/{$order->id}/pdf");

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'sales.view');
    }

    public function test_cashier_cannot_render_a_credit_note(): void
    {
        $creditNote = $this->creditNote();
        $this->publishedTemplate('credit_note', '<div>CN {{ credit_note.number }}</div>', 'ar_credit_note');

        $response = $this->actingAs($this->cashier())->get("/api/v1/credit-notes/{$creditNote->id}/pdf");

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'accounting.view');
    }

    public function test_posted_credit_note_snapshot_hook_is_active_and_served_by_the_render_route(): void
    {
        $this->seedCreditNotePostingFixtures();
        $this->publishedTemplate('credit_note', '<div>ISSUED {{ credit_note.number }}</div>', 'ar_credit_note');

        $created = $this->actingAs($this->admin)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $this->invoice?->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'Snapshot hook activation',
            'lines' => [[
                'description' => 'Snapshot credit line',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
            ]],
        ]);
        $created->assertCreated();
        $creditNoteId = (int) $created->json('id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/post")
            ->assertOk();

        // #613's CreditNotePosted hook was wired but inert while no
        // 'credit_note' data source existed. Now that #615 registers one, the
        // posted note must be stored as an immutable snapshot.
        $snapshot = GeneratedDocument::query()
            ->where('document_type', 'credit_note')
            ->where('document_id', (string) $creditNoteId)
            ->where('is_immutable', true)
            ->whereNull('superseded_at')
            ->firstOrFail();

        $download = $this->actingAs($this->admin)->get("/api/v1/credit-notes/{$creditNoteId}/pdf");
        $download->assertOk();
        $this->assertSame(hash('sha256', $download->getContent()), $snapshot->checksum);
    }

    private function cashier(): User
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');

        DB::table('user_company_access')->insert([
            'user_id' => $cashier->id,
            'tenant_id' => $this->tenantId,
            'role' => 'cashier',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $cashier;
    }

    private function salesOrder(): SalesOrder
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
            'required_date' => now()->addDays(7)->toDateString(),
            'currency_code' => 'MYR',
            'created_by' => $this->admin->id,
            'customer_reference' => 'PO-REF-615',
            'attention_to' => 'Warehouse Manager',
            'notes' => 'Deliver to the loading bay.',
        ]);

        $order->lines()->create([
            'product_id' => $this->product->id,
            'description' => 'Render Widget',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);

        $order->calculateTotals();

        return $order->fresh(['lines', 'customer']);
    }

    private function invoice(): Invoice
    {
        return $this->invoice ??= Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 1000,
            'paid_amount' => 0,
            'balance' => 1000,
            'created_by' => $this->admin->id,
        ]);
    }

    private function creditNote(): \App\Models\CustomerCreditNote
    {
        $creditNote = \App\Models\CustomerCreditNote::create([
            'customer_id' => $this->customer->id,
            'invoice_id' => $this->invoice()->id,
            'customer_id' => $this->customer->id,
            'invoice_id' => $this->invoice->id,
            'credit_note_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'created_by' => $this->admin->id,
            'reason' => 'Damaged goods',
        ]);

        $creditNote->lines()->create([
            'description' => 'Damaged goods credit',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);

        return $creditNote->fresh(['lines', 'customer', 'invoice']);
    }

    private function publishedTemplate(string $documentType, string $html, ?string $canonicalType = null): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'document_type' => $documentType,
            'name' => 'Render '.$documentType.' Template',
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

        return $template;
    }

    /**
     * GL fixtures a posted credit note needs (mirrors CreditNoteInvoiceBalanceTest).
     */
    private function seedCreditNotePostingFixtures(): void
    {
        $this->invoice();

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-615', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-615', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-615', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
    }
}
