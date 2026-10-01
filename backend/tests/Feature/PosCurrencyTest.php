<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Services\PosPostingService;
use Tests\TenantTestCase;

/**
 * MRD pricelist-multicurrency FR-9.1 — POS terminal currency configuration:
 * allowed-currency enforcement, per-order FX snapshot, base-amount GL posting
 * at the snapshot rate, and snapshot propagation into the sales order.
 */
class PosCurrencyTest extends TenantTestCase
{
    private User $admin;

    private Product $product;

    private PosTerminal $terminal;

    private PosSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) \Illuminate\Support\Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $tenantId], [
            'name' => 'POS Currency Tenant',
            'email' => 'poscur-'.substr(md5($tenantId), 0, 8).'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = $this->setUpTenantAdminUser('POS Currency Tenant');
        CompanySettings::query()->create(['base_currency' => 'SGD']);
        ExchangeRate::create([
            'from_currency' => 'SGD', 'to_currency' => 'USD', 'rate' => 0.74,
            'effective_date' => now()->toDateString(), 'source' => 'manual',
        ]);

        $this->product = Product::factory()->create([
            'price' => 100, 'cost' => 40, 'tax_rate' => 0, 'track_inventory' => false,
        ]);

        $warehouse = Warehouse::factory()->create();
        $this->terminal = PosTerminal::create([
            'name' => 'FX Terminal',
            'code' => 'FX-'.uniqid(),
            'warehouse_id' => $warehouse->id,
            'default_currency' => 'USD',
            'allowed_currencies' => ['USD', 'SGD'],
            'is_active' => true,
        ]);

        $this->session = PosSession::create([
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->admin->id,
            'session_number' => 'SESS-'.uniqid(),
            'status' => 'open',
            'opening_balance' => 0,
        ]);
    }

    private function createOrder(?string $currency = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/pos/orders', [
            'session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'currency' => $currency,
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
            ]],
        ]);
    }

    public function test_foreign_order_gets_the_terminal_currency_and_snapshot(): void
    {
        $response = $this->createOrder(); // terminal default = USD
        $response->assertCreated();

        $order = PosOrder::with('items')->find((int) $response->json('id'));
        $this->assertSame('USD', $order->currency, 'terminal default currency applied');
        $this->assertSame(1.351351, round((float) $order->exchange_rate, 6), 'snapshot = 1/0.74');
        $this->assertSame('SGD', $order->base_currency_code);

        // 100 SGD catalogue → 74.00 USD at the snapshot rate.
        $this->assertSame(74.0, round((float) $order->items->first()->unit_price, 2));
        $this->assertSame(74.0, round((float) $order->total, 2));
    }

    public function test_currency_outside_the_terminal_allow_list_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/pos/orders', [
            'session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'currency' => 'EUR', // not in ['USD','SGD']
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('currency');
    }

    public function test_foreign_order_posts_base_amounts_at_the_snapshot_rate(): void
    {
        $orderId = (int) $this->createOrder()->json('id');
        $order = PosOrder::with(['items', 'terminal'])->find($orderId);

        $entry = app(PosPostingService::class)->postPaidOrder($order, 74.0, 'cash');

        $cash = $entry->lines->first(fn ($line) => (float) $line->debit > 0);
        $this->assertNotNull($cash);
        $this->assertSame(100.0, round((float) $cash->debit, 2), '74.00 USD × 1.351351 ≈ 100.00 SGD base');
        $this->assertSame('SGD', $cash->currency_code, 'GL line carries the BASE currency');
        $this->assertSame(74.0, round((float) $cash->original_amount, 2), 'original document amount preserved');
        $this->assertSame(1.351351, round((float) $cash->exchange_rate, 6));

        $this->assertSame(
            round((float) $entry->lines->sum('debit'), 2),
            round((float) $entry->lines->sum('credit'), 2),
            'entry balances in base'
        );
    }

    public function test_pos_order_conversion_to_sales_order_carries_the_snapshot(): void
    {
        $orderId = (int) $this->createOrder()->json('id');
        $order = PosOrder::with(['items', 'terminal'])->find($orderId);

        $salesOrder = app(\Modules\Sales\Services\SalesOrderService::class)->createFromPosOrder($order);

        $this->assertSame('USD', $salesOrder->currency_code);
        $this->assertSame(1.351351, round((float) $salesOrder->exchange_rate, 6));
        $this->assertSame('SGD', $salesOrder->base_currency_code);
        $line = $salesOrder->lines()->first();
        $this->assertSame(74.0, round((float) $line->unit_price, 2));
        $this->assertSame(100.0, round((float) $line->base_unit_price, 2));
    }
}