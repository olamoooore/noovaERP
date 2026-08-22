<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Warehouse;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Services\PosPaymentCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class PosPaymentIdempotencyTest extends TenantTestCase
{

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

        $terminal = PosTerminal::create([
            'name' => 'POS Terminal 1',
            'terminal_number' => 'TERM-001',
            'warehouse_id' => $warehouse->id,
            'is_active' => true,
        ]);

        return $terminal;
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

    private function createPayableOrder(PosTerminal $terminal, PosSession $session): PosOrder
    {
        $product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'barcode' => 'TEST-BARCODE-001',
            'price' => 100.00,
            'status' => 'active',
            'track_inventory' => true,
            'type' => 'product',
        ]);

        return PosOrder::create([
            'order_number' => 'POS-TEST-'.uniqid(),
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
    public function test_cash_payment_retry_idempotent(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createPayableOrder($terminal, $session);
        $order->items()->create([
            'product_id' => $order->items()->first()?->product_id ?? 1,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'quantity' => 1,
            'unit_price' => 100.00,
            'tax_rate' => 0,
            'line_total' => 100.00,
        ]);

        $this->assertEquals('draft', $order->status);

        $completionService = app(PosPaymentCompletionService::class);

        $order = $completionService->complete($order, 100.00, 'cash', null, 1);
        $firstPaymentCount = PosPayment::where('pos_order_id', $order->id)->count();

        $this->assertEquals('paid', $order->status);
        $this->assertEquals(1, $firstPaymentCount);

        $order = PosOrder::find($order->id);
        $order = $completionService->complete($order, 100.00, 'cash', null, 1);
        $secondPaymentCount = PosPayment::where('pos_order_id', $order->id)->count();

        $this->assertEquals(1, $secondPaymentCount, 'Retry payment should be idempotent - only one payment record should exist');
    }

    /** @test */
    public function test_null_reference_payment_idempotent_with_null_reference(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createPayableOrder($terminal, $session);
        $order->items()->create([
            'product_id' => 1,
            'name' => 'Test Product',
            'sku' => 'TEST-002',
            'quantity' => 1,
            'unit_price' => 100.00,
            'tax_rate' => 0,
            'line_total' => 100.00,
        ]);

        $completionService = app(PosPaymentCompletionService::class);

        $order = $completionService->complete($order, 100.00, 'cash', null, 1);
        $this->assertEquals(1, PosPayment::where('pos_order_id', $order->id)->whereNull('reference')->count());

        $order = PosOrder::find($order->id);
        $order = $completionService->complete($order, 100.00, 'cash', null, 1);
        $this->assertEquals(1, PosPayment::where('pos_order_id', $order->id)->count());
    }

    /** @test */
    public function test_different_reference_creates_different_payment(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createPayableOrder($terminal, $session);
        $order->items()->create([
            'product_id' => 1,
            'name' => 'Test Product',
            'sku' => 'TEST-003',
            'quantity' => 1,
            'unit_price' => 100.00,
            'tax_rate' => 0,
            'line_total' => 100.00,
        ]);

        $completionService = app(PosPaymentCompletionService::class);

        $order = $completionService->complete($order, 100.00, 'cash', 'TXN-001', 1);
        $this->assertEquals(1, PosPayment::where('pos_order_id', $order->id)->count());

        $order = PosOrder::find($order->id);
        $order = $completionService->complete($order, 100.00, 'cash', 'TXN-002', 1);
        $this->assertEquals(2, PosPayment::where('pos_order_id', $order->id)->count(), 'Different references should create separate payments');
    }
}
