<?php

namespace Modules\PaymentGateway\Services;

use Modules\PaymentGateway\Models\PaymentGateway;

class PaymentGatewayFactory
{
    /**
     * Create gateway service instance based on gateway type
     *
     * @param PaymentGateway $gateway
     * @return PaymentGatewayInterface
     * @throws \Exception
     */
    public static function create(PaymentGateway $gateway): PaymentGatewayInterface
    {
        return match ($gateway->gateway_name) {
            'stripe' => new StripeGateway($gateway),
            'hitpay' => new HitPayGateway($gateway),
            'paypal' => new PayPalGateway($gateway),
            default => throw new \Exception('Unsupported payment gateway: ' . $gateway->gateway_name),
        };
    }

    /**
     * Get available gateway types
     *
     * @return array
     */
    public static function getAvailableGateways(): array
    {
        return [
            [
                'key' => 'stripe',
                'name' => 'Stripe',
                'description' => 'Accept credit cards, debit cards, and more',
                'logo' => '/images/gateways/stripe.svg',
                'fields' => [
                    'publishable_key' => [
                        'label' => 'Publishable Key',
                        'type' => 'text',
                        'required' => true,
                        'help' => 'Your Stripe publishable key (pk_test_... or pk_live_...)',
                    ],
                    'api_key' => [
                        'label' => 'Secret Key',
                        'type' => 'password',
                        'required' => true,
                        'help' => 'Your Stripe secret key (sk_test_... or sk_live_...)',
                    ],
                    'webhook_secret' => [
                        'label' => 'Webhook Secret (Optional)',
                        'type' => 'password',
                        'required' => false,
                        'help' => 'Optional: Webhook signing secret for verifying Stripe webhooks (whsec_...)',
                    ],
                ],
            ],
            [
                'key' => 'hitpay',
                'name' => 'HitPay',
                'description' => 'Accept payments in Singapore and Southeast Asia',
                'logo' => '/images/gateways/hitpay.svg',
                'fields' => [
                    'api_key' => [
                        'label' => 'API Key',
                        'type' => 'password',
                        'required' => true,
                        'help' => 'Your HitPay Business API Key',
                    ],
                    'api_secret' => [
                        'label' => 'Salt',
                        'type' => 'password',
                        'required' => true,
                        'help' => 'Your HitPay Salt for webhook verification',
                    ],
                ],
            ],
            [
                'key' => 'paypal',
                'name' => 'PayPal',
                'description' => 'Accept PayPal and credit card payments worldwide',
                'logo' => '/images/gateways/paypal.svg',
                'fields' => [
                    'api_key' => [
                        'label' => 'Client ID',
                        'type' => 'password',
                        'required' => true,
                        'help' => 'Your PayPal REST API Client ID',
                    ],
                    'api_secret' => [
                        'label' => 'Client Secret',
                        'type' => 'password',
                        'required' => true,
                        'help' => 'Your PayPal REST API Client Secret',
                    ],
                ],
            ],
        ];
    }
}
