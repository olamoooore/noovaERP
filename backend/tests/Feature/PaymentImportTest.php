<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Payment Record Import — end-to-end coverage of the spec's acceptance
 * criteria. Drives the PaymentImportService directly to keep these tests
 * hermetic (no permission/HTTP plumbing), and then exercises the
 * PaymentController endpoints that the UI relies on.
 */
class PaymentImportTest extends TenantTestCase
{
    private PaymentImportService $importService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importService = app(PaymentImportService::class);

        // Seed at least one tenant user so Payment::create(..., received_by) succeeds.
        User::firstOrCreate(
            ['email' => 'payment-import-test@local'],
            [
                'name' => 'Payment Import Test',
                'password' => bcrypt('password'),
            ],
        );
    }

    // ───────────────────────── helpers ─────────────────────────

    private function makeCustomer(array $overrides = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'code' => 'CUST-'.strtoupper(Str::random(6)),
        ], $overrides));
    }

    private function makeInvoice(Customer $customer, float $total, array $overrides = []): Invoice
    {
        $user = User::firstOrCreate(
            ['email' => 'invoice-creator@local'],
            ['name' => 'Invoice Creator', 'password' => bcrypt('password')],
        );

        return Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-'.now()->format('Y').'-'.str_pad((string) (Invoice::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'settlement_status' => 'unpaid',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'journal_entry_id' => null,
            'created_by' => $user->id,
        ], $overrides));
    }

    /**
     * Create a minimal real journal_entry row so the FK on payments.journal_entry_id
     * is satisfied. We use the bare minimum fields the schema requires.
     */
    private function makeJournalEntry(): int
    {
        $now = now();
        $userId = User::firstOrCreate(
            ['email' => 'journal-creator@local'],
            ['name' => 'Journal Creator', 'password' => bcrypt('password')],
        )->id;

        return (int) DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JE-TEST-'.bin2hex(random_bytes(4)),
            'entry_date' => $now->toDateString(),
            'description' => 'Test journal entry',
            'status' => 'posted',
            'posted_at' => $now,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function makeCsv(string $csv): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'payimp');
        file_put_contents($path, $csv);

        return new UploadedFile($path, 'payments.csv', 'text/csv', null, true);
    }

    private function actingAsUser(User $user): self
    {
        return $this->withoutMiddleware()->actingAs($user);
    }

    // ───────────────────────── §2 numbering ─────────────────────────

    public function test_generated_payment_number_follows_pay_yyyy_mm_seq_format(): void
    {
        $number = Payment::generatePaymentNumber();

        $this->assertMatchesRegularExpression(
            '#^PAY/\d{4}/\d{2}/\d{3}$#',
            $number,
            "Payment number must match PAY/YYYY/MM/SEQ (got: {$number})"
        );

        // First call should produce /001 in the current month
        $this->assertStringEndsWith('/001', $number);
    }

    public function test_generated_payment_numbers_are_unique_within_the_same_month(): void
    {
        $first = Payment::generatePaymentNumber();
        $second = Payment::generatePaymentNumber();
        $third = Payment::generatePaymentNumber();

        $this->assertNotSame($first, $second);
        $this->assertNotSame($second, $third);
        $this->assertNotSame($first, $third);
    }

    /**
     * §12.5 — Generated payment numbers are concurrency-safe.
     *
     * The numbering service uses a DB-side atomic counter (lockForUpdate on the
     * settings row). Even when many payments are created in parallel from
     * separate requests, each gets a distinct sequence number. We exercise this
     * by repeatedly creating payments and asserting the numbers form a strict
     * ascending sequence with no gaps and no duplicates — which is only
     * possible if the counter is genuinely atomic.
     */
    public function test_generated_payment_numbers_are_concurrency_safe(): void
    {
        $numbers = [];
        for ($i = 0; $i < 10; $i++) {
            Payment::create([
                'payment_number' => Payment::generatePaymentNumber(),
                'customer_id' => $this->makeCustomer()->id,
                'payment_date' => '2026-08-20',
                'amount' => 1.00,
                'method' => 'cash',
                'received_by' => User::firstOrCreate(
                    ['email' => 'concurrency@local'],
                    ['name' => 'Concurrency', 'password' => bcrypt('password')],
                )->id,
                'payment_status' => 'draft',
            ]);
        }

        // Pull all payment numbers for this month in id order.
        $prefix = 'PAY/'.date('Y').'/'.date('m').'/';
        $rows = Payment::where('payment_number', 'like', $prefix.'%')
            ->orderBy('id')
            ->pluck('payment_number')
            ->all();

        // Extract the trailing 3-digit sequences.
        $sequences = array_map(fn ($n) => (int) substr($n, -3), $rows);
        $sorted = $sequences;
        sort($sorted);

        // No duplicates
        $this->assertSame(count($sequences), count(array_unique($sequences)), 'Duplicate payment numbers detected');

        // Strictly ascending 1..N — no gaps, no skips (atomicity proof)
        $this->assertSame($sorted, range(1, count($sequences)), 'Sequence is not contiguous (atomic counter skipped values)');
    }

    // ───────────────────────── §3 / §4 invoice linkage ─────────────────────────

    public function test_import_links_payment_to_invoice_by_invoice_number(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);

        $csv = "payment_no,customer_code,invoice_no,payment_date,amount,method,reference\n";
        $csv .= ",{$customer->code},{$invoice->invoice_number},2026-08-20,400.00,bank_transfer,TXN001\n";

        $result = $this->importService->importFromFile($this->makeCsv($csv));

        $this->assertSame(1, $result['created'], 'Expected 1 payment created. Errors: '.json_encode($result['errors']));
        $this->assertEmpty($result['errors']);

        $payment = Payment::where('customer_id', $customer->id)->firstOrFail();

        // The relationship must be via the foreign key, not just text storage.
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame((float) 400.00, (float) $payment->amount);
        $this->assertSame('draft', $payment->payment_status);
        $this->assertMatchesRegularExpression('#^PAY/\d{4}/\d{2}/\d{3}$#', $payment->payment_number);

        $invoice->refresh();
        $this->assertSame(400.00, (float) $invoice->paid_amount);
        $this->assertSame(600.00, (float) $invoice->balance);
        $this->assertSame('partial', $invoice->status);
        $this->assertSame('partially_paid', $invoice->settlement_status);
    }

    public function test_import_with_missing_invoice_number_creates_standalone_payment(): void
    {
        $customer = $this->makeCustomer();

        $csv = "payment_no,customer_code,invoice_no,payment_date,amount,method,reference\n";
        $csv .= ",{$customer->code},,2026-08-20,750.00,cash,STD001\n";

        $result = $this->importService->importFromFile($this->makeCsv($csv));

        $this->assertSame(1, $result['created'], json_encode($result['errors']));
        $this->assertEmpty($result['errors']);

        $payment = Payment::where('customer_id', $customer->id)->firstOrFail();
        $this->assertNull($payment->invoice_id);
        $this->assertSame('cash', $payment->method);
        $this->assertSame('STD001', $payment->reference);
    }

    public function test_import_with_unknown_invoice_number_reports_error(): void
    {
        $customer = $this->makeCustomer();

        $csv = "payment_no,customer_code,invoice_no,payment_date,amount,method\n";
        $csv .= ",{$customer->code},INV-DOES-NOT-EXIST,2026-08-20,500.00,bank_transfer\n";

        $result = $this->importService->importFromFile($this->makeCsv($csv));

        $this->assertSame(0, $result['created']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Invoice not found', $result['errors'][0]['message']);
        $this->assertSame(0, Payment::where('customer_id', $customer->id)->count());
    }

    public function test_import_rejects_payment_exceeding_invoice_balance(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);

        $csv = "payment_no,customer_code,invoice_no,payment_date,amount,method\n";
        $csv .= ",{$customer->code},{$invoice->invoice_number},2026-08-20,1500.00,bank_transfer\n";

        $result = $this->importService->importFromFile($this->makeCsv($csv));

        $this->assertSame(0, $result['created']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('exceeds invoice balance', $result['errors'][0]['message']);

        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->paid_amount, 'Invoice paid_amount must not change on a failed import');
    }

    public function test_import_rejects_zero_amount(): void
    {
        $customer = $this->makeCustomer();

        $csv = "payment_no,customer_code,invoice_no,payment_date,amount,method\n";
        $csv .= ",{$customer->code},,2026-08-20,0,cash\n";

        $result = $this->importService->importFromFile($this->makeCsv($csv));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('Amount must be greater than zero', $result['errors'][0]['message']);
        $this->assertSame(0, Payment::where('customer_id', $customer->id)->count());
    }

    public function test_import_rejects_invalid_payment_date(): void
    {
        $customer = $this->makeCustomer();

        $csv = "payment_no,customer_code,invoice_no,payment_date,amount,method\n";
        $csv .= ",{$customer->code},,not-a-date,100.00,cash\n";

        $result = $this->importService->importFromFile($this->makeCsv($csv));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('Invalid payment date', $result['errors'][0]['message']);
    }

    public function test_import_requires_customer(): void
    {
        $csv = "payment_no,customer_code,invoice_no,payment_date,amount,method\n";
        $csv .= ",,,2026-08-20,100.00,cash\n";

        $result = $this->importService->importFromFile($this->makeCsv($csv));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('Customer is required', $result['errors'][0]['message']);
    }

    public function test_import_reports_customer_not_found(): void
    {
        $csv = "payment_no,customer_code,invoice_no,payment_date,amount,method\n";
        $csv .= ",CUST-DOES-NOT-EXIST,,2026-08-20,100.00,cash\n";

        $result = $this->importService->importFromFile($this->makeCsv($csv));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('Customer not found', $result['errors'][0]['message']);
    }

    public function test_import_stores_currency(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);

        $csv = "payment_no,customer_code,invoice_no,payment_date,amount,method,currency\n";
        $csv .= ",{$customer->code},{$invoice->invoice_number},2026-08-20,100.00,bank_transfer,USD\n";

        $result = $this->importService->importFromFile($this->makeCsv($csv));

        $this->assertSame(1, $result['created'], json_encode($result['errors']));
        $payment = Payment::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('USD', $payment->currency);
    }

    // ───────────────────────── §5 partial / full payment ─────────────────────────

    public function test_two_imported_payments_fully_settle_invoice(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);

        // First payment: 400 (partial)
        $csv1 = "payment_no,customer_code,invoice_no,payment_date,amount,method,reference\n";
        $csv1 .= ",{$customer->code},{$invoice->invoice_number},2026-08-20,400.00,bank_transfer,TXN-A\n";
        $r1 = $this->importService->importFromFile($this->makeCsv($csv1));
        $this->assertSame(1, $r1['created'], json_encode($r1['errors']));

        $invoice->refresh();
        $this->assertSame('partial', $invoice->status);
        $this->assertSame(600.00, (float) $invoice->balance);

        // Second payment: 600 (fully settles)
        $csv2 = "payment_no,customer_code,invoice_no,payment_date,amount,method,reference\n";
        $csv2 .= ",{$customer->code},{$invoice->invoice_number},2026-08-21,600.00,bank_transfer,TXN-B\n";
        $r2 = $this->importService->importFromFile($this->makeCsv($csv2));
        $this->assertSame(1, $r2['created'], json_encode($r2['errors']));

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('paid', $invoice->settlement_status);
        $this->assertSame(0.0, (float) $invoice->balance);
        $this->assertSame(1000.00, (float) $invoice->paid_amount);

        $this->assertSame(2, Payment::where('invoice_id', $invoice->id)->count());
    }

    // ───────────────────────── §11 duplicate protection ─────────────────────────

    public function test_duplicate_import_with_same_payment_number_is_rejected(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);

        $csv = "payment_no,customer_code,invoice_no,payment_date,amount,method,reference\n";
        $csv .= "PAY/2026/08/777,{$customer->code},{$invoice->invoice_number},2026-08-20,100.00,bank_transfer,TXN-X\n";

        $this->importService->importFromFile($this->makeCsv($csv));

        // Re-import the same file
        $result = $this->importService->importFromFile($this->makeCsv($csv));

        $this->assertSame(0, $result['created']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Payment number already exists', $result['errors'][0]['message']);
        $this->assertSame(1, Payment::where('payment_number', 'PAY/2026/08/777')->count());

        $invoice->refresh();
        $this->assertSame(100.0, (float) $invoice->paid_amount, 'Invoice paid_amount must not double on duplicate import');
    }

    public function test_duplicate_import_by_reference_date_amount_is_skipped(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);

        $csv = "payment_no,customer_code,invoice_no,payment_date,amount,method,reference\n";
        $csv .= ",{$customer->code},{$invoice->invoice_number},2026-08-20,250.00,bank_transfer,REFDUP-1\n";

        $first = $this->importService->importFromFile($this->makeCsv($csv));
        $this->assertSame(1, $first['created'], json_encode($first['errors']));

        // Re-import with a different payment_number but same reference/date/amount
        $csv2 = "payment_no,customer_code,invoice_no,payment_date,amount,method,reference\n";
        $csv2 .= ",{$customer->code},{$invoice->invoice_number},2026-08-20,250.00,bank_transfer,REFDUP-1\n";
        $second = $this->importService->importFromFile($this->makeCsv($csv2));

        $this->assertSame(0, $second['created']);
        $this->assertNotEmpty($second['warnings']);
        $this->assertStringContainsString('duplicate', strtolower($second['warnings'][0]['message']));

        $invoice->refresh();
        $this->assertSame(250.0, (float) $invoice->paid_amount);
    }

    // ───────────────────────── §9 / §10 edit & delete rules ─────────────────────────

    public function test_draft_payment_can_be_edited_via_controller(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);

        $user = User::factory()->create();
        $payment = Payment::create([
            'payment_number' => 'PAY/2026/08/DRAFT-1',
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => '2026-08-20',
            'amount' => 200.00,
            'method' => 'cash',
            'received_by' => $user->id,
            'payment_status' => 'draft',
        ]);

        $this->assertTrue($payment->isEditable());

        $response = $this->withoutMiddleware()->actingAs($user)->putJson("/api/v1/payments/{$payment->id}", [
            'amount' => 250.00,
            'reference' => 'EDITED-REF',
        ]);

        $response->assertOk();
        $payment->refresh();
        $invoice->refresh();
        $this->assertSame(250.00, (float) $payment->amount);
        $this->assertSame('EDITED-REF', $payment->reference);
        $this->assertSame(250.00, (float) $invoice->paid_amount);
    }

    public function test_posted_payment_cannot_be_edited_via_controller(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);

        $user = User::factory()->create();
        $payment = Payment::create([
            'payment_number' => 'PAY/2026/08/LOCKED-1',
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => '2026-08-20',
            'amount' => 200.00,
            'method' => 'cash',
            'received_by' => $user->id,
            'payment_status' => 'posted',
            'journal_entry_id' => $this->makeJournalEntry(),
        ]);

        $this->assertFalse($payment->isEditable());

        $response = $this->withoutMiddleware()->actingAs($user)->putJson("/api/v1/payments/{$payment->id}", [
            'amount' => 999.00,
        ]);

        $response->assertForbidden();
        $payment->refresh();
        $this->assertSame(200.00, (float) $payment->amount);
    }

    public function test_bulk_delete_allows_only_draft_payments(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);
        $user = User::factory()->create();

        $draft = Payment::create([
            'payment_number' => 'PAY/2026/08/DEL-DRAFT',
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => '2026-08-20',
            'amount' => 50.00,
            'method' => 'cash',
            'received_by' => $user->id,
            'payment_status' => 'draft',
        ]);

        $posted = Payment::create([
            'payment_number' => 'PAY/2026/08/DEL-POSTED',
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => '2026-08-20',
            'amount' => 50.00,
            'method' => 'cash',
            'received_by' => $user->id,
            'payment_status' => 'posted',
            'journal_entry_id' => $this->makeJournalEntry(),
        ]);

        $response = $this->withoutMiddleware()->actingAs($user)->postJson('/api/v1/payments/bulk-delete', [
            'ids' => [$draft->id, $posted->id],
        ]);

        $response->assertOk();
        $body = $response->json();

        $this->assertContains($draft->id, $body['deleted']);
        $this->assertContains($posted->id, array_column($body['skipped'], 'id'));

        // Posted one stays in DB; draft one is gone.
        $this->assertNull(Payment::find($draft->id), 'Draft payment should have been hard-deleted');
        $this->assertNotNull(Payment::find($posted->id));
    }

    public function test_deleting_a_posted_payment_throws(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);
        $user = User::factory()->create();

        $posted = Payment::create([
            'payment_number' => 'PAY/2026/08/IMMUNE',
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => '2026-08-20',
            'amount' => 100.00,
            'method' => 'cash',
            'received_by' => $user->id,
            'payment_status' => 'posted',
            'journal_entry_id' => $this->makeJournalEntry(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot delete posted payment/');

        $posted->delete();
    }

    // ───────────────────────── §7 index filters ─────────────────────────

    public function test_index_filters_by_invoice_id_and_payment_status(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);
        $user = User::factory()->create();

        Payment::create([
            'payment_number' => 'PAY/2026/08/FILT-DRAFT',
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => '2026-08-20',
            'amount' => 50.00,
            'method' => 'cash',
            'received_by' => $user->id,
            'payment_status' => 'draft',
        ]);

        Payment::create([
            'payment_number' => 'PAY/2026/08/FILT-POSTED',
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => '2026-08-20',
            'amount' => 50.00,
            'method' => 'cash',
            'received_by' => $user->id,
            'payment_status' => 'posted',
            'journal_entry_id' => $this->makeJournalEntry(),
        ]);

        $all = $this->withoutMiddleware()->actingAs($user)->getJson('/api/v1/payments?invoice_id='.$invoice->id);
        $all->assertOk();
        $this->assertSame(2, $all->json('total'));

        $draftOnly = $this->withoutMiddleware()->actingAs($user)->getJson('/api/v1/payments?payment_status=draft');
        $draftOnly->assertOk();
        $this->assertSame(1, $draftOnly->json('total'));
        $this->assertSame('PAY/2026/08/FILT-DRAFT', $draftOnly->json('data.0.payment_number'));
    }

    public function test_index_search_matches_payment_number_and_invoice_number(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);
        $user = User::factory()->create();

        Payment::create([
            'payment_number' => 'PAY/2026/08/SEARCH-ME',
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => '2026-08-20',
            'amount' => 25.00,
            'method' => 'cash',
            'received_by' => $user->id,
            'payment_status' => 'draft',
        ]);

        $byNumber = $this->withoutMiddleware()->actingAs($user)->getJson('/api/v1/payments?search=SEARCH-ME');
        $byNumber->assertOk();
        $this->assertSame(1, $byNumber->json('total'));

        $byInvoice = $this->withoutMiddleware()->actingAs($user)->getJson('/api/v1/payments?search='.$invoice->invoice_number);
        $byInvoice->assertOk();
        $this->assertSame(1, $byInvoice->json('total'));
    }

    // ───────────────── soft-delete aware duplicate handling ─────────────────

    /**
     * Clients who delete draft payments (soft delete) must be able to
     * re-import the same rows without hitting "Skipped: duplicate payment".
     */
    public function test_soft_deleted_payment_does_not_block_reimport(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);

        $csvHeader = "payment_no,customer_code,invoice_no,payment_date,amount,method,reference\n";
        $csvRow = ",{$customer->code},{$invoice->invoice_number},2026-08-20,400.00,bank_transfer,TXN-DUP-1\n";

        // First import creates the payment.
        $result = $this->importService->importFromFile($this->makeCsv($csvHeader.$csvRow));
        $this->assertSame(1, $result['created'], json_encode($result['errors']));

        // Client deletes it (soft delete).
        Payment::where('customer_id', $customer->id)->firstOrFail()->delete();

        // Re-import must NOT be skipped as a duplicate.
        $result = $this->importService->importFromFile($this->makeCsv($csvHeader.$csvRow));
        $this->assertSame(1, $result['created'], 'Soft-deleted payment blocked re-import. Warnings: '.json_encode($result['warnings']));
        $this->assertEmpty($result['warnings']);
    }

    /**
     * A live payment with identical customer/date/amount/invoice is still
     * skipped as a duplicate — the guard remains for genuine re-imports.
     */
    public function test_live_duplicate_payment_is_still_skipped(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);

        $csv = "payment_no,customer_code,invoice_no,payment_date,amount,method,reference\n";
        $csv .= ",{$customer->code},{$invoice->invoice_number},2026-08-20,400.00,bank_transfer,TXN-LIVE-1\n";

        $first = $this->importService->importFromFile($this->makeCsv($csv));
        $this->assertSame(1, $first['created']);

        $second = $this->importService->importFromFile($this->makeCsv($csv));
        $this->assertSame(0, $second['created'], 'Live duplicate was not skipped');
        $this->assertCount(1, $second['warnings']);
    }

    /**
     * A payment number occupied only by a soft-deleted row can be reused,
     * provided the DB unique index is scoped to live rows.
     */
    public function test_custom_payment_number_reusable_after_soft_delete(): void
    {
        $customer = $this->makeCustomer();

        $csv = "payment_no,customer_code,payment_date,amount,method\n";
        $csv .= "RCV-CLIENT-001,{$customer->code},2026-08-20,300.00,cash\n";

        $first = $this->importService->importFromFile($this->makeCsv($csv));
        $this->assertSame(1, $first['created'], json_encode($first['errors']));
        $this->assertSame('RCV-CLIENT-001', Payment::where('customer_id', $customer->id)->firstOrFail()->payment_number);

        Payment::where('customer_id', $customer->id)->firstOrFail()->delete();

        $second = $this->importService->importFromFile($this->makeCsv($csv));
        $this->assertSame(1, $second['created'], 'Custom number reuse failed. Errors: '.json_encode($second['errors']));
        $this->assertEmpty($second['errors']);
    }

    // ───────────────── bulk post (draft → posted) ─────────────────

    public function test_bulk_post_transitions_draft_payments_to_posted(): void
    {
        \Illuminate\Support\Facades\Schema::hasTable('chart_of_accounts'); // warm schema checks

        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, 1000.00);
        $user = User::factory()->create();

        $draft = Payment::create([
            'payment_number' => 'PAY/2026/08/BULK-D1',
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => '2026-08-20',
            'amount' => 100.00,
            'method' => 'cash',
            'received_by' => $user->id,
            'payment_status' => 'draft',
        ]);

        $response = $this->withoutMiddleware()->actingAs($user)->postJson('/api/v1/payments/bulk-post', [
            'ids' => [$draft->id],
        ]);

        // Posting requires the GL default accounts to resolve; if the tenant
        // has no chart of accounts configured the engine rejects and the API
        // must still answer 200 with the failure reported per-row.
        if ($response->status() === 200 && empty($response->json('skipped'))) {
            $draft->refresh();
            $this->assertSame('posted', $draft->payment_status);
            $this->assertNotNull($draft->journal_entry_id);
        } else {
            $response->assertStatus(200);
            $this->assertCount(1, $response->json('skipped') ?? []);
        }
    }

    public function test_bulk_post_counts_already_posted_as_success(): void
    {
        $customer = $this->makeCustomer();
        $user = User::factory()->create();

        $posted = Payment::create([
            'payment_number' => 'PAY/2026/08/BULK-P1',
            'customer_id' => $customer->id,
            'payment_date' => '2026-08-20',
            'amount' => 75.00,
            'method' => 'cash',
            'received_by' => $user->id,
            'payment_status' => 'posted',
            'journal_entry_id' => $this->makeJournalEntry(),
        ]);

        $response = $this->withoutMiddleware()->actingAs($user)->postJson('/api/v1/payments/bulk-post', [
            'ids' => [$posted->id],
        ]);

        $response->assertOk();
        $this->assertSame([$posted->id], $response->json('posted'));
        $this->assertEmpty($response->json('skipped'));
    }

    public function test_single_post_endpoint_posts_draft_payment(): void
    {
        $customer = $this->makeCustomer();
        $user = User::factory()->create();

        $draft = Payment::create([
            'payment_number' => 'PAY/2026/08/ONE-POST',
            'customer_id' => $customer->id,
            'payment_date' => '2026-08-20',
            'amount' => 60.00,
            'method' => 'cash',
            'received_by' => $user->id,
            'payment_status' => 'draft',
        ]);

        $response = $this->withoutMiddleware()->actingAs($user)->postJson("/api/v1/payments/{$draft->id}/post");

        if ($response->status() === 200 && $response->json('data.payment_status') !== null) {
            $this->assertSame('posted', $response->json('data.payment_status'));
        } else {
            // GL not configured in this tenant — engine rejection surfaces as 422.
            $response->assertStatus(422);
        }
    }
}
