<?php

namespace Modules\PaymentGateway\Services;

use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\OfflinePaymentMethod;
use Modules\PaymentGateway\Models\PaymentTransaction;

class PosPaymentService
{
    /**
     * Get all available payment methods for POS
     * Includes cash, online gateways, and offline methods
     *
     * @return array
     */
    public function getAvailablePaymentMethods(): array
    {
        $methods = [];

        // 1. Cash (always available)
        $methods[] = [
            'id' => 'cash',
            'code' => 'cash',
            'name' => 'Cash',
            'type' => 'cash',
            'category' => 'cash',
            'icon' => 'solar:money-bag-bold',
            'requires_reference' => false,
            'config' => [],
        ];

        // 2. Online Payment Gateways (Stripe, PayPal, HitPay)
        $onlineGateways = PaymentGateway::where('is_active', true)
            ->where('gateway_type', 'online')
            ->get();

        foreach ($onlineGateways as $gateway) {
            $methods[] = [
                'id' => 'gateway_' . $gateway->id,
                'code' => $gateway->gateway_name,
                'name' => $this->getGatewayDisplayName($gateway->gateway_name),
                'type' => 'online',
                'category' => 'card',
                'icon' => $this->getGatewayIcon($gateway->gateway_name),
                'requires_reference' => false,
                'gateway_id' => $gateway->id,
                'config' => [
                    'is_test_mode' => $gateway->is_test_mode,
                ],
            ];
        }

        // 3. Offline Payment Methods (Bank Transfer, etc.)
        $offlineMethods = OfflinePaymentMethod::with('paymentGateway')
            ->where('is_active', true)
            ->ordered()
            ->get();

        foreach ($offlineMethods as $method) {
            $methods[] = [
                'id' => 'offline_' . $method->id,
                'code' => $method->method_code,
                'name' => $method->method_name,
                'type' => 'offline',
                'category' => 'bank_transfer',
                'icon' => 'mdi:bank',
                'requires_reference' => true,
                'reference_label' => 'Reference Number',
                'reference_placeholder' => 'Enter transaction reference',
                'config' => [
                    'instructions' => $method->instructions,
                    'account_details' => $method->account_details,
                ],
            ];
        }

        return $methods;
    }

    /**
     * Process a payment from POS
     *
     * @param array $data
     * @return array
     */
    public function processPayment(array $data): array
    {
        $paymentType = $data['payment_type'] ?? 'cash';
        
        return match($paymentType) {
            'cash' => $this->processCashPayment($data),
            'online' => $this->processOnlinePayment($data),
            'offline' => $this->processOfflinePayment($data),
            default => throw new \Exception('Invalid payment type'),
        };
    }

    /**
     * Process cash payment
     */
    private function processCashPayment(array $data): array
    {
        return [
            'success' => true,
            'transaction_id' => 'CASH-' . uniqid(),
            'status' => 'completed',
            'amount' => $data['amount'],
            'payment_type' => 'cash',
        ];
    }

    /**
     * Process online payment through gateway
     */
    private function processOnlinePayment(array $data): array
    {
        $gateway = PaymentGateway::findOrFail($data['gateway_id']);
        $gatewayService = PaymentGatewayFactory::create($gateway);

        $paymentData = [
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'MYR',
            'description' => $data['description'] ?? 'POS Order',
            'metadata' => [
                'pos_order_id' => $data['pos_order_id'],
                'customer_id' => $data['customer_id'] ?? null,
            ],
        ];

        $result = $gatewayService->createPayment($paymentData);

        // Create transaction record
        PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => $result['transaction_id'],
            'amount' => $data['amount'],
            'currency' => $paymentData['currency'],
            'status' => 'pending',
            'payment_type' => 'pos',
            'reference_id' => $data['pos_order_id'],
            'metadata' => $paymentData['metadata'],
        ]);

        return $result;
    }

    /**
     * Process offline payment (bank transfer, etc.)
     */
    private function processOfflinePayment(array $data): array
    {
        $offlineMethod = OfflinePaymentMethod::findOrFail($data['offline_method_id']);

        // Create transaction record as pending (awaiting confirmation)
        $transaction = PaymentTransaction::create([
            'payment_gateway_id' => $offlineMethod->payment_gateway_id,
            'transaction_id' => 'OFFLINE-' . uniqid(),
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'MYR',
            'status' => 'pending_confirmation',
            'payment_type' => 'pos_offline',
            'reference_id' => $data['pos_order_id'],
            'reference_number' => $data['reference_number'] ?? null,
            'metadata' => [
                'offline_method_id' => $offlineMethod->id,
                'offline_method_name' => $offlineMethod->method_name,
                'account_details' => $offlineMethod->account_details,
            ],
        ]);

        return [
            'success' => true,
            'transaction_id' => $transaction->transaction_id,
            'status' => 'pending_confirmation',
            'amount' => $data['amount'],
            'payment_type' => 'offline',
            'instructions' => $offlineMethod->instructions,
            'account_details' => $offlineMethod->account_details,
        ];
    }

    /**
     * Get display name for gateway
     */
    private function getGatewayDisplayName(string $gateway): string
    {
        return match($gateway) {
            'stripe' => 'Credit Card (Stripe)',
            'paypal' => 'PayPal',
            'hitpay' => 'HitPay',
            default => ucfirst($gateway),
        };
    }

    /**
     * Get icon for gateway
     */
    private function getGatewayIcon(string $gateway): string
    {
        return match($gateway) {
            'stripe' => 'solar:card-bold',
            'paypal' => 'simple-icons:paypal',
            'hitpay' => 'solar:wallet-bold',
            default => 'solar:card-bold',
        };
    }
}
