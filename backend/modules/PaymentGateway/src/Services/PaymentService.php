<?php

namespace Modules\PaymentGateway\Services;

use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Unified Payment Service for creating payment intents.
 * Used by WebCheckoutController and other services that need payment processing.
 */
class PaymentService
{
    /**
     * Create a payment intent using the first active payment gateway.
     *
     * @param array $data [amount, currency, description, metadata, email]
     * @return array [client_secret, publishable_key, transaction_id, gateway_name]
     * @throws \Exception if no active payment gateway found or payment creation fails
     */
    public function createIntent(array $data): array
    {
        // Use specific gateway if provided, otherwise find first active
        if (!empty($data['gateway_id'])) {
            $gateway = PaymentGateway::where('is_active', true)
                ->where('id', $data['gateway_id'])
                ->first();
            
            if (!$gateway) {
                throw new \Exception('Selected payment gateway is not available.');
            }
        } else {
            // Find the first active payment gateway (exclude offline gateways)
            $gateway = PaymentGateway::where('is_active', true)
                ->whereIn('gateway_name', ['stripe', 'hitpay', 'paypal'])
                ->orderByRaw("CASE WHEN gateway_name = 'stripe' THEN 0 ELSE 1 END") // Prefer Stripe
                ->first();
        }

        if (!$gateway) {
            throw new \Exception('No active payment gateway configured. Please configure a payment gateway in Settings.');
        }

        $gatewayService = PaymentGatewayFactory::create($gateway);

        $result = $gatewayService->createPayment([
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'USD',
            'description' => $data['description'] ?? null,
            'metadata' => $data['metadata'] ?? [],
            'email' => $data['email'] ?? null,
        ]);

        if (!($result['success'] ?? false)) {
            throw new \Exception($result['error'] ?? 'Payment creation failed');
        }

        // Create payment transaction record
        $transaction = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => $result['transaction_id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'USD',
            'status' => 'pending',
            'metadata' => $data['metadata'] ?? [],
        ]);

        return [
            'client_secret' => $result['client_secret'] ?? null,
            'publishable_key' => $gateway->publishable_key,
            'payment_url' => $result['payment_url'] ?? null,
            'transaction_id' => $result['transaction_id'],
            'gateway_name' => $gateway->gateway_name,
            'gateway_id' => $gateway->id,
        ];
    }

    /**
     * Confirm a payment and update transaction status.
     */
    public function confirmPayment(string $transactionId): array
    {
        $transaction = PaymentTransaction::where('transaction_id', $transactionId)->first();

        if (!$transaction) {
            throw new \Exception('Transaction not found');
        }

        $gateway = $transaction->gateway;
        if (!$gateway) {
            throw new \Exception('Payment gateway not found');
        }

        $gatewayService = PaymentGatewayFactory::create($gateway);
        $status = $gatewayService->getPaymentStatus($transactionId);

        if ($status['success'] ?? false) {
            $transaction->update([
                'status' => $status['status'],
                'processed_at' => $status['status'] === 'completed' ? now() : null,
                'gateway_response' => $status,
            ]);
        }

        return [
            'status' => $status['status'] ?? 'unknown',
            'transaction_id' => $transactionId,
            'amount' => $transaction->amount,
        ];
    }

    /**
     * Get all available active gateways for the checkout page.
     */
    public function getAvailableGateways(): array
    {
        return PaymentGateway::where('is_active', true)
            ->whereIn('gateway_name', ['stripe', 'hitpay', 'paypal'])
            ->get()
            ->map(fn($gw) => [
                'id' => $gw->id,
                'name' => $gw->gateway_name,
                'display_name' => ucfirst($gw->gateway_name),
                'is_test_mode' => $gw->is_test_mode,
            ])
            ->toArray();
    }
}
