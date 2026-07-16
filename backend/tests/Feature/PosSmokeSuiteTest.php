<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * POS Smoke Test Suite
 *
 * Critical end-to-end tests that verify core POS functionality
 * after deployment. These tests should be run in staging before
 * production go-live.
 */
class PosSmokeSuiteTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected User $manager;

    protected Product $product;

    protected Customer $customer;

    protected PosTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        Role::create(['name' => 'cashier']);
        Role::create(['name' => 'manager']);

        // Create users
        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');

        // Create test data
        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create([
            'price' => 100.00,
            'tax_rate' => 10.00,
            'stock_quantity' => 1000,
        ]);

        $this->terminal = PosTerminal::factory()->create();
    }

    /**
     * SMOKE TEST 1: Session Open/Close
     */
    public function test_smoke_session_open_and_close()
    {
        // Open session
        $response = $this->actingAs($this->cashier)->postJson('/api/pos/sessions/open', [
            'terminal_id' => $this->terminal->id,
            'opening_cash' => 100.00,
        ]);

        $response->assertStatus(200);
        $sessionId = $response->json('id');

        // Verify session is open
        $session = PosSession::find($sessionId);
        $this->assertEquals('open', $session->status);
        $this->assertEquals(100.00, $session->opening_cash);

        // Close session
        $closeResponse = $this->actingAs($this->cashier)->postJson("/api/pos/sessions/{$sessionId}/close", [
            'closing_cash' => 210.00,
            'notes' => 'End of shift',
        ]);

        $closeResponse->assertStatus(200);

        // Verify session is closed
        $session->refresh();
        $this->assertEquals('closed', $session->status);
        $this->assertEquals(210.00, $session->closing_cash);
    }

    /**
     * SMOKE TEST 2: Order Create and Pay
     */
    public function test_smoke_order_create_and_pay()
    {
        // Open session
        $session = $this->openSession();

        // Create order
        $response = $this->actingAs($this->cashier)->postJson('/api/pos/orders', [
            'session_id' => $session->id,
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

        // Verify order created
        $order = PosOrder::find($orderId);
        $this->assertEquals('draft', $order->status);
        $this->assertEquals(100.00, $order->subtotal);
        $this->assertEquals(10.00, $order->tax_amount);
        $this->assertEquals(110.00, $order->total);

        // Pay order
        $payResponse = $this->actingAs($this->cashier)->postJson("/api/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        $payResponse->assertStatus(200);

        // Verify order paid
        $order->refresh();
        $this->assertEquals('paid', $order->status);
        $this->assertNotNull($order->paid_at);

        // Verify payment record
        $payment = $order->payments()->first();
        $this->assertNotNull($payment);
        $this->assertEquals('cash', $payment->method);
        $this->assertEquals(110.00, $payment->amount);

        // Verify GL entry
        $this->assertNotNull($payment->journal_entry_id);
        $glEntry = JournalEntry::find($payment->journal_entry_id);
        $this->assertNotNull($glEntry);

        // Verify stock movement
        $stockMovement = StockMovement::where('reference_id', $orderId)
            ->where('reference_type', 'pos_order')
            ->first();
        $this->assertNotNull($stockMovement);
        $this->assertEquals(-1, $stockMovement->quantity);
    }

    /**
     * SMOKE TEST 3: Void with Approval
     */
    public function test_smoke_void_with_approval()
    {
        $session = $this->openSession();
        $order = $this->createAndPayOrder($session);

        // Request void (as cashier)
        $response = $this->actingAs($this->cashier)->postJson("/api/pos/orders/{$order->id}/void", [
            'void_reason' => 'Customer request',
        ]);

        $response->assertStatus(202);
        $approvalRequestId = $response->json('approval_request.id');
        $this->assertNotNull($approvalRequestId);

        // Approve void (as manager)
        $approveResponse = $this->actingAs($this->manager)->postJson("/api/pos/orders/{$order->id}/void", [
            'void_reason' => 'Customer request',
            'approval_request_id' => $approvalRequestId,
        ]);

        $approveResponse->assertStatus(200);

        // Verify order voided
        $order->refresh();
        $this->assertEquals('void', $order->status);
        $this->assertNotNull($order->voided_by);
        $this->assertNotNull($order->void_approved_by);
        $this->assertNotNull($order->void_reason);

        // Verify GL reversal
        $reversalEntry = JournalEntry::where('source_document_id', $order->id)
            ->where('source_document_type', 'pos_order')
            ->where('type', 'reversal')
            ->first();
        $this->assertNotNull($reversalEntry);

        // Verify stock restored
        $stockMovements = StockMovement::where('reference_id', $order->id)
            ->where('reference_type', 'pos_order')
            ->get();
        $totalQuantity = $stockMovements->sum('quantity');
        $this->assertEquals(0, $totalQuantity, 'Stock should be restored (net zero)');
    }

    /**
     * SMOKE TEST 4: Refund with Approval
     */
    public function test_smoke_refund_with_approval()
    {
        $session = $this->openSession();
        $order = $this->createAndPayOrder($session);

        // Request refund (as cashier)
        $response = $this->actingAs($this->cashier)->postJson("/api/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer request',
            'refund_method' => 'cash',
        ]);

        $response->assertStatus(202);
        $approvalRequestId = $response->json('approval_request.id');
        $this->assertNotNull($approvalRequestId);

        // Approve refund (as manager)
        $approveResponse = $this->actingAs($this->manager)->postJson("/api/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer request',
            'refund_method' => 'cash',
            'approval_request_id' => $approvalRequestId,
        ]);

        $approveResponse->assertStatus(200);

        // Verify refund payment created
        $refundPayment = PosPayment::where('pos_order_id', $order->id)
            ->where('method', 'refund')
            ->first();
        $this->assertNotNull($refundPayment);
        $this->assertLessThan(0, $refundPayment->amount);

        // Verify refund payment has GL entry
        $this->assertNotNull($refundPayment->journal_entry_id);
    }

    /**
     * SMOKE TEST 5: Tax Audit Trail
     */
    public function test_smoke_tax_audit_trail()
    {
        $session = $this->openSession();
        $order = $this->createAndPayOrder($session);

        // Verify tax_transaction_details record exists
        $taxDetail = DB::table('tax_transaction_details')
            ->where('source_document_id', $order->id)
            ->where('source_document_type', 'pos_order')
            ->first();

        $this->assertNotNull($taxDetail, 'Tax transaction detail must exist');
        $this->assertNotNull($taxDetail->tax_code, 'Tax code must be populated');
        $this->assertEquals(10.00, $taxDetail->tax_amount, 'Tax amount must match order');
    }

    /**
     * SMOKE TEST 6: Payment Idempotency
     */
    public function test_smoke_payment_idempotency()
    {
        $session = $this->openSession();

        // Create order
        $response = $this->actingAs($this->cashier)->postJson('/api/pos/orders', [
            'session_id' => $session->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        $orderId = $response->json('id');

        // Pay order
        $this->actingAs($this->cashier)->postJson("/api/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        // Attempt duplicate payment
        $duplicateResponse = $this->actingAs($this->cashier)->postJson("/api/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        // Should fail or be idempotent
        $this->assertContains($duplicateResponse->status(), [200, 400, 422]);

        // Verify only one payment exists
        $paymentCount = PosPayment::where('pos_order_id', $orderId)->count();
        $this->assertEquals(1, $paymentCount, 'Only one payment should exist');

        // Verify only one GL entry exists
        $glCount = JournalEntry::where('source_document_id', $orderId)
            ->where('source_document_type', 'pos_order')
            ->where('type', 'standard')
            ->count();
        $this->assertEquals(1, $glCount, 'Only one GL entry should exist');
    }

    /**
     * SMOKE TEST 7: Session Enforcement
     */
    public function test_smoke_session_enforcement()
    {
        $session = $this->openSession();

        // Create order with valid session
        $response = $this->actingAs($this->cashier)->postJson('/api/pos/orders', [
            'session_id' => $session->id,
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

        // Close session
        $this->actingAs($this->cashier)->postJson("/api/pos/sessions/{$session->id}/close", [
            'closing_cash' => 100.00,
        ]);

        // Attempt to create order with closed session
        $closedSessionResponse = $this->actingAs($this->cashier)->postJson('/api/pos/orders', [
            'session_id' => $session->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        // Should fail
        $this->assertContains($closedSessionResponse->status(), [400, 422]);
    }

    /**
     * SMOKE TEST 8: Reconciliation
     */
    public function test_smoke_reconciliation()
    {
        $session = $this->openSession();
        $order = $this->createAndPayOrder($session);

        // Run reconciliation
        $reconciliationService = app(\App\Services\Reconciliation\PosPaymentGLReconciliationService::class);
        $exceptions = $reconciliationService->reconcile();

        // Verify no exceptions for clean order
        $orderExceptions = collect($exceptions)->filter(function ($exception) use ($order) {
            return $exception['reference_id'] === $order->id;
        });

        $this->assertCount(0, $orderExceptions, 'Clean order should produce zero reconciliation exceptions');
    }

    // Helper methods

    protected function openSession(): PosSession
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/pos/sessions/open', [
            'terminal_id' => $this->terminal->id,
            'opening_cash' => 100.00,
        ]);

        return PosSession::find($response->json('id'));
    }

    protected function createAndPayOrder(PosSession $session): PosOrder
    {
        // Create order
        $response = $this->actingAs($this->cashier)->postJson('/api/pos/orders', [
            'session_id' => $session->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        $orderId = $response->json('id');

        // Pay order
        $this->actingAs($this->cashier)->postJson("/api/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        return PosOrder::find($orderId);
    }
}
