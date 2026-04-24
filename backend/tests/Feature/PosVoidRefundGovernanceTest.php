<?php

namespace Tests\Feature;

use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Models\PosPayment;
use Tests\TestCase;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

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

    private function createManagerUser(): User
    {
        $user = User::create([
            'name' => 'Test Manager',
            'email' => 'manager@test.com',
            'password' => Hash::make('password123'),
        ]);

        $user->assignRole('manager');

        return $user;
    }

    private function createPaidOrder(PosTerminal $terminal, PosSession $session): PosOrder
    {
        $order = PosOrder::create([
            'order_number' => 'POS-TEST-' . uniqid(),
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

    /** @test */
    public function test_paid_void_requires_manager_approval(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $manager = $this->createManagerUser();
        $order = $this->createPaidOrder($terminal, $session);

        $response = $this->postJson("/api/pos/orders/{$order->id}/void", [
            'reason' => 'Customer changed mind about purchase',
            'manager_id' => $manager->id,
            'manager_password' => 'password123',
        ]);

        $response->assertStatus(200);
        $order->refresh();
        $this->assertEquals('void', $order->status);
    }

    /** @test */
    public function test_void_rejects_invalid_manager_credentials(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $manager = $this->createManagerUser();
        $order = $this->createPaidOrder($terminal, $session);

        $response = $this->postJson("/api/pos/orders/{$order->id}/void", [
            'reason' => 'Customer changed mind about purchase',
            'manager_id' => $manager->id,
            'manager_password' => 'wrongpassword',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Invalid manager credentials');
    }

    /** @test */
    public function test_void_rejects_non_manager(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);

        $nonManager = User::create([
            'name' => 'Cashier',
            'email' => 'cashier@test.com',
            'password' => Hash::make('password123'),
        ]);

        $order = $this->createPaidOrder($terminal, $session);

        $response = $this->postJson("/api/pos/orders/{$order->id}/void", [
            'reason' => 'Customer changed mind about purchase',
            'manager_id' => $nonManager->id,
            'manager_password' => 'password123',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_void_reason_is_mandatory(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $manager = $this->createManagerUser();
        $order = $this->createPaidOrder($terminal, $session);

        $response = $this->postJson("/api/pos/orders/{$order->id}/void", [
            'manager_id' => $manager->id,
            'manager_password' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_void_records_refund_payment(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $manager = $this->createManagerUser();
        $order = $this->createPaidOrder($terminal, $session);

        $this->postJson("/api/pos/orders/{$order->id}/void", [
            'reason' => 'Customer changed mind about purchase',
            'manager_id' => $manager->id,
            'manager_password' => 'password123',
        ]);

        $refundPayment = PosPayment::where('pos_order_id', $order->id)
            ->where('method', 'void_refund')
            ->first();

        $this->assertNotNull($refundPayment);
        $this->assertEquals(-100.00, $refundPayment->amount);
    }

    /** @test */
    public function test_refund_requires_manager_approval(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $manager = $this->createManagerUser();
        $order = $this->createPaidOrder($terminal, $session);

        $response = $this->postJson("/api/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer returned item',
            'refund_method' => 'cash',
            'manager_id' => $manager->id,
            'manager_password' => 'password123',
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function test_refund_records_refund_payment(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $manager = $this->createManagerUser();
        $order = $this->createPaidOrder($terminal, $session);

        $this->postJson("/api/pos/orders/{$order->id}/refund", [
            'notes' => 'Customer returned item',
            'refund_method' => 'cash',
            'manager_id' => $manager->id,
            'manager_password' => 'password123',
        ]);

        $refundPayment = PosPayment::where('pos_order_id', $order->id)
            ->where('reference', 'LIKE', 'REFUND-%')
            ->first();

        $this->assertNotNull($refundPayment);
    }

    /** @test */
    public function test_return_requires_manager_approval(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $manager = $this->createManagerUser();
        $order = $this->createPaidOrder($terminal, $session);
        $itemId = $order->items()->first()->id;

        $response = $this->postJson("/api/pos/orders/{$order->id}/return", [
            'items' => [['item_id' => $itemId, 'quantity' => 1]],
            'notes' => 'Customer returned item in good condition',
            'refund_method' => 'cash',
            'manager_id' => $manager->id,
            'manager_password' => 'password123',
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function test_return_rejects_invalid_manager_credentials(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $manager = $this->createManagerUser();
        $order = $this->createPaidOrder($terminal, $session);
        $itemId = $order->items()->first()->id;

        $response = $this->postJson("/api/pos/orders/{$order->id}/return", [
            'items' => [['item_id' => $itemId, 'quantity' => 1]],
            'notes' => 'Customer returned item in good condition',
            'refund_method' => 'cash',
            'manager_id' => $manager->id,
            'manager_password' => 'wrongpassword',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Invalid manager credentials');
    }

    /** @test */
    public function test_return_rejects_non_manager(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createPaidOrder($terminal, $session);
        $itemId = $order->items()->first()->id;

        $nonManager = User::create([
            'name' => 'Cashier',
            'email' => 'cashier2@test.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson("/api/pos/orders/{$order->id}/return", [
            'items' => [['item_id' => $itemId, 'quantity' => 1]],
            'notes' => 'Customer returned item in good condition',
            'refund_method' => 'cash',
            'manager_id' => $nonManager->id,
            'manager_password' => 'password123',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Manager approval required');
    }

    /** @test */
    public function test_return_notes_are_mandatory(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $manager = $this->createManagerUser();
        $order = $this->createPaidOrder($terminal, $session);
        $itemId = $order->items()->first()->id;

        $response = $this->postJson("/api/pos/orders/{$order->id}/return", [
            'items' => [['item_id' => $itemId, 'quantity' => 1]],
            'refund_method' => 'cash',
            'manager_id' => $manager->id,
            'manager_password' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['notes']);
    }

    /** @test */
    public function test_return_records_refund_payment(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $manager = $this->createManagerUser();
        $order = $this->createPaidOrder($terminal, $session);
        $itemId = $order->items()->first()->id;

        $this->postJson("/api/pos/orders/{$order->id}/return", [
            'items' => [['item_id' => $itemId, 'quantity' => 1]],
            'notes' => 'Customer returned item in good condition',
            'refund_method' => 'cash',
            'manager_id' => $manager->id,
            'manager_password' => 'password123',
        ]);

        $refundPayment = PosPayment::where('pos_order_id', $order->id)
            ->where('reference', 'LIKE', 'RETURN-%')
            ->first();

        $this->assertNotNull($refundPayment);
        $this->assertEquals(-100.00, $refundPayment->amount);
    }
}
