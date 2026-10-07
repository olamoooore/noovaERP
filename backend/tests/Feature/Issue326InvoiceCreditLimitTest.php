<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #326 ([SALES-ODOO] credit-limit check on invoices).
 *
 * Business rules (acceptance criteria):
 *  - Invoice create (POST /invoices) and post (POST /invoices/{id}/post) run
 *    the SAME credit check as sales-order confirm (CreditCheckService), and
 *    warn or block according to the tenant policy
 *    (sales.invoice.credit_limit_policy: block | warn).
 *  - Exposure is measured in base currency: open AR (base equivalent) +
 *    the invoice's base total must not exceed the credit limit.
 *  - The verdict is shown in the customer context strip
 *    (GET /customers/{id}/context) and in the D7 pre-flight checklist.
 *
 * Router-level: every assertion goes through the real HTTP route.
 */
class Issue326InvoiceCreditLimitTest extends TenantTestCase
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
            'name' => 'Issue 326 Credit Tenant',
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
            'name' => 'Issue 326 Service',
            'sku' => 'I326-'.uniqid(),
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

    private function createInvoice(Customer $customer, float $unitPrice): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/invoices', [
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

    private function setPolicy(string $policy): void
    {
        Setting::setValue('sales.invoice.credit_limit_policy', $policy);
    }

    public function test_create_over_the_limit_is_blocked_with_a_422_and_no_invoice_by_default(): void
    {
        $customer = $this->customer(['credit_limit' => 100]);

        $response = $this->createInvoice($customer, 500);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'CREDIT_LIMIT_EXCEEDED')
            ->assertJsonPath('credit_check.approved', false)
            ->assertJsonPath('credit_check.policy', 'block');
        $this->assertSame(0, Invoice::query()->where('customer_id', $customer->id)->count(), 'A blocked invoice must not be created.');
    }

    public function test_create_over_the_limit_under_the_warn_policy_creates_the_invoice_and_returns_the_warning(): void
    {
        $this->setPolicy('warn');
        $customer = $this->customer(['credit_limit' => 100]);

        $response = $this->createInvoice($customer, 500);

        $response->assertCreated()
            ->assertJsonPath('credit_check.approved', false)
            ->assertJsonPath('credit_check.policy', 'warn');
        $this->assertSame('draft', Invoice::query()->findOrFail($response->json('id'))->status);
    }

    public function test_create_within_the_limit_passes_and_reports_an_approved_check(): void
    {
        $customer = $this->customer(['credit_limit' => 1000]);

        $this->createInvoice($customer, 500)
            ->assertCreated()
            ->assertJsonPath('credit_check.approved', true);
    }

    public function test_exposure_is_measured_in_base_currency(): void
    {
        $customer = $this->customer(['credit_limit' => 500]);

        // An open foreign-currency invoice: 100 EUR at 4.5 = 450 in base.
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'status' => 'posted',
            'currency_code' => 'EUR',
            'exchange_rate' => 4.5,
            'total' => 100,
            'paid_amount' => 0,
            'balance' => 100,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'requires_approval' => false,
        ]);
        $customer->updateOutstanding();
        $this->assertSame(100.0, (float) $customer->fresh()->total_outstanding, 'total_outstanding is the raw document-currency sum.');

        // 450 base AR + 100 base invoice = 550 > 500. Judged on the raw
        // total_outstanding (100 + 100 = 200) it would wrongly pass.
        $response = $this->createInvoice($customer, 100);

        $response->assertStatus(422)->assertJsonPath('code', 'CREDIT_LIMIT_EXCEEDED');
        $this->assertEqualsWithDelta(450.0, (float) $response->json('credit_check.current_balance'), 0.01);
    }

    public function test_post_runs_the_same_check_and_blocks_under_the_block_policy(): void
    {
        $customer = $this->customer(['credit_limit' => 1000]);
        $invoiceId = (int) $this->createInvoice($customer, 500)->assertCreated()->json('id');

        // The limit is cut after the draft was entered.
        $customer->update(['credit_limit' => 100]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoiceId}/post")
            ->assertStatus(422)
            ->assertJsonPath('code', 'CREDIT_LIMIT_EXCEEDED')
            ->assertJsonPath('credit_check.approved', false);

        $this->assertSame('draft', Invoice::query()->findOrFail($invoiceId)->status, 'A blocked invoice must stay draft.');
    }

    public function test_post_under_the_warn_policy_posts_and_returns_the_warning(): void
    {
        $this->setPolicy('warn');
        $customer = $this->customer(['credit_limit' => 1000]);
        $invoiceId = (int) $this->createInvoice($customer, 500)->assertCreated()->json('id');
        $customer->update(['credit_limit' => 100]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoiceId}/post")
            ->assertOk()
            ->assertJsonPath('credit_check.approved', false)
            ->assertJsonPath('credit_check.policy', 'warn');

        $this->assertSame('posted', Invoice::query()->findOrFail($invoiceId)->status);
    }

    public function test_the_customer_context_strip_reports_the_credit_verdict(): void
    {
        $customer = $this->customer(['credit_limit' => null, 'on_hold' => true, 'hold_reason' => 'Bounced cheque']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/customers/{$customer->id}/context")
            ->assertOk()
            ->assertJsonPath('credit_check.approved', false)
            ->assertJsonPath('credit_check.policy', 'block');

        $this->assertStringContainsString('on hold', (string) $response->json('credit_check.reason'));
    }

    public function test_the_preflight_credit_check_is_the_same_verdict_and_follows_the_policy(): void
    {
        // On hold with no limit: the old preflight-only rule ("no limit set")
        // passed this customer; CreditCheckService refuses it.
        $customer = $this->customer(['credit_limit' => null, 'on_hold' => true]);
        $this->setPolicy('warn');
        $invoiceId = (int) $this->createInvoice($customer, 50)->assertCreated()->json('id');

        $checks = fn () => collect($this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoiceId}/preflight")
            ->assertOk()
            ->json('checks'))->keyBy('key');

        $this->assertSame('warn', $checks()['credit']['status'], 'Warn policy: an over-limit customer warns.');

        $this->setPolicy('block');
        $this->assertSame('fail', $checks()['credit']['status'], 'Block policy: an over-limit customer fails the checklist.');
    }
}
