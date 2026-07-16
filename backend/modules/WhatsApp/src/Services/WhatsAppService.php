<?php

namespace Modules\WhatsApp\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\WhatsApp\Models\WhatsAppApiKey;

class WhatsAppService
{
    /**
     * Send a WhatsApp message using the provided API key.
     */
    public function sendMessage(WhatsAppApiKey $apiKey, string $phoneNumber, string $message, ?array $orderData = null): array
    {
        // Validate API key
        if (! $apiKey->isValid()) {
            return [
                'success' => false,
                'error' => 'API key is inactive or expired',
            ];
        }

        // Record usage
        $apiKey->recordUsage();

        // Format phone number (remove + and spaces)
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

        try {
            // Send message based on provider
            $response = match ($apiKey->whatsapp_provider) {
                'twilio' => $this->sendViaTwilio($apiKey, $phoneNumber, $message),
                'vonage' => $this->sendViaVonage($apiKey, $phoneNumber, $message),
                'custom' => $this->sendViaCustom($apiKey, $phoneNumber, $message, $orderData),
                default => $this->sendViaCustom($apiKey, $phoneNumber, $message, $orderData),
            };

            return $response;
        } catch (\Exception $e) {
            Log::error('WhatsApp send error', [
                'api_key_id' => $apiKey->id,
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send message via Twilio.
     */
    protected function sendViaTwilio(WhatsAppApiKey $apiKey, string $phoneNumber, string $message): array
    {
        $endpoint = $apiKey->api_endpoint ?: 'https://api.twilio.com/2010-04-01';

        // Twilio expects account_sid:auth_token in the key
        [$accountSid, $authToken] = explode(':', $apiKey->key.':');

        $response = Http::withBasicAuth($accountSid, $authToken)
            ->asForm()
            ->post("{$endpoint}/Accounts/{$accountSid}/Messages.json", [
                'From' => 'whatsapp:+14155238886', // Twilio sandbox number
                'To' => "whatsapp:+{$phoneNumber}",
                'Body' => $message,
            ]);

        if ($response->successful()) {
            return [
                'success' => true,
                'message_id' => $response->json('sid'),
                'data' => $response->json(),
            ];
        }

        return [
            'success' => false,
            'error' => $response->json('message') ?? 'Failed to send message',
            'status' => $response->status(),
        ];
    }

    /**
     * Send message via Vonage (Nexmo).
     */
    protected function sendViaVonage(WhatsAppApiKey $apiKey, string $phoneNumber, string $message): array
    {
        $endpoint = $apiKey->api_endpoint ?: 'https://messages-sandbox.nexmo.com/v1/messages';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey->key,
            'Content-Type' => 'application/json',
        ])->post($endpoint, [
            'from' => 'VONAGE_SANDBOX', // Replace with actual WhatsApp number
            'to' => $phoneNumber,
            'message_type' => 'text',
            'text' => $message,
            'channel' => 'whatsapp',
        ]);

        if ($response->successful()) {
            return [
                'success' => true,
                'message_id' => $response->json('message_uuid'),
                'data' => $response->json(),
            ];
        }

        return [
            'success' => false,
            'error' => $response->json('title') ?? 'Failed to send message',
            'status' => $response->status(),
        ];
    }

    /**
     * Send message via custom WhatsApp API.
     */
    protected function sendViaCustom(WhatsAppApiKey $apiKey, string $phoneNumber, string $message, ?array $orderData = null): array
    {
        if (! $apiKey->api_endpoint) {
            return [
                'success' => false,
                'error' => 'API endpoint not configured',
            ];
        }

        // If order data is provided, send as webhook format
        if ($orderData) {
            $webhookUrl = rtrim($apiKey->api_endpoint, '/').'/webhooks/order-created';
            $payload = $orderData;

            Log::info('Sending order webhook to custom endpoint', [
                'endpoint' => $webhookUrl,
                'order_id' => $orderData['order_id'] ?? 'N/A',
                'phone_number' => $phoneNumber,
            ]);
        } else {
            // Simple message format
            $webhookUrl = $apiKey->api_endpoint;
            $payload = [
                'phone_number' => $phoneNumber,
                'message' => $message,
            ];

            Log::info('Sending simple message to custom endpoint', [
                'endpoint' => $webhookUrl,
                'phone_number' => $phoneNumber,
            ]);
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'x-api-key' => $apiKey->key,
                    'Content-Type' => 'application/json',
                ])->post($webhookUrl, $payload);

            Log::info('Custom endpoint response', [
                'status' => $response->status(),
                'body' => $response->body(),
                'json' => $response->json(),
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message_id' => $response->json('message_id') ?? $response->json('id'),
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('error') ?? $response->json('message') ?? 'Failed to send message',
                'status' => $response->status(),
                'response_body' => $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to connect to custom endpoint', [
                'endpoint' => $apiKey->api_endpoint,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Format an order confirmation message.
     */
    public function formatOrderConfirmation(array $orderData): string
    {
        $items = collect($orderData['items'] ?? [])->map(function ($item) {
            $name = $item['product_name'] ?? $item['name'] ?? 'Product';

            return "• {$name} (×{$item['quantity']}) - {$item['price']}";
        })->join("\n");

        $trackingLine = isset($orderData['order_url']) ? "\n🔗 Track your order: {$orderData['order_url']}\n" : '';

        return <<<MSG
🛒 Order Confirmation

Thank you, {$orderData['customer_name']}! ✨

📦 Order #{$orderData['order_id']}
💰 Total: {$orderData['currency']} {$orderData['total_amount']}

Items:
{$items}
{$trackingLine}
We'll notify you when your order ships! 🚚
MSG;
    }

    /**
     * Format a payment confirmation message.
     */
    public function formatPaymentConfirmation(array $paymentData): string
    {
        return <<<MSG
✅ Payment Confirmed

Hi {$paymentData['customer_name']},

We've successfully received your payment! 💳

🆔 Order #{$paymentData['order_id']}
💵 Amount: {$paymentData['currency']} {$paymentData['amount']}
🏦 Method: {$paymentData['payment_method']}

Your order is now being processed. 📦
MSG;
    }

    /**
     * Format a shipping notification message.
     */
    public function formatShippingNotification(array $shippingData): string
    {
        return <<<MSG
📮 Your Order Has Shipped!

Good news, {$shippingData['customer_name']}!

🆔 Order #{$shippingData['order_id']} is on its way! 🚚

📦 Tracking: {$shippingData['tracking_number']}
🚛 Carrier: {$shippingData['carrier']}
📅 Estimated Delivery: {$shippingData['estimated_delivery']}

Track your package for real-time updates! 📍
MSG;
    }

    /**
     * Format a delivery confirmation message.
     */
    public function formatDeliveryConfirmation(array $deliveryData): string
    {
        return <<<MSG
🎉 Order Delivered!

Great news, {$deliveryData['customer_name']}!

✅ Order #{$deliveryData['order_id']} has been delivered!
📅 Delivered on: {$deliveryData['delivered_at']}

Hope you love your purchase! ❤️

💬 We'd love to hear your feedback!
MSG;
    }

    /**
     * Format an order cancellation message.
     */
    public function formatCancellation(array $cancellationData): string
    {
        return <<<MSG
❌ Order Cancelled

Hi {$cancellationData['customer_name']},

Your order #{$cancellationData['order_id']} has been cancelled.

📝 Reason: {$cancellationData['cancellation_reason']}
💰 Refund: {$cancellationData['refund_amount']}
🏦 Method: {$cancellationData['refund_method']}

Refunds typically process within 5-7 business days.

We hope to serve you again soon! 🙏
MSG;
    }
}
