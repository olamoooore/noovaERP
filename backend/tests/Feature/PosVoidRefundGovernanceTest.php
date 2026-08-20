<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Hash;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POS Void/Refund Governance Tests
 *
 * Tests the entitlement-based approval workflow.
 * NO manager_password — approval is via ApprovalRequest model.
 */
class PosVoidRefundGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    private function createTerminalWithWarehouse(): PosTerminal
    {
        $warehouse = Warehouse::create([
            'name' => 'POS Warehouse',
            'code' => 'POS-WH-001',
            'is_active' => true,
        ]);

        return PosTerminal::create([
            'name' => 'POS Terminal 1',
            'terminal_number' => 'TERM-001',
            'warehouse_id' => $warehouse->id,
            'is_active' => true,
        ]);
    }

    private function createOpenSession(PosTerminal $terminal): PosSession
    {
        return PosSession::create([
            'terminal_id' => $terminal->id,
            'status' => 'open',
            'opened_at' => now(),
            'opened_by' => 1,
        ]);
    }

    private function createSupervisorUser(): User
    {
        $user = User::create([
            'name' => 'Test Supervisor',
            'email' => 'supervisor@test.com',
            'password' => Hash::make('password123'),
        ]);

        $user->assignRole('supervisor');

        return $user;
    }

    private function createCashierUser(): User
    {
        return User::create([
            'name' => 'Test Cashier',
            'email' => 'cashier@test.com',
            'password' => Hash::make('password123'),
        ]);
    }

    private function createPaidOrder(PosTerminal $terminal, PosSession $session): PosOrder
    {
        $order = PosOrder::create([
            'order_number' => 'POS-TEST-'.uniqid(),
            'status' => 'paid',
            'paid_at' => now(),
            'terminal_id' => $terminal->id,
            'session_id' => $session->id,
            'subtotal' => 100.00,
            'tax_amount' => 0,
            'total' => 100.00,
            'created_by' => 1,
        ]);

        $order->items()->create([
            'product_id' => 1,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'quantity' => 1,
            'unit_price' => 100.00,
            'tax_rate' => 0,
            'line_total' => 100.00,
        ]);

        $order->payments()->create([
            'method' => 'cash',
            'amount' => 100.00,
            'received_by' => 1,
        ]);

        return $order;
    }

    /**
     * Create an approved ApprovalRequest for a given order and operation.
     */
    private function createApprovedRequest(PosOrder $order, string $type, User $reviewer): ApprovalRequest
    {
        return ApprovalRequest::create([
            'type' => $type,
            'source_type' => 'PosOrder',
            'source_id' => $order->id,
            'amount' => $order->total,
            'reason' => 'Test approval reason for '.$type,
            'status' => 'approved',
            'requested_by' => 1,
            'reviewed_by' => $reviewer->id,
            'requested_at' => now(),
            'reviewed_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
    }

    // ==========================================
    // VOID TESTS
    // ==========================================

    /** @test */
    public function test_void_without_approval_request_returns_202_requires_approval(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createPaidOrder($terminal, $session);

        $response = $this->postJson("/api/pos/orders/{$order->id}/void", [
            'reason' => 'Customer changed mind about purchase',
        ]);

        // Should return 202 Accepted with approval_request created
        $response->assertStatus(202);
        $response->assertJsonPath('requires_approval', true);
        $response->assertJsonStructure(['approval_request' => ['id', 'status', 'type']]);
    }

    /** @test */
    public function test_void_with_approved_request_succeeds(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $supervisor = $this->createSupervisorUser();
        $order = $this->createPaidOrder($terminal, $session);

        $approvalRequest = $this->createApprovedRequest($order, 'void', $supervisor);

        $response = $this->postJson("/api/pos/orders/{$order->id}/void", [
            'reason' => 'Customer changed mind about purchase',
            'approval_request_id' => $approvalRequest->id,
        ]);

        $response->assertStatus(200);
        $order->refresh();
        $this->assertEquals('void', $order->status);
        $this->assertEquals($supervisor->id, $order->void_approved_by);
    }

    /** @test */
    public function test_void_with_pending_approval_request_returns_403(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $supervisor = $this->createSupervisorUser();
        $order = $this->createPaidOrder($terminal, $session);

        // Create a PENDING (not yet approved) request
        $pendingRequest = ApprovalRequest::create([
            'type' => 'void',
            'source_type' => 'PosOrder',
            'source_id' => $order->id,
            'amount' => $order->total,
            'reason' => 'Pending approval',
            'status' => 'pending',
            'requested_by' => 1,
            'requested_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);

        $response = $this->postJson("/api/pos/orders/{$order->id}/void", [
            'reason' => 'Customer changed mind about purchase',
            'approval_request_id' => $pendingRequest->id,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Approval request not approved');
    }

    /** @test */
    public function test_void_reason_is_mandatory(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createPaidOrder($terminal, $session);

        $response = $this->postJson("/api/pos/orders/{$order->id}/void", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
    }

    /** @test */
    public function test_void_reason_minimum_length(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createPaidOrder($terminal, $session);

        $response = $this->postJson("/api/pos/orders/{$order->id}/void", [
            'reason' => 'Short',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
    }

    /** @test */
    public function test_void_records_refund_payment(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $supervisor = $this->createSupervisorUser();
        $order = $this->createPaidOrder($terminal, $session);

        $approvalRequest = $this->createApprovedRequest($order, 'void', $supervisor);

        $this->postJson("/api/pos/orders/{$order->id}/void", [
            'reason' => 'Customer changed mind about purchase',
            'approval_request_id' => $approvalRequest->id,
        ]);

        $refundPayment = PosPayment::where('pos_order_id', $order->id)
            ->where('method', 'void_refund')
            ->first();

        $this->assertNotNull($refundPayment);
        $this->assertEquals(-100.00, $refundPayment->amount);
    }

    /** @test */
    public function test_void_creates_refund_transaction(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $supervisor = $this->createSupervisorUser();
        $order = $this->createPaidOrder($terminal, $session);

        $approvalRequest = $this->createApprovedRequest($order, 'void', $supervisor);

        $response = $this->postJson("/api/pos/orders/{$order->id}/void", [
            'reason' => 'Customer changed mind about purchase',
            'approval_request_id' => $approvalRequest->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['refund_transaction' => ['id', 'refund_number', 'status']]);
    }

    /** @test */
    public function test_void_is_idempotent_via_approval_request(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $supervisor = $this->createSupervisorUser();
        $order = $this->createPaidOrder($terminal, $session);

        $approvalRequest = $this->createApprovedRequest($order, 'void', $supervisor);

        // First void
        $this->postJson("/api/pos/orders/{$order->id}/void", [
            'reason' => 'Customer changed mind about purchase',
            'approval_request_id' => $approvalRequest->id,
        ])->assertStatus(200);

        // Second void attempt on already-voided order
        $response = $this->postJson("/api/pos/orders/{$order->id}/void", [
            'reason' => 'Customer changed mind about purchase',
            'approval_request_id' => $approvalRequest->id,
        ]);

        // Should fail — order is already voided
        $response->assertStatus(422);
    }

    // ==========================================
    // REFUND TESTS
    // ==========================================

    /** @test */
    public function test_refund_without_approval_request_returns_202(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createPaidOrder($terminal, $session);

        $response = $this->postJson("/api/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer returned item in good condition',
            'refund_method' => 'cash',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('requires_approval', true);
    }

    /** @test */
    public function test_refund_with_approved_request_succeeds(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $supervisor = $this->createSupervisorUser();
        $order = $this->createPaidOrder($terminal, $session);

        $approvalRequest = $this->createApprovedRequest($order, 'refund', $supervisor);

        $response = $this->postJson("/api/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer returned item in good condition',
            'refund_method' => 'cash',
            'approval_request_id' => $approvalRequest->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['refund_transaction', 'return_order']);
    }

    /** @test */
    public function test_refund_records_refund_payment(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $supervisor = $this->createSupervisorUser();
        $order = $this->createPaidOrder($terminal, $session);

        $approvalRequest = $this->createApprovedRequest($order, 'refund', $supervisor);

        $this->postJson("/api/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer returned item in good condition',
            'refund_method' => 'cash',
            'approval_request_id' => $approvalRequest->id,
        ]);

        $refundPayment = PosPayment::where('pos_order_id', $order->id)
            ->where('reference', 'LIKE', 'REFUND-%')
            ->first();

        $this->assertNotNull($refundPayment);
        $this->assertEquals(-100.00, $refundPayment->amount);
    }

    /** @test */
    public function test_refund_notes_minimum_length(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createPaidOrder($terminal, $session);

        $response = $this->postJson("/api/pos/orders/{$order->id}/refund", [
            'notes' => 'Short',
            'refund_method' => 'cash',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['notes']);
    }

    // ==========================================
    // RETURN TESTS
    // ==========================================

    /** @test */
    public function test_return_without_approval_request_returns_202(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createPaidOrder($terminal, $session);
        $itemId = $order->items()->first()->id;

        $response = $this->postJson("/api/pos/orders/{$order->id}/return", [
            'items' => [['item_id' => $itemId, 'quantity' => 1]],
            'notes' => 'Customer returned item in good condition',
            'refund_method' => 'cash',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('requires_approval', true);
    }

    /** @test */
    public function test_return_with_approved_request_succeeds(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $supervisor = $this->createSupervisorUser();
        $order = $this->createPaidOrder($terminal, $session);
        $itemId = $order->items()->first()->id;

        $approvalRequest = $this->createApprovedRequest($order, 'return', $supervisor);

        $response = $this->postJson("/api/pos/orders/{$order->id}/return", [
            'items' => [['item_id' => $itemId, 'quantity' => 1]],
            'notes' => 'Customer returned item in good condition',
            'refund_method' => 'cash',
            'approval_request_id' => $approvalRequest->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['return_order', 'refund_transaction']);
    }

    /** @test */
    public function test_return_notes_are_mandatory(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createPaidOrder($terminal, $session);
        $itemId = $order->items()->first()->id;

        $response = $this->postJson("/api/pos/orders/{$order->id}/return", [
            'items' => [['item_id' => $itemId, 'quantity' => 1]],
            'refund_method' => 'cash',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['notes']);
    }

    /** @test */
    public function test_return_records_refund_payment(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $supervisor = $this->createSupervisorUser();
        $order = $this->createPaidOrder($terminal, $session);
        $itemId = $order->items()->first()->id;

        $approvalRequest = $this->createApprovedRequest($order, 'return', $supervisor);

        $this->postJson("/api/pos/orders/{$order->id}/return", [
            'items' => [['item_id' => $itemId, 'quantity' => 1]],
            'notes' => 'Customer returned item in good condition',
            'refund_method' => 'cash',
            'approval_request_id' => $approvalRequest->id,
        ]);

        $refundPayment = PosPayment::where('pos_order_id', $order->id)
            ->where('reference', 'LIKE', 'RETURN-%')
            ->first();

        $this->assertNotNull($refundPayment);
        $this->assertEquals(-100.00, $refundPayment->amount);
    }

    /** @test */
    public function test_no_manager_password_field_accepted(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createPaidOrder($terminal, $session);

        // Sending manager_password should be ignored — system uses approval_request_id
        $response = $this->postJson("/api/pos/orders/{$order->id}/void", [
            'reason' => 'Customer changed mind about purchase',
            'manager_password' => 'any_password_is_ignored',
        ]);

        // Should return 202 (approval required) — not 403 (wrong password)
        // This confirms password-based auth is fully removed
        $response->assertStatus(202);
        $response->assertJsonPath('requires_approval', true);
    }
}
