<?php

namespace Tests\Feature;

use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Services\PosPaymentCompletionService;
use Tests\TestCase;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class PosWarehouseEnforcementTest extends TestCase
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

    private function createTerminalWithoutWarehouse(): PosTerminal
    {
        return PosTerminal::create([
            'name' => 'POS Terminal 2',
            'terminal_number' => 'TERM-002',
            'warehouse_id' => null,
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

    private function createDraftOrder(PosTerminal $terminal, PosSession $session): PosOrder
    {
        return PosOrder::create([
            'order_number' => 'POS-TEST-' . uniqid(),
            'status' => 'draft',
            'terminal_id' => $terminal->id,
            'session_id' => $session->id,
            'subtotal' => 100.00,
            'tax_amount' => 0,
            'total' => 100.00,
            'created_by' => 1,
        ]);
    }

    /** @test */
    public function test_payment_completes_with_warehouse(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createDraftOrder($terminal, $session);

        $completionService = app(PosPaymentCompletionService::class);

        $order = $completionService->complete($order, 100.00, 'cash', 'TXN-001', 1);

        $this->assertEquals('paid', $order->status);
    }

    /** @test */
    public function test_payment_blocked_without_warehouse(): void
    {
        $terminal = $this->createTerminalWithoutWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createDraftOrder($terminal, $session);

        $completionService = app(PosPaymentCompletionService::class);

        $this->expectException(\RuntimeException::class);

        $completionService->complete($order, 100.00, 'cash', 'TXN-001', 1);
    }

    /** @test */
    public function test_error_message_mentions_warehouse(): void
    {
        $terminal = $this->createTerminalWithoutWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createDraftOrder($terminal, $session);

        $completionService = app(PosPaymentCompletionService::class);

        try {
            $completionService->complete($order, 100.00, 'cash', 'TXN-001', 1);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('warehouse', $e->getMessage());
        }
    }
}