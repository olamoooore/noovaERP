<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\InvoiceActivityLog;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #327 ([SALES-ODOO] duplicate-invoice warning, likely vs possible).
 *
 * Business rules (acceptance criteria):
 *  - On create, the same customer already having a live invoice with the
 *    same customer reference or PO number is a LIKELY duplicate: creation
 *    is refused until the user confirms (confirm_duplicate = true), and the
 *    confirmation is recorded.
 *  - The same customer having an invoice for the same total (same
 *    currency) within 7 days is a POSSIBLE duplicate: creation goes through
 *    with a warning.
 *  - Both are shown in the D7 pre-flight checklist (likely = fail unless
 *    confirmed, possible = warn).
 *
 * Router-level: every assertion goes through the real HTTP route.
 */
class Issue327DuplicateInvoiceWarningTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantId = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantId,
            'name' => 'Issue 327 Duplicate Tenant',
            'email' => $this->tenantId.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        FiscalPeriod::firstOrCreate(
            ['code' => now()->format('Y-m')],
            [
                'name' => now()->format('F Y'),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->admin->id,
            ]
        );

        foreach ([
            'cash' => '1010',
            'bank' => '1020',
            'accounts_receivable' => '1100',
            'sales_tax_payable' => '2200',
            'sales_revenue' => '4100',
        ] as $mappingKey => $accountCode) {
            if (DB::table('account_mappings')->where('mapping_key', $mappingKey)->whereNull('document_type')->exists()) {
                continue;
            }

            DB::table('account_mappings')->insert([
                'mapping_key' => $mappingKey,
                'account_id' => DB::table('chart_of_accounts')->where('code', $accountCode)->value('id'),
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->product = Product::factory()->create([
            'name' => 'Issue 327 Service',
            'sku' => 'I327-'.uniqid(),
            'price' => 100,
            'cost' => 10,
            'track_inventory' => false,
        ]);
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'legal_entity_id' => null,
            'payment_terms' => 30,
            'credit_limit' => null,
            'total_outstanding' => 0,
        ], $attributes));
    }

    private function createInvoice(Customer $customer, float $unitPrice, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/invoices', $extra + [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
    }

    private function existing(Customer $customer, array $attributes = []): Invoice
    {
        $invoice = Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'status' => 'posted',
            'currency_code' => null,
            'exchange_rate' => 1,
            'total' => 999,
            'paid_amount' => 0,
            'balance' => 999,
            'invoice_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'requires_approval' => false,
            'customer_reference' => null,
            'po_number' => null,
        ], $attributes));

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'description' => 'Earlier service',
            'quantity' => 1,
            'unit_price' => (float) $invoice->total,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
            'line_total' => (float) $invoice->total,
            'tax_amount' => 0,
        ]);

        return $invoice;
    }

    private function preflightDuplicateRow(int $invoiceId): array
    {
        return collect($this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoiceId}/preflight")
            ->assertOk()
            ->json('checks'))->keyBy('key')['duplicate'];
    }

    public function test_same_customer_reference_is_a_likely_duplicate_and_needs_confirmation(): void
    {
        $customer = $this->customer();
        $earlier = $this->existing($customer, ['customer_reference' => 'REF-7781']);

        // Different total, two months apart: only the reference matches
        // (case and surrounding spaces ignored).
        $response = $this->createInvoice($customer, 120, ['customer_reference' => ' ref-7781 ']);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_INVOICE_LIKELY')
            ->assertJsonPath('duplicate_check.level', 'likely')
            ->assertJsonPath('duplicate_check.matches.0.invoice_number', $earlier->invoice_number)
            ->assertJsonPath('duplicate_check.matches.0.level', 'likely');
        $this->assertContains('customer_reference', $response->json('duplicate_check.matches.0.matched_on'));
        $this->assertSame(1, Invoice::query()->where('customer_id', $customer->id)->count(), 'An unconfirmed likely duplicate must not be created.');
    }

    public function test_same_po_number_is_a_likely_duplicate_and_needs_confirmation(): void
    {
        $customer = $this->customer();
        $earlier = $this->existing($customer, ['po_number' => 'PO-5520']);

        // Different total, two months apart, no customer reference: only the
        // PO number matches (case and surrounding spaces ignored).
        $response = $this->createInvoice($customer, 120, ['po_number' => ' po-5520 ']);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_INVOICE_LIKELY')
            ->assertJsonPath('duplicate_check.level', 'likely')
            ->assertJsonPath('duplicate_check.matches.0.invoice_number', $earlier->invoice_number)
            ->assertJsonPath('duplicate_check.matches.0.matched_on', ['po_number']);
        $this->assertSame(1, Invoice::query()->where('customer_id', $customer->id)->count(), 'An unconfirmed likely duplicate must not be created.');
    }

    public function test_a_confirmed_likely_duplicate_is_created_and_the_confirmation_is_recorded(): void
    {
        $customer = $this->customer();
        $earlier = $this->existing($customer, ['po_number' => 'PO-4410']);

        $response = $this->createInvoice($customer, 120, ['po_number' => 'PO-4410', 'confirm_duplicate' => true]);

        $response->assertCreated()->assertJsonPath('duplicate_check.level', 'likely');
        $this->assertContains('po_number', $response->json('duplicate_check.matches.0.matched_on'));

        $invoiceId = (int) $response->json('id');
        $created = InvoiceActivityLog::query()
            ->where('invoice_id', $invoiceId)
            ->where('event', InvoiceActivityLog::EVENT_CREATED)
            ->firstOrFail();
        $this->assertTrue((bool) ($created->metadata['duplicate_confirmed'] ?? false));
        $this->assertSame([(int) $earlier->id], array_map('intval', $created->metadata['duplicate_of'] ?? []));

        $row = $this->preflightDuplicateRow($invoiceId);
        $this->assertSame('warn', $row['status'], 'A confirmed likely duplicate warns instead of failing.');
        $this->assertStringContainsString($earlier->invoice_number, $row['detail']);
    }

    public function test_same_total_within_seven_days_is_a_possible_duplicate_that_warns(): void
    {
        $customer = $this->customer();
        $earlier = $this->existing($customer, [
            'total' => 120,
            'balance' => 120,
            'invoice_date' => now()->subDays(3)->toDateString(),
        ]);

        $response = $this->createInvoice($customer, 120);

        $response->assertCreated()
            ->assertJsonPath('duplicate_check.level', 'possible')
            ->assertJsonPath('duplicate_check.matches.0.invoice_number', $earlier->invoice_number)
            ->assertJsonPath('duplicate_check.matches.0.level', 'possible');
    }

    public function test_same_total_outside_the_window_or_another_customer_is_not_a_duplicate(): void
    {
        $customer = $this->customer();
        $this->existing($customer, ['total' => 120, 'balance' => 120, 'invoice_date' => now()->subDays(30)->toDateString()]);
        $this->existing($this->customer(), ['customer_reference' => 'REF-1', 'total' => 120, 'balance' => 120, 'invoice_date' => now()->toDateString()]);

        $this->createInvoice($customer, 120, ['customer_reference' => 'REF-1'])
            ->assertCreated()
            ->assertJsonPath('duplicate_check.level', 'none');
    }

    public function test_a_voided_invoice_is_not_a_duplicate(): void
    {
        $customer = $this->customer();
        $this->existing($customer, ['customer_reference' => 'REF-9', 'lifecycle_status' => 'voided']);

        $this->createInvoice($customer, 120, ['customer_reference' => 'REF-9'])
            ->assertCreated()
            ->assertJsonPath('duplicate_check.level', 'none');
    }

    public function test_preflight_fails_an_unconfirmed_likely_duplicate(): void
    {
        $customer = $this->customer();
        $earlier = $this->existing($customer, ['po_number' => 'PO-77']);
        $draft = $this->existing($customer, [
            'status' => 'draft',
            'po_number' => 'PO-77',
            'total' => 50,
            'balance' => 50,
            'invoice_date' => now()->toDateString(),
        ]);

        $row = $this->preflightDuplicateRow((int) $draft->id);

        $this->assertSame('fail', $row['status'], 'An unconfirmed likely duplicate fails the checklist.');
        $this->assertStringContainsString($earlier->invoice_number, $row['detail']);
    }
}
