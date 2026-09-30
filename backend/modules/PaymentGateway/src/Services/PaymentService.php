<?php

namespace Modules\PaymentGateway\Services;

use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;

/**
 * Unified Payment Service for creating payment intents.
 * Used by WebCheckoutController and other services that need payment processing.
 */
class PaymentService
{
    /**
     * Create a payment intent using the first active payment gateway.
     *
     * @param  array  $data  [amount, currency, description, metadata, email]
     * @return array [client_secret, publishable_key, transaction_id, gateway_name]
     *
     * @throws \Exception if no active payment gateway found or payment creation fails
     */
    public function createIntent(array $data): array
    {
        // Use specific gateway if provided, otherwise find first active
        if (! empty($data['gateway_id'])) {
            $gateway = PaymentGateway::where('is_active', true)
                ->where('id', $data['gateway_id'])
                ->first();

            if (! $gateway) {
                throw new \Exception('Selected payment gateway is not available.');
            }
        } else {
            // Find the first active payment gateway (exclude offline gateways)
            $gateway = PaymentGateway::where('is_active', true)
                ->whereIn('gateway_name', ['stripe', 'hitpay', 'paypal'])
                // MRD D6 v0.3: MY/SG market — HitPay (DuitNow/TNG/PayNow/…) is
                // the preferred default; Stripe dormant code is avoided.
                ->orderByRaw("CASE WHEN gateway_name = 'hitpay' THEN 0 ELSE 1 END")
                ->first();
        }

        if (! $gateway) {
            throw new \Exception('No active payment gateway configured. Please configure a payment gateway in Settings.');
        }

        $gatewayService = PaymentGatewayFactory::create($gateway);

        $result = $gatewayService->createPayment([
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'USD',
            'description' => $data['description'] ?? null,
            'metadata' => $data['metadata'] ?? [],
            'email' => $data['email'] ?? null,
            'redirect_url' => $data['redirect_url'] ?? null,
            'cancel_url' => $data['cancel_url'] ?? null,
            'payment_methods' => $data['payment_methods'] ?? null,
        ]);

        if (! ($result['success'] ?? false)) {
            throw new \Exception($result['error'] ?? 'Payment creation failed');
        }

        // Determine customer_id - only set for invoices (tenant schema customers)
        // For web orders and POS orders, customer is in tenant schema but payment_transactions
        // is in public schema, so we store customer_id in metadata only to avoid FK violation
        $customerId = null;
        if (! empty($data['customer_id'])) {
            // Only use customer_id if this is an invoice payment (customer exists in public schema)
            // For web orders (type=web_order) and POS orders, skip to avoid FK violation
            $metadata = $data['metadata'] ?? [];
            $type = $metadata['type'] ?? null;
            if ($type !== 'web_order' && ! isset($metadata['pos_order_id'])) {
                $customerId = $data['customer_id'];
            }
        }

        // Single durable writer for intents (P-04d). firstOrCreate on the
        // unique transaction_id makes a deduped replay (gateway returned an
        // EXISTING pending request) reuse the original row instead of
        // crashing on the unique index. invoice_id is linked when the intent
        // belongs to an invoice so webhook completion resolves settlement.
        $invoiceId = null;
        $metaType = (string) (($data['metadata']['type'] ?? null));
        if ($metaType === 'invoice' && isset($data['metadata']['invoice_id'])) {
            $invoiceId = (int) $data['metadata']['invoice_id'];
        }

        $attributes = [
            'payment_gateway_id' => $gateway->id,
            'customer_id' => $customerId,
            'transaction_id' => $result['transaction_id'],
            'invoice_id' => $invoiceId,
            'payment_method' => $gateway->gateway_name,
        ];

        $transaction = PaymentTransaction::firstOrCreate(
            ['transaction_id' => $result['transaction_id']],
            $attributes + [
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'USD',
                'status' => 'pending',
                'metadata' => $data['metadata'] ?? [],
            ]
        );

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

        if (! $transaction) {
            throw new \Exception('Transaction not found');
        }

        $gateway = $transaction->gateway;
        if (! $gateway) {
            throw new \Exception('Payment gateway not found');
        }

        $status = $this->getGatewayStatus($gateway, $transactionId);

        if ($status['success'] ?? false) {
            // Hardening rule (CHAN-003): the gateway reporting "completed" is
            // NOT a terminal local state. Until local processing finishes
            // (processed_at is set by the webhook handler), keep the local
            // transaction at 'pending' so concurrent webhooks and retries
            // cannot double-apply the payment.
            $transactionUpdate = [
                'payment_method' => $status['payment_method'] ?? $transaction->payment_method,
                'processed_at' => null,
                'gateway_response' => $status,
            ];

            if ($transaction->processed_at) {
                $transactionUpdate['status'] = $transaction->status;
                $transactionUpdate['processed_at'] = $transaction->processed_at;
            } else {
                $transactionUpdate['status'] = 'pending';
            }

            $transaction->update($transactionUpdate);
        }

        return [
            'status' => $status['status'] ?? 'unknown',
            'transaction_id' => $transactionId,
            'amount' => $transaction->amount,
        ];
    }

    protected function getGatewayStatus(PaymentGateway $gateway, string $transactionId): array
    {
        return PaymentGatewayFactory::create($gateway)->getPaymentStatus($transactionId);
    }

    /**
     * Get all available active gateways for the checkout page.
     */
    public function getAvailableGateways(): array
    {
        // D6 v0.3: even if a legacy stripe row is active, it is not offered —
        // the storefront launch set is HitPay (+PayPal). Stripe factory code
        // is dormant platform plumbing, not a checkout choice.
        return PaymentGateway::where('is_active', true)
            ->whereIn('gateway_name', ['hitpay', 'paypal'])
            ->get()
            ->map(fn ($gw) => [
                'id' => $gw->id,
                'name' => $gw->gateway_name,
                'display_name' => ucfirst($gw->gateway_name),
                'is_test_mode' => $gw->is_test_mode,
            ])
            ->toArray();
    }
}
