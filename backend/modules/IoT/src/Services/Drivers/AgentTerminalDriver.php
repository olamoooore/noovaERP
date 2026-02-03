<?php

namespace Modules\IoT\Services\Drivers;

use Modules\IoT\Models\IoTDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Agent Terminal Driver
 * 
 * Communicates with USB payment terminals via Local Agent.
 * The Local Agent runs on the Windows POS machine and bridges
 * USB devices to the cloud backend via WebSocket.
 */
class AgentTerminalDriver implements TerminalDriverInterface
{
    protected IoTDevice $device;
    protected string $agentUrl;
    protected string $agentToken;

    public function __construct(IoTDevice $device)
    {
        $this->device = $device;
        
        // Agent URL from device config
        $this->agentUrl = $device->config['agent_url'] ?? 'http://localhost:3001';
        $this->agentToken = $device->config['agent_token'] ?? '';
        
        if (empty($this->agentToken)) {
            throw new \Exception('Agent token not configured for device: ' . $device->name);
        }
    }

    /**
     * Create a payment request via Local Agent.
     *
     * @param float $amount
     * @param string $currency
     * @param array $options
     * @return array
     */
    public function createPaymentRequest(float $amount, string $currency, array $options = []): array
    {
        try {
            $response = Http::timeout(30)
                ->withoutVerifying() // Disable SSL verification for development
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->agentToken,
                    'Content-Type' => 'application/json',
                ])
                ->post($this->agentUrl . '/api/terminal/payment', [
                    'device_id' => $this->device->config['agent_device_id'] ?? 'terminal-1',
                    'amount' => $amount,
                    'currency' => $currency,
                    'reference' => $options['order_reference'] ?? null,
                    'metadata' => $options['metadata'] ?? [],
                ]);

            if (!$response->successful()) {
                throw new \Exception('Agent returned error: ' . $response->body());
            }

            $data = $response->json();

            // Log the request
            $this->device->logAction('create_payment', 'success', [
                'amount' => $amount,
                'currency' => $currency,
                'transaction_id' => $data['transaction_id'] ?? null,
            ]);

            return [
                'id' => $data['transaction_id'],
                'status' => $data['status'] ?? 'pending',
                'amount' => $amount,
                'currency' => $currency,
                'created_at' => now()->toIso8601String(),
            ];

        } catch (\Exception $e) {
            $this->device->logAction('create_payment', 'failed', [
                'amount' => $amount,
                'currency' => $currency,
            ], null, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Check payment status via Local Agent.
     *
     * @param string $paymentRequestId
     * @return array
     */
    public function checkPaymentStatus(string $paymentRequestId): array
    {
        try {
            $response = Http::timeout(10)
                ->withoutVerifying() // Disable SSL verification for development
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->agentToken,
                ])
                ->get($this->agentUrl . '/api/terminal/payment/' . $paymentRequestId);

            if (!$response->successful()) {
                throw new \Exception('Agent returned error: ' . $response->body());
            }

            $data = $response->json();

            return [
                'id' => $data['transaction_id'],
                'status' => $data['status'],
                'amount' => $data['amount'] ?? null,
                'currency' => $data['currency'] ?? null,
                'card_type' => $data['card_type'] ?? null,
                'last4' => $data['last4'] ?? null,
                'auth_code' => $data['auth_code'] ?? null,
            ];

        } catch (\Exception $e) {
            $this->device->logAction('check_status', 'failed', [
                'payment_request_id' => $paymentRequestId,
            ], null, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Cancel a pending payment via Local Agent.
     *
     * @param string $paymentRequestId
     * @return bool
     */
    public function cancelPayment(string $paymentRequestId): bool
    {
        try {
            $response = Http::timeout(10)
                ->withoutVerifying() // Disable SSL verification for development
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->agentToken,
                ])
                ->post($this->agentUrl . '/api/terminal/payment/' . $paymentRequestId . '/cancel');

            if (!$response->successful()) {
                throw new \Exception('Agent returned error: ' . $response->body());
            }

            $data = $response->json();

            $this->device->logAction('cancel_payment', 'success', [
                'payment_request_id' => $paymentRequestId,
            ]);

            return $data['success'] ?? false;

        } catch (\Exception $e) {
            $this->device->logAction('cancel_payment', 'failed', [
                'payment_request_id' => $paymentRequestId,
            ], null, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Verify webhook signature from Local Agent.
     * 
     * Note: Agent webhooks use the same signature mechanism as cloud terminals.
     *
     * @param array $payload
     * @param string $signature
     * @return bool
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        $salt = $this->device->config['agent_webhook_secret'] ?? '';
        
        if (empty($salt)) {
            Log::warning('Agent webhook secret not configured', [
                'device_id' => $this->device->id,
            ]);
            return false;
        }

        // Sort payload keys alphabetically
        ksort($payload);

        // Concatenate key-value pairs
        $data = collect($payload)->map(function ($value, $key) {
            return "{$key}{$value}";
        })->implode('');

        // Append salt and hash
        $expectedSignature = hash_hmac('sha256', $data . $salt, $salt);

        // Timing-safe comparison
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Test connection to Local Agent and device.
     *
     * @return bool True if agent is reachable and device is connected
     */
    public function testConnection(): bool
    {
        try {
            $response = Http::timeout(5)
                ->withoutVerifying() // Disable SSL verification for development
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->agentToken,
                ])
                ->get($this->agentUrl . '/api/health');

            if (!$response->successful()) {
                Log::error('Agent connection test failed', [
                    'device_id' => $this->device->id,
                    'agent_url' => $this->agentUrl,
                    'status' => $response->status(),
                ]);
                return false;
            }

            $data = $response->json();
            
            // Check if our specific device is connected to the agent
            $deviceId = $this->device->config['agent_device_id'] ?? null;
            if ($deviceId) {
                $devices = $data['devices'] ?? [];
                $deviceFound = collect($devices)->contains(function ($device) use ($deviceId) {
                    return ($device['device_id'] ?? null) === $deviceId;
                });
                
                if (!$deviceFound) {
                    Log::warning('Device not found in agent', [
                        'device_id' => $this->device->id,
                        'agent_device_id' => $deviceId,
                        'available_devices' => $devices,
                    ]);
                    return false;
                }
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Agent connection test exception', [
                'device_id' => $this->device->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get agent health status.
     *
     * @return array
     */
    public function getAgentHealth(): array
    {
        try {
            $response = Http::timeout(5)
                ->withoutVerifying() // Disable SSL verification for development
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->agentToken,
                ])
                ->get($this->agentUrl . '/api/health');

            if (!$response->successful()) {
                return [
                    'status' => 'offline',
                    'error' => 'Agent unreachable',
                ];
            }

            $data = $response->json();

            return [
                'status' => 'online',
                'agent_id' => $data['agent_id'] ?? null,
                'version' => $data['version'] ?? null,
                'uptime' => $data['uptime'] ?? null,
                'devices' => $data['devices'] ?? [],
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'offline',
                'error' => $e->getMessage(),
            ];
        }
    }
}
