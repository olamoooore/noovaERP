<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Services\PosPaymentCompletionService;
use Tests\TenantTestCase;

class PosWarehouseEnforcementTest extends TenantTestCase
{
    private User $user;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->tenantId = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'Warehouse Enforcement Test Tenant',
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

        if (Schema::hasTable('pos_orders') && ! Schema::hasColumn('pos_orders', 'deleted_at')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        $this->ensureOpenFiscalPeriodForToday();
    }

    private function ensureOpenFiscalPeriodForToday(): void
    {
        if (! Schema::hasTable('fiscal_periods')) {
            return;
        }

        $today = now()->toDateString();
        $exists = DB::table('fiscal_periods')
            ->where('period_start', '<=', $today)
            ->where('period_end', '>=', $today)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('fiscal_periods')->insert([
            'name' => now()->format('F Y'),
            'code' => now()->format('Y-m'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'period_type' => 'monthly',
            'fiscal_year' => (int) now()->format('Y'),
            'is_locked' => false,
            'status' => 'open',
            'created_by' => $this->user->id,
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
            'user_id' => $this->user->id,
            'status' => 'open',
            'opened_at' => now(),
            'opening_balance' => 0,
        ]);
    }

    private function createDraftOrder(PosTerminal $terminal, PosSession $session): PosOrder
    {
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

        $product = Product::factory()->create([
            'price' => 100.00,
            'stock_quantity' => 100,
            'track_inventory' => true,
        ]);

        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => 100.00,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 100.00,
        ]);

        return $order;
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
