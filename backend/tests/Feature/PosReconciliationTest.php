<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Reconciliation\PosPaymentGLReconciliationService;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Product $product;

    protected Customer $customer;

    protected PosTerminal $terminal;

    protected PosSession $session;

    protected PosPaymentGLReconciliationService $reconciliationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create([
            'price' => 100.00,
            'tax_rate' => 10.00,
            'stock_quantity' => 1000,
        ]);

        $this->terminal = PosTerminal::factory()->create();
        $this->session = PosSession::factory()->create([
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->user->id,
            'status' => 'open',
        ]);

        $this->reconciliationService = app(PosPaymentGLReconciliationService::class);
    }

    /**
     * Test REC-001: Clean POS sale reconciliation produces zero exceptions
     */
    public function test_clean_pos_sale_reconciliation_produces_zero_exceptions()
    {
        // Create and pay order
        $order = $this->createAndPayOrder();

        // Run reconciliation
        $exceptions = $this->reconciliationService->reconcile();

        // Verify no exceptions for this order
        $orderExceptions = collect($exceptions)->filter(function ($exception) use ($order) {
            return $exception['reference_id'] === $order->id
                && $exception['reference_type'] === 'pos_order';
        });

        $this->assertCount(0, $orderExceptions, 'Clean POS sale should produce zero reconciliation exceptions');
    }

    /**
     * Test refund payment without GL creates ReconciliationException
     */
    public function test_refund_payment_without_gl_creates_exception()
    {
        // Create and pay order
        $order = $this->createAndPayOrder();

        // Create refund payment WITHOUT GL entry (simulating bypass)
        $refundPayment = PosPayment::create([
            'pos_order_id' => $order->id,
            'method' => 'refund',
            'amount' => -110.00,
            'reference' => 'REFUND-'.uniqid(),
            'journal_entry_id' => null, // Missing GL entry
        ]);

        // Run reconciliation
        $exceptions = $this->reconciliationService->reconcile();

        // Verify exception exists for refund payment
        $refundExceptions = collect($exceptions)->filter(function ($exception) use ($refundPayment) {
            return $exception['reference_id'] === $refundPayment->id
                && $exception['reference_type'] === 'pos_payment'
                && $exception['exception_type'] === 'missing_gl_entry';
        });

        $this->assertGreaterThan(0, $refundExceptions->count(), 'Refund payment without GL should create reconciliation exception');
    }

    /**
     * Test paid POS order without invoice creates ReconciliationException
     */
    public function test_paid_order_without_invoice_creates_exception()
    {
        // Create and pay order
        $order = $this->createAndPayOrder();

        // Delete invoice if it exists (simulating missing invoice)
        if ($order->invoice_id) {
            DB::table('invoices')->where('id', $order->invoice_id)->delete();
            $order->update(['invoice_id' => null]);
        }

        // Run reconciliation
        $exceptions = $this->reconciliationService->reconcile();

        // Verify exception exists for missing invoice
        $invoiceExceptions = collect($exceptions)->filter(function ($exception) use ($order) {
            return $exception['reference_id'] === $order->id
                && $exception['reference_type'] === 'pos_order'
                && $exception['exception_type'] === 'missing_invoice';
        });

        $this->assertGreaterThan(0, $invoiceExceptions->count(), 'Paid order without invoice should create reconciliation exception');
    }

    /**
     * Test running reconciliation twice does not duplicate exceptions
     */
    public function test_reconciliation_idempotency()
    {
        // Create order with missing GL (to generate exception)
        $order = $this->createAndPayOrder();

        // Create payment without GL
        $payment = PosPayment::create([
            'pos_order_id' => $order->id,
            'method' => 'refund',
            'amount' => -110.00,
            'reference' => 'REFUND-'.uniqid(),
            'journal_entry_id' => null,
        ]);

        // Run reconciliation first time
        $exceptions1 = $this->reconciliationService->reconcile();
        $count1 = count($exceptions1);

        // Run reconciliation second time
        $exceptions2 = $this->reconciliationService->reconcile();
        $count2 = count($exceptions2);

        // Verify exception count is the same (no duplicates)
        $this->assertEquals($count1, $count2, 'Running reconciliation twice should not duplicate exceptions');
    }

    /**
     * Test payment/invoice match reconciliation
     */
    public function test_payment_invoice_match_reconciliation()
    {
        // Create and pay order
        $order = $this->createAndPayOrder();

        // Verify payment exists
        $payment = $order->payments()->first();
        $this->assertNotNull($payment, 'Payment must exist');

        // Verify invoice exists
        $this->assertNotNull($order->invoice_id, 'Invoice must exist');

        // Run reconciliation
        $exceptions = $this->reconciliationService->reconcile();

        // Verify no payment/invoice mismatch exceptions
        $mismatchExceptions = collect($exceptions)->filter(function ($exception) use ($order) {
            return $exception['reference_id'] === $order->id
                && $exception['exception_type'] === 'payment_invoice_mismatch';
        });

        $this->assertCount(0, $mismatchExceptions, 'Payment and invoice should match');
    }

    /**
     * Test GL/payment match reconciliation
     */
    public function test_gl_payment_match_reconciliation()
    {
        // Create and pay order
        $order = $this->createAndPayOrder();

        // Verify payment has GL entry
        $payment = $order->payments()->first();
        $this->assertNotNull($payment->journal_entry_id, 'Payment must have GL entry');

        // Verify GL entry exists
        $glEntry = JournalEntry::find($payment->journal_entry_id);
        $this->assertNotNull($glEntry, 'GL entry must exist');

        // Run reconciliation
        $exceptions = $this->reconciliationService->reconcile();

        // Verify no GL/payment mismatch exceptions
        $mismatchExceptions = collect($exceptions)->filter(function ($exception) use ($payment) {
            return $exception['reference_id'] === $payment->id
                && $exception['exception_type'] === 'gl_payment_mismatch';
        });

        $this->assertCount(0, $mismatchExceptions, 'GL and payment should match');
    }

    /**
     * Test tax/invoice match reconciliation
     */
    public function test_tax_invoice_match_reconciliation()
    {
        // Create and pay order
        $order = $this->createAndPayOrder();

        // Verify tax transaction details exist
        $taxDetails = DB::table('tax_transaction_details')
            ->where('source_document_id', $order->id)
            ->where('source_document_type', 'pos_order')
            ->first();

        $this->assertNotNull($taxDetails, 'Tax transaction details must exist');

        // Run reconciliation
        $exceptions = $this->reconciliationService->reconcile();

        // Verify no tax/invoice mismatch exceptions
        $mismatchExceptions = collect($exceptions)->filter(function ($exception) use ($order) {
            return $exception['reference_id'] === $order->id
                && $exception['exception_type'] === 'tax_invoice_mismatch';
        });

        $this->assertCount(0, $mismatchExceptions, 'Tax and invoice should match');
    }

    /**
     * Test stock/order match reconciliation
     */
    public function test_stock_order_match_reconciliation()
    {
        // Create and pay order
        $order = $this->createAndPayOrder();

        // Verify stock movements exist
        $stockMovements = StockMovement::where('reference_id', $order->id)
            ->where('reference_type', 'pos_order')
            ->get();

        $this->assertGreaterThan(0, $stockMovements->count(), 'Stock movements must exist');

        // Run reconciliation
        $exceptions = $this->reconciliationService->reconcile();

        // Verify no stock/order mismatch exceptions
        $mismatchExceptions = collect($exceptions)->filter(function ($exception) use ($order) {
            return $exception['reference_id'] === $order->id
                && $exception['exception_type'] === 'stock_order_mismatch';
        });

        $this->assertCount(0, $mismatchExceptions, 'Stock and order should match');
    }

    /**
     * Test refund/GL match reconciliation
     */
    public function test_refund_gl_match_reconciliation()
    {
        // Create and pay order
        $order = $this->createAndPayOrder();

        // Create refund with GL entry
        $this->actingAs($this->user)->postJson("/api/pos/orders/{$order->id}/refund", [
            'notes' => 'Test refund',
            'refund_method' => 'cash',
        ]);

        // Verify refund payment has GL entry
        $refundPayment = PosPayment::where('pos_order_id', $order->id)
            ->where('method', 'refund')
            ->first();

        $this->assertNotNull($refundPayment, 'Refund payment must exist');
        $this->assertNotNull($refundPayment->journal_entry_id, 'Refund payment must have GL entry');

        // Run reconciliation
        $exceptions = $this->reconciliationService->reconcile();

        // Verify no refund/GL mismatch exceptions
        $mismatchExceptions = collect($exceptions)->filter(function ($exception) use ($refundPayment) {
            return $exception['reference_id'] === $refundPayment->id
                && $exception['exception_type'] === 'missing_gl_entry';
        });

        $this->assertCount(0, $mismatchExceptions, 'Refund and GL should match');
    }

    /**
     * Test replay idempotency in reconciliation
     */
    public function test_replay_reconciliation_idempotency()
    {
        // Create and pay order
        $order = $this->createAndPayOrder();

        // Attempt duplicate payment (should be idempotent)
        try {
            app(\Modules\POS\Services\PosPaymentCompletionService::class)->complete(
                $order,
                'cash',
                110.00,
                null
            );
        } catch (\Exception $e) {
            // Expected to fail or be idempotent
        }

        // Run reconciliation
        $exceptions = $this->reconciliationService->reconcile();

        // Verify no duplicate exceptions
        $orderExceptions = collect($exceptions)->filter(function ($exception) use ($order) {
            return $exception['reference_id'] === $order->id;
        });

        $this->assertCount(0, $orderExceptions, 'Replay should not create reconciliation exceptions');
    }

    // Helper methods

    protected function createAndPayOrder(): PosOrder
    {
        // Create order
        $response = $this->actingAs($this->user)->postJson('/api/pos/orders', [
            'session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $orderId = $response->json('id');

        // Pay order
        $paymentResponse = $this->actingAs($this->user)->postJson("/api/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        $paymentResponse->assertStatus(200);

        return PosOrder::find($orderId);
    }
}
