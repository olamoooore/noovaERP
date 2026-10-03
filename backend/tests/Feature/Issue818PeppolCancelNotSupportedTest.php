<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\ComplianceService;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\ComplianceSubmission;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceSubmissionProvider;
use Modules\AccountingCountry\Singapore\Services\PeppolSubmissionProvider;
use Tests\TenantTestCase;

/**
 * Issue #818 (EP7) — Peppol has no recall: once a document has reached the
 * buyer's access point it exists, and the only correction is a credit note.
 * PeppolSubmissionProvider::cancel() nevertheless sent an HTTP DELETE and
 * reported success on HTTP 200, so the core marked the submission cancelled
 * while the buyer still held the invoice.
 *
 * Contract asserted here:
 *  - the SG pack declares cancellation unsupported and makes no HTTP call;
 *  - cancelling a delivered SG invoice through the core is refused with
 *    credit-note guidance, and the submission/document are left untouched
 *    (before the fix the MY 72-hour window message masked this, and inside the
 *    window the DELETE was attempted and "succeeded");
 *  - MY still supports cancellation (the capability did not flip).
 */
class Issue818PeppolCancelNotSupportedTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->assertTenantSchemaReady([
            'acct_compliance_documents',
            'acct_compliance_submissions',
        ]);
    }

    /**
     * @return array{0: ComplianceDocument, 1: ComplianceSubmission}
     */
    private function deliveredSgSubmission(): array
    {
        $document = ComplianceDocument::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'SG',
            'document_type' => 'invoice',
            'document_id' => 1,
            'document_number' => 'INV-818-0001',
            'issued_date' => now()->subDays(5)->toDateString(),
            'total_amount' => 109.00,
            'currency_code' => 'SGD',
            'compliance_status' => 'accepted',
        ]);

        $submission = ComplianceSubmission::create([
            'tenant_id' => $this->tenantId,
            'document_id' => $document->id,
            'country_code' => 'SG',
            'submission_status' => 'accepted',
            'provider_reference' => 'PEPPOL-SUB-818',
            'external_document_uuid' => 'peppol-uuid-818',
            'submitted_at' => now()->subDays(5),
        ]);

        return [$document, $submission];
    }

    public function test_cancelling_a_delivered_sg_invoice_is_refused_with_credit_note_guidance(): void
    {
        [$document, $submission] = $this->deliveredSgSubmission();

        // Deliberately outside the MY 72-hour window: an SG user must get the
        // pack-correct guidance, not a MyInvois window message.
        $result = app(ComplianceService::class)->cancelSubmission($submission->id);

        $this->assertFalse($result['success'], 'a delivered Peppol document cannot be cancelled');
        $this->assertStringContainsString(
            'credit note',
            strtolower($result['message']),
            'the refusal must route the user to the credit-note flow'
        );

        $submission->refresh();
        $document->refresh();

        $this->assertSame('accepted', $submission->submission_status, 'the submission must not be marked cancelled');
        $this->assertSame('accepted', $document->compliance_status, 'the document must not be marked cancelled');
        Http::assertNothingSent();
    }

    public function test_the_sg_provider_declares_cancellation_unsupported_and_refuses_without_http(): void
    {
        $provider = new PeppolSubmissionProvider;

        $this->assertFalse(
            $provider->supportsCancellation(),
            'the SG pack must declare that Peppol cannot cancel a delivered document'
        );

        $result = $provider->cancel('PEPPOL-SUB-818', ['api_key' => 'x']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('credit note', strtolower($result['message']));
        Http::assertNothingSent();
    }

    public function test_the_my_provider_still_supports_cancellation(): void
    {
        $this->assertTrue(
            (new MyInvoiceSubmissionProvider)->supportsCancellation(),
            'the capability declaration must not change MyInvois behaviour'
        );
    }
}
