<?php

namespace Modules\PaymentGateway\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Ecommerce\Models\WebOrder;
use Modules\Ecommerce\Services\WebOrderNotificationService;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentGatewayFactory;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly WebOrderNotificationService $webOrderNotificationService,
    ) {}

    /**
     * ResolveTenantFromWebhook must initialize tenant context before any
     * webhook reaches this controller. Fail closed if that canonical
     * middleware path is bypassed.
     */
    protected function ensureTenantContext(Request $request, string $gateway)
    {
        if (function_exists('tenant') && tenant()) {
            return null;
        }

        Log::error('Payment webhook executed without tenant context', [
            'gateway' => $gateway,
            'route' => $request->path(),
        ]);

        return response()->json(['error' => 'Tenant context not resolved'], 500);
    }

    /**
     * Handle Stripe webhook
     */
    public function stripe(Request $request)
    {
        if ($response = $this->ensureTenantContext($request, 'stripe')) {
            return $response;
        }

        $gateway = PaymentGateway::where('gateway_name', 'stripe')
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            Log::error('Stripe webhook received but no active Stripe gateway found');

            return response()->json(['error' => 'Gateway not configured'], 400);
        }

        try {
            $service = PaymentGatewayFactory::create($gateway);

            // Verify webhook signature
            if (! $service->verifyWebhook($request)) {
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

        if ($response = $this->ensureTenantContext($request, 'hitpay')) {
            return $response;
        }

        $gateway = PaymentGateway::where('gateway_name', 'hitpay')
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            Log::error('HitPay webhook received but no active HitPay gateway found');

            return response()->json(['error' => 'Gateway not configured'], 400);
        }

        try {
            $service = PaymentGatewayFactory::create($gateway);

            // Verify webhook signature
            if (! $service->verifyWebhook($request)) {
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
    }

    /**
     * Handle successful payment
     */
    protected function handlePaymentSuccess(array $webhookData, PaymentGateway $gateway)
    {
        $transaction = PaymentTransaction::where('transaction_id', $webhookData['transaction_id'])->first();

        if (! $transaction) {
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

        if ($transaction->processed_at) {
            Log::info('Payment already processed (processed_at set)', [
                'transaction_id' => $webhookData['transaction_id'],
                'processed_at' => $transaction->processed_at,
            ]);

            return;
        }

        // Increment webhook attempts
        $transaction->increment('webhook_attempts');

        $mergedMetadata = array_merge(
            $transaction->metadata ?? [],
            $webhookData['metadata'] ?? []
        );

        $transaction->update([
            'webhook_id' => $webhookId,
            'webhook_received_at' => now(),
            'status' => 'processing',
            'payment_method' => $webhookData['payment_method'] ?? null,
            'gateway_response' => $webhookData['raw_data'] ?? null,
            'metadata' => $mergedMetadata,
            'error_message' => null,
        ]);

        try {
            // Process the payment and update invoice
            $this->processPayment($transaction->fresh());

            $transaction->refresh();
            $transaction->update([
                'status' => 'completed',
                'processed_at' => $transaction->processed_at ?? now(),
                'error_message' => null,
            ]);

            $webOrderId = $mergedMetadata['order_id'] ?? null;
            if ($webOrderId) {
                $webOrder = WebOrder::find($webOrderId);
                if ($webOrder) {
                    $this->webOrderNotificationService->dispatchPaidOrderNotifications($webOrder);
                }
            }

            // Send payment receipt email
            $this->sendPaymentReceipt($transaction->fresh());

            // Fire PaymentSuccessful event for loyalty points and other integrations
            event(new \Modules\PaymentGateway\Events\PaymentSuccessful(
                $transaction->fresh(),
                $mergedMetadata
            ));

            Log::info('Payment processed successfully via webhook', [
                'transaction_id' => $webhookData['transaction_id'],
                'invoice_id' => $transaction->invoice_id,
            ]);
        } catch (\Throwable $e) {
            $transaction->refresh();
            $transaction->update([
                'webhook_id' => null,
                'status' => 'pending',
                'processed_at' => null,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle failed payment
     */
    protected function handlePaymentFailed(array $webhookData, PaymentGateway $gateway)
    {
        $transaction = PaymentTransaction::where('transaction_id', $webhookData['transaction_id'])->first();

        if (! $transaction) {
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
        DB::transaction(function () use ($transaction) {
            $transaction = PaymentTransaction::whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->processed_at) {
                Log::info('Skipping payment processing because processed_at is already set', [
                    'transaction_id' => $transaction->transaction_id,
                    'processed_at' => $transaction->processed_at,
                ]);

                return;
            }

            // Check if this is a web order payment (no invoice yet)
            $metadata = $transaction->metadata ?? [];
            $isWebOrder = ($metadata['type'] ?? null) === 'web_order';

            // Web-order completion now routes through the canonical completion
            // service just like the direct checkout and repair paths.
            if ($isWebOrder && ! $transaction->invoice_id) {
                app(PaymentTransactionCompletionService::class)->complete(
                    $transaction,
                    $transaction->payment_method ?? $transaction->gateway?->gateway_name
                );

                return;
            }

            // Standard invoice-based payment processing now routes through the
            // canonical completion service to avoid a second invoice mutation owner.
            $invoice = $transaction->invoice;

            if (! $invoice) {
                Log::error('Payment transaction has no associated invoice', [
                    'transaction_id' => $transaction->transaction_id,
                    'invoice_id' => $transaction->invoice_id,
                ]);

                return;
            }

            app(PaymentTransactionCompletionService::class)->complete(
                $transaction,
                $transaction->payment_method ?? $transaction->gateway?->gateway_name
            );
        });
    }

    /**
     * Send payment receipt email
     */
    protected function sendPaymentReceipt(PaymentTransaction $transaction)
    {
        try {
            $customer = $transaction->customer ?? $transaction->invoice->customer;

            if (! $customer || ! $customer->email) {
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

        if ($response = $this->ensureTenantContext($request, 'paypal')) {
            return $response;
        }

        $gateway = PaymentGateway::where('gateway_name', 'paypal')
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            Log::error('PayPal webhook received but no active PayPal gateway found');

            return response()->json(['error' => 'Gateway not configured'], 400);
        }

        try {
            $service = PaymentGatewayFactory::create($gateway);

            // Verify webhook signature
            if (! $service->verifyWebhook($request)) {
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
    }
}
