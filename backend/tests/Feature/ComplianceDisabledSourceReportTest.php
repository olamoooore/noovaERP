<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\ComplianceSubmission;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #812 EP1 / #813 EP2 data remediation — the report of every supplier bill,
 * vendor debit note and journal entry that reached LHDN before those source
 * types were disabled, per tenant, with the LHDN UUID and status.
 */
class ComplianceDisabledSourceReportTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->assertTenantSchemaReady(['acct_compliance_documents', 'acct_compliance_submissions']);
    }

    private function document(
        string $type,
        string $number,
        ?string $uuid = null,
        string $status = 'accepted',
        bool $withSubmission = true,
    ): ComplianceDocument {
        $document = ComplianceDocument::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'document_type' => $type,
            'document_id' => 1,
            'document_number' => $number,
            'issued_date' => now()->subDays(3)->toDateString(),
            'total_amount' => 100.00,
            'currency_code' => 'MYR',
            'compliance_status' => $status,
        ]);

        if ($withSubmission) {
            ComplianceSubmission::create([
                'tenant_id' => $this->tenantId,
                'document_id' => $document->id,
                'country_code' => 'MY',
                'submission_status' => $status,
                'external_document_uuid' => $uuid,
                'provider_reference' => 'REF-'.$number,
                'submitted_at' => now()->subDays(3),
            ]);
        }

        return $document;
    }

    private function report(array $options = []): array
    {
        Artisan::call('compliance:disabled-source-report', $options + ['--format' => 'json']);

        return json_decode(trim(Artisan::output()), true) ?? [];
    }

    public function test_it_reports_previously_submitted_disabled_source_documents(): void
    {
        $this->document('bill', 'BILL-001', 'uuid-bill');
        $this->document('vendor_debit_note', 'VDN-001', 'uuid-vdn', 'rejected');
        $this->document('journal_entry', 'JE-001', 'uuid-je');

        // Never part of the report: legitimate invoices and unsubmitted bills.
        $this->document('invoice', 'INV-001', 'uuid-invoice');
        $this->document('bill', 'BILL-DRAFT', null, 'pending', withSubmission: false);

        $rows = $this->report();

        $this->assertCount(3, $rows);
        $this->assertSame(['bill', 'journal_entry', 'vendor_debit_note'], array_column($rows, 'document_type'));
        $this->assertSame(['uuid-bill', 'uuid-je', 'uuid-vdn'], array_column($rows, 'external_document_uuid'));
        $this->assertSame('rejected', $rows[2]['submission_status']);
        $this->assertSame($this->tenantId, $rows[0]['tenant_id']);
        $this->assertNotContains('INV-001', array_column($rows, 'document_number'));
        $this->assertNotContains('BILL-DRAFT', array_column($rows, 'document_number'));
    }

    public function test_the_source_option_limits_the_report(): void
    {
        $this->document('bill', 'BILL-001', 'uuid-bill');
        $this->document('journal_entry', 'JE-001', 'uuid-je');

        $rows = $this->report(['--source' => ['bill']]);

        $this->assertCount(1, $rows);
        $this->assertSame('bill', $rows[0]['document_type']);
    }

    public function test_cancel_in_window_cancels_a_document_inside_the_cancellation_window(): void
    {
        app(\App\Accounting\Plugin\CountryPluginRegistry::class)->register('MY', [
            'provider' => new FakeRemediationProvider,
        ]);

        $document = $this->document('bill', 'BILL-RECENT', 'uuid-recent', 'accepted');
        // Inside LHDN's 72-hour window.
        $document->submissions()->update(['submitted_at' => now()->subHour()]);

        $rows = $this->report(['--cancel-in-window' => true]);

        $this->assertCount(1, $rows);
        $this->assertSame('cancelled', $rows[0]['remediation_action']);
        $this->assertSame('cancelled', $document->submissions()->first()->fresh()->submission_status);
    }

    public function test_mark_remediation_notes_a_document_outside_the_cancellation_window(): void
    {
        $document = $this->document('journal_entry', 'JE-OLD', 'uuid-old', 'accepted');
        // Outside LHDN's 72-hour window.
        $document->submissions()->update(['submitted_at' => now()->subDays(5)]);

        $rows = $this->report(['--mark-remediation' => true]);

        $this->assertCount(1, $rows);
        $this->assertSame('remediation_noted', $rows[0]['remediation_action']);

        $document->refresh();
        $this->assertStringContainsString('#812/#813 remediation', (string) $document->compliance_notes);
        $this->assertSame('accepted', $document->submissions()->first()->fresh()->submission_status, 'no cancellation was attempted');
    }

    public function test_cancel_in_window_and_mark_remediation_together_cover_the_rest(): void
    {
        app(\App\Accounting\Plugin\CountryPluginRegistry::class)->register('MY', [
            'provider' => new FakeRemediationProvider,
        ]);

        $recent = $this->document('bill', 'BILL-RECENT', 'uuid-recent', 'accepted');
        $recent->submissions()->update(['submitted_at' => now()->subHour()]);

        $old = $this->document('journal_entry', 'JE-OLD', 'uuid-old', 'accepted');
        $old->submissions()->update(['submitted_at' => now()->subDays(5)]);

        $rows = $this->report(['--cancel-in-window' => true, '--mark-remediation' => true]);
        $byNumber = collect($rows)->keyBy('document_number');

        $this->assertSame('cancelled', $byNumber['BILL-RECENT']['remediation_action']);
        $this->assertStringContainsString('cancel_failed', (string) $byNumber['JE-OLD']['remediation_action']);
        $this->assertStringContainsString('remediation_noted', (string) $byNumber['JE-OLD']['remediation_action']);

        $old->refresh();
        $this->assertStringContainsString('#812/#813 remediation', (string) $old->compliance_notes);
        $this->assertSame('cancelled', $recent->submissions()->first()->fresh()->submission_status);
    }
}

/**
 * LHDN boundary double for the remediation-cancel path: records the cancel
 * and reports success without any HTTP.
 */
class FakeRemediationProvider implements \App\Accounting\Contracts\SubmissionProviderContract
{
    public array $cancelCalls = [];

    public function submit(array $payload, array $credentials): array
    {
        return ['success' => true, 'provider_reference' => 'FAKE-SUB', 'message' => 'ok'];
    }

    public function getStatus(string $providerReference, array $credentials): array
    {
        return ['success' => true, 'status' => 'accepted', 'provider_reference' => $providerReference, 'details' => []];
    }

    public function cancel(string $providerReference, array $credentials): array
    {
        $this->cancelCalls[] = $providerReference;

        return ['success' => true, 'status' => 'cancelled', 'details' => []];
    }

    public function supportsCancellation(): bool
    {
        return true;
    }

    public function retry(array $payload, string $providerReference, array $credentials): array
    {
        return ['success' => true, 'provider_reference' => $providerReference, 'message' => 'ok'];
    }

    public function getConfigRequirements(): array
    {
        return ['fields' => [], 'description' => 'Remediation test double'];
    }
}
