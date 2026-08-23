<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Services\PosPaymentCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class PosPaymentIdempotencyTest extends TenantTestCase
{

    protected string $tenantId;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->tenantId = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'Payment Idempotency Test Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });
        $this->bindTenantContext($this->tenantId);

        $this->user = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'cashier',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            'user_id' => $this->user->id,
            'status' => 'open',
            'opened_at' => now(),
            'opened_by' => $this->user->id,
        ]);
    }

    private function createPayableOrder(PosTerminal $terminal, PosSession $session): PosOrder
    {
        $product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-001-'.uniqid(),
            'barcode' => 'TEST-BARCODE-001',
            'price' => 100.00,
            'cost' => 50.00,
            'stock_quantity' => 100,
            'status' => 'active',
            'track_inventory' => true,
            'type' => 'product',
        ]);

        // Seed stock level directly so the sale succeeds.
        \Modules\Inventory\Models\StockLevel::create([
            'product_id' => $product->id,
            'warehouse_id' => $terminal->warehouse_id,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        $order = PosOrder::create([
            'external_id' => (string) Str::uuid(),
            'order_number' => 'POS-TEST-'.uniqid(),
            'status' => 'draft',
            'terminal_id' => $terminal->id,
            'session_id' => $session->id,
            'subtotal' => 100.00,
            'tax_amount' => 0,
            'total' => 100.00,
            'created_by' => $this->user->id,
        ]);

        $this->createdProduct = $product;

        return $order;
    }

    private ?Product $createdProduct = null;

    /** @test */
    public function test_cash_payment_retry_idempotent(): void
    {
        $terminal = $this->createTerminalWithWarehouse();
        $session = $this->createOpenSession($terminal);
        $order = $this->createPayableOrder($terminal, $session);
        $order->items()->create([
            'product_id' => $this->createdProduct->id,
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
            'product_id' => $this->createdProduct->id,
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
            'product_id' => $this->createdProduct->id,
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

        // Reset to draft so the second completion isn't short-circuited by
        // the early-return on already-paid orders; the test is exercising
        // the per-reference idempotency guard, not multi-payment on a paid order.
        $order = PosOrder::find($order->id);
        $order->update(['status' => 'draft', 'paid_at' => null]);
        PosPayment::where('pos_order_id', $order->id)->delete();

        $order = $completionService->complete($order, 100.00, 'cash', 'TXN-002', 1);
        $this->assertEquals(1, PosPayment::where('pos_order_id', $order->id)->where('reference', 'TXN-002')->count(), 'Different references should create separate payments');
    }
}
