<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\SubmissionProviderContract;
use App\Accounting\Plugin\CountryPluginRegistry;
use App\Accounting\Services\ComplianceService;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\ComplianceSubmission;
use App\Models\Accounting\CountryConfiguration;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Malaysia\Services\MFRSValidationService;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceStatusSync;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #663 [SALES-EINV] P0 — cancel / void / reset-to-draft of a validated
 * invoice never reaches LHDN, and corrected invoices are not resubmitted.
 *
 * Every action under test goes through the real HTTP route (router →
 * middleware → controller). The e-invoice itself is produced by the real
 * compliance path too: the invoice is created and posted through
 * POST /api/v1/invoices + POST /api/v1/invoices/{id}/send, then submitted
 * through GET /api/v1/accounting/compliance/documents +
 * POST /api/v1/accounting/compliance/documents/{id}/submit — the real
 * ComplianceController → ComplianceService → MY validator/mapper → LHDN chain.
 *
 * The ONLY fake is the LHDN boundary itself: a SubmissionProviderContract stub
 * registered in the CountryPluginRegistry in place of
 * MyInvoiceSubmissionProvider (the class that performs the HTTP calls to
 * LHDN). No internal service (ComplianceService, PostingEngine, ...) is
 * mocked. LHDN validates asynchronously and there is no LHDN endpoint to poll
 * without a live token, so the fixture records LHDN's answer (Valid) directly
 * — that is the authority's state, not a decision NoovaERP makes.
 *
 * The 72-hour cancellation window comes from the existing
 * ComplianceService::MYINVOICE_CANCELLATION_WINDOW_HOURS constant (= 72,
 * ComplianceService.php:21) and the issue text ("allowed within 72 hours of
 * validation").
 */
class Issue663EInvoiceCancelLifecycleTest extends TenantTestCase
{
    private string $tenantId;

    private User $user;

    private Customer $customer;

    private Product $product;

    private FakeLhdnProvider $lhdn;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => (string) Str::uuid(),
        ]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@einv663.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fake ONLY the LHDN HTTP boundary: swap the MyInvois provider for a
        // recording stub. Validator/mapper/sync stay real.
        $this->lhdn = new FakeLhdnProvider;
        app(CountryPluginRegistry::class)->register('MY', [
            'validator' => MFRSValidationService::class,
            'mapper' => MyInvoiceMapper::class,
            'provider' => $this->lhdn,
            'sync' => MyInvoiceStatusSync::class,
        ]);

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
                'supplier_name' => 'NoovaERP Sdn Bhd',
                'supplier_registration_number' => '202400000001',
            ],
            'settings' => ['auto_submit' => false],
        ]);

        DB::table('company_settings')->insert([
            'company_name' => 'NoovaERP Sdn Bhd',
            'base_currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['type' => 'individual', 'company' => null]);
        $this->product = Product::factory()->create([
            'track_inventory' => false,
            'name' => 'E-invoice lifecycle item',
            'price' => 100,
            'cost' => 60,
        ]);
    }

    /**
     * Act as the tenant admin with the tenant context bound.
     *
     * TenantTestCase switches the tenant-resolution middleware off, and
     * phpunit.xml forces QUEUE_CONNECTION=sync: posting an invoice dispatches a
     * sync job whose stancl QueueTenancyBootstrapper calls tenancy()->end(),
     * which leaves tenancy()->tenant null for the rest of that request. The
     * compliance layer scopes every query on tenancy()->tenant, so the context
     * is re-bound here to match a real tenant request.
     */
    private function asTenant(): self
    {
        $this->bindTenantContext($this->tenantId);

        return $this->actingAs($this->user);
    }

    /**
     * Post an invoice through the real create + send routes.
     */
    private function postedInvoice(): Invoice
    {
        $created = $this->asTenant()->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'description' => 'E-invoice lifecycle line',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ],
            ],
        ]);
        $created->assertCreated();

        $invoiceId = $created->json('id');

        $this->asTenant()
            ->postJson('/api/v1/invoices/'.$invoiceId.'/send')
            ->assertOk();

        $invoice = Invoice::query()->findOrFail($invoiceId);

        $this->assertSame('posted', $invoice->status, 'Fixture: the invoice must be posted.');
        $this->assertNotNull($invoice->journal_entry_id, 'Fixture: posting must have written a journal entry.');

        return $invoice;
    }

    /**
     * Submit the invoice's e-invoice through the real compliance routes
     * (the compliance list backfills the document stub, the submit route maps,
     * validates and submits it), then record LHDN's asynchronous validation
     * result $hoursAgo.
     *
     * @return array{0: ComplianceDocument, 1: ComplianceSubmission}
     */
    private function submitEInvoiceThroughRoutes(Invoice $invoice, int $hoursAgo): array
    {
        $this->asTenant()
            ->getJson('/api/v1/accounting/compliance/documents?country_code=MY')
            ->assertOk();

        $document = ComplianceDocument::query()
            ->where('tenant_id', $this->tenantId)
            ->where('document_type', 'invoice')
            ->where('document_id', $invoice->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($document, 'Fixture: the compliance list must backfill a document for the invoice.');

        $this->asTenant()
            ->postJson('/api/v1/accounting/compliance/documents/'.$document->id.'/submit', ['country_code' => 'MY'])
            ->assertOk();

        return $this->markAsValidAtLhdn('invoice', $invoice->id, $hoursAgo);
    }

    /**
     * @return array{0: Invoice, 1: ComplianceDocument, 2: ComplianceSubmission}
     */
    private function validatedInvoiceEInvoice(int $hoursAgo = 1): array
    {
        $invoice = $this->postedInvoice();

        [$document, $submission] = $this->submitEInvoiceThroughRoutes($invoice, $hoursAgo);

        return [$invoice, $document, $submission];
    }

    /**
     * Assert the authority holds an e-invoice for the document, then record the
     * result of its asynchronous validation (Valid) $hoursAgo.
     *
     * @return array{0: ComplianceDocument, 1: ComplianceSubmission}
     */
    private function markAsValidAtLhdn(string $documentType, int $documentId, int $hoursAgo): array
    {
        $document = ComplianceDocument::query()
            ->where('tenant_id', $this->tenantId)
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->latest('id')
            ->first();

        $this->assertNotNull(
            $document,
            "Fixture: submitting the {$documentType} {$documentId} must create a compliance document."
        );

        $submission = ComplianceSubmission::query()
            ->where('document_id', $document->id)
            ->latest('id')
            ->first();

        $this->assertNotNull(
            $submission,
            "Fixture: submitting the {$documentType} {$documentId} must create a compliance submission."
        );

        $submission->update([
            'submission_status' => 'accepted',
            'submitted_at' => now()->subHours($hoursAgo),
        ]);
        $document->update(['compliance_status' => 'accepted']);

        return [$document->fresh(), $submission->fresh()];
    }

    /**
     * @return array{0: CustomerCreditNote, 1: ComplianceDocument, 2: ComplianceSubmission}
     */
    private function validatedCreditNoteEInvoice(Invoice $invoice, int $hoursAgo = 1): array
    {
        $creditNote = CustomerCreditNote::create([
            'credit_note_number' => 'CCN-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'status' => 'posted',
            'subtotal' => 50,
            'tax_amount' => 0,
            'total_amount' => 50,
            'currency_code' => 'USD',
            'reason' => 'Issue #663 lifecycle test',
        ]);

        $this->asTenant()->postJson(
            '/api/v1/accounting/compliance/credit-notes/'.$creditNote->id.'/submit',
            ['country_code' => 'MY']
        )->assertOk();

        [$document, $submission] = $this->markAsValidAtLhdn('credit_note', $creditNote->id, $hoursAgo);

        return [$creditNote, $document, $submission];
    }

    public function test_cancel_inside_window_cancels_lhdn_first_then_cancels_invoice(): void
    {
        [$invoice, $document, $submission] = $this->validatedInvoiceEInvoice(hoursAgo: 1);

        $response = $this->asTenant()
            ->postJson('/api/v1/invoices/'.$invoice->id.'/cancel');

        $response->assertOk();

        $this->assertCount(1, $this->lhdn->cancelCalls, 'LHDN cancellation must be called before the local cancel.');
        $this->assertSame('cancelled', $invoice->fresh()->status, 'Invoice must end up cancelled.');
        $this->assertSame('cancelled', $submission->fresh()->submission_status, 'Compliance submission must be cancelled at LHDN.');
        $this->assertSame('cancelled', $document->fresh()->compliance_status, 'Compliance document must no longer be Valid.');
    }

    public function test_cancel_is_blocked_when_the_lhdn_cancellation_fails(): void
    {
        [$invoice, $document, $submission] = $this->validatedInvoiceEInvoice(hoursAgo: 1);
        $this->lhdn->failCancel = true;

        $response = $this->asTenant()
            ->postJson('/api/v1/invoices/'.$invoice->id.'/cancel');

        $response->assertStatus(422);

        $this->assertCount(1, $this->lhdn->cancelCalls, 'The LHDN cancellation must have been attempted.');
        $this->assertSame('posted', $invoice->fresh()->status, 'The invoice must not be cancelled while LHDN still holds a Valid e-invoice.');
        $this->assertSame('accepted', $submission->fresh()->submission_status);
        $this->assertSame('accepted', $document->fresh()->compliance_status);
    }

    public function test_cancel_outside_window_is_blocked_with_credit_note_link(): void
    {
        [$invoice, $document, $submission] = $this->validatedInvoiceEInvoice(hoursAgo: 73);

        $response = $this->asTenant()
            ->postJson('/api/v1/invoices/'.$invoice->id.'/cancel');

        $response->assertStatus(422);
        $this->assertStringContainsString('Issue a credit note', (string) $response->json('message'));
        $this->assertNotEmpty($response->json('credit_note_url'), 'The block must link to credit-note creation.');

        $this->assertCount(0, $this->lhdn->cancelCalls, 'No LHDN call may happen outside the 72-hour window.');
        $this->assertSame('posted', $invoice->fresh()->status, 'Invoice must remain posted.');
        $this->assertSame('accepted', $document->fresh()->compliance_status, 'LHDN still holds a Valid e-invoice — do not touch it.');
        $this->assertSame('accepted', $submission->fresh()->submission_status);
    }

    public function test_invoice_without_an_e_invoice_can_still_be_cancelled(): void
    {
        $invoice = $this->postedInvoice();

        $this->assertSame(0, ComplianceDocument::query()
            ->where('tenant_id', $this->tenantId)
            ->where('document_type', 'invoice')
            ->where('document_id', $invoice->id)
            ->count(), 'Fixture: no compliance document must exist.');

        $this->asTenant()
            ->postJson('/api/v1/invoices/'.$invoice->id.'/cancel')
            ->assertOk();

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertCount(0, $this->lhdn->cancelCalls);
    }

    public function test_reset_to_draft_is_blocked_while_the_e_invoice_is_valid(): void
    {
        [$invoice, $document] = $this->validatedInvoiceEInvoice(hoursAgo: 1);

        $response = $this->asTenant()
            ->postJson('/api/v1/invoices/'.$invoice->id.'/reset-to-draft');

        $response->assertStatus(422);
        $this->assertStringContainsString('e-invoice', (string) $response->json('message'));

        $this->assertCount(0, $this->lhdn->cancelCalls, 'Reset must not silently cancel or ignore the e-invoice.');
        $this->assertSame('posted', $invoice->fresh()->status, 'Invoice must not be reset while LHDN holds a valid e-invoice.');
        $this->assertSame('accepted', $document->fresh()->compliance_status);
    }

    public function test_reset_to_draft_is_allowed_once_the_e_invoice_has_been_cancelled_at_lhdn(): void
    {
        [$invoice, $document] = $this->validatedInvoiceEInvoice(hoursAgo: 1);

        $this->asTenant()
            ->postJson('/api/v1/invoices/'.$invoice->id.'/cancel')
            ->assertOk();

        $this->asTenant()
            ->postJson('/api/v1/invoices/'.$invoice->id.'/reset-to-draft')
            ->assertOk();

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame('cancelled', $document->fresh()->compliance_status);
    }

    public function test_void_inside_window_cancels_lhdn_first_then_voids_invoice(): void
    {
        [$invoice, $document, $submission] = $this->validatedInvoiceEInvoice(hoursAgo: 1);

        $response = $this->asTenant()->postJson(
            '/api/v1/corrections/invoices/'.$invoice->id.'/void',
            ['reason_code' => 'WRONG_AMOUNT', 'reason_text' => 'Wrong amount']
        );

        $response->assertOk();

        $this->assertCount(1, $this->lhdn->cancelCalls, 'LHDN cancellation must be called before the local void.');
        $this->assertSame('voided', $invoice->fresh()->lifecycle_status, 'Invoice must be voided locally.');
        $this->assertSame('cancelled', $submission->fresh()->submission_status);
        $this->assertSame('cancelled', $document->fresh()->compliance_status);
    }

    public function test_void_outside_window_is_blocked_with_credit_note_link(): void
    {
        [$invoice, $document] = $this->validatedInvoiceEInvoice(hoursAgo: 73);

        $response = $this->asTenant()->postJson(
            '/api/v1/corrections/invoices/'.$invoice->id.'/void',
            ['reason_code' => 'WRONG_AMOUNT']
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('Issue a credit note', (string) $response->json('message'));
        $this->assertNotEmpty($response->json('credit_note_url'));

        $this->assertCount(0, $this->lhdn->cancelCalls);
        $this->assertSame('posted', $invoice->fresh()->lifecycle_status, 'Invoice must not be voided while LHDN holds a Valid e-invoice.');
        $this->assertSame('accepted', $document->fresh()->compliance_status);
    }

    public function test_replacement_cancels_the_original_e_invoice_and_submits_a_new_compliance_document(): void
    {
        [$invoice, $originalDocument] = $this->validatedInvoiceEInvoice(hoursAgo: 1);

        $response = $this->asTenant()->postJson(
            '/api/v1/corrections/invoices/'.$invoice->id.'/replacement',
            ['reason_code' => 'WRONG_AMOUNT', 'reason_text' => 'Wrong amount']
        );

        $response->assertOk();

        $this->assertCount(1, $this->lhdn->cancelCalls, 'The original e-invoice must be cancelled at LHDN when replaced.');
        $this->assertSame('cancelled', $originalDocument->fresh()->compliance_status);

        $replacement = Invoice::query()->findOrFail($response->json('replacement.id'));
        $this->assertSame($invoice->id, $replacement->original_document_id, 'Replacement must reference the original invoice.');

        // Posting the replacement runs the real submission path again — it must
        // produce a NEW compliance document, not reuse the original's.
        $this->asTenant()
            ->postJson('/api/v1/invoices/'.$replacement->id.'/send')
            ->assertOk();

        [$replacementDocument] = $this->submitEInvoiceThroughRoutes($replacement, 1);

        $this->assertNotSame($originalDocument->id, $replacementDocument->id, 'Replacement must get its own compliance document.');
        $this->assertSame(
            $originalDocument->id,
            $replacementDocument->original_compliance_document_id,
            'The new compliance document must reference the original compliance document.'
        );
    }

    public function test_resubmitting_an_invoice_that_lhdn_already_holds_is_refused(): void
    {
        [$invoice, $document] = $this->validatedInvoiceEInvoice(hoursAgo: 1);
        $document->update(['total_amount' => 999]);
        $submissionsBefore = count($this->lhdn->submitCalls);

        $this->bindTenantContext($this->tenantId);
        $result = app(ComplianceService::class)->submitInvoice($invoice, 'MY');

        $this->assertFalse($result['success'] ?? true, 'A Valid e-invoice must not be silently replaced by a re-submission.');
        $this->assertStringContainsString('Valid', (string) ($result['message'] ?? ''));
        $this->assertCount($submissionsBefore, $this->lhdn->submitCalls, 'No new LHDN submission may be made for the already-validated invoice.');
    }

    public function test_credit_note_void_cancels_e_invoice_inside_window(): void
    {
        [$invoice] = $this->validatedInvoiceEInvoice(hoursAgo: 1);
        [$creditNote, $document, $submission] = $this->validatedCreditNoteEInvoice($invoice, hoursAgo: 1);

        $response = $this->asTenant()
            ->postJson('/api/v1/accounting/customers/credit-notes/'.$creditNote->id.'/void');

        $response->assertOk();

        $this->assertCount(1, $this->lhdn->cancelCalls, 'LHDN cancellation must be called before the local void.');
        $this->assertSame('voided', $creditNote->fresh()->status);
        $this->assertSame('cancelled', $submission->fresh()->submission_status);
        $this->assertSame('cancelled', $document->fresh()->compliance_status);
    }

    public function test_credit_note_void_outside_window_is_blocked(): void
    {
        [$invoice] = $this->validatedInvoiceEInvoice(hoursAgo: 1);
        [$creditNote, $document] = $this->validatedCreditNoteEInvoice($invoice, hoursAgo: 73);

        $response = $this->asTenant()
            ->postJson('/api/v1/accounting/customers/credit-notes/'.$creditNote->id.'/void');

        $response->assertStatus(422);
        $this->assertStringContainsString('e-invoice', (string) $response->json('message'));

        $this->assertCount(0, $this->lhdn->cancelCalls);
        $this->assertSame('posted', $creditNote->fresh()->status, 'Credit note must not be voided while LHDN holds a Valid e-invoice.');
        $this->assertSame('accepted', $document->fresh()->compliance_status);
    }

    public function test_data_check_reports_invoices_cancelled_in_noovaerp_with_live_e_invoice(): void
    {
        // Historical bad data: locally cancelled, e-invoice never touched.
        [$badInvoice] = $this->validatedInvoiceEInvoice(hoursAgo: 1);
        $badInvoice->update(['status' => 'cancelled']);

        // Reset-to-draft leftovers: draft again in NoovaERP, still Valid at LHDN.
        [$resetInvoice] = $this->validatedInvoiceEInvoice(hoursAgo: 1);
        $resetInvoice->update(['status' => 'draft', 'lifecycle_status' => 'draft', 'journal_entry_id' => null]);

        // Correct flow: cancel through the route so LHDN is cancelled first.
        [$goodInvoice] = $this->validatedInvoiceEInvoice(hoursAgo: 1);
        $this->asTenant()
            ->postJson('/api/v1/invoices/'.$goodInvoice->id.'/cancel')
            ->assertOk();

        $response = $this->asTenant()->getJson(
            '/api/v1/accounting/compliance/data-checks/einvoice-lifecycle?document_type=invoice'
        );
        $response->assertOk();

        $foundIds = array_column($response->json('data.mismatches'), 'document_id');

        $this->assertContains(
            $badInvoice->id,
            $foundIds,
            'The data check must report an invoice cancelled in NoovaERP whose compliance document is still Valid.'
        );
        $this->assertContains(
            $resetInvoice->id,
            $foundIds,
            'The data check must report an invoice reset to draft whose compliance document is still Valid.'
        );
        $this->assertNotContains(
            $goodInvoice->id,
            $foundIds,
            'An invoice cancelled through the guarded path (LHDN cancelled first) is not a mismatch.'
        );
    }

    public function test_data_check_reports_credit_notes_voided_in_noovaerp_with_live_e_invoice(): void
    {
        [$invoice] = $this->validatedInvoiceEInvoice(hoursAgo: 1);
        [$creditNote] = $this->validatedCreditNoteEInvoice($invoice, hoursAgo: 1);
        $creditNote->update(['status' => 'voided']);

        $response = $this->asTenant()->getJson(
            '/api/v1/accounting/compliance/data-checks/einvoice-lifecycle?document_type=credit_note'
        );
        $response->assertOk();

        $this->assertContains(
            $creditNote->id,
            array_column($response->json('data.mismatches'), 'document_id'),
            'The data check must report a voided credit note whose compliance document is still Valid.'
        );
    }
}

/**
 * LHDN boundary double: records every submit/cancel call instead of making
 * HTTP requests. Registered in the CountryPluginRegistry as the MY "provider"
 * — the single seam where ComplianceService talks to LHDN.
 */
class FakeLhdnProvider implements SubmissionProviderContract
{
    /** @var array<int, array{uuid: string, credentials: array}> */
    public array $cancelCalls = [];

    /** @var array<int, array> */
    public array $submitCalls = [];

    public bool $failCancel = false;

    public function submit(array $payload, array $credentials): array
    {
        $this->submitCalls[] = $payload;
        $sequence = count($this->submitCalls);

        return [
            'success' => true,
            'provider_reference' => 'SUB-TEST-'.$sequence,
            'external_document_uuid' => 'uuid-test-'.$sequence,
            'external_long_id' => 'long-id-test-'.$sequence,
            'validation_link' => 'https://portal.test/validate/'.$sequence,
            'status_details' => ['status' => 'Valid'],
            'message' => 'Submitted successfully',
        ];
    }

    public function getStatus(string $providerReference, array $credentials): array
    {
        return [
            'success' => true,
            'status' => 'accepted',
            'provider_reference' => $providerReference,
            'external_document_uuid' => 'uuid-test-from-status',
            'details' => [],
            'message' => 'Valid',
        ];
    }

    public function cancel(string $documentUuid, array $credentials): array
    {
        $this->cancelCalls[] = ['uuid' => $documentUuid, 'credentials' => $credentials];

        if ($this->failCancel) {
            return ['success' => false, 'message' => 'LHDN cancel failed'];
        }

        return [
            'success' => true,
            'status' => 'cancelled',
            'provider_status' => 'cancelled',
            'details' => ['reason' => 'cancelled via test'],
        ];
    }

    public function supportsCancellation(): bool
    {
        return true;
    }

    public function retry(array $payload, string $providerReference, array $credentials): array
    {
        return ['success' => true, 'provider_reference' => $providerReference, 'message' => 'Retried'];
    }

    public function getConfigRequirements(): array
    {
        return ['fields' => [], 'description' => 'Test provider'];
    }
}
