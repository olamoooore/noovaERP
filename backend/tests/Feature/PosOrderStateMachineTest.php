<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Product;
use App\Models\Customer;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PosOrderStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Product $product;
    protected Customer $customer;
    protected PosTerminal $terminal;
    protected PosSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create([
            'price' => 100.00,
            'tax_rate' => 10.00,
            'stock_quantity' => 1000,
        ]);

        $this->terminal = PosTerminal::factory()->create();
        $this->session = PosSession::factory()->create([
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->user->id,
            'status' => 'open',
        ]);
    }

    /**
     * Test STATE-001: Valid transition draft → paid succeeds
     */
    public function test_valid_transition_draft_to_paid_succeeds()
    {
        $order = $this->createDraftOrder();

        // Verify current status
        $this->assertEquals('draft', $order->status);

        // Verify transition is allowed
        $this->assertTrue($order->canTransitionTo('paid'));

        // Perform transition
        $order->transitionTo('paid');
        $order->save();

        // Verify new status
        $this->assertEquals('paid', $order->status);
    }

    /**
     * Test STATE-001: Valid transition draft → void succeeds
     */
    public function test_valid_transition_draft_to_void_succeeds()
    {
        $order = $this->createDraftOrder();

        // Verify transition is allowed
        $this->assertTrue($order->canTransitionTo('void'));

        // Perform transition
        $order->transitionTo('void');
        $order->save();

        // Verify new status
        $this->assertEquals('void', $order->status);
    }

    /**
     * Test STATE-001: Valid transition paid → void succeeds
     */
    public function test_valid_transition_paid_to_void_succeeds()
    {
        $order = $this->createPaidOrder();

        // Verify transition is allowed
        $this->assertTrue($order->canTransitionTo('void'));

        // Perform transition
        $order->transitionTo('void');
        $order->save();

        // Verify new status
        $this->assertEquals('void', $order->status);
    }

    /**
     * Test STATE-001: Valid transition paid → refunded succeeds
     */
    public function test_valid_transition_paid_to_refunded_succeeds()
    {
        $order = $this->createPaidOrder();

        // Verify transition is allowed
        $this->assertTrue($order->canTransitionTo('refunded'));

        // Perform transition
        $order->transitionTo('refunded');
        $order->save();

        // Verify new status
        $this->assertEquals('refunded', $order->status);
    }

    /**
     * Test STATE-001: Valid transition paid → returned succeeds
     */
    public function test_valid_transition_paid_to_returned_succeeds()
    {
        $order = $this->createPaidOrder();

        // Verify transition is allowed
        $this->assertTrue($order->canTransitionTo('returned'));

        // Perform transition
        $order->transitionTo('returned');
        $order->save();

        // Verify new status
        $this->assertEquals('returned', $order->status);
    }

    /**
     * Test STATE-001: Invalid transition - paying a voided order throws exception
     */
    public function test_invalid_transition_void_to_paid_throws_exception()
    {
        $order = $this->createDraftOrder();
        $order->transitionTo('void');
        $order->save();

        // Verify transition is not allowed
        $this->assertFalse($order->canTransitionTo('paid'));

        // Attempt invalid transition
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid state transition from 'void' to 'paid'");
        
        $order->transitionTo('paid');
    }

    /**
     * Test STATE-001: Invalid transition - voiding a refunded order throws exception
     */
    public function test_invalid_transition_refunded_to_void_throws_exception()
    {
        $order = $this->createPaidOrder();
        $order->transitionTo('refunded');
        $order->save();

        // Verify transition is not allowed
        $this->assertFalse($order->canTransitionTo('void'));

        // Attempt invalid transition
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid state transition from 'refunded' to 'void'");
        
        $order->transitionTo('void');
    }

    /**
     * Test STATE-001: Invalid transition - returning a draft order throws exception
     */
    public function test_invalid_transition_draft_to_returned_throws_exception()
    {
        $order = $this->createDraftOrder();

        // Verify transition is not allowed
        $this->assertFalse($order->canTransitionTo('returned'));

        // Attempt invalid transition
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid state transition from 'draft' to 'returned'");
        
        $order->transitionTo('returned');
    }

    /**
     * Test STATE-001: Invalid transition - refunding a draft order throws exception
     */
    public function test_invalid_transition_draft_to_refunded_throws_exception()
    {
        $order = $this->createDraftOrder();

        // Verify transition is not allowed
        $this->assertFalse($order->canTransitionTo('refunded'));

        // Attempt invalid transition
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid state transition from 'draft' to 'refunded'");
        
        $order->transitionTo('refunded');
    }

    /**
     * Test STATE-001: Invalid transition - voided order cannot transition to any state
     */
    public function test_voided_order_cannot_transition()
    {
        $order = $this->createDraftOrder();
        $order->transitionTo('void');
        $order->save();

        // Verify no transitions are allowed from void
        $this->assertFalse($order->canTransitionTo('paid'));
        $this->assertFalse($order->canTransitionTo('refunded'));
        $this->assertFalse($order->canTransitionTo('returned'));
        $this->assertFalse($order->canTransitionTo('cancelled'));
    }

    /**
     * Test STATE-001: Invalid transition - refunded order cannot transition to any state
     */
    public function test_refunded_order_cannot_transition()
    {
        $order = $this->createPaidOrder();
        $order->transitionTo('refunded');
        $order->save();

        // Verify no transitions are allowed from refunded
        $this->assertFalse($order->canTransitionTo('paid'));
        $this->assertFalse($order->canTransitionTo('void'));
        $this->assertFalse($order->canTransitionTo('returned'));
        $this->assertFalse($order->canTransitionTo('cancelled'));
    }

    /**
     * Test STATE-001: Invalid transition - returned order cannot transition to any state
     */
    public function test_returned_order_cannot_transition()
    {
        $order = $this->createPaidOrder();
        $order->transitionTo('returned');
        $order->save();

        // Verify no transitions are allowed from returned
        $this->assertFalse($order->canTransitionTo('paid'));
        $this->assertFalse($order->canTransitionTo('void'));
        $this->assertFalse($order->canTransitionTo('refunded'));
        $this->assertFalse($order->canTransitionTo('cancelled'));
    }

    /**
     * Test STATE-001: Validate transition method throws exception for invalid status
     */
    public function test_validate_transition_throws_exception_for_invalid_status()
    {
        $order = $this->createDraftOrder();
        
        // Manually set invalid status
        $order->status = 'invalid_status';

        // Attempt transition
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid current status: invalid_status");
        
        $order->validateTransition('paid');
    }

    // Helper methods

    protected function createDraftOrder(): PosOrder
    {
        $response = $this->actingAs($this->user)->postJson('/api/pos/orders', [
            'session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'price' => 100.00,
                ],
            ],
        ]);

        $response->assertStatus(201);
        return PosOrder::find($response->json('id'));
    }

    protected function createPaidOrder(): PosOrder
    {
        $order = $this->createDraftOrder();

        // Pay the order
        $this->actingAs($this->user)->postJson("/api/pos/orders/{$order->id}/pay", [
            'method' => 'cash',
            'amount' => 110.00,
        ]);

        return $order->fresh();
    }
}
