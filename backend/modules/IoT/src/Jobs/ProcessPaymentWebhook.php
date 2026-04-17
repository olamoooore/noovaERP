<?php

namespace Modules\IoT\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\IoT\Models\IoTDevice;
use Modules\POS\Services\PosPaymentCompletionService;

/**
 * Process Payment Webhook Job
 * 
 * Handles payment webhook processing asynchronously with retry logic.
 * This ensures the webhook endpoint returns quickly to HitPay while
 * processing happens in the background with automatic retries.
 */
class ProcessPaymentWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     * Exponential backoff: 10s, 30s, 1m, 5m, 15m
     */
    public $backoff = [10, 30, 60, 300, 900];

    /**
     * The maximum number of seconds the job can run.
     */
    public $timeout = 120;

    /**
     * Delete the job if its models no longer exist.
     */
    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $orderId,
        public array $payload,
        public int $deviceId,
        public string $tenantId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PosPaymentCompletionService $paymentCompletionService): void
    {
        // Initialize tenant context
        $tenant = \Stancl\Tenancy\Database\Models\Tenant::find($this->tenantId);
        if (!$tenant) {
            Log::error('Tenant not found for webhook processing', [
                'tenant_id' => $this->tenantId,
                'order_id' => $this->orderId,
            ]);
            $this->fail(new \Exception('Tenant not found'));
            return;
        }

        tenancy()->initialize($tenant);

        try {
            $order = PosOrder::find($this->orderId);
            $device = IoTDevice::find($this->deviceId);

            if (!$order) {
                Log::error('Order not found for webhook processing', [
                    'order_id' => $this->orderId,
                    'payload' => $this->payload,
                ]);
                $this->fail(new \Exception('Order not found'));
                return;
            }

            if (!$device) {
                Log::error('Device not found for webhook processing', [
                    'device_id' => $this->deviceId,
                    'order_id' => $this->orderId,
                ]);
                $this->fail(new \Exception('Device not found'));
                return;
            }

            // Process webhook in transaction
            DB::transaction(function () use ($order, $device, $paymentCompletionService) {
                $status = $this->payload['status'] ?? null;

                if ($status === 'completed') {
                    $this->handlePaymentSuccess($order, $device, $paymentCompletionService);
                } elseif ($status === 'failed') {
                    $this->handlePaymentFailure($order, $device);
                } else {
                    Log::warning('Unknown webhook status', [
                        'status' => $status,
                        'order_id' => $order->id,
                        'payload' => $this->payload,
                    ]);
                }

                // Log successful processing
                $device->logAction('webhook_processed', 'success', $this->payload, [
                    'order_id' => $order->id,
                    'attempt' => $this->attempts(),
                ]);
            });

            Log::info('Payment webhook processed successfully', [
                'order_id' => $order->id,
                'status' => $this->payload['status'],
                'attempt' => $this->attempts(),
            ]);

        } catch (\Exception $e) {
            Log::error('Payment webhook processing error', [
                'order_id' => $this->orderId,
                'device_id' => $this->deviceId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry
            throw $e;
        } finally {
            tenancy()->end();
        }
    }

    /**
     * Handle successful payment.
     */
    protected function handlePaymentSuccess(PosOrder $order, IoTDevice $device, PosPaymentCompletionService $paymentCompletionService): void
    {
        $amount = (float) ($this->payload['amount'] ?? 0);
        $paymentMethod = $this->payload['payment_type'] ?? 'card';
        $paymentId = $this->payload['payment_id'] ?? null;

        if ($order->status === 'paid' || $order->paid_at) {
            Log::info('Skipping already-paid IoT webhook order', [
                'order_id' => $order->id,
                'payment_id' => $paymentId,
            ]);
            return;
        }

        if ($paymentId && PosPayment::where('reference', $paymentId)->where('pos_order_id', $order->id)->exists()) {
            Log::info('Skipping duplicate IoT payment webhook', [
                'order_id' => $order->id,
                'payment_id' => $paymentId,
            ]);
            return;
        }

        // Validate amount matches order total
        if (abs($amount - (float) $order->total) > 0.01) {
            Log::warning('Payment amount mismatch', [
                'order_id' => $order->id,
                'order_total' => $order->total,
                'payment_amount' => $amount,
            ]);
        }

        $order = $paymentCompletionService->complete(
            $order,
            $amount,
            $paymentMethod,
            $paymentId,
            $order->created_by,
        );

        Log::info('Payment processed successfully', [
            'order_id' => $order->id,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'journal_entry_id' => $order->journal_entry_id,
        ]);
    }

    /**
     * Handle failed payment.
     */
    protected function handlePaymentFailure(PosOrder $order, IoTDevice $device): void
    {
        $errorMessage = $this->payload['error_message'] ?? 'Payment failed';

        $order->update([
            'status' => 'payment_failed',
            'metadata' => array_merge($order->metadata ?? [], [
                'payment_error' => $errorMessage,
                'failed_at' => now()->toIso8601String(),
            ]),
        ]);

        Log::warning('Payment failed', [
            'order_id' => $order->id,
            'error' => $errorMessage,
            'payload' => $this->payload,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Payment webhook processing failed after all retries', [
            'order_id' => $this->orderId,
            'device_id' => $this->deviceId,
            'tenant_id' => $this->tenantId,
            'payload' => $this->payload,
            'attempts' => $this->attempts(),
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Initialize tenant to update order
        try {
            $tenant = \Stancl\Tenancy\Database\Models\Tenant::find($this->tenantId);
            if ($tenant) {
                tenancy()->initialize($tenant);

                $order = PosOrder::find($this->orderId);
                if ($order) {
                    $order->update([
                        'status' => 'payment_processing_failed',
                        'metadata' => array_merge($order->metadata ?? [], [
                            'processing_error' => $exception->getMessage(),
                            'requires_manual_review' => true,
                            'failed_at' => now()->toIso8601String(),
                        ]),
                    ]);
                }

                tenancy()->end();
            }
        } catch (\Exception $e) {
            Log::error('Failed to update order after job failure', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);
        }

        // TODO: Send alert to admin/support team
        // TODO: Create support ticket for manual review
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'payment',
            'webhook',
            'order:' . $this->orderId,
            'device:' . $this->deviceId,
            'tenant:' . $this->tenantId,
        ];
    }
}
