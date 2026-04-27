<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Product;
use App\Models\Customer;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PosAuditLineageTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected User $manager;
    protected Product $product;
    protected Customer $customer;
    protected PosTerminal $terminal;
    protected PosSession $session;
    protected AuditLogger $auditLogger;

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
        $this->session = PosSession::factory()->create([
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->cashier->id,
            'status' => 'open',
        ]);

        $this->auditLogger = app(AuditLogger::class);
    }

    /**
     * Test AUD-001: Payment creates audit log
     */
    public function test_payment_creates_audit_log()
    {
        $order = $this->createAndPayOrder();

        // Log payment
        $payment = $order->payments()->first();
        $this->auditLogger->logPayment(
            orderId: $order->id,
            paymentId: $payment->id,
            method: 'cash',
            amount: 110.00,
            terminalId: $this->terminal->id,
            sessionId: $this->session->id
        );

        // Verify audit log exists
        $auditLog = AuditLog::where('operation', 'pos_payment')
            ->where('reference_id', $order->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals('pos_payment', $auditLog->operation);
        $this->assertEquals('pos_order', $auditLog->reference_type);
        $this->assertEquals($order->id, $auditLog->reference_id);
        $this->assertEquals($this->cashier->id, $auditLog->actor_id);
        $this->assertEquals($this->terminal->id, $auditLog->terminal_id);
        $this->assertEquals($this->session->id, $auditLog->session_id);
        $this->assertNotNull($auditLog->after_state);
        $this->assertEquals($payment->id, $auditLog->after_state['payment_id']);
    }

    /**
     * Test AUD-001: Void creates audit log
     */
    public function test_void_creates_audit_log()
    {
        $order = $this->createAndPayOrder();

        // Log void
        $this->auditLogger->logVoid(
            orderId: $order->id,
            voidReason: 'Customer request',
            approvedBy: $this->manager->id,
            terminalId: $this->terminal->id,
            sessionId: $this->session->id
        );

        // Verify audit log exists
        $auditLog = AuditLog::where('operation', 'pos_void')
            ->where('reference_id', $order->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals('pos_void', $auditLog->operation);
        $this->assertEquals($order->id, $auditLog->reference_id);
        $this->assertEquals($this->cashier->id, $auditLog->actor_id);
        $this->assertEquals($this->terminal->id, $auditLog->terminal_id);
        $this->assertEquals($this->session->id, $auditLog->session_id);
        $this->assertEquals('Customer request', $auditLog->after_state['void_reason']);
        $this->assertEquals($this->manager->id, $auditLog->after_state['approved_by']);
    }

    /**
     * Test AUD-001: Refund creates audit log
     */
    public function test_refund_creates_audit_log()
    {
        $order = $this->createAndPayOrder();

        // Log refund
        $this->auditLogger->logRefund(
            orderId: $order->id,
            refundPaymentId: 999,
            refundAmount: -110.00,
            notes: 'Customer request',
            approvedBy: $this->manager->id,
            terminalId: $this->terminal->id,
            sessionId: $this->session->id
        );

        // Verify audit log exists
        $auditLog = AuditLog::where('operation', 'pos_refund')
            ->where('reference_id', $order->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals('pos_refund', $auditLog->operation);
        $this->assertEquals($order->id, $auditLog->reference_id);
        $this->assertEquals(-110.00, $auditLog->after_state['refund_amount']);
        $this->assertEquals($this->manager->id, $auditLog->after_state['approved_by']);
    }

    /**
     * Test AUD-001: Approval grant creates audit log
     */
    public function test_approval_grant_creates_audit_log()
    {
        // Log approval grant
        $this->auditLogger->logApprovalGrant(
            approvalRequestId: 1,
            operation: 'void',
            referenceId: 123,
            terminalId: $this->terminal->id,
            sessionId: $this->session->id
        );

        // Verify audit log exists
        $auditLog = AuditLog::where('operation', 'approval_grant')
            ->where('reference_id', 1)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals('approval_grant', $auditLog->operation);
        $this->assertEquals('approval_request', $auditLog->reference_type);
        $this->assertEquals('void', $auditLog->metadata['approved_operation']);
        $this->assertEquals(123, $auditLog->metadata['approved_reference_id']);
    }

    /**
     * Test AUD-001: Audit log idempotency
     */
    public function test_audit_log_idempotency()
    {
        $order = $this->createAndPayOrder();
        $payment = $order->payments()->first();

        // Log payment twice with same idempotency key
        $log1 = $this->auditLogger->logPayment(
            orderId: $order->id,
            paymentId: $payment->id,
            method: 'cash',
            amount: 110.00,
            terminalId: $this->terminal->id,
            sessionId: $this->session->id
        );

        $log2 = $this->auditLogger->logPayment(
            orderId: $order->id,
            paymentId: $payment->id,
            method: 'cash',
            amount: 110.00,
            terminalId: $this->terminal->id,
            sessionId: $this->session->id
        );

        // Verify same log returned
        $this->assertEquals($log1->id, $log2->id);

        // Verify only one log exists
        $count = AuditLog::where('operation', 'pos_payment')
            ->where('reference_id', $order->id)
            ->count();
        $this->assertEquals(1, $count);
    }

    /**
     * Test AUD-002: Audit logs are immutable (cannot be updated)
     */
    public function test_audit_logs_cannot_be_updated()
    {
        $order = $this->createAndPayOrder();
        $payment = $order->payments()->first();

        $auditLog = $this->auditLogger->logPayment(
            orderId: $order->id,
            paymentId: $payment->id,
            method: 'cash',
            amount: 110.00
        );

        // Attempt to update
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Audit logs are immutable and cannot be updated');

        $auditLog->operation = 'modified';
        $auditLog->save();
    }

    /**
     * Test AUD-002: Audit logs are immutable (cannot be deleted)
     */
    public function test_audit_logs_cannot_be_deleted()
    {
        $order = $this->createAndPayOrder();
        $payment = $order->payments()->first();

        $auditLog = $this->auditLogger->logPayment(
            orderId: $order->id,
            paymentId: $payment->id,
            method: 'cash',
            amount: 110.00
        );

        // Attempt to delete
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Audit logs are immutable and cannot be deleted');

        $auditLog->delete();
    }

    /**
     * Test AUD-001: Audit log includes request context
     */
    public function test_audit_log_includes_request_context()
    {
        $order = $this->createAndPayOrder();
        $payment = $order->payments()->first();

        $auditLog = $this->auditLogger->logPayment(
            orderId: $order->id,
            paymentId: $payment->id,
            method: 'cash',
            amount: 110.00
        );

        // Verify request context captured
        $this->assertNotNull($auditLog->ip_address);
        $this->assertNotNull($auditLog->source_route);
    }

    // Helper methods

    protected function createAndPayOrder(): PosOrder
    {
        // Create order
        $response = $this->actingAs($this->cashier)->postJson('/api/pos/orders', [
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

        $orderId = $response->json('id');

        // Pay order
        $this->actingAs($this->cashier)->postJson("/api/pos/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        return PosOrder::find($orderId);
    }
}
