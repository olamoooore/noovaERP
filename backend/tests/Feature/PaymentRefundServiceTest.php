<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentRefundService;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Tests\TenantTestCase;

class PaymentRefundServiceTest extends TenantTestCase
{

    private PaymentRefundService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentRefundService::class);
    }

    /** @test */
    public function it_updates_invoice_balances_on_refund(): void
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => 1000,
            'balance' => 0,
            'paid_amount' => 1000,
            'status' => 'paid',
        ]);

        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 1000,
        ]);

        $gateway = PaymentGateway::factory()->create([
            'gateway_name' => 'hitpay',
        ]);

        $transaction = PaymentTransaction::factory()->create([
            'payment_gateway_id' => $gateway->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 1000,
            'status' => 'completed',
            'transaction_id' => 'TXN-'.uniqid(),
            'refunded_amount' => 0,
        ]);

        $result = $this->service->processRefund(
            $transaction,
            500,
            'Customer request',
            1,
        );

        // Assert transaction updated
        $this->assertEquals(500, $result['transaction']->refunded_amount);
        $this->assertNotNull($result['transaction']->refunded_at);

        // Assert refund payment record created
        $this->assertNotNull($result['refundPayment']);
        $this->assertEquals(-500, $result['refundPayment']->amount);
        $this->assertEquals('refund', $result['refundPayment']->method);

        // Assert invoice updated
        $invoice->refresh();
        $this->assertEquals(500, $invoice->balance);
        $this->assertEquals(500, $invoice->paid_amount);
    }

    /** @test */
    public function it_prevents_refunding_more_than_available(): void
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => 1000,
            'balance' => 0,
            'paid_amount' => 1000,
            'status' => 'paid',
        ]);

        $gateway = PaymentGateway::factory()->create([
            'gateway_name' => 'hitpay',
        ]);

        $transaction = PaymentTransaction::factory()->create([
            'payment_gateway_id' => $gateway->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 1000,
            'status' => 'completed',
            'transaction_id' => 'TXN-'.uniqid(),
            'refunded_amount' => 600, // Already refunded 600
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds available balance');

        $this->service->processRefund(
            $transaction,
            500, // Trying to refund 500 more (only 400 available)
            'Customer request',
            1,
        );
    }

    /** @test */
    public function it_prevents_refunding_non_completed_transaction(): void
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => 1000,
        ]);

        $gateway = PaymentGateway::factory()->create([
            'gateway_name' => 'hitpay',
        ]);

        $transaction = PaymentTransaction::factory()->create([
            'payment_gateway_id' => $gateway->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 1000,
            'status' => 'pending', // Not completed
            'transaction_id' => 'TXN-'.uniqid(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only completed transactions can be refunded');

        $this->service->processRefund(
            $transaction,
            500,
            'Customer request',
            1,
        );
    }

    /** @test */
    public function it_marks_transaction_as_fully_refunded(): void
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => 1000,
            'balance' => 0,
            'paid_amount' => 1000,
            'status' => 'paid',
        ]);

        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 1000,
        ]);

        $gateway = PaymentGateway::factory()->create([
            'gateway_name' => 'hitpay',
        ]);

        $transaction = PaymentTransaction::factory()->create([
            'payment_gateway_id' => $gateway->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 1000,
            'status' => 'completed',
            'transaction_id' => 'TXN-'.uniqid(),
            'refunded_amount' => 0,
        ]);

        $result = $this->service->processRefund(
            $transaction,
            1000, // Full refund
            'Full refund requested',
            1,
        );

        $this->assertEquals('refunded', $result['transaction']->status);
        $this->assertEquals(1000, $result['transaction']->refunded_amount);
    }

    /** @test */
    public function can_refund_returns_true_for_valid_refund(): void
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => 1000,
        ]);

        $gateway = PaymentGateway::factory()->create([
            'gateway_name' => 'hitpay',
        ]);

        $transaction = PaymentTransaction::factory()->create([
            'payment_gateway_id' => $gateway->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 1000,
            'status' => 'completed',
            'transaction_id' => 'TXN-'.uniqid(),
            'refunded_amount' => 0,
        ]);

        $this->assertTrue($this->service->canRefund($transaction, 500));
        $this->assertTrue($this->service->canRefund($transaction, 1000));
        $this->assertFalse($this->service->canRefund($transaction, 1500));
    }

    /** @test */
    public function get_available_refund_amount_returns_correct_value(): void
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => 1000,
        ]);

        $gateway = PaymentGateway::factory()->create([
            'gateway_name' => 'hitpay',
        ]);

        $transaction = PaymentTransaction::factory()->create([
            'payment_gateway_id' => $gateway->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 1000,
            'status' => 'completed',
            'transaction_id' => 'TXN-'.uniqid(),
            'refunded_amount' => 300,
        ]);

        $this->assertEquals(700, $this->service->getAvailableRefundAmount($transaction));
    }

    /** @test */
    public function duplicate_refund_attempts_are_prevented(): void
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => 1000,
            'balance' => 0,
            'paid_amount' => 1000,
            'status' => 'paid',
        ]);

        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 1000,
        ]);

        $gateway = PaymentGateway::factory()->create([
            'gateway_name' => 'hitpay',
        ]);

        $transaction = PaymentTransaction::factory()->create([
            'payment_gateway_id' => $gateway->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 1000,
            'status' => 'completed',
            'transaction_id' => 'TXN-'.uniqid(),
            'refunded_amount' => 0,
        ]);

        // First refund
        $result1 = $this->service->processRefund($transaction, 500, 'First refund', 1);
        $this->assertEquals(500, $result1['transaction']->refunded_amount);

        // Second refund of same transaction (should only allow remaining 500)
        $result2 = $this->service->processRefund($result1['transaction'], 300, 'Second refund', 1);
        $this->assertEquals(800, $result2['transaction']->refunded_amount);

        // Verify invoice balance updated correctly
        $invoice->refresh();
        $this->assertEquals(200, $invoice->paid_amount);
        $this->assertEquals(800, $invoice->balance);

        // Third refund should fail (only 200 available)
        $this->expectException(\InvalidArgumentException::class);
        $this->service->processRefund($result2['transaction'], 300, 'Third refund', 1);
    }
}
