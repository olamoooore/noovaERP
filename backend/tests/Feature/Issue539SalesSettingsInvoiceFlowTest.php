<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerEmail;
use App\Models\ApprovalRequest;
use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Tests\TenantTestCase;

/**
 * Issue #539 (ST2): invoice-level Sales Settings change live behaviour —
 * approval expiry, fallback payment terms, default notes/terms, partial
 * payments and auto-send on post — through the real HTTP routes.
 */
class Issue539SalesSettingsInvoiceFlowTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $serviceProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $this->admin = User::factory()->create(['email' => 'i539-'.uniqid().'@example.local']);
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        CompanySettings::query()->create([
            'company_name' => 'Issue 539 Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'I539 Group',
            'code' => 'I539-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legalEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'I539 Entity',
            'code' => 'I539-E-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'legal_entity_id' => $legalEntityId,
            'payment_terms' => 10,
            'email' => 'buyer@customer.example.test',
        ]);

        $receivable = ChartOfAccount::factory()->create([
            'code' => 'I539-1240', 'name' => 'I539 AR', 'type' => 'asset', 'is_active' => true,
        ]);
        $revenue = ChartOfAccount::factory()->create([
            'code' => 'I539-4100', 'name' => 'I539 Revenue', 'type' => 'revenue', 'is_active' => true,
        ]);

        foreach (['accounts_receivable' => $receivable->id, 'sales_revenue' => $revenue->id] as $key => $accountId) {
            DB::table('account_mappings')->where('mapping_key', $key)->delete();
            DB::table('account_mappings')->insert([
                'mapping_key' => $key,
                'account_id' => $accountId,
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->serviceProduct = Product::factory()->create([
            'name' => 'I539 Consulting',
            'sku' => 'I539-SRV-'.Str::upper(Str::random(5)),
            'price' => 100,
            'cost' => 0,
            'track_inventory' => false,
            'income_account_id' => $revenue->id,
        ]);

        DB::table('currencies')->updateOrInsert(
            ['code' => 'MYR'],
            ['name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimal_places' => 2, 'is_active' => true, 'is_sellable' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        app(DocumentTemplateServiceContract::class)->seedDefaults();
    }

    private function createInvoice(array $overrides = []): Invoice
    {
        $payload = array_merge([
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->serviceProduct->id,
                'description' => 'Consulting',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ], $overrides);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', $payload);
        $response->assertCreated($response->getContent());

        return Invoice::with('lines')->findOrFail($response->json('id'));
    }

    // ── sales.invoice.approval_expiry_days ───────────────────────────────

    public function test_invoice_approval_request_expires_after_the_configured_days(): void
    {
        Setting::setValue('sales.invoice.approval_expiry_days', 3);

        $invoice = $this->createInvoice();
        $invoice->update(['requires_approval' => true]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/submit-approval", ['reason' => 'Review'])
            ->assertOk();

        $request = ApprovalRequest::findOrFail($invoice->fresh()->approval_request_id);

        $this->assertEqualsWithDelta(
            now()->addDays(3)->getTimestamp(),
            $request->expires_at->getTimestamp(),
            120,
            'The approval request must expire after the configured 3 days, not the hard-coded 7.'
        );
    }

    // ── sales.invoice.default_payment_terms_days ─────────────────────────

    public function test_due_date_falls_back_to_the_tenant_terms_when_the_customer_has_none(): void
    {
        Setting::setValue('sales.invoice.default_payment_terms_days', 45);

        // A customer created without payment terms (customers.payment_terms is
        // NOT NULL, so "no terms" can only mean "none given at creation").
        $created = $this->actingAs($this->admin)->postJson('/api/v1/customers', [
            'name' => 'I539 No-Terms Customer',
            'email' => 'no-terms@customer.example.test',
        ]);
        $created->assertCreated($created->getContent());
        $customerId = (int) ($created->json('data.id') ?? $created->json('id'));
        DB::table('customers')->where('id', $customerId)->update(['legal_entity_id' => $this->customer->legal_entity_id]);

        $this->assertSame(45, (int) DB::table('customers')->where('id', $customerId)->value('payment_terms'),
            'A customer saved without terms must take the tenant default (45), not the hard-coded 30.');

        $invoice = $this->createInvoice(['customer_id' => $customerId]);

        $this->assertSame(
            now()->addDays(45)->toDateString(),
            $invoice->due_date->toDateString(),
            'The invoice due date must follow the tenant default terms, not the hard-coded 30.'
        );
    }

    public function test_customer_payment_terms_still_beat_the_tenant_default(): void
    {
        Setting::setValue('sales.invoice.default_payment_terms_days', 45);

        $invoice = $this->createInvoice();

        $this->assertSame(now()->addDays(10)->toDateString(), $invoice->due_date->toDateString());
    }

    // ── sales.invoice.default_notes / default_terms ──────────────────────

    public function test_default_notes_and_terms_are_applied_server_side_when_omitted(): void
    {
        Setting::setValue('sales.invoice.default_notes', 'Thank you for your business.');
        Setting::setValue('sales.invoice.default_terms', 'Net 10. Late payments incur 1.5% monthly.');

        $invoice = $this->createInvoice();

        $this->assertSame('Net 10. Late payments incur 1.5% monthly.', $invoice->terms);
        $this->assertStringContainsString('Thank you for your business.', (string) $invoice->notes);
    }

    public function test_explicit_notes_and_terms_win_over_the_defaults(): void
    {
        Setting::setValue('sales.invoice.default_notes', 'Thank you for your business.');
        Setting::setValue('sales.invoice.default_terms', 'Net 10.');

        $invoice = $this->createInvoice(['notes' => 'Deliver to gate 3', 'terms' => 'COD']);

        $this->assertSame('Deliver to gate 3', $invoice->notes);
        $this->assertSame('COD', $invoice->terms);
    }

    // ── sales.invoice.allow_partial_payments ─────────────────────────────

    public function test_partial_payment_is_rejected_when_the_setting_is_off(): void
    {
        Setting::setValue('sales.invoice.allow_partial_payments', false);

        $invoice = $this->postedInvoice();

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 40,
            'method' => 'cash',
        ])->assertStatus(422)->assertJsonPath('code', 'PARTIAL_PAYMENT_NOT_ALLOWED');

        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => (float) $invoice->balance,
            'method' => 'cash',
        ])->assertCreated();
    }

    public function test_partial_payment_is_accepted_by_default(): void
    {
        $invoice = $this->postedInvoice();

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 40,
            'method' => 'cash',
        ])->assertCreated();
    }

    // ── sales.invoice.auto_send_to_customer_on_post ──────────────────────

    public function test_posting_does_not_email_the_customer_when_auto_send_is_off(): void
    {
        Mail::fake();
        Setting::setValue('sales.invoice.auto_send_to_customer_on_post', false);

        $invoice = $this->createInvoice();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        $this->assertSame('posted', $invoice->fresh()->status);
        Mail::assertNotSent(CustomerEmail::class);
        $this->assertSame(0, InvoiceDelivery::where('invoice_id', $invoice->id)->count());
    }

    public function test_posting_emails_the_customer_when_auto_send_is_on(): void
    {
        Mail::fake();
        Setting::setValue('sales.invoice.auto_send_to_customer_on_post', true);

        $invoice = $this->createInvoice();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        Mail::assertSent(CustomerEmail::class, fn (CustomerEmail $mail) => $mail->hasTo('buyer@customer.example.test'));
        $this->assertSame(1, InvoiceDelivery::where('invoice_id', $invoice->id)->count());
    }

    private function postedInvoice(): Invoice
    {
        Mail::fake();
        Setting::setValue('sales.invoice.auto_send_to_customer_on_post', false);

        $invoice = $this->createInvoice();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        $invoice = $invoice->fresh();
        $this->assertSame('posted', $invoice->status);
        $this->assertGreaterThan(40, (float) $invoice->balance);

        return $invoice;
    }
}
