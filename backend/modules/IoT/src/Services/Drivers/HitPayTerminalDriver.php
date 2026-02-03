<?php

namespace Modules\IoT\Services\Drivers;

use Modules\IoT\Models\IoTDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HitPay Terminal Driver
 * 
 * Implements payment terminal integration with HitPay API.
 * Supports card payments via WiFi terminals and QR payments (PayNow, GrabPay, etc.)
 */
class HitPayTerminalDriver implements TerminalDriverInterface
{
    protected IoTDevice $device;
    protected string $apiKey;
    protected string $apiUrl;
    protected string $salt;

    /**
     * Create a new HitPay terminal driver instance.
     *
     * @param IoTDevice $device IoT device with HitPay configuration
     * @throws \Exception If required configuration is missing
     */
    public function __construct(IoTDevice $device)
    {
        $this->device = $device;
        
        // Extract configuration from encrypted device config
        $this->apiKey = $device->config['api_key'] ?? throw new \Exception('HitPay API key not configured');
        $this->salt = $device->config['salt'] ?? throw new \Exception('HitPay salt not configured');
        $this->apiUrl = $device->config['api_url'] ?? 'https://api.hit-pay.com/v1';
    }

    /**
     * Create a payment request via HitPay API.
     *
     * @param float $amount Payment amount
     * @param string $currency Currency code (SGD, USD, EUR, etc.)
     * @param array $options Additional options (order_reference, payment_method, redirect_url)
     * @return array Payment request data (id, status, url)
     * @throws \Exception If API request fails
     */
    public function createPaymentRequest(float $amount, string $currency, array $options = []): array
    {
        $payload = [
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => strtoupper($currency),
            'payment_methods' => [$options['payment_method'] ?? 'wifi_card_reader'],
            'reference_number' => $options['order_reference'] ?? null,
            'redirect_url' => $options['redirect_url'] ?? null,
            'webhook' => route('iot.terminals.webhook'),
        ];

        try {
            $response = Http::withHeaders([
                'X-BUSINESS-API-KEY' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post("{$this->apiUrl}/payment-requests", $payload);

            if (!$response->successful()) {
                $this->device->logAction('create_payment', 'failed', $payload, $response->json(), $response->body());
                throw new \Exception("HitPay API error: " . $response->body());
            }

            $data = $response->json();
            $this->device->logAction('create_payment', 'success', $payload, $data);
            $this->device->markOnline();

            return $data;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->device->logAction('create_payment', 'failed', $payload, null, 'Connection timeout: ' . $e->getMessage());
            $this->device->markOffline();
            throw new \Exception('Connection to HitPay failed: ' . $e->getMessage());
        }
    }

    /**
     * Check payment status via HitPay API.
     *
     * @param string $paymentRequestId HitPay payment request ID
     * @return array Payment status data
     * @throws \Exception If API request fails
     */
    public function checkPaymentStatus(string $paymentRequestId): array
    {
        try {
            $response = Http::withHeaders([
                'X-BUSINESS-API-KEY' => $this->apiKey,
            ])->timeout(10)->get("{$this->apiUrl}/payment-requests/{$paymentRequestId}");

            if (!$response->successful()) {
                throw new \Exception("Failed to check payment status: " . $response->body());
            }

            return $response->json();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \Exception('Connection to HitPay failed: ' . $e->getMessage());
        }
    }

    /**
     * Cancel a pending payment via HitPay API.
     *
     * @param string $paymentRequestId HitPay payment request ID
     * @return bool True if cancellation successful
     * @throws \Exception If API request fails
     */
    public function cancelPayment(string $paymentRequestId): bool
    {
        try {
            $response = Http::withHeaders([
                'X-BUSINESS-API-KEY' => $this->apiKey,
            ])->timeout(10)->delete("{$this->apiUrl}/payment-requests/{$paymentRequestId}");

            $success = $response->successful();
            
            $this->device->logAction(
                'cancel_payment',
                $success ? 'success' : 'failed',
                ['payment_request_id' => $paymentRequestId],
                $response->json(),
                $success ? null : $response->body()
            );

            return $success;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->device->logAction(
                'cancel_payment',
                'failed',
                ['payment_request_id' => $paymentRequestId],
                null,
                'Connection timeout: ' . $e->getMessage()
            );
            throw new \Exception('Connection to HitPay failed: ' . $e->getMessage());
        }
    }

    /**
     * Test connection to HitPay API.
     *
     * @return bool True if connection successful and credentials valid
     */
    public function testConnection(): bool
    {
        try {
            $response = Http::withHeaders([
                'X-BUSINESS-API-KEY' => $this->apiKey,
            ])->timeout(10)->get("{$this->apiUrl}/payment-methods");

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("HitPay connection test failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify webhook signature using HMAC-SHA256.
     *
     * @param array $payload Webhook payload data
     * @param string $signature HMAC signature from X-SIGNATURE header
     * @return bool True if signature is valid
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        // Sort payload keys alphabetically
        ksort($payload);
        
        // Concatenate key-value pairs
        $data = collect($payload)->map(function ($value, $key) {
            return "{$key}{$value}";
        })->implode('');
        
        // Append salt and compute HMAC
        $expectedSignature = hash_hmac('sha256', $data . $this->salt, $this->salt);
        
        // Use timing-safe comparison to prevent timing attacks
        return hash_equals($expectedSignature, $signature);
    }
}
