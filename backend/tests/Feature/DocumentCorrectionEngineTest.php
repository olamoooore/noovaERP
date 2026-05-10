<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Bill;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Vendor;
use App\Models\User;
use App\Services\CorrectionRequest;
use Illuminate\Support\Facades\Gate;
use Tests\TenantTestCase;

class DocumentCorrectionEngineTest extends TenantTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::find(1) ?? User::factory()->create(['id' => 1]);
        Gate::before(static function (User $user, string $ability): ?bool {
            return str_starts_with($ability, 'accounting.') ? true : null;
        });
        $this->ensureOpenFiscalPeriod();
    }

    private function ensureOpenFiscalPeriod(): void
    {
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
                'created_by' => $this->user->id,
            ]
        );
    }

    public function test_posted_invoice_cannot_be_edited_in_place(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'journal_entry_id' => null,
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/invoices/{$invoice->id}", [
            'customer_id' => $invoice->customer_id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'total' => 1000,
        ]);

        $response->assertStatus(403);
    }

    public function test_replacement_draft_is_linked_to_original(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'total' => 500,
        ]);

        $correctionRequest = new CorrectionRequest(
            userId: $this->user->id,
            reasonCode: 'data_entry_error',
            reasonText: 'Wrong amount entered',
        );

        $result = app(\App\Services\DocumentCorrectionService::class)
            ->requestReplacementDraft($invoice, $correctionRequest);

        $this->assertNotNull($result->document->original_document_id);
        $this->assertEquals($invoice->id, $result->document->original_document_id);
        $this->assertEquals('replacement', $result->document->correction_type);
    }

    public function test_original_invoice_marked_superseded_after_replacement(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);

        $correctionRequest = new CorrectionRequest(
            userId: $this->user->id,
            reasonCode: 'data_entry_error',
            reasonText: 'Wrong amount',
        );

        $result = app(\App\Services\DocumentCorrectionService::class)
            ->requestReplacementDraft($invoice, $correctionRequest);

        $invoice->refresh();

        $this->assertEquals('superseded', $invoice->lifecycle_status);
        $this->assertFalse($invoice->is_active_version);
    }

    public function test_only_one_active_version_exists_per_correction_group(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'total' => 500,
        ]);

        $correctionRequest = new CorrectionRequest(
            userId: $this->user->id,
            reasonCode: 'data_entry_error',
            reasonText: 'Wrong amount',
        );

        $result = app(\App\Services\DocumentCorrectionService::class)
            ->requestReplacementDraft($invoice, $correctionRequest);

        $activeVersions = Invoice::where('correction_group_id', $invoice->correction_group_id)
            ->where('is_active_version', true)
            ->count();

        $this->assertEquals(1, $activeVersions);
    }

    public function test_payment_existing_invoice_blocks_replacement(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'total' => 500,
            'paid_amount' => 500,
            'settlement_status' => 'paid',
        ]);

        $correctionRequest = new CorrectionRequest(
            userId: $this->user->id,
            reasonCode: 'data_entry_error',
            reasonText: 'Wrong amount',
        );

        $policy = app(\App\Services\DocumentCorrectionPolicyService::class)
            ->canCreateReplacement($invoice, $this->user);

        $this->assertFalse($policy->allowed);
    }

    public function test_void_creates_correction_link(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);

        $correctionRequest = new CorrectionRequest(
            userId: $this->user->id,
            reasonCode: 'duplicate',
            reasonText: 'Duplicate invoice',
        );

        $result = app(\App\Services\DocumentCorrectionService::class)
            ->voidDocument($invoice, $correctionRequest);

        $invoice->refresh();

        $this->assertEquals('voided', $invoice->lifecycle_status);
        $this->assertFalse($invoice->is_active_version);

        $hasLink = \App\Models\DocumentCorrectionLink::where('document_type', 'invoice')
            ->where('document_id', $invoice->id)
            ->exists();

        $this->assertTrue($hasLink);
    }

    public function test_reverse_creates_reversal_journal_entry(): void
    {
        $journalEntry = JournalEntry::factory()->create([
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);

        $correctionRequest = new CorrectionRequest(
            userId: $this->user->id,
            reasonCode: 'incorrect_account',
            reasonText: 'Wrong account used',
        );

        $result = app(\App\Services\DocumentCorrectionService::class)
            ->reverseDocument($journalEntry, $correctionRequest);

        $journalEntry->refresh();

        $this->assertEquals('reversed', $journalEntry->lifecycle_status);
        $this->assertNotNull($result->reversalJournalEntry);
    }

    public function test_refund_recalculates_invoice_settlement_status(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'total' => 500,
            'paid_amount' => 500,
            'settlement_status' => 'paid',
        ]);

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'payment_status' => 'applied',
            'customer_id' => $invoice->customer_id,
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
            'received_by' => $this->user->id,
        ]);

        $correctionRequest = new CorrectionRequest(
            userId: $this->user->id,
            reasonCode: 'customer_request',
            reasonText: 'Customer requested refund',
        );

        $result = app(\App\Services\DocumentCorrectionService::class)
            ->refundDocument($invoice, $correctionRequest);

        $invoice->refresh();

        $this->assertEquals('refunded', $invoice->settlement_status);
        $this->assertEquals(0, $invoice->paid_amount);
    }

    public function test_active_version_scope_returns_only_active(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);

        $correctionRequest = new CorrectionRequest(
            userId: $this->user->id,
            reasonCode: 'error',
            reasonText: 'Test',
        );

        app(\App\Services\DocumentCorrectionService::class)
            ->requestReplacementDraft($invoice, $correctionRequest);

        $activeCount = Invoice::activeVersion()->count();
        $this->assertGreaterThanOrEqual(1, $activeCount);
    }

    public function test_lifecycle_status_derived_display_status(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'settlement_status' => 'paid',
            'reconciliation_status' => 'cleared',
        ]);

        $displayStatus = app(\App\Services\DocumentLifecycleService::class)
            ->getDisplayStatus($invoice);

        $this->assertEquals('Cleared', $displayStatus);
    }

    public function test_replacement_not_allowed_when_already_superseded(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'draft',
            'lifecycle_status' => 'superseded',
        ]);

        $correctionRequest = new CorrectionRequest(
            userId: $this->user->id,
            reasonCode: 'test',
            reasonText: 'Test',
        );

        $policy = app(\App\Services\DocumentCorrectionPolicyService::class)
            ->canCreateReplacement($invoice, $this->user);

        $this->assertFalse($policy->allowed);
    }

    public function test_void_blocked_when_payment_exists(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'settlement_status' => 'paid',
            'paid_amount' => 500,
            'total' => 500,
        ]);

        $correctionRequest = new CorrectionRequest(
            userId: $this->user->id,
            reasonCode: 'test',
            reasonText: 'Test',
        );

        $policy = app(\App\Services\DocumentCorrectionPolicyService::class)
            ->canVoid($invoice, $this->user);

        $this->assertFalse($policy->allowed);
        $this->assertStringContainsString('payment', $policy->message());
    }

    public function test_reverse_blocked_when_not_posted(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'draft',
            'lifecycle_status' => 'draft',
        ]);

        $correctionRequest = new CorrectionRequest(
            userId: $this->user->id,
            reasonCode: 'test',
            reasonText: 'Test',
        );

        $policy = app(\App\Services\DocumentCorrectionPolicyService::class)
            ->canReverse($invoice, $this->user);

        $this->assertFalse($policy->allowed);
    }
}