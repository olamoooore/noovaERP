<?php

namespace Modules\PaymentGateway\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\PaymentGateway\Models\PaymentGateway;

class PaymentGatewaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Stripe Test Gateway
        PaymentGateway::firstOrCreate(
            ['gateway_name' => 'stripe'],
            [
                'publishable_key' => 'pk_test_REPLACE_WITH_YOUR_KEY',
                'api_key' => 'sk_test_REPLACE_WITH_YOUR_KEY',
                'webhook_secret' => 'whsec_REPLACE_WITH_YOUR_SECRET',
                'is_active' => false,
                'is_test_mode' => true,
                'config' => [
                    'supported_currencies' => ['USD', 'SGD', 'EUR', 'GBP'],
                    'supported_payment_methods' => ['card', 'alipay', 'grabpay'],
                    'automatic_payment_methods' => true,
                    'capture_method' => 'automatic',
                ],
            ]
        );

        // HitPay Test Gateway
        PaymentGateway::firstOrCreate(
            ['gateway_name' => 'hitpay'],
            [
                'api_key' => 'REPLACE_WITH_YOUR_HITPAY_API_KEY',
                'api_secret' => 'REPLACE_WITH_YOUR_HITPAY_SALT',
                'webhook_secret' => null, // HitPay uses api_secret for HMAC
                'is_active' => false,
                'is_test_mode' => true,
                'config' => [
                    'supported_currencies' => ['SGD', 'USD'],
                    'supported_payment_methods' => ['paynow', 'card', 'grabpay', 'alipay', 'wechatpay'],
                    'default_currency' => 'SGD',
                ],
            ]
        );

        // PayPal Test Gateway
        PaymentGateway::firstOrCreate(
            ['gateway_name' => 'paypal'],
            [
                'api_key' => 'REPLACE_WITH_YOUR_PAYPAL_CLIENT_ID',
                'api_secret' => 'REPLACE_WITH_YOUR_PAYPAL_CLIENT_SECRET',
                'webhook_secret' => null, // PayPal uses webhook_id for verification
                'is_active' => false,
                'is_test_mode' => true,
                'config' => [
                    'supported_currencies' => ['USD', 'EUR', 'GBP', 'SGD', 'AUD', 'CAD', 'JPY'],
                    'supported_payment_methods' => ['paypal', 'card'],
                    'default_currency' => 'USD',
                    'webhook_id' => 'REPLACE_WITH_YOUR_WEBHOOK_ID', // Get from PayPal Dashboard
                ],
            ]
        );

        $this->command->info('Payment gateways seeded successfully!');
        $this->command->warn('Remember to update API keys and secrets in production!');
    }
}
