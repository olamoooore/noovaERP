<?php

namespace Modules\IoT\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\IoT\Models\IoTDevice;
use Modules\IoT\Services\DeviceManager;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\POS\Services\PosPaymentCompletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * IoT Terminal Controller
 * 
 * Handles payment terminal operations including payment creation,
 * status checking, cancellation, and webhook processing.
 */
class IoTTerminalController extends Controller
{
    public function __construct(
        protected DeviceManager $deviceManager,
        protected PosPaymentCompletionService $paymentCompletionService
    ) {}

    /**
     * Create a payment request.
     *
     * @param Request $request
     * @param IoTDevice $device
     * @return \Illuminate\Http\JsonResponse
     */
    public function createPayment(Request $request, $device)
    {
        $device = IoTDevice::findOrFail($device);
        $validated = $request->validate([
            'order_id' => 'required|integer|exists:pos_orders,id',
            'payment_method' => 'nullable|string|in:wifi_card_reader,paynow_online,grabpay,wechat,alipay',
        ]);

        $order = PosOrder::findOrFail($validated['order_id']);

        if ($order->status !== 'draft') {
            return response()->json(['message' => 'Order is not payable'], 422);
        }

        // Validate currency is supported
        $supportedCurrencies = config('iot.hitpay.supported_currencies', []);
        if (!in_array(strtoupper($order->currency), $supportedCurrencies)) {
            return response()->json([
                'message' => "Currency {$order->currency} is not supported by HitPay",
                'supported_currencies' => $supportedCurrencies,
            ], 422);
        }

        try {
            $driver = $this->deviceManager->getDriver($device);

            $paymentData = $driver->createPaymentRequest(
                $order->total,
                $order->currency,
                [
                    'order_reference' => $order->order_number,
                    'payment_method' => $validated['payment_method'] ?? 'wifi_card_reader',
                ]
            );

            // Store payment request ID in order metadata
            $order->update([
                'metadata' => array_merge($order->metadata ?? [], [
                    'payment_request_id' => $paymentData['id'],
                    'payment_method' => $validated['payment_method'] ?? 'wifi_card_reader',
                    'terminal_id' => $device->id,
                ]),
            ]);

            return response()->json([
                'success' => true,
                'payment_request_id' => $paymentData['id'],
                'status' => $paymentData['status'],
                'qr_code_url' => $paymentData['url'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('Payment request creation failed', [
                'order_id' => $order->id,
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->parseErrorMessage($e->getMessage()),
            ], 500);
        }
    }

    /**
     * Check payment status.
     *
     * @param Request $request
     * @param IoTDevice $device
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkPaymentStatus(Request $request, $device)
    {
        $device = IoTDevice::findOrFail($device);
        $validated = $request->validate([
            'payment_request_id' => 'required|string',
        ]);

        try {
            $driver = $this->deviceManager->getDriver($device);
            $status = $driver->checkPaymentStatus($validated['payment_request_id']);

            return response()->json($status);

        } catch (\Exception $e) {
            Log::error('Payment status check failed', [
                'payment_request_id' => $validated['payment_request_id'],
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->parseErrorMessage($e->getMessage()),
            ], 500);
        }
    }

    /**
     * Cancel a pending payment.
     *
     * @param Request $request
     * @param IoTDevice $device
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelPayment(Request $request, $device)
    {
        $device = IoTDevice::findOrFail($device);
        $validated = $request->validate([
            'payment_request_id' => 'required|string',
            'order_id' => 'required|integer|exists:pos_orders,id',
        ]);

        try {
            $driver = $this->deviceManager->getDriver($device);
            $cancelled = $driver->cancelPayment($validated['payment_request_id']);

            if ($cancelled) {
                $order = PosOrder::findOrFail($validated['order_id']);
                $order->update(['status' => 'cancelled']);

                return response()->json([
                    'success' => true,
                    'message' => 'Payment cancelled successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel payment',
            ], 500);

        } catch (\Exception $e) {
            Log::error('Payment cancellation failed', [
                'payment_request_id' => $validated['payment_request_id'],
                'order_id' => $validated['order_id'],
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->parseErrorMessage($e->getMessage()),
            ], 500);
        }
    }

    /**
     * Parse error message to user-friendly format.
     *
     * @param string $errorMessage
     * @return string
     */
    protected function parseErrorMessage(string $errorMessage): string
    {
        // Extract error code if present in HitPay response
        if (str_contains($errorMessage, 'insufficient_funds')) {
            return 'Payment declined: Insufficient funds. Please try another card.';
        }

        if (str_contains($errorMessage, 'card_declined')) {
            return 'Payment declined by card issuer. Please try another card.';
        }

        if (str_contains($errorMessage, 'terminal_offline')) {
            return 'Payment terminal is offline. Please check the terminal connection.';
        }

        if (str_contains($errorMessage, 'invalid_currency')) {
            return 'This currency is not supported. Please contact support.';
        }

        if (str_contains($errorMessage, 'Connection')) {
            return 'Connection to payment gateway failed. Please try again.';
        }

        // Return generic message for unknown errors
        return 'Payment failed. Please try again or contact support if the problem persists.';
    }

    /**
     * Handle HitPay webhook notifications.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function webhook(Request $request)
    {
        $signature = $request->header('X-SIGNATURE');
        $payload = $request->all();

        // Find the payment request ID
        $paymentRequestId = $payload['payment_request_id'] ?? null;
        if (!$paymentRequestId) {
            Log::warning('Webhook received without payment_request_id', ['payload' => $payload]);
            return response()->json(['message' => 'Invalid webhook payload'], 400);
        }

        // Find order by payment request ID
        $order = PosOrder::whereJsonContains('metadata->payment_request_id', $paymentRequestId)->first();
        if (!$order) {
            Log::warning('Webhook received for unknown order', [
                'payment_request_id' => $paymentRequestId,
                'payload' => $payload,
            ]);
            return response()->json(['message' => 'Order not found'], 404);
        }

        // Find terminal device
        $terminalId = $order->metadata['terminal_id'] ?? null;
        $device = $terminalId 
            ? IoTDevice::find($terminalId)
            : IoTDevice::where('type', 'terminal')
                ->where('vendor', 'hitpay')
                ->where('is_active', true)
                ->first();

        if (!$device) {
            Log::error('No active HitPay terminal found for webhook', [
                'payment_request_id' => $paymentRequestId,
                'order_id' => $order->id,
            ]);
            return response()->json(['message' => 'Terminal not found'], 404);
        }

        // Validate terminal configuration
        if (!$device->config || !is_array($device->config)) {
            Log::error('Terminal has invalid configuration', [
                'device_id' => $device->id,
                'payment_request_id' => $paymentRequestId,
            ]);
            return response()->json(['message' => 'Terminal configuration error'], 500);
        }

        try {
            $driver = $this->deviceManager->getDriver($device);

            // Verify webhook signature (fast operation)
            if (!$driver->verifyWebhookSignature($payload, $signature)) {
                $device->logAction('webhook', 'failed', $payload, null, 'Invalid signature');
                Log::warning('Webhook signature verification failed', [
                    'payment_request_id' => $paymentRequestId,
                    'order_id' => $order->id,
                ]);
                return response()->json(['message' => 'Invalid signature'], 403);
            }

            // Get current tenant ID
            $tenantId = tenant('id');

            // Dispatch job to queue - RETURN IMMEDIATELY
            \Modules\IoT\Jobs\ProcessPaymentWebhook::dispatch(
                $order->id,
                $payload,
                $device->id,
                $tenantId
            )
            ->onQueue('payments')
            ->delay(now()->addSeconds(2)); // Small delay to ensure order is saved

            // Log webhook received
            $device->logAction('webhook', 'received', $payload, [
                'order_id' => $order->id,
                'queued' => true,
            ]);

            Log::info('Webhook received and queued for processing', [
                'order_id' => $order->id,
                'payment_request_id' => $paymentRequestId,
                'status' => $payload['status'] ?? 'unknown',
            ]);

            // Return 200 immediately (HitPay won't retry)
            return response()->json(['message' => 'Webhook received'], 200);

        } catch (\Exception $e) {
            Log::error('Webhook processing error', [
                'payment_request_id' => $paymentRequestId,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Still return 200 to prevent HitPay from retrying
            // The error is logged and can be investigated
            return response()->json(['message' => 'Webhook received'], 200);
        }
    }

    /**
     * Handle successful payment webhook.
     *
     * @param PosOrder $order
     * @param array $payload
     * @param IoTDevice $device
     * @return void
     */
    protected function handlePaymentSuccess(PosOrder $order, array $payload, IoTDevice $device)
    {
        DB::transaction(function () use ($order, $payload, $device) {
            $amount = (float) ($payload['amount'] ?? 0);
            $paymentMethod = $payload['payment_type'] ?? 'card';
            $paymentId = $payload['payment_id'] ?? null;

            if ($order->status === 'paid' || $order->paid_at) {
                Log::info('Skipping already-paid IoT terminal order', [
                    'order_id' => $order->id,
                    'payment_id' => $paymentId,
                ]);
                return;
            }

            if ($paymentId && PosPayment::where('reference', $paymentId)->where('pos_order_id', $order->id)->exists()) {
                Log::info('Skipping duplicate IoT terminal payment', [
                    'order_id' => $order->id,
                    'payment_id' => $paymentId,
                ]);
                return;
            }

            $order = $this->paymentCompletionService->complete(
                $order,
                $amount,
                $paymentMethod,
                $paymentId,
                $order->created_by,
            );

            // Update terminal last seen timestamp
            $device->markOnline();

            // TODO: Trigger receipt printing (Phase 4)
            // if ($printer = $this->getBoundPrinter($device)) {
            //     $this->triggerReceiptPrint($order, $printer);
            // }

            Log::info('Payment processed successfully', [
                'order_id' => $order->id,
                'payment_id' => $payload['payment_id'] ?? null,
                'amount' => $amount,
                'journal_entry_id' => $order->journal_entry_id,
            ]);
        });
    }

    /**
     * Handle failed payment webhook.
     *
     * @param PosOrder $order
     * @param array $payload
     * @param IoTDevice $device
     * @return void
     */
    protected function handlePaymentFailure(PosOrder $order, array $payload, IoTDevice $device)
    {
        $order->update([
            'status' => 'payment_failed',
            'metadata' => array_merge($order->metadata ?? [], [
                'payment_error' => $payload['error_message'] ?? 'Payment failed',
                'payment_error_code' => $payload['error_code'] ?? null,
            ]),
        ]);

        Log::warning('Payment failed', [
            'order_id' => $order->id,
            'payment_request_id' => $payload['payment_request_id'] ?? null,
            'error' => $payload['error_message'] ?? 'Unknown error',
        ]);
    }
}
