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
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
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
 * Issue #816 (EP5) — MyInvois refund notes (type 04).
 *
 * A refund returns money to the buyer, which is a statutory document of its
 * own (type 04), separate from the type-02 credit note that only reduces what
 * is owed. Nothing fired when a customer payment was refunded, so a tenant
 * with e-invoicing enabled and auto_submit on never reported refunds: the
 * buyer's money left the system with no MyInvois document behind it.
 *
 * Contract asserted here:
 *  - refunding a posted customer payment creates a compliance document of
 *    type refund_note and submits it with the same auto_submit / country
 *    rules invoices get;
 *  - the submitted payload is a refund note that references the original
 *    invoice's accepted submission UUID;
 *  - auto_submit off submits nothing.
 */
class PaymentRefundComplianceAutoSubmitTest extends TenantTestCase
{
    private const INVOICE_LHDN_UUID = 'a1b2c3d4-0000-4000-8000-000000000001';

    private const REFUND_LHDN_UUID = 'a1b2c3d4-0000-4000-8000-000000000014';

    private User $user;

    private Customer $customer;

    private string $tenantId;

    private array $httpContainer = [];

    protected function setUp(): void
    {
        parent::setUp();

        // JournalEntryObserver dispatches a BI summary job on queue; phpunit
        // runs the queue synchronously, and that job calls tenancy()->end(),
        // which unbinds the tenant mid-test and forces a reconnect (losing
        // the uncommitted fixture transaction) on the next HTTP request.
        // #667: the auto-submit itself is a queued job now — let it run as
        // the (sync) queue would; everything else stays faked.
        \Illuminate\Support\Facades\Queue::fake()->except([\App\Jobs\SubmitEInvoiceJob::class]);

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
            'payments',
            'chart_of_accounts',
            'account_mappings',
            'journal_entries',
            'journal_entry_lines',
            'acct_compliance_documents',
            'acct_compliance_submissions',
            'fiscal_periods',
        ]);

        // The permission middleware resolves the CENTRAL user id by email in
        // public.users (user_company_access stores central ids) — mirror the
        // wiring InvoicePostingPermissionTest uses.
        $this->user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $this->user->email],
            ['name' => $this->user->name, 'password' => $this->user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $this->user->email)->value('id');
        $this->user->assignRole(\App\Enums\UserRole::ACCOUNTANT->spatieName());
        DB::table('public.user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'accountant', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->customer = Customer::factory()->create();

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-RF816', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-RF816', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-RF816', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);
        $cash = ChartOfAccount::factory()->create(['code' => '1000-RF816', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
        AccountMapping::create(['mapping_key' => 'cash', 'account_id' => $cash->id]);

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
        // depend on which call answers.
        $accepted = fn (): Response => new Response(200, [], json_encode([
            'submissionUID' => 'SUBMISSION-0014',
            'acceptedDocuments' => [['uuid' => self::REFUND_LHDN_UUID, 'longId' => 'RF-0014']],
        ]));

        $mock = new MockHandler([$accepted(), $accepted(), $accepted()]);

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->httpContainer));

        $this->app->instance(Client::class, new Client(['handler' => $stack]));
    }

    private function configureMalaysia(bool $autoSubmit): void
    {
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

    /**
     * A posted (GL-backed) invoice with one tax line — the refundable document.
     */
    private function invoiceWithLine(): array
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

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 6,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);

        $invoice->calculateTotals();

        return [$invoice->fresh(), $invoice->lines()->first()];
    }

    /**
     * Posted (journal-backed) payment applied to the invoice — the payment the
     * refund correction reverses. PaymentCorrectionService refuses to correct
     * a payment that has no journal entry.
     */
    private function appliedPayment(Invoice $invoice): Payment
    {
        $cash = ChartOfAccount::where('code', '1000-RF816')->first();
        $ar = ChartOfAccount::where('code', '1100-RF816')->first();

        $journal = JournalEntry::create([
            'entry_number' => 'JE-RF816-'.Str::upper(Str::random(6)),
            'entry_date' => now()->toDateString(),
            'description' => 'Customer payment',
            'status' => 'posted',
            'type' => 'manual',
            'posted_at' => now(),
            'posted_by' => $this->user->id,
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $journal->id, 'account_id' => $ar->id,
            'debit' => $invoice->total, 'credit' => 0, 'description' => 'AR debit',
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $journal->id, 'account_id' => $cash->id,
            'debit' => 0, 'credit' => $invoice->total, 'description' => 'Cash credit',
        ]);

        $payment = Payment::create([
            'payment_number' => 'PAY-RF816-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => $invoice->total,
            'method' => 'bank_transfer',
            'reference' => 'RF816-PAY',
            'received_by' => $this->user->id,
            'payment_status' => 'applied',
            'journal_entry_id' => $journal->id,
        ]);

        $invoice->update([
            'amount_paid' => $invoice->total, 'paid_amount' => $invoice->total,
            'balance' => 0, 'status' => 'paid',
        ]);

        return $payment;
    }

    private function refund(Payment $payment)
    {
        return $this->actingAs($this->user)->postJson(
            "/api/v1/corrections/payments/{$payment->id}/refund",
            ['reason_code' => 'customer_request', 'reason_text' => 'Buyer returned goods']
        );
    }

    public function test_refunding_a_validated_invoice_produces_a_type_04_document_linked_to_its_uuid(): void
    {
        $this->fakeLhdnEndpoint();
        $this->configureMalaysia(autoSubmit: true);

        [$invoice] = $this->invoiceWithLine();
        $this->recordAcceptedInvoiceSubmission($invoice);
        $payment = $this->appliedPayment($invoice);

        $response = $this->refund($payment);
        $response->assertOk();
        $refundId = $response->json('refund_payment.id');
        $this->assertNotNull($refundId, 'the refund must produce its own refund payment');

        $document = ComplianceDocument::where('tenant_id', $this->tenantId)
            ->where('document_type', 'refund_note')
            ->where('document_id', $refundId)
            ->first();

        $this->assertNotNull($document, 'refunding a payment must create a refund_note compliance document');

        $submission = ComplianceSubmission::where('document_id', $document->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($submission, 'the refund note must actually be submitted to the e-invoicing provider');
        $this->assertSame(self::REFUND_LHDN_UUID, $submission->external_document_uuid, 'the provider UUID must be recorded');

        $mapped = $document->mapped_payload ?? json_decode((string) $document->request_payload, true);
        $mapped = is_array($mapped) ? $mapped : [];

        $this->assertSame('refund_note', $mapped['document_type'] ?? null, 'the submitted document must be typed as a refund note');

        $references = $mapped['original_invoice_references'] ?? [];
        $this->assertNotEmpty($references, 'a MyInvois refund note must reference the original invoice');
        $this->assertSame(
            self::INVOICE_LHDN_UUID,
            $references[0]['uuid'] ?? null,
            'the reference must carry the original invoice LHDN UUID'
        );
    }

    public function test_no_refund_note_is_created_when_auto_submit_is_off(): void
    {
        $this->fakeLhdnEndpoint();
        $this->configureMalaysia(autoSubmit: false);

        [$invoice] = $this->invoiceWithLine();
        $this->recordAcceptedInvoiceSubmission($invoice);
        $payment = $this->appliedPayment($invoice);

        $response = $this->refund($payment);
        $response->assertOk();
        $refundId = $response->json('refund_payment.id');

        $this->assertSame(0, ComplianceDocument::where('tenant_id', $this->tenantId)
            ->where('document_type', 'refund_note')
            ->where('document_id', $refundId)
            ->count(), 'auto_submit off must not submit the refund note');

        $this->assertEmpty($this->httpContainer, 'no provider call may happen when auto_submit is off');
    }
}
