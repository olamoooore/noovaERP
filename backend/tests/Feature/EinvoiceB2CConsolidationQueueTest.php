<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\ComplianceSubmission;
use App\Models\Accounting\CountryConfiguration;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #815 (EP4) AC1 — POS/storefront B2C receipts (no buyer TIN) were
 * submitted one-by-one as individual "consolidated" invoices; LHDN wants them
 * reported in ONE monthly consolidated e-invoice.
 *
 * Contract asserted here:
 *  - with the per-entity setting on, a posted receipt whose buyer is an
 *    individual without a TIN is held on its compliance document as
 *    queued_for_consolidation and no provider call is made (nothing is
 *    submitted individually);
 *  - with the setting off (default), receipts behave exactly as before —
 *    submitted individually;
 *  - a business buyer without a TIN is #664's error case, not a receipt: it
 *    must never be silently swallowed into the consolidation batch.
 */
class EinvoiceB2CConsolidationQueueTest extends TenantTestCase
{
    private User $user;

    private string $tenantId;

    private array $httpContainer = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Compliance auto-submit runs post-commit; a sync-queue BI job would
        // tear the tenancy context down mid-test (JournalEntryObserver trap).
        // #667: the auto-submit itself is a queued job now — let it run as
        // the (sync) queue would; everything else stays faked.
        Queue::fake()->except([\App\Jobs\SubmitEInvoiceJob::class]);
        Mail::fake();

        // Re-enable the tenant middleware the base case disables: RequirePermission
        // resolves the central role, and the compliance listener needs tenancy.
        $this->withMiddleware(
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
        );

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->assertTenantSchemaReady([
            'invoices',
            'invoice_lines',
            'customers',
            'chart_of_accounts',
            'account_mappings',
            'journal_entries',
            'acct_compliance_documents',
            'acct_compliance_submissions',
            'fiscal_periods',
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole(\App\Enums\UserRole::ACCOUNTANT->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'accountant',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-B2C', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-B2C', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-B2C', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);

        DB::table('fiscal_periods')->insert([
            'name' => now()->format('F Y'),
            'code' => now()->format('Y-m'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'period_type' => 'monthly',
            'fiscal_year' => (int) now()->format('Y'),
            'is_locked' => false,
            'status' => 'open',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('company_settings')->insert([
            'company_name' => 'NoovaERP Test Sdn Bhd',
            'base_currency' => 'MYR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function configureMalaysia(bool $autoSubmit, bool $b2cConsolidation): void
    {
        $settings = ['auto_submit' => $autoSubmit, 'environment' => 'preprod'];
        if ($b2cConsolidation) {
            $settings['b2c_consolidation'] = true;
        }

        CountryConfiguration::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'provider_name' => 'myinvoice',
            'is_enabled' => true,
            'test_mode' => true,
            'api_credentials' => [
                'myinvoice_client_id' => 'test-client-id',
                'myinvoice_client_secret' => 'test-client-secret',
                'supplier_tin' => 'C1234567890',
                'supplier_registration_number' => '202301012345',
                'supplier_name' => 'NoovaERP Test Sdn Bhd',
            ],
            'settings' => $settings,
        ]);
    }

    private function fakeProvider(): void
    {
        $accepted = fn (): Response => new Response(200, [], json_encode([
            'submissionUID' => 'SUBMISSION-B2C',
            'acceptedDocuments' => [['uuid' => 'a1b2c3d4-0000-4000-8000-00000000b2c1', 'longId' => 'B2C-0001']],
        ]));

        $mock = new MockHandler([$accepted(), $accepted(), $accepted(), $accepted()]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->httpContainer));

        $this->app->instance(Client::class, new Client(['handler' => $stack]));
    }

    private function customer(string $type, ?string $taxNumber): Customer
    {
        return Customer::factory()->create([
            'name' => $type === 'individual' ? 'Walk-in Consumer' : 'Acme Sdn Bhd',
            'type' => $type,
            'tax_number' => $taxNumber,
        ]);
    }

    private function postInvoice(Customer $customer): int
    {
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'currency_code' => 'MYR',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->user->id,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'POS receipt',
            'quantity' => 1,
            'unit_price' => 25,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);

        $invoice->calculateTotals();

        $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        return (int) $invoice->id;
    }

    private function documentFor(int $invoiceId): ?ComplianceDocument
    {
        return ComplianceDocument::where('tenant_id', $this->tenantId)
            ->where('document_type', 'invoice')
            ->where('document_id', $invoiceId)
            ->first();
    }

    public function test_no_tin_b2c_receipts_are_held_for_consolidation_instead_of_submitted(): void
    {
        $this->fakeProvider();
        $this->configureMalaysia(autoSubmit: true, b2cConsolidation: true);

        $invoiceId = $this->postInvoice($this->customer('individual', null));

        $document = $this->documentFor($invoiceId);

        $this->assertNotNull($document, 'the posted receipt must still get its compliance document');
        $this->assertSame(
            'queued_for_consolidation',
            $document->compliance_status,
            'with the setting on, a no-TIN B2C receipt must be held for the monthly consolidated e-invoice'
        );
        $this->assertSame(
            0,
            ComplianceSubmission::where('document_id', $document->id)->count(),
            'a held receipt must not be submitted individually'
        );
        $this->assertEmpty($this->httpContainer, 'no provider call may happen for a held receipt');
    }

    public function test_receipts_still_submit_individually_when_the_setting_is_off(): void
    {
        $this->fakeProvider();
        $this->configureMalaysia(autoSubmit: true, b2cConsolidation: false);

        $invoiceId = $this->postInvoice($this->customer('individual', null));

        $document = $this->documentFor($invoiceId);
        $this->assertNotNull($document, 'the posted receipt must get its compliance document');
        $this->assertNotSame(
            'queued_for_consolidation',
            $document->compliance_status,
            'with the setting off (default) the existing individual submission behaviour must be preserved'
        );
        $this->assertSame(
            1,
            ComplianceSubmission::where('document_id', $document->id)->count(),
            'with the setting off the receipt is submitted individually as before'
        );
        $this->assertNotEmpty($this->httpContainer, 'the provider must have been called');
    }

    public function test_a_business_buyer_without_a_tin_is_never_silently_queued(): void
    {
        $this->fakeProvider();
        $this->configureMalaysia(autoSubmit: true, b2cConsolidation: true);

        $invoiceId = $this->postInvoice($this->customer('company', null));

        $document = $this->documentFor($invoiceId);

        $this->assertNotNull($document, 'the posted invoice must get its compliance document');
        $this->assertNotSame(
            'queued_for_consolidation',
            $document->compliance_status,
            'a business buyer without a TIN is #664\'s error case and must not disappear into the consolidation batch'
        );
        $this->assertSame(
            0,
            ComplianceSubmission::where('document_id', $document->id)->count(),
            'and it must not be submitted either (the no-TIN business buyer is rejected)'
        );
    }
}
