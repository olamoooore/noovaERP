<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Modules\Sales\Http\Controllers\SalesOrderController;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\CreditCheckService;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * Credit-control cluster (#144 / #139).
 *
 * #144: credit_limit NULL means "not configured (unlimited)"; 0 is a real
 * limit of zero and must block — previously both were treated as unlimited,
 * giving every default customer unlimited credit.
 *
 * #139: the verdict must gate SalesOrderService::confirmOrder() — previously
 * computed and ignored, so over-limit orders confirmed, reserved stock and
 * unlocked invoicing.
 */
class CreditLimitEnforcementTest extends TenantTestCase
{
    protected User $user;

    protected Product $product;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext('test-tenant');

        $this->assertTenantSchemaReady([
            'customers', 'products', 'sales_orders', 'sales_order_lines',
        ]);

        $this->user = User::create([
            'name' => 'Credit User',
            'email' => 'credit-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Credit Widget',
            'sku' => 'CREDIT-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Credit Warehouse',
            'code' => 'CREDIT-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function customer(?float $creditLimit, float $outstanding = 0): Customer
    {
        return Customer::factory()->create([
            'name' => 'Credit Customer',
            'credit_limit' => $creditLimit,
            'total_outstanding' => $outstanding,
        ]);
    }

    private function draftOrder(Customer $customer, float $total): SalesOrder
    {
        return app(SalesOrderService::class)->createOrder([
            'customer_id' => $customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => (int) ($total / 100),
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
    }

    /** #144 — explicit zero credit must block, not pass as unlimited. */
    public function test_zero_credit_limit_is_not_unlimited(): void
    {
        $customer = $this->customer(0);

        $result = app(CreditCheckService::class)->checkCreditLimit($customer, 100);

        $this->assertFalse($result->approved);
        $this->assertStringContainsString('prepayment', $result->reason);
    }

    /** #144 — NULL still means "no credit control configured". */
    public function test_null_credit_limit_is_unlimited(): void
    {
        $customer = $this->customer(null);

        $result = app(CreditCheckService::class)->checkCreditLimit($customer, 999999);

        $this->assertTrue($result->approved);
        $this->assertStringContainsString('unlimited', $result->reason);
    }

    /** #144 — a positive limit is evaluated against current exposure. */
    public function test_positive_limit_blocks_when_exposure_exceeds_it(): void
    {
        $customer = $this->customer(1000, 800);
        $service = app(CreditCheckService::class);

        $this->assertFalse($service->checkCreditLimit($customer, 300)->approved);
        $this->assertTrue($service->checkCreditLimit($customer, 200)->approved);
    }

    /** #139 — confirmOrder() must refuse an over-limit order and leave it draft. */
    public function test_confirm_order_over_credit_limit_is_blocked(): void
    {
        $customer = $this->customer(1000, 800);
        $order = $this->draftOrder($customer, 300);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Credit check failed');

        try {
            app(SalesOrderService::class)->confirmOrder($order);
        } finally {
            $this->assertSame('draft', $order->fresh()->status, 'A blocked order must stay draft.');
        }
    }

    /** #139 — the controller exposes the block as a 422. */
    public function test_confirm_endpoint_returns_422_for_blocked_order(): void
    {
        $customer = $this->customer(0);
        $order = $this->draftOrder($customer, 300);

        $response = app(SalesOrderController::class)->confirm($order->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Credit check failed', $response->getData(true)['message']);
        $this->assertSame('draft', $order->fresh()->status);
    }

    /** #139 — within-limit and unlimited customers keep confirming normally. */
    public function test_confirm_order_succeeds_within_limit_and_with_null_limit(): void
    {
        $within = $this->customer(5000, 100);
        $withinOrder = $this->draftOrder($within, 300);
        $confirmed = app(SalesOrderService::class)->confirmOrder($withinOrder);
        $this->assertSame('confirmed', $confirmed->status);

        $unlimited = $this->customer(null);
        $unlimitedOrder = $this->draftOrder($unlimited, 500);
        $confirmedUnlimited = app(SalesOrderService::class)->confirmOrder($unlimitedOrder);
        $this->assertSame('confirmed', $confirmedUnlimited->status);
    }
}
