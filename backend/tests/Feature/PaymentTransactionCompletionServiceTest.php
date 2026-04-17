<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosTerminal;
use Tests\TestCase;

class PaymentTransactionCompletionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentTransactionCompletionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentTransactionCompletionService::class);
    }

    /** @test */
    public function it_completes_invoice_payment(): void
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => 1000,
            'balance' => 1000,
            'paid_amount' => 0,
            'status' => 'sent',
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
            'transaction_id' => 'TXN-' . uniqid(),
        ]);

        $result = $this->service->complete($transaction, 'card');

        $this->assertInstanceOf(PaymentTransaction::class, $result);
        $this->assertNotNull($result->processed_at);

        // Payment record should be created
        $payment = Payment::where('reference', $transaction->transaction_id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(1000, $payment->amount);

        // Invoice should be updated
        $invoice->refresh();
        $this->assertEquals(1000, $invoice->paid_amount);
        $this->assertEquals(0, $invoice->balance);
        $this->assertEquals('paid', $invoice->status);
    }

    /** @test */
    public function duplicate_retries_do_not_double_post_invoice_payment(): void
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => 1000,
            'balance' => 1000,
            'paid_amount' => 0,
            'status' => 'sent',
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
            'transaction_id' => 'TXN-DUP-' . uniqid(),
        ]);

        // First completion
        $this->service->complete($transaction, 'card');

        // Second completion (duplicate retry)
        $this->service->complete($transaction->fresh(), 'card');

        // Should only have one payment record
        $paymentCount = Payment::where('reference', $transaction->transaction_id)->count();
        $this->assertEquals(1, $paymentCount);

        // Invoice should have correct amounts (not double-updated)
        $invoice->refresh();
        $this->assertEquals(1000, $invoice->paid_amount);
        $this->assertEquals(0, $invoice->balance);
    }

    /** @test */
    public function it_throws_exception_when_no_invoice_or_pos_order_found(): void
    {
        $gateway = PaymentGateway::factory()->create([
            'gateway_name' => 'hitpay',
        ]);

        $transaction = PaymentTransaction::factory()->create([
            'payment_gateway_id' => $gateway->id,
            'invoice_id' => null,
            'amount' => 1000,
            'status' => 'completed',
            'transaction_id' => 'TXN-' . uniqid(),
            'metadata' => [], // No pos_order_id
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No invoice or POS order found for transaction');

        $this->service->complete($transaction, 'card');
    }

    /** @test */
    public function it_normalizes_payment_methods(): void
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => 1000,
            'balance' => 1000,
            'status' => 'sent',
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
            'transaction_id' => 'TXN-' . uniqid(),
        ]);

        // Test with bank transfer method
        $this->service->complete($transaction->fresh(), 'bank_transfer');

        $payment = Payment::where('reference', $transaction->transaction_id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals('bank_transfer', $payment->method);
    }

    /** @test */
    public function it_updates_transaction_processed_at(): void
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => 1000,
            'balance' => 1000,
            'status' => 'sent',
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
            'transaction_id' => 'TXN-' . uniqid(),
            'processed_at' => null,
        ]);

        $this->assertNull($transaction->processed_at);

        $result = $this->service->complete($transaction, 'card');

        $this->assertNotNull($result->processed_at);
    }

    /** @test */
    public function it_preserves_existing_processed_at_timestamp(): void
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total' => 1000,
            'balance' => 0, // Already paid
            'paid_amount' => 1000,
            'status' => 'paid',
        ]);

        $gateway = PaymentGateway::factory()->create([
            'gateway_name' => 'hitpay',
        ]);

        $processedAt = now()->subHours(2);
        $transaction = PaymentTransaction::factory()->create([
            'payment_gateway_id' => $gateway->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => 1000,
            'status' => 'completed',
            'transaction_id' => 'TXN-' . uniqid(),
            'processed_at' => $processedAt,
        ]);

        $result = $this->service->complete($transaction, 'card');

        // Should preserve the original processed_at timestamp
        $this->assertEquals($processedAt->format('Y-m-d H:i:s'), $result->processed_at->format('Y-m-d H:i:s'));
    }
}
