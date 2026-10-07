<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TenantTestCase;

/**
 * #1001: payment advice reconciliation through the real routes.
 *
 * The five client samples are: Parkway (HSBC ACH PDF, 55 lines), UOB GIRO
 * screenshot (2 bills), DBS transaction advice (1 invoice), Standard Chartered
 * payee advice (2 invoices), SCB ACH advice (partial, variance flag) and the
 * UOB transfer notice (no invoice references — customer+amount suggestion).
 *
 * Image advice text comes from the OCR service; the fixtures are the text
 * extracted from the real sample files (macOS Vision, higher accuracy than the
 * test environment's Tesseract), so the parsers are exercised against the real
 * advice content while the OCR hop is faked.
 */
class PaymentAdviceReconciliationTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->setUpTenantAdminUser('Payment Advice Tenant');
    }

    private function seedInvoice(Customer $customer, string $number, float $amount, string $date = '2026-08-01'): Invoice
    {
        $id = DB::table('invoices')->insertGetId([
            'invoice_number' => $number,
            'invoice_date' => $date,
            'due_date' => date('Y-m-d', strtotime($date.' +30 days')),
            'customer_id' => $customer->id,
            'created_by' => $this->user->id,
            'status' => 'posted',
            'subtotal' => $amount,
            'total' => $amount,
            'balance' => $amount,
            'paid_amount' => 0,
            'settlement_status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Invoice::findOrFail($id);
    }

    private function fixture(string $name): string
    {
        return base_path('tests/fixtures/payment-advices/'.$name);
    }

    private function upload(string $fileName, ?string $fixturePath = null, string $mime = 'image/jpeg'): \Illuminate\Testing\TestResponse
    {
        if ($fixturePath !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'advice_');
            copy($fixturePath, $tmp);
            $file = new UploadedFile($tmp, $fileName, $mime, null, true);
        } else {
            $file = UploadedFile::fake()->createWithContent($fileName, 'fake-advice-bytes');
        }

        return $this->actingAs($this->user)->post('/api/v1/payment-advices', ['file' => $file]);
    }

    private function fakeOcr(string $fixture): void
    {
        Http::fake([
            '*/api/ocr/raw' => Http::response([
                'text' => file_get_contents($this->fixture($fixture)),
                'confidence' => 90.0,
            ]),
        ]);
    }

    public function test_parkway_hsbc_pdf_is_parsed_matched_and_reconciles_to_the_remittance(): void
    {
        $parkway = Customer::factory()->create(['name' => 'Parkway Hospital Pte Ltd']);
        $invoiceA = $this->seedInvoice($parkway, '2026/08/019', 109.00);
        $invoiceB = $this->seedInvoice($parkway, '2026/08/021', 163.50);

        $response = $this->upload('Parkway.pdf', $this->fixture('parkway-hsbc.pdf'), 'application/pdf');

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertSame('hsbc', $data['bank_code']);
        $this->assertSame('pdf-text', $data['extractor']);
        $this->assertSame(10976.30, (float) $data['total_amount']);
        $this->assertSame(10976.30, (float) $data['extracted_total']);
        $this->assertSame(0.0, (float) $data['variance']);
        $this->assertCount(55, $data['lines']);
        $this->assertSame('PAR***Y HOSP****S (*) P*L', $data['payer_name']);
        $this->assertSame('2026-09-25', substr((string) $data['value_date'], 0, 10));
        $this->assertSame($parkway->id, $data['customer_id']);

        $matched = collect($data['lines'])->keyBy('invoice_number');
        $this->assertSame('matched', $matched['2026/08/019']['status']);
        $this->assertSame($invoiceA->id, $matched['2026/08/019']['invoice_id']);
        $this->assertSame('matched', $matched['2026/08/021']['status']);
        $this->assertSame($invoiceB->id, $matched['2026/08/021']['invoice_id']);
        $this->assertSame('unmatched', $matched['2026/08/030']['status']);

        // The uploaded advice is retained as evidence.
        $this->assertNotNull($data['file_path']);
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists($data['file_path']));
    }

    public function test_uob_giro_screenshot_is_ocr_parsed_and_matches_both_bills(): void
    {
        $this->fakeOcr('uob-payment-advice.txt');

        $ascensus = Customer::factory()->create(['name' => "ASCENSUS-BEH'S CLINIC FOR WOMEN PTE LTD"]);
        $invoiceA = $this->seedInvoice($ascensus, '2026/08/223', 7194.00, '2026-08-31');
        $invoiceB = $this->seedInvoice($ascensus, '2026/07/089', 1744.00, '2026-07-14');

        $response = $this->upload('uob.jpg');

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertSame('uob_giro', $data['bank_code']);
        $this->assertSame('ocr', $data['extractor']);
        $this->assertSame(8938.00, (float) $data['total_amount']);
        $this->assertSame(8938.00, (float) $data['extracted_total']);
        $this->assertSame(0.0, (float) $data['variance']);
        $this->assertCount(2, $data['lines']);
        $this->assertSame($invoiceA->id, $data['lines'][0]['invoice_id']);
        $this->assertSame('matched', $data['lines'][0]['status']);
        $this->assertSame($invoiceB->id, $data['lines'][1]['invoice_id']);
        $this->assertSame($ascensus->id, $data['customer_id']);
    }

    public function test_dbs_advice_matches_the_single_invoice_and_resolves_the_payer(): void
    {
        $this->fakeOcr('dbs-payment-advice.txt');

        $sgh = Customer::factory()->create(['name' => 'SINGAPORE GENERAL HOSPITAL PTE LTD']);
        $invoice = $this->seedInvoice($sgh, '2026/08/159', 981.00, '2026-08-24');

        $response = $this->upload('dbs.jpg');

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertSame('dbs', $data['bank_code']);
        $this->assertSame(981.00, (float) $data['total_amount']);
        $this->assertSame(0.0, (float) $data['variance']);
        $this->assertCount(1, $data['lines']);
        $this->assertSame('matched', $data['lines'][0]['status']);
        $this->assertSame($invoice->id, $data['lines'][0]['invoice_id']);
        $this->assertSame($sgh->id, $data['customer_id']);
        $this->assertStringContainsString('SINGAPORE GENERAL', (string) $data['payer_name']);
    }

    public function test_scb_advice_matches_both_invoices(): void
    {
        $this->fakeOcr('standard-charted-payment-advice.txt');

        $thomson = Customer::factory()->create(['name' => 'THOMSON MEDICAL PTE LTD']);
        $invoiceA = $this->seedInvoice($thomson, '2024/12/017', 545.00, '2024-12-12');
        $invoiceB = $this->seedInvoice($thomson, '2024/12/016', 545.00, '2024-12-12');

        $response = $this->upload('scb.jpg');

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertSame('scb', $data['bank_code']);
        $this->assertSame(1090.00, (float) $data['total_amount']);
        $this->assertSame(1090.00, (float) $data['extracted_total']);
        $this->assertSame(0.0, (float) $data['variance']);
        $this->assertCount(2, $data['lines']);
        $this->assertSame($invoiceA->id, $data['lines'][0]['invoice_id']);
        $this->assertSame($invoiceB->id, $data['lines'][1]['invoice_id']);
        $this->assertSame($thomson->id, $data['customer_id']);
    }

    public function test_scb_partial_advice_shows_the_variance_and_leaves_unmatched_lines_open(): void
    {
        $this->fakeOcr('standard-charted-payment-advice-1.txt');

        $thomson = Customer::factory()->create(['name' => 'THOMSON MEDICAL PTE LTD']);
        $invoice = $this->seedInvoice($thomson, '2025/07/019', 588.60, '2025-07-07');

        $response = $this->upload('scb2.jpg');

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertSame(8371.20, (float) $data['total_amount']);
        $this->assertGreaterThan(0, count($data['lines']));
        $this->assertNotEquals(0.0, (float) $data['variance']);
        $this->assertContains('line_total_mismatch', $data['warnings']);

        // The one invoice present on page 1 matches; the rest stay open.
        $matched = collect($data['lines'])->firstWhere('invoice_number', '2025/07/019');
        $this->assertSame('matched', $matched['status']);
        $this->assertSame($invoice->id, $matched['invoice_id']);
        $open = collect($data['lines'])->where('status', 'unmatched');
        $this->assertGreaterThan(0, $open->count());
        $this->assertSame(0, collect($data['lines'])->where('status', 'confirmed')->count());
    }

    public function test_uob_transfer_notice_has_no_references_and_suggests_by_customer_and_amount(): void
    {
        $this->fakeOcr('uob-transfer-notice.txt');

        $centre = Customer::factory()->create(['name' => 'HEALTH & FERTILITY CENTRE FOR WOMEN PTE LTD']);
        $invoice = $this->seedInvoice($centre, '2026/05/088', 545.00, '2026-05-02');

        $response = $this->upload('uob-transfer.jpg');

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertSame('uob_transfer', $data['bank_code']);
        $this->assertSame(545.00, (float) $data['total_amount']);
        $this->assertSame([], $data['lines']);
        $this->assertContains('no_invoice_references', $data['warnings']);
        $this->assertSame($centre->id, $data['customer_id']);
        $this->assertCount(1, $data['suggestions']);
        $this->assertSame($invoice->id, $data['suggestions'][0]['invoice_id']);
    }

    public function test_confirming_creates_draft_payments_linked_to_the_advice_and_leaves_unmatched_open(): void
    {
        $this->fakeOcr('uob-payment-advice.txt');

        $ascensus = Customer::factory()->create(['name' => "ASCENSUS-BEH'S CLINIC FOR WOMEN PTE LTD"]);
        $invoiceA = $this->seedInvoice($ascensus, '2026/08/223', 7194.00, '2026-08-31');
        $invoiceB = $this->seedInvoice($ascensus, '2026/07/089', 2000.00, '2026-07-14');

        $upload = $this->upload('uob.jpg')->assertCreated();
        $importId = $upload->json('data.id');

        // Confirm both allocations, one partial on invoice B.
        $confirm = $this->actingAs($this->user)->postJson("/api/v1/payment-advices/{$importId}/confirm", [
            'allocations' => [
                ['line_index' => 0, 'invoice_id' => $invoiceA->id, 'amount' => 7194.00],
                ['line_index' => 1, 'invoice_id' => $invoiceB->id, 'amount' => 1744.00],
            ],
        ]);

        $confirm->assertOk();
        $this->assertSame('confirmed', $confirm->json('data.status'));

        $payments = Payment::query()->where('payment_advice_import_id', $importId)->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame([$invoiceA->id, $invoiceB->id], $payments->pluck('invoice_id')->all());
        $this->assertSame(7194.00, (float) $payments[0]->amount);
        $this->assertSame(1744.00, (float) $payments[1]->amount);

        // Drafts only: no ledger write until the operator posts them.
        foreach ($payments as $payment) {
            $this->assertNull($payment->journal_entry_id);
            $this->assertSame('recorded', $payment->payment_status);
        }

        // The advice is linked to its payments and keeps the payment ids.
        $this->assertSame([$payments[0]->id, $payments[1]->id], Payment::query()
            ->where('payment_advice_import_id', $importId)
            ->pluck('id')
            ->all());
        $this->assertNotNull($confirm->json('data.confirmed_at'));

        // A confirmed advice cannot be confirmed twice.
        $this->actingAs($this->user)
            ->postJson("/api/v1/payment-advices/{$importId}/confirm", [
                'allocations' => [['line_index' => 0, 'invoice_id' => $invoiceA->id, 'amount' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_unauthenticated_uploads_are_rejected(): void
    {
        $this->postJson('/api/v1/payment-advices', [])->assertStatus(401);
    }
}
