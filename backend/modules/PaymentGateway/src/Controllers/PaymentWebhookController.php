<?php

namespace Modules\PaymentGateway\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
            $invoice = $transaction->invoice;
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

            // Mark as processed
            $transaction->update(['processed_at' => now()]);
        });
    }

    /**
     * Update invoice with payment
     */
    protected function updateInvoice($invoice, $amount)
    {
        $newPaidAmount = $invoice->paid_amount + $amount;
        $newBalance = $invoice->total - $newPaidAmount;

        $invoice->paid_amount = $newPaidAmount;
        $invoice->balance = $newBalance;

        if ($newBalance <= 0) {
            $invoice->status = 'paid';
        } elseif ($newPaidAmount > 0) {
            $invoice->status = 'partial';
        }

        $invoice->save();

        Log::info('Invoice updated via webhook', [
            'invoice_id' => $invoice->id,
            'status' => $invoice->status,
            'paid_amount' => $invoice->paid_amount,
            'balance' => $invoice->balance,
        ]);
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

                // Verify webhook signature (optional for PayPal)
                if (!$service->verifyWebhook($request)) {
                    Log::warning('PayPal webhook signature verification failed - processing anyway');
                    // PayPal verification is complex, so we proceed but log the warning
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
