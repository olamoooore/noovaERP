<?php

namespace Modules\PaymentGateway\Services;

use Illuminate\Http\Request;
use Modules\PaymentGateway\Models\PaymentGateway;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeGateway implements PaymentGatewayInterface
{
    protected PaymentGateway $gateway;

    public function __construct(PaymentGateway $gateway)
    {
        $this->gateway = $gateway;
        Stripe::setApiKey($this->gateway->api_key);
    }

    /**
     * Create a Stripe Payment Intent
     */
    public function createPayment(array $data): array
    {
        try {
            // Generate idempotency key to prevent duplicate charges
            // Each payment attempt should have a unique key
            if (! empty($data['idempotency_key'])) {
                $idempotencyKey = $data['idempotency_key'];
            } else {
                // Generate a unique key for each payment attempt
                $idempotencyKey = md5(uniqid('stripe_', true).microtime(true).rand(1000, 9999));
            }

            $paymentIntent = PaymentIntent::create([
                'amount' => (int) ($data['amount'] * 100), // Convert to cents
                'currency' => strtolower($data['currency'] ?? 'myr'), // Stripe requires lowercase
                'metadata' => $data['metadata'] ?? [],
                'description' => $data['description'] ?? null,
                'receipt_email' => $data['email'] ?? null,
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never', // Disable redirect-based payment methods for easier testing
                ],
            ], [
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'success' => true,
                'transaction_id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount / 100,
                'currency' => $paymentIntent->currency,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get payment status from Stripe
     */
    public function getPaymentStatus(string $transactionId): array
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($transactionId);

            // Map Stripe status to our status
            $statusMap = [
                'succeeded' => 'completed',
                'processing' => 'processing',
                'requires_payment_method' => 'pending',
                'requires_confirmation' => 'pending',
                'requires_action' => 'pending',
                'canceled' => 'cancelled',
                'requires_capture' => 'pending',
            ];

            $status = $statusMap[$paymentIntent->status] ?? 'failed';

            return [
                'success' => true,
                'status' => $status,
                'amount' => $paymentIntent->amount / 100,
                'currency' => $paymentIntent->currency,
                'payment_method' => $paymentIntent->payment_method,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Confirm and capture a payment intent (for testing)
     */
    public function confirmPayment(string $paymentIntentId, ?string $paymentMethodId = null): array
    {
        try {
            // Use Stripe test card if no payment method provided
            $paymentMethodId = $paymentMethodId ?? 'pm_card_visa';

            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

            // Confirm the payment intent
            $paymentIntent->confirm([
                'payment_method' => $paymentMethodId,
            ]);

            // If automatic capture is disabled, manually capture
            if ($paymentIntent->status === 'requires_capture') {
                $paymentIntent->capture();
            }

            return [
                'success' => true,
                'transaction_id' => $paymentIntent->id,
                'status' => $this->mapStripeStatus($paymentIntent->status),
                'amount' => $paymentIntent->amount / 100,
                'currency' => $paymentIntent->currency,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process refund via Stripe
     */
    public function refundPayment(string $transactionId, float $amount): array
    {
        try {
            $refund = Refund::create([
                'payment_intent' => $transactionId,
                'amount' => (int) ($amount * 100), // Convert to cents
            ]);

            return [
                'success' => true,
                'refund_id' => $refund->id,
                'status' => $refund->status,
                'amount' => $refund->amount / 100,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify Stripe webhook signature
     */
    public function verifyWebhook(Request $request): bool
    {
        try {
            $signature = $request->header('Stripe-Signature');
            $payload = $request->getContent();

            Webhook::constructEvent(
                $payload,
                $signature,
                $this->gateway->webhook_secret
            );

            return true;
        } catch (SignatureVerificationException $e) {
            return false;
        }
    }

    /**
     * Process Stripe webhook
     */
    public function processWebhook(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        $event = $payload['type'] ?? null;
        $data = $payload['data']['object'] ?? null;
        $eventId = $payload['id'] ?? null; // Stripe event ID for deduplication

        return [
            'event' => $event,
            'webhook_id' => $eventId, // Use Stripe event ID for duplicate detection
            'transaction_id' => $data['payment_intent'] ?? $data['id'] ?? null,
            'status' => $this->mapStripeStatus($data['status'] ?? null),
            'amount' => isset($data['amount']) ? $data['amount'] / 100 : null,
            'currency' => $data['currency'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'metadata' => $data['metadata'] ?? [],
            'raw_data' => $data,
        ];
    }

    /**
     * Test Stripe connection
     */
    public function testConnection(): bool
    {
        try {
            // Try to retrieve account info as a connection test
            \Stripe\Account::retrieve();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Process a refund
     */
    public function refund(string $transactionId, float $amount): array
    {
        try {
            $refund = Refund::create([
                'payment_intent' => $transactionId,
                'amount' => (int) ($amount * 100), // Convert to cents
            ]);

            return [
                'success' => true,
                'refund_id' => $refund->id,
                'status' => $refund->status,
                'amount' => $refund->amount / 100,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Map Stripe status to our internal status
     */
    protected function mapStripeStatus(?string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'succeeded' => 'completed',
            'processing' => 'processing',
            'requires_payment_method', 'requires_confirmation', 'requires_action' => 'pending',
            'canceled' => 'cancelled',
            default => 'failed',
        };
    }
}
