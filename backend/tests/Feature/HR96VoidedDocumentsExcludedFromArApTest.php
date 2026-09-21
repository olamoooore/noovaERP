<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\VendorBillsController;
use App\Models\Bill;
use App\Models\Invoice;
use App\Services\AgedPayablesService;
use App\Services\AgedReceivablesService;
use App\Services\CorrectionRequest;
use App\Services\DocumentLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #96 (BLOCKER) — voided/reversed documents must not remain payable or
 * count toward AR/AP aging.
 *
 * DocumentLifecycleService::markVoided()/markReversed() only touch the
 * lifecycle_* columns, never the legacy status/balance columns that these
 * readers historically used. Every test below drives the REAL service +
 * controller code against the real DB and the ar_transactions/ap_transactions
 * views:
 *
 *  - payment eligibility (PaymentController::eligibleInvoices,
 *    VendorBillsController::eligibleBills)
 *  - AR/AP aging summaries (AgedReceivablesService / AgedPayablesService)
 *  - the AR/AP reporting views (statements + reconciliation read these)
 */
class HR96VoidedDocumentsExcludedFromArApTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext(Str::uuid()->toString());

        $this->assertTenantSchemaReady([
            'invoices', 'bills', 'customers', 'vendors',
        ]);

        // Guard against a stale view definition (pre-#96) shipping with the schema.
        $this->assertTrue(DB::table('information_schema.views')
            ->where('table_schema', 'public')
            ->where('table_name', 'ar_transactions')
            ->exists(), 'ar_transactions view must exist');
    }

    private function lifecycle(): DocumentLifecycleService
    {
        return app(DocumentLifecycleService::class);
    }

    private function request(): CorrectionRequest
    {
        return new CorrectionRequest(userId: 1, reasonCode: 'data_entry_error', reasonText: 'hr96 test');
    }

    private function eligibleInvoiceIds(?int $customerId = null): array
    {
        $response = app(PaymentController::class)->eligibleInvoices(new Request(
            $customerId ? ['customer_id' => $customerId] : []
        ));

        return array_column($response->getData(true)['data'] ?? [], 'id');
    }

    private function eligibleBillIds(?int $vendorId = null): array
    {
        $response = app(VendorBillsController::class)->eligibleBills(new Request(
            $vendorId ? ['vendor_id' => $vendorId] : []
        ));

        return array_column($response->getData(true)['data'] ?? [], 'id');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Invoices — payment eligibility
    // ─────────────────────────────────────────────────────────────────────

    public function test_voided_invoice_is_no_longer_eligible_for_payment(): void
    {
        $invoice = Invoice::factory()->posted()->create(['total' => 500, 'balance' => 500]);

        $this->assertContains($invoice->id, $this->eligibleInvoiceIds($invoice->customer_id), 'Baseline: posted invoice must be eligible');

        $this->lifecycle()->markVoided($invoice->fresh(), $this->request());

        $this->assertNotContains(
            $invoice->id,
            $this->eligibleInvoiceIds($invoice->customer_id),
            'A voided invoice must never be selectable for payment'
        );
        // The legacy columns are deliberately untouched (that is the root cause).
        $this->assertSame('posted', $invoice->fresh()->status);
        $this->assertGreaterThan(0, (float) $invoice->fresh()->balance);
    }

    public function test_reversed_invoice_is_no_longer_eligible_for_payment(): void
    {
        $invoice = Invoice::factory()->posted()->create(['total' => 300, 'balance' => 300]);
        $this->assertContains($invoice->id, $this->eligibleInvoiceIds($invoice->customer_id));

        $this->lifecycle()->markReversed($invoice->fresh(), $this->request());

        $this->assertNotContains($invoice->id, $this->eligibleInvoiceIds($invoice->customer_id));
    }

    public function test_superseded_invoice_is_no_longer_eligible_for_payment(): void
    {
        $invoice = Invoice::factory()->posted()->create(['total' => 250, 'balance' => 250]);
        $this->assertContains($invoice->id, $this->eligibleInvoiceIds($invoice->customer_id));

        $this->lifecycle()->markSuperseded($invoice->fresh(), $this->request());

        $this->assertNotContains($invoice->id, $this->eligibleInvoiceIds($invoice->customer_id));
    }

    public function test_credited_invoice_remains_eligible_while_it_has_a_balance(): void
    {
        // A credited invoice is offset by the credit-note transaction (credit in
        // the AR view) — while a positive balance remains it is still payable.
        $invoice = Invoice::factory()->posted()->create(['total' => 400, 'balance' => 100]);

        $creditNote = \App\Models\CustomerCreditNote::create([
            'credit_note_number' => 'CN-'.Str::upper(Str::random(8)),
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'status' => 'posted',
            'subtotal' => 300,
            'tax_amount' => 0,
            'total_amount' => 300,
            'currency_code' => 'USD',
            'reason' => 'hr96 credited test',
            'created_by' => $invoice->created_by,
        ]);

        $this->lifecycle()->markCredited($invoice->fresh(), $this->request(), $creditNote->id);

        $this->assertContains(
            $invoice->id,
            $this->eligibleInvoiceIds($invoice->customer_id),
            'Credited invoice with remaining balance must stay payable'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Invoices — AR view + aging
    // ─────────────────────────────────────────────────────────────────────

    public function test_voided_invoice_is_excluded_from_ar_view_and_aging(): void
    {
        $invoice = Invoice::factory()->posted()->create(['total' => 800, 'balance' => 800]);
        $customerId = $invoice->customer_id;

        $this->assertTrue($this->arViewHasInvoice($invoice->id), 'Baseline: posted invoice must be in ar_transactions');
        $this->assertSame(800.0, $this->arAgingTotal());

        $this->lifecycle()->markVoided($invoice->fresh(), $this->request());

        $this->assertFalse($this->arViewHasInvoice($invoice->id), 'Voided invoice must leave ar_transactions');
        $this->assertSame(0.0, $this->arAgingTotal(), 'Voided invoice must not count toward AR aging');

        // Statement payload (view-backed) must not include it either.
        $statement = DB::table('ar_transactions')->where('customer_id', $customerId)->get();
        $this->assertCount(0, $statement);
    }

    public function test_reversed_invoice_is_excluded_from_ar_view_and_aging(): void
    {
        $invoice = Invoice::factory()->posted()->create(['total' => 650, 'balance' => 650]);
        $this->assertTrue($this->arViewHasInvoice($invoice->id));

        $this->lifecycle()->markReversed($invoice->fresh(), $this->request());

        $this->assertFalse($this->arViewHasInvoice($invoice->id));
        $this->assertSame(0.0, $this->arAgingTotal());
    }

    public function test_superseded_invoice_is_excluded_from_ar_view(): void
    {
        $invoice = Invoice::factory()->posted()->create(['total' => 120, 'balance' => 120]);
        $this->assertTrue($this->arViewHasInvoice($invoice->id));

        $this->lifecycle()->markSuperseded($invoice->fresh(), $this->request());

        $this->assertFalse($this->arViewHasInvoice($invoice->id));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Bills — payment eligibility, AP view + aging
    // ─────────────────────────────────────────────────────────────────────

    public function test_voided_bill_is_no_longer_eligible_for_payment_and_leaves_ap_view(): void
    {
        $bill = Bill::factory()->posted()->create(['total' => 700, 'balance' => 700]);

        $this->assertContains($bill->id, $this->eligibleBillIds($bill->vendor_id), 'Baseline: posted bill must be eligible');
        $this->assertTrue($this->apViewHasBill($bill->id));
        $this->assertSame(700.0, $this->apAgingTotal());

        $this->lifecycle()->markVoided($bill->fresh(), $this->request());

        $this->assertNotContains($bill->id, $this->eligibleBillIds($bill->vendor_id), 'Voided bill must never be payable');
        $this->assertFalse($this->apViewHasBill($bill->id), 'Voided bill must leave ap_transactions');
        $this->assertSame(0.0, $this->apAgingTotal(), 'Voided bill must not count toward AP aging');

        // Root cause still visible in the legacy columns.
        $this->assertSame('posted', $bill->fresh()->status);
        $this->assertGreaterThan(0, (float) $bill->fresh()->balance);
    }

    public function test_reversed_bill_is_excluded_from_ap_view_and_aging(): void
    {
        $bill = Bill::factory()->posted()->create(['total' => 210, 'balance' => 210]);
        $this->assertTrue($this->apViewHasBill($bill->id));

        $this->lifecycle()->markReversed($bill->fresh(), $this->request());

        $this->assertFalse($this->apViewHasBill($bill->id));
        $this->assertSame(0.0, $this->apAgingTotal());
        $this->assertNotContains($bill->id, $this->eligibleBillIds($bill->vendor_id));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function arViewHasInvoice(int $id): bool
    {
        return DB::table('ar_transactions')
            ->where('transaction_type', 'invoice')
            ->where('transaction_id', $id)
            ->exists();
    }

    private function apViewHasBill(int $id): bool
    {
        return DB::table('ap_transactions')
            ->where('transaction_type', 'bill')
            ->where('transaction_id', $id)
            ->exists();
    }

    private function arAgingTotal(): float
    {
        $result = app(AgedReceivablesService::class)->getAgedReceivables([]);

        return (float) $result['summary']['total_outstanding'];
    }

    private function apAgingTotal(): float
    {
        $result = app(AgedPayablesService::class)->getAgedPayables([]);

        return (float) $result['summary']['total_outstanding'];
    }
}
