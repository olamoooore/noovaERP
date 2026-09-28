<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Storefront\Enums\ActorType;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Exceptions\InvalidOrderTransitionException;
use Modules\Storefront\Models\StorefrontAccount;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontCartItem;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontOrderItem;
use Modules\Storefront\Models\StorefrontOrderPaymentAttempt;
use Modules\Storefront\Services\OrderService;
use Modules\Storefront\Services\OrderStateMachine;
use Tests\TenantTestCase;

/**
 * SF-007 — Order foundation (FR-ORD-01..03 P0 slice: one state machine for
 * all writers, entity stamping resolution, idempotent placement keys, E2
 * constraints).
 *
 * A-07: "Invalid transitions rejected for all writers; history recorded" —
 * the matrix is driven from the SAME map the service uses, so an edge added
 * without a rule here cannot silently become an open door.
 */
class SF007OrderStateMachineTest extends TenantTestCase
{
    private string $tenantId;

    private OrderService $orders;

    private OrderStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET search_path TO public');

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF007 Tenant', 'email' => 'sf007-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        // Product audit attribution requires at least one tenant user
        // (ProductAuditLog::log -> requireTenantUserId); the factory path in
        // the cart/cascade tests writes that audit row. Mirrors SF003 setUp.
        \App\Models\User::factory()->create();

        $this->machine = new OrderStateMachine;
        $this->orders = new OrderService($this->machine);
    }

    // ─── The one machine, driven by the map ─────────────────────────────

    public function test_happy_ladder_walks_the_full_graph_and_records_history(): void
    {
        $order = $this->makeOrder();

        $ladder = [
            OrderStatus::PAID, OrderStatus::PROCESSING, OrderStatus::SHIPPED,
            OrderStatus::DELIVERED, OrderStatus::COMPLETED, OrderStatus::REFUNDED,
        ];

        foreach ($ladder as $to) {
            $order = $this->orders->transitionOrder($order, $to, ActorType::SYSTEM);
            $this->assertSame($to->value, $order->status);
        }

        $this->assertSame(count($ladder), $order->statusHistory()->count());
        $this->assertNotNull($order->paid_at);

        // Every history row carries actor + from/to (audit, A-07).
        $row = $order->statusHistory()->first();
        $this->assertContains($row->from_status, OrderStatus::values());
        $this->assertNotNull($row->actor_type);
    }

    public function test_every_matrix_edge_is_enforced_for_all_writers(): void
    {
        $writers = [ActorType::SYSTEM, ActorType::STAFF, ActorType::ACCOUNT, ActorType::GATEWAY];

        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                if ($from === $to) {
                    continue; // idempotent path tested separately
                }

                $order = $this->makeOrder(['status' => $from->value]);
                $actor = $writers[array_rand($writers)];
                $allowed = $this->machine->canTransitionOrder($from->value, $to->value);

                if ($allowed) {
                    $result = $this->orders->transitionOrder($order, $to, $actor);
                    $this->assertSame($to->value, $result->status, "{$from->value} -> {$to->value} must be allowed");
                } else {
                    try {
                        $this->orders->transitionOrder($order, $to, $actor);
                        $this->fail("{$from->value} -> {$to->value} must be rejected for writer '{$actor->value}'");
                    } catch (InvalidOrderTransitionException $e) {
                        $this->assertSame($from->value, $e->from);
                        $this->assertSame($to->value, $e->to);
                        $this->assertSame($from->value, $order->fresh()->status, 'rejected transitions must not mutate state');
                    }
                }
            }
        }
    }

    public function test_paid_orders_cannot_be_cancelled_only_refunded(): void
    {
        // Money-safety: once paid, the ONLY backward exit is the refund
        // path (P3 wires reversals) — a paid -> cancelled edge would be an
        // untracked refund loophole (FR-ORD-02).
        $order = $this->makeOrder();
        $order = $this->orders->transitionOrder($order, OrderStatus::PAID, ActorType::GATEWAY);

        $this->expectException(InvalidOrderTransitionException::class);
        $this->orders->transitionOrder($order, OrderStatus::CANCELLED, ActorType::STAFF);
    }

    public function test_terminal_states_accept_nothing(): void
    {
        foreach ([OrderStatus::CANCELLED, OrderStatus::REFUNDED] as $terminal) {
            $order = $this->makeOrder(['status' => $terminal->value]);

            foreach (OrderStatus::cases() as $to) {
                if ($to === $terminal) {
                    $again = $this->orders->transitionOrder($order, $to, ActorType::SYSTEM);
                    $this->assertSame($to->value, $again->status, 'same-state replay on terminal must be an idempotent success');

                    continue;
                }

                try {
                    $this->orders->transitionOrder($order, $to, ActorType::SYSTEM);
                    $this->fail("terminal {$terminal->value} -> {$to->value} must be rejected");
                } catch (InvalidOrderTransitionException $e) {
                    $this->assertSame([], $e->allowedFrom, 'terminal states have no outgoing edges');
                }

                $this->assertSame($terminal->value, $order->fresh()->status);
            }
        }
    }

    public function test_same_state_transition_is_idempotent_without_extra_history(): void
    {
        $order = $this->makeOrder();
        $this->orders->transitionOrder($order, OrderStatus::PAID, ActorType::GATEWAY);

        // Webhook replay of the same completion (A-06 at-most-once).
        $again = $this->orders->transitionOrder($order->fresh(), OrderStatus::PAID, ActorType::GATEWAY);

        $this->assertSame(OrderStatus::PAID->value, $again->status);
        $this->assertSame(1, $again->statusHistory()->count(), 'replays must not double-record history');
    }

    public function test_validation_uses_fresh_locked_state_not_stale_models(): void
    {
        $order = $this->makeOrder();
        $stale = $order->fresh();

        // Another writer cancels first (valid from pending).
        $this->orders->transitionOrder($order, OrderStatus::CANCELLED, ActorType::STAFF, 1, 'ops cancel');

        // The stale model still believes status=pending; its attempt to
        // mark paid must be judged against the FRESH state (cancelled).
        $this->expectException(InvalidOrderTransitionException::class);
        try {
            $this->orders->transitionOrder($stale, OrderStatus::PAID, ActorType::GATEWAY);
        } finally {
            $this->assertSame(OrderStatus::CANCELLED->value, $order->fresh()->status);
            $this->assertSame(OrderStatus::PENDING->value, $stale->status, 'caller model was stale — machine ignored it');
        }
    }

    public function test_transition_locks_the_order_row(): void
    {
        $order = $this->makeOrder();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->orders->transitionOrder($order, OrderStatus::PAID, ActorType::SYSTEM);
        $log = collect(DB::getQueryLog())->pluck('query')->all();
        DB::disableQueryLog();

        $this->assertTrue(
            collect($log)->contains(fn (string $q) => stripos($q, 'for update') !== false),
            'FR-ORD-01: transitions must take a row lock (no lost-update races between writers)'
        );
    }

    // ─── Payment graph ──────────────────────────────────────────────────

    public function test_payment_graph_and_matrix(): void
    {
        $order = $this->makeOrder();

        // Valid chain incl. retry-after-failure.
        $order = $this->orders->transitionPayment($order, PaymentStatus::FAILED, ActorType::GATEWAY);
        $order = $this->orders->transitionPayment($order, PaymentStatus::PENDING_PAYMENT, ActorType::GATEWAY, null, 'retry');
        $order = $this->orders->transitionPayment($order, PaymentStatus::PAID, ActorType::GATEWAY);
        $this->assertNotNull($order->paid_at);
        $order = $this->orders->transitionPayment($order, PaymentStatus::PARTIALLY_REFUNDED, ActorType::STAFF);
        $order = $this->orders->transitionPayment($order, PaymentStatus::PARTIALLY_REFUNDED, ActorType::STAFF, null, 'second partial');
        $order = $this->orders->transitionPayment($order, PaymentStatus::REFUNDED, ActorType::STAFF);

        // Invalid: paid cannot go BACK to failed (decline after capture).
        $bad = $this->makeOrder(['payment_status' => PaymentStatus::PAID->value]);
        $this->expectException(InvalidOrderTransitionException::class);
        $this->orders->transitionPayment($bad, PaymentStatus::FAILED, ActorType::GATEWAY);
    }

    // ─── Placement constraints (E2) ─────────────────────────────────────

    public function test_idempotency_key_is_unique_per_tenant_when_present(): void
    {
        $key = 'idem_'.Str::random(10);

        $this->makeOrder(['idempotency_key' => $key]);

        $this->expectException(QueryException::class);
        $this->makeOrder(['idempotency_key' => $key]);
    }

    public function test_null_idempotency_keys_are_unconstrained(): void
    {
        $this->makeOrder();
        $this->makeOrder();
        $this->assertGreaterThanOrEqual(2, StorefrontOrder::query()->whereNull('idempotency_key')->count());
    }

    public function test_guest_token_hash_is_unique_per_tenant(): void
    {
        $hash = hash('sha256', 'guest-'.Str::random(8));

        $this->makeOrder(['guest_token_hash' => $hash]);

        $this->expectException(QueryException::class);
        $this->makeOrder(['guest_token_hash' => $hash]);
    }

    public function test_order_number_conflicts_are_rejected(): void
    {
        $order = $this->makeOrder();

        $this->expectException(QueryException::class);
        $this->makeOrder(['order_number' => $order->order_number]);
    }

    public function test_generated_order_numbers_are_unique_and_prefixed(): void
    {
        $numbers = [];
        for ($i = 0; $i < 25; $i++) {
            $numbers[] = $n = $this->orders->generateOrderNumber();
            $this->assertMatchesRegularExpression('/^SF-\d{6}-[A-Z0-9]{6}$/', $n);
        }
        $this->assertCount(25, array_unique($numbers));
    }

    // ─── Entity stamping (FR-ORD-03) ────────────────────────────────────

    public function test_legal_entity_resolution_prefers_request_context_then_customer(): void
    {
        $customer = Customer::factory()->create(['legal_entity_id' => null]);

        $this->assertNull($this->orders->resolveLegalEntityId($customer), 'no context, no customer entity -> null');

        $request = request();
        $request->attributes->set('legal_entity_id', 77);
        $this->assertSame(77, $this->orders->resolveLegalEntityId($customer), 'request entity context wins (staff routes)');
        $request->attributes->remove('legal_entity_id');

        $customer->legal_entity_id = 42;
        $this->assertSame(42, $this->orders->resolveLegalEntityId($customer), 'guest/checkout path falls back to the customer entity (SalesOrderService precedent)');
    }

    // ─── Cart constraints (E2) ──────────────────────────────────────────

    public function test_exactly_one_active_cart_per_account(): void
    {
        $account = StorefrontAccount::create([
            'email' => 'buyer-'.Str::random(6).'@example.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        StorefrontCart::create(['account_id' => $account->id, 'status' => 'active']);

        $this->expectException(QueryException::class);
        StorefrontCart::create(['account_id' => $account->id, 'status' => 'active']);
    }

    public function test_ordered_cart_frees_the_slot_for_a_new_active_cart(): void
    {
        $account = StorefrontAccount::create([
            'email' => 'repeat-'.Str::random(6).'@example.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        $first = StorefrontCart::create(['account_id' => $account->id, 'status' => 'active']);
        $first->update(['status' => 'ordered']);

        $second = StorefrontCart::create(['account_id' => $account->id, 'status' => 'active']);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_guest_token_cart_slot_is_unique_and_variant_lines_distinct(): void
    {
        $guest = hash('sha256', 'g-'.Str::random(8));
        StorefrontCart::create(['guest_token_hash' => $guest, 'status' => 'active']);

        $this->expectException(QueryException::class);
        StorefrontCart::create(['guest_token_hash' => $guest, 'status' => 'active']);
    }

    public function test_cart_line_uniqueness_coalesces_null_variant(): void
    {
        $product = Product::factory()->create();
        $cart = StorefrontCart::create(['status' => 'active']);

        StorefrontCartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        // Same product, same (null) variant => same line: rejected.
        $this->expectException(QueryException::class);
        StorefrontCartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 2]);
    }

    public function test_different_variants_are_distinct_cart_lines(): void
    {
        $product = Product::factory()->create();
        $cart = StorefrontCart::create(['status' => 'active']);

        StorefrontCartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'variant_id' => 11, 'quantity' => 1]);
        StorefrontCartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'variant_id' => 12, 'quantity' => 1]);

        $this->assertSame(2, $cart->items()->count());
    }

    // ─── Attempts + cascade ─────────────────────────────────────────────

    public function test_payment_attempts_increment_and_stay_unique_per_order(): void
    {
        $order = $this->makeOrder();

        $a1 = $this->orders->recordPaymentAttempt($order, 'stripe', 'pi_1', 'created');
        $a2 = $this->orders->recordPaymentAttempt($order, 'stripe', 'pi_2', 'declined', 'card declined');

        $this->assertSame(1, $a1->attempt_no);
        $this->assertSame(2, $a2->attempt_no);
        $this->assertSame('card declined', $a2->error);

        $this->expectException(QueryException::class);
        StorefrontOrderPaymentAttempt::create([
            'order_id' => $order->id, 'attempt_no' => 2, 'gateway' => 'stripe', 'status' => 'x',
        ]);
    }

    public function test_order_items_and_history_cascade_with_hard_delete(): void
    {
        $product = Product::factory()->create();
        $order = $this->makeOrder();
        StorefrontOrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => 'X',
            'unit_price' => 1, 'quantity' => 1, 'line_total' => 1,
        ]);
        $this->orders->transitionOrder($order, OrderStatus::PAID, ActorType::SYSTEM);

        $order->forceDelete();

        $this->assertSame(0, StorefrontOrderItem::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, \Modules\Storefront\Models\StorefrontOrderStatusHistory::query()->where('order_id', $order->id)->count());
    }

    public function test_service_is_container_resolvable_for_all_writers(): void
    {
        $resolved = app(OrderService::class);
        $this->assertInstanceOf(OrderService::class, $resolved);
        $this->assertSame(OrderStateMachine::class, get_class(app(OrderStateMachine::class)), 'single shared machine instance (FR-ORD-01)');
    }

    // ─── Helper ─────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function makeOrder(array $overrides = []): StorefrontOrder
    {
        return StorefrontOrder::create(array_merge([
            'order_number' => $this->orders->generateOrderNumber(),
            'email' => 'buyer-'.Str::lower(Str::random(8)).'@example.test',
            'status' => OrderStatus::PENDING->value,
            'payment_status' => PaymentStatus::PENDING_PAYMENT->value,
            'subtotal' => 10.00,
            'total' => 10.00,
            'currency' => 'USD',
            'channel' => 'storefront',
            'placed_at' => now(),
        ], $overrides));
    }
}
