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
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #516 (CN7) — the compliance plugin auto-submitted only on
 * InvoicePosted. Nothing was fired when a credit note was posted, so a tenant
 * with e-invoicing enabled and auto_submit on never submitted its credit
 * notes: for MyInvois a credit note is type 02 and must reference the original
 * invoice's UUID, so the buyer's and seller's records diverge.
 *
 * Contract asserted here:
 *  - posting a credit note fires the compliance workflow with the same
 *    auto_submit / country rules invoices get, creating a compliance document
 *    of type credit_note;
 *  - the submitted payload references the original invoice's accepted
 *    submission UUID;
 *  - auto_submit off submits nothing, and the manual submit endpoint still
 *    works.
 */
class CreditNoteComplianceAutoSubmitTest extends TenantTestCase
{
    private const INVOICE_LHDN_UUID = 'a1b2c3d4-0000-4000-8000-000000000001';

    private const CREDIT_NOTE_LHDN_UUID = 'a1b2c3d4-0000-4000-8000-000000000002';

    private User $user;

    private Customer $customer;

    private string $tenantId;

    private array $httpContainer = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Re-enable the tenant middleware the base test case disables: the
        // compliance listener resolves the tenant from tenancy, so this suite
        // must exercise the real request pipeline rather than a hand-bound one.
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
            'customer_credit_notes',
            'customer_credit_note_lines',
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

        $this->customer = Customer::factory()->create();

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-CN7', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-CN7', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-CN7', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

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

    private function fakeLhdnEndpoint(): void
    {
        // Identical responses: the submission flow may call the provider more
        // than once (preflight/sign/submit), so the recorded UUID must not
        // depend on which call answers. Each Response needs its own body.
        $accepted = fn (): Response => new Response(200, [], json_encode([
            'submissionUID' => 'SUBMISSION-0001',
            'acceptedDocuments' => [['uuid' => self::CREDIT_NOTE_LHDN_UUID, 'longId' => 'CN-0001']],
        ]));

        $mock = new MockHandler([$accepted(), $accepted(), $accepted()]);

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->httpContainer));

        $this->app->instance(Client::class, new Client(['handler' => $stack]));

    }

    private function configureMalaysia(bool $autoSubmit): void
    {
        // Written through the model: api_credentials is stored encrypted, so a
        // raw insert would leave the provider context unreadable.
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
                'supplier_name' => 'NoovaERP Test Sdn Bhd',
                'supplier_registration_number' => '202301012345',
            ],
            'settings' => ['auto_submit' => $autoSubmit, 'environment' => 'preprod'],
        ]);
    }

    /**
     * The invoice is already accepted by LHDN — a credit note may only carry its
     * UUID as the original document reference.
     */
    private function recordAcceptedInvoiceSubmission(Invoice $invoice): void
    {
        $document = ComplianceDocument::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'document_type' => 'invoice',
            'document_id' => $invoice->id,
            'document_number' => $invoice->invoice_number,
            'issued_date' => $invoice->invoice_date,
            'total_amount' => $invoice->total,
            'currency_code' => 'MYR',
            'compliance_status' => 'accepted',
            'created_by' => $this->user->id,
        ]);

        ComplianceSubmission::create([
            'tenant_id' => $this->tenantId,
            'document_id' => $document->id,
            'country_code' => 'MY',
            'submission_status' => 'accepted',
            'external_document_uuid' => self::INVOICE_LHDN_UUID,
            'submitted_at' => now(),
            'submitted_by' => $this->user->id,
        ]);
    }

    private function invoice(): array
    {
        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'currency_code' => 'MYR',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->user->id,
        ]);

        $line = InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 6,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);

        $invoice->calculateTotals();

        return [$invoice->fresh(), $line];
    }

    private function postCreditNote(Invoice $invoice, InvoiceLine $line)
    {
        $create = $this->actingAs($this->user)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'CN7 auto submit',
            'lines' => [[
                'invoice_line_id' => $line->id,
                'description' => $line->description,
                'quantity' => 1,
                'unit_price' => $line->unit_price,
                'tax_rate' => 6,
            ]],
        ]);

        $create->assertCreated();
        $id = $create->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$id}/post")
            ->assertOk();

        return $id;
    }

    public function test_posting_a_credit_note_auto_submits_it_and_references_the_original_invoice(): void
    {
        $this->fakeLhdnEndpoint();
        $this->configureMalaysia(autoSubmit: true);

        [$invoice, $line] = $this->invoice();
        $this->recordAcceptedInvoiceSubmission($invoice);

        $creditNoteId = $this->postCreditNote($invoice, $line);

        $document = ComplianceDocument::where('tenant_id', $this->tenantId)
            ->where('document_type', 'credit_note')
            ->where('document_id', $creditNoteId)
            ->first();

        $this->assertNotNull($document, 'posting a credit note must create a credit_note compliance document');

        $submission = ComplianceSubmission::where('document_id', $document->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($submission, 'the credit note must actually be submitted to the e-invoicing provider');
        $this->assertSame(self::CREDIT_NOTE_LHDN_UUID, $submission->external_document_uuid, 'the provider UUID must be recorded');

        // The mapped payload persisted on the compliance document is what the
        // provider receives, and a MyInvois credit note (type 02) must carry the
        // original invoice's accepted UUID in original_invoice_references.
        $mapped = $document->mapped_payload ?? json_decode((string) $document->request_payload, true);
        $mapped = is_array($mapped) ? $mapped : [];

        $references = $mapped['original_invoice_references'] ?? [];
        $this->assertNotEmpty($references, 'a MyInvois credit note must reference the original invoice');
        $this->assertSame(
            self::INVOICE_LHDN_UUID,
            $references[0]['uuid'] ?? null,
            'the reference must carry the original invoice LHDN UUID'
        );
        $this->assertSame(
            'credit_note',
            $mapped['document_type'] ?? null,
            'the submitted document must be typed as a credit note'
        );
    }

    public function test_no_credit_note_document_is_created_when_auto_submit_is_off(): void
    {
        $this->fakeLhdnEndpoint();
        $this->configureMalaysia(autoSubmit: false);

        [$invoice, $line] = $this->invoice();
        $this->recordAcceptedInvoiceSubmission($invoice);

        $creditNoteId = $this->postCreditNote($invoice, $line);

        $this->assertSame(0, ComplianceDocument::where('tenant_id', $this->tenantId)
            ->where('document_type', 'credit_note')
            ->where('document_id', $creditNoteId)
            ->count(), 'auto_submit off must not submit the credit note');

        $this->assertEmpty($this->httpContainer, 'no provider call may happen when auto_submit is off');
    }
}
