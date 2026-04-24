<?php

namespace Tests\Feature;

use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Services\PosPaymentCompletionService;
use Tests\TestCase;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class PosSessionEnforcementTest extends TestCase
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

    private function createClosedSession(PosTerminal $terminal): PosSession
    {
        return PosSession::create([
            'terminal_id' => $terminal->id,
            'status' => 'closed',
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
            'opened_by' => 1,
        ]);
    }

    private function createDraftOrder(PosTerminal $terminal, ?PosSession $session = null): PosOrder
    {
        return PosOrder::create([
            'order_number' => 'POS-TEST-' . uniqid(),
            'status' => 'draft',
            'terminal_id' => $terminal->id,
            'session_id' => $session?->id,
            'subtotal' => 100.00,
            'tax_amount' => 0,
            'total' => 100.00,
            'created_by' => 1,
        ]);
    }

    /** @test */
    public function test_order_creation_requires_session(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);

        $response = $this->postJson('/api/pos/orders', [
            'terminal_id' => $terminal->id,
            'session_id' => $session->id,
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
    }

    /** @test */
    public function test_order_creation_rejects_missing_session(): void
    {
        $terminal = $this->createTerminalWithWarehouse();

        $response = $this->postJson('/api/pos/orders', [
            'terminal_id' => $terminal->id,
            'session_id' => 99999,
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_order_creation_rejects_closed_session(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $closedSession = $this->createClosedSession($terminal);

        $response = $this->postJson('/api/pos/orders', [
            'terminal_id' => $terminal->id,
            'session_id' => $closedSession->id,
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Session is not open');
    }

    /** @test */
    public function test_order_creation_requires_terminal(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);

        $differentTerminal = PosTerminal::create([
            'name' => 'POS Terminal 2',
            'terminal_number' => 'TERM-002',
            'warehouse_id' => $terminal->warehouse_id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/pos/orders', [
            'terminal_id' => $differentTerminal->id,
            'session_id' => $session->id,
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_payment_blocked_on_closed_session(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createDraftOrder($terminal, $session);

        $session->update(['status' => 'closed', 'closed_at' => now()]);

        $response = $this->postJson("/api/pos/orders/{$order->id}/pay", [
            'method' => 'cash',
            'amount' => 100.00,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Session is not open');
    }
}