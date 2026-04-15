<?php

namespace Modules\PaymentGateway\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentGatewayFactory;
use Stancl\Tenancy\Facades\Tenancy;

class PaymentWebhookController extends Controller
{
    /**
     * Initialize tenancy for webhooks
     * Webhooks come from external domains (ngrok, production), so we need to
     * manually identify the tenant from the payment gateway/transaction
     */
    protected function initializeTenancyForTransaction($transactionId)
    {
        // Query all tenants to find the transaction
        $tenants = \App\Models\Tenant::all();

        foreach ($tenants as $tenant) {
            $found = $tenant->run(function () use ($transactionId) {
                $transaction = PaymentTransaction::where('transaction_id', $transactionId)->first();
                return $transaction !== null;
            });

            if ($found) {
                return $tenant;
            }
        }

        return null;
    }
    /**
     * Handle Stripe webhook
     */
    public function stripe(Request $request)
    {
        // Extract transaction ID from Stripe payload to identify tenant
        $payload = $request->all();
        $transactionId = $payload['data']['object']['id'] 
            ?? $payload['data']['object']['payment_intent'] 
            ?? null;

        if (!$transactionId) {
            Log::error('Stripe webhook missing transaction identifier', ['payload' => $payload]);
            return response()->json(['error' => 'Missing transaction identifier'], 400);
        }

        // Find and initialize the tenant for this transaction
        $tenant = $this->initializeTenancyForTransaction($transactionId);

        if (!$tenant) {
            Log::error('Stripe transaction not found in any tenant', ['transaction_id' => $transactionId]);
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        // Now we're in the correct tenant context, process the webhook
        return $tenant->run(function () use ($request) {
            // Find the Stripe gateway
            $gateway = PaymentGateway::where('gateway_name', 'stripe')
                ->where('is_active', true)
                ->first();

            if (!$gateway) {
                Log::error('Stripe webhook received but no active Stripe gateway found');
                return response()->json(['error' => 'Gateway not configured'], 400);
            }

            try {
                $service = PaymentGatewayFactory::create($gateway);

                // Verify webhook signature
                if (!$service->verifyWebhook($request)) {
                    Log::error('Stripe webhook signature verification failed');
                    return response()->json(['error' => 'Invalid signature'], 400);
                }

                // Process webhook
                $webhookData = $service->processWebhook($request);

                Log::info('Stripe webhook received', [
                    'event' => $webhookData['event'],
                    'transaction_id' => $webhookData['transaction_id'],
                ]);

                // Handle different event types
                if (in_array($webhookData['event'], ['payment_intent.succeeded', 'charge.succeeded'])) {
                    $this->handlePaymentSuccess($webhookData, $gateway);
                } elseif (in_array($webhookData['event'], ['payment_intent.payment_failed', 'charge.failed'])) {
                    $this->handlePaymentFailed($webhookData, $gateway);
                }

                return response()->json(['status' => 'success']);
            } catch (\Exception $e) {
                Log::error('Stripe webhook processing error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json(['error' => 'Webhook processing failed'], 500);
            }
        });
    }

    /**
     * Handle HitPay webhook
     */
    public function hitpay(Request $request)
    {
        Log::info('HitPay webhook received', ['payload' => $request->all()]);

        // Get transaction ID from webhook data
        // HitPay sends different formats:
        // 1. payment_request_id = our transaction ID
        // 2. id = payment request ID (for payment request webhooks)
        // 3. reference_number = invoice number (fallback)
        $transactionId = $request->input('payment_request_id')
            ?? $request->input('id')
            ?? $request->input('reference_number');

        if (!$transactionId) {
            Log::error('HitPay webhook missing transaction identifier', ['payload' => $request->all()]);
            return response()->json(['error' => 'Missing transaction identifier'], 400);
        }

        // Find and initialize the tenant for this transaction
        $tenant = $this->initializeTenancyForTransaction($transactionId);

        if (!$tenant) {
            Log::error('Transaction not found in any tenant', ['transaction_id' => $transactionId]);
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        // Now we're in the correct tenant context, process the webhook
        return $tenant->run(function () use ($request) {
            // Find the HitPay gateway
            $gateway = PaymentGateway::where('gateway_name', 'hitpay')
                ->where('is_active', true)
                ->first();

            if (!$gateway) {
                Log::error('HitPay webhook received but no active HitPay gateway found');
                return response()->json(['error' => 'Gateway not configured'], 400);
            }

            try {
                $service = PaymentGatewayFactory::create($gateway);

                // Verify webhook signature
                if (!$service->verifyWebhook($request)) {
                    Log::error('HitPay webhook signature verification failed');
                    return response()->json(['error' => 'Invalid signature'], 400);
                }

                // Process webhook
                $webhookData = $service->processWebhook($request);

                Log::info('HitPay webhook received', [
                    'event' => $webhookData['event'],
                    'transaction_id' => $webhookData['transaction_id'],
                ]);

                // Handle payment status
                if ($webhookData['status'] === 'completed') {
                    $this->handlePaymentSuccess($webhookData, $gateway);
                } elseif ($webhookData['status'] === 'failed') {
                    $this->handlePaymentFailed($webhookData, $gateway);
                }

                return response()->json(['status' => 'success']);
            } catch (\Exception $e) {
                Log::error('HitPay webhook processing error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json(['error' => 'Webhook processing failed'], 500);
            }
        });
    }

    /**
     * Handle successful payment
     */
    protected function handlePaymentSuccess(array $webhookData, PaymentGateway $gateway)
    {
        $transaction = PaymentTransaction::where('transaction_id', $webhookData['transaction_id'])->first();

        if (!$transaction) {
            Log::warning('Payment transaction not found', ['transaction_id' => $webhookData['transaction_id']]);
            return;
        }

        // Check for duplicate webhook using webhook_id (Stripe event ID or HitPay webhook ID)
        $webhookId = $webhookData['webhook_id'] ?? null;

        if ($webhookId) {
            // Check if this exact webhook has already been processed
            if ($transaction->webhook_id === $webhookId) {
                Log::info('Duplicate webhook detected and ignored (same webhook_id)', [
                    'transaction_id' => $webhookData['transaction_id'],
                    'webhook_id' => $webhookId,
                ]);
                return;
            }

            // Also check if any other transaction has processed this webhook
            $duplicateCheck = PaymentTransaction::where('webhook_id', $webhookId)
                ->where('id', '!=', $transaction->id)
                ->exists();

            if ($duplicateCheck) {
                Log::info('Duplicate webhook detected and ignored (webhook processed elsewhere)', [
                    'transaction_id' => $webhookData['transaction_id'],
                    'webhook_id' => $webhookId,
                ]);
                return;
            }
        }

        if ($transaction->status === 'completed') {
            Log::info('Payment already processed (status=completed)', ['transaction_id' => $webhookData['transaction_id']]);
            return;
        }

        // Increment webhook attempts
        $transaction->increment('webhook_attempts');

        $transaction->update([
            'webhook_id' => $webhookId,
            'webhook_received_at' => now(),
            'status' => 'completed',
            'payment_method' => $webhookData['payment_method'] ?? null,
            'gateway_response' => $webhookData['raw_data'] ?? null,
            'metadata' => array_merge(
                $transaction->metadata ?? [],
                $webhookData['metadata'] ?? []
            ), // Merge existing metadata with webhook metadata (contains payment_id for HitPay)
        ]);

        // Process the payment and update invoice
        $this->processPayment($transaction);

        // Send payment receipt email
        $this->sendPaymentReceipt($transaction);

        // Fire PaymentSuccessful event for loyalty points and other integrations
        event(new \Modules\PaymentGateway\Events\PaymentSuccessful(
            $transaction,
            $transaction->metadata ?? []
        ));

        Log::info('Payment processed successfully via webhook', [
            'transaction_id' => $webhookData['transaction_id'],
            'invoice_id' => $transaction->invoice_id,
        ]);
    }

    /**
     * Handle failed payment
     */
    protected function handlePaymentFailed(array $webhookData, PaymentGateway $gateway)
    {
        $transaction = PaymentTransaction::where('transaction_id', $webhookData['transaction_id'])->first();

        if (!$transaction) {
            Log::warning('Payment transaction not found for failure', ['transaction_id' => $webhookData['transaction_id']]);
            return;
        }

        $transaction->update([
            'status' => 'failed',
            'error_message' => $webhookData['raw_data']['failure_message'] ?? 'Payment failed',
            'gateway_response' => $webhookData['raw_data'] ?? null,
        ]);

        Log::info('Payment marked as failed', [
            'transaction_id' => $webhookData['transaction_id'],
            'invoice_id' => $transaction->invoice_id,
        ]);
    }

    /**
     * Process completed payment
     */
    protected function processPayment(PaymentTransaction $transaction)
    {
        \DB::transaction(function () use ($transaction) {
            // Check if this is a web order payment (no invoice yet)
            $metadata = $transaction->metadata ?? [];
            $isWebOrder = ($metadata['type'] ?? null) === 'web_order';
            
            // For web orders, find and process the WebOrder first
            if ($isWebOrder && !$transaction->invoice_id) {
                $this->processWebOrderPayment($transaction);
                return;
            }
            
            // Standard invoice-based payment processing
            $invoice = $transaction->invoice;
            
            if (!$invoice) {
                Log::error('Payment transaction has no associated invoice', [
                    'transaction_id' => $transaction->transaction_id,
                    'invoice_id' => $transaction->invoice_id,
                ]);
                return;
            }
            
            $invoice->refresh();

            // Check if payment already exists
            $existingPayment = \App\Models\Payment::where('reference', $transaction->transaction_id)->first();

            if ($existingPayment) {
                Log::info('Payment already exists, skipping creation', [
                    'transaction_id' => $transaction->transaction_id,
                    'payment_id' => $existingPayment->id,
                ]);

                // Ensure invoice is updated
                $this->updateInvoice($invoice, $transaction->amount);
                $transaction->update(['processed_at' => now()]);
                return;
            }

            // Create Payment record
            try {
                $payment = \App\Models\Payment::create([
                    'customer_id' => $transaction->customer_id,
                    'invoice_id' => $invoice->id,
                    'payment_date' => now(),
                    'method' => 'card',
                    'reference' => $transaction->transaction_id,
                    'amount' => $transaction->amount,
                    'notes' => "Online payment via {$transaction->gateway->gateway_name} (webhook)",
                    'received_by' => $invoice->created_by,
                ]);

                Log::info('Payment created via webhook', [
                    'payment_id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'transaction_id' => $transaction->transaction_id,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to create payment from webhook', [
                    'transaction_id' => $transaction->transaction_id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Update invoice
            $this->updateInvoice($invoice, $transaction->amount);

            // Update associated WebOrder status if payment is complete
            $this->updateWebOrderStatus($invoice, $transaction);

            // Mark as processed
            $transaction->update(['processed_at' => now()]);
        });
    }
    
    /**
     * Process payment for web orders (which don't have invoices yet)
     */
    protected function processWebOrderPayment(PaymentTransaction $transaction)
    {
        $metadata = $transaction->metadata ?? [];
        $orderId = $metadata['order_id'] ?? null;
        
        // Try to find WebOrder by metadata order_id or payment_transaction_id
        $webOrder = null;
        
        if ($orderId) {
            $webOrder = \Modules\Ecommerce\Models\WebOrder::find($orderId);
        }
        
        if (!$webOrder) {
            $webOrder = \Modules\Ecommerce\Models\WebOrder::where('payment_transaction_id', $transaction->transaction_id)->first();
        }
        
        if (!$webOrder) {
            Log::error('WebOrder not found for payment transaction', [
                'transaction_id' => $transaction->transaction_id,
                'order_id' => $orderId,
            ]);
            return;
        }
        
        Log::info('Processing web order payment via webhook', [
            'transaction_id' => $transaction->transaction_id,
            'order_id' => $webOrder->id,
            'order_number' => $webOrder->order_number,
        ]);
        
        // Update web order status via state machine
        if ($webOrder->status === \Modules\Ecommerce\Models\WebOrder::STATUS_PENDING) {
            $webOrder->confirm();
        }
        $webOrder->markPaid();
        
        // Convert to invoice
        $invoice = null;
        if (!$webOrder->invoice_id) {
            try {
                $invoice = $webOrder->convertToInvoice();
                Log::info('WebOrder converted to invoice via webhook', [
                    'order_id' => $webOrder->id,
                    'invoice_id' => $invoice->id,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to convert WebOrder to invoice via webhook', [
                    'order_id' => $webOrder->id,
                    'error' => $e->getMessage(),
                ]);
                return;
            }
        } else {
            $invoice = $webOrder->invoice;
        }
        
        if (!$invoice) {
            Log::error('Invoice not created for web order', [
                'order_id' => $webOrder->id,
            ]);
            return;
        }
        
        // Update transaction with invoice_id and customer_id
        $transaction->update([
            'invoice_id' => $invoice->id,
            'customer_id' => $webOrder->customer_id,
        ]);
        
        // Create Payment record
        try {
            $payment = \App\Models\Payment::create([
                'customer_id' => $webOrder->customer_id,
                'invoice_id' => $invoice->id,
                'payment_date' => now(),
                'method' => 'card',
                'reference' => $transaction->transaction_id,
                'amount' => $transaction->amount,
                'notes' => "Online payment via {$transaction->gateway->gateway_name} (webhook)",
                'received_by' => $invoice->created_by,
            ]);

            Log::info('Payment created for web order via webhook', [
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'transaction_id' => $transaction->transaction_id,
                'order_number' => $webOrder->order_number,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create payment for web order from webhook', [
                'transaction_id' => $transaction->transaction_id,
                'order_id' => $webOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Post to accounting FIRST — this is the financial truth.
        // If GL posting fails, we must NOT proceed with downstream mutations
        // (loyalty, customer stats) that assume a successful sale.
        $this->postToAccounting($webOrder, $transaction);
        
        // Award loyalty points (only after accounting succeeds)
        $this->awardLoyaltyPoints($webOrder);
        
        // Update customer stats (only after accounting succeeds)
        $this->updateCustomerStats($webOrder);
        
        // Mark transaction as processed
        $transaction->update(['processed_at' => now()]);
        
        Log::info('Web order payment processed successfully via webhook', [
            'transaction_id' => $transaction->transaction_id,
            'order_number' => $webOrder->order_number,
            'invoice_id' => $invoice->id,
        ]);
    }
    
    /**
     * Post web order to accounting system.
     * Throws on failure so the caller can abort downstream mutations.
     */
    protected function postToAccounting(\Modules\Ecommerce\Models\WebOrder $order, PaymentTransaction $transaction): void
    {
        try {
            $postingService = app(\Modules\Ecommerce\Services\WebOrderPostingService::class);
            $paymentMethod = $transaction->payment_method ?? $transaction->gateway->gateway_name ?? 'card';
            
            $entry = $postingService->postPaidOrder(
                $order,
                (float) $transaction->amount,
                $paymentMethod
            );
            
            if ($entry) {
                Log::info('Web order posted to accounting via webhook', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'entry_number' => $entry->entry_number,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to post web order to accounting', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Re-throw — GL failure must abort the webhook flow
        }
    }
    
    /**
     * Award loyalty points after successful payment.
     */
    protected function awardLoyaltyPoints(\Modules\Ecommerce\Models\WebOrder $order): void
    {
        if (!$order->customer_id) return;

        try {
            $earningService = app(\Modules\Loyalty\Services\PointsEarningService::class);
            $result = $earningService->awardPoints(
                customerId: $order->customer_id,
                amount: (float) $order->subtotal,
                referenceType: 'web_order',
                referenceId: $order->id,
                description: "Points earned from web order {$order->order_number}"
            );

            if ($result['success'] ?? false) {
                $order->update([
                    'loyalty_points_earned' => $result['points_earned'] ?? 0,
                ]);

                Log::info('Loyalty points awarded for web order via webhook', [
                    'order_id' => $order->id,
                    'points' => $result['points_earned'] ?? 0,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to award loyalty points via webhook', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Update customer purchase statistics.
     */
    protected function updateCustomerStats(\Modules\Ecommerce\Models\WebOrder $order): void
    {
        if (!$order->customer_id) return;

        try {
            $customer = \App\Models\Customer::find($order->customer_id);
            if ($customer) {
                $customer->increment('total_orders');
                $customer->increment('total_purchases', (float) $order->total);
                $customer->update([
                    'last_interaction_at' => now(),
                    'average_order_value' => $customer->total_orders > 0
                        ? $customer->total_purchases / $customer->total_orders
                        : 0,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to update customer stats via webhook', [
                'customer_id' => $order->customer_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update invoice with payment using atomic operations to prevent race conditions
     */
    protected function updateInvoice($invoice, $amount)
    {
        // Use atomic increment to prevent lost updates under concurrency
        \App\Models\Invoice::where('id', $invoice->id)
            ->increment('paid_amount', $amount);

        $invoice->refresh();

        $newBalance = $invoice->total - $invoice->paid_amount;

        \App\Models\Invoice::where('id', $invoice->id)->update([
            'balance' => $newBalance,
            'status' => $newBalance <= 0 ? 'paid' : ($invoice->paid_amount > 0 ? 'partial' : $invoice->status),
        ]);

        $invoice->refresh();

        // Update customer outstanding
        if ($invoice->customer) {
            $invoice->customer->updateOutstanding();
        }

        Log::info('Invoice updated via webhook', [
            'invoice_id' => $invoice->id,
            'status' => $invoice->status,
            'paid_amount' => $invoice->paid_amount,
            'balance' => $invoice->balance,
        ]);
    }

    /**
     * Update WebOrder status when payment is confirmed
     */
    protected function updateWebOrderStatus($invoice, $transaction = null)
    {
        try {
            // Try to find WebOrder by invoice_id first, then by payment_transaction_id
            $webOrder = \Modules\Ecommerce\Models\WebOrder::where('invoice_id', $invoice->id)->first();

            if (!$webOrder && $transaction) {
                $webOrder = \Modules\Ecommerce\Models\WebOrder::where('payment_transaction_id', $transaction->transaction_id)->first();
            }

            if ($webOrder && $webOrder->payment_status !== 'paid') {
                $webOrder->update([
                    'payment_status' => 'paid',
                    'status' => 'confirmed',
                    'paid_at' => now(),
                    'confirmed_at' => now(),
                ]);

                // Convert to invoice if not already linked
                if (!$webOrder->invoice_id) {
                    try {
                        $inv = $webOrder->convertToInvoice();
                        Log::info('WebOrder converted to invoice via webhook', [
                            'order_id' => $webOrder->id,
                            'invoice_id' => $inv->id,
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to convert WebOrder to invoice via webhook', [
                            'order_id' => $webOrder->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                Log::info('WebOrder status updated to confirmed/paid via webhook', [
                    'order_id' => $webOrder->id,
                    'order_number' => $webOrder->order_number,
                ]);
            }
        } catch (\Exception $e) {
            // WebOrder may not exist (e.g., non-ecommerce payment) — ignore
            Log::debug('No WebOrder found for invoice/transaction', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send payment receipt email
     */
    protected function sendPaymentReceipt(PaymentTransaction $transaction)
    {
        try {
            $customer = $transaction->customer ?? $transaction->invoice->customer;

            if (!$customer || !$customer->email) {
                Log::warning('Cannot send payment receipt: no customer email', [
                    'transaction_id' => $transaction->transaction_id,
                ]);
                return;
            }

            $customer->notify(new \Modules\PaymentGateway\Notifications\PaymentReceiptNotification($transaction));

            Log::info('Payment receipt email sent', [
                'transaction_id' => $transaction->transaction_id,
                'customer_email' => $customer->email,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send payment receipt email', [
                'transaction_id' => $transaction->transaction_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle PayPal webhook
     */
    public function paypal(Request $request)
    {
        Log::info('PayPal webhook received', ['payload' => $request->all()]);

        // Get transaction ID from webhook data
        $transactionId = $request->input('resource.id')
            ?? $request->input('resource.supplementary_data.related_ids.order_id')
            ?? null;

        if (!$transactionId) {
            Log::error('PayPal webhook missing transaction identifier', ['payload' => $request->all()]);
            return response()->json(['error' => 'Missing transaction identifier'], 400);
        }

        // Find and initialize the tenant for this transaction
        $tenant = $this->initializeTenancyForTransaction($transactionId);

        if (!$tenant) {
            Log::error('Transaction not found in any tenant', ['transaction_id' => $transactionId]);
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        // Now we're in the correct tenant context, process the webhook
        return $tenant->run(function () use ($request) {
            // Find the PayPal gateway
            $gateway = PaymentGateway::where('gateway_name', 'paypal')
                ->where('is_active', true)
                ->first();

            if (!$gateway) {
                Log::error('PayPal webhook received but no active PayPal gateway found');
                return response()->json(['error' => 'Gateway not configured'], 400);
            }

            try {
                $service = PaymentGatewayFactory::create($gateway);

                // Verify webhook signature
                if (!$service->verifyWebhook($request)) {
                    Log::error('PayPal webhook signature verification failed');
                    return response()->json(['error' => 'Invalid signature'], 400);
                }

                // Process webhook
                $webhookData = $service->processWebhook($request);

                Log::info('PayPal webhook processed', [
                    'event' => $webhookData['event'],
                    'transaction_id' => $webhookData['transaction_id'],
                ]);

                // Handle payment status
                if ($webhookData['status'] === 'completed') {
                    $this->handlePaymentSuccess($webhookData, $gateway);
                } elseif ($webhookData['status'] === 'failed') {
                    $this->handlePaymentFailed($webhookData, $gateway);
                }

                return response()->json(['status' => 'success']);
            } catch (\Exception $e) {
                Log::error('PayPal webhook processing error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json(['error' => 'Webhook processing failed'], 500);
            }
        });
    }
}
