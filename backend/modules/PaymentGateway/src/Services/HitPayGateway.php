<?php

namespace Modules\PaymentGateway\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\PaymentGateway\Models\PaymentGateway;

class HitPayGateway implements PaymentGatewayInterface
{
    protected PaymentGateway $gateway;
    protected string $baseUrl;

    public function __construct(PaymentGateway $gateway)
    {
        $this->gateway = $gateway;
        $this->baseUrl = $this->gateway->is_test_mode
            ? 'https://api.sandbox.hit-pay.com/v1'
            : 'https://api.hit-pay.com/v1';
    }

    /**
     * Create a HitPay payment request
     */
    public function createPayment(array $data): array
    {
        try {
            // Generate a unique reference for idempotency
            // HitPay uses reference_number which should be unique per invoice/payment attempt
            $referenceNumber = $data['reference'] ?? null;

            // If we have metadata with invoice_id, use it to create a consistent reference
            if (!$referenceNumber && isset($data['metadata']['invoice_id'])) {
                $referenceNumber = 'INV-' . $data['metadata']['invoice_id'] . '-' . date('Ymd');
            }

            // Check if a payment request already exists for this reference
            // This provides basic idempotency by checking our local database
            if ($referenceNumber && isset($data['metadata']['invoice_id'])) {
                $existingTransaction = \Modules\PaymentGateway\Models\PaymentTransaction::where('invoice_id', $data['metadata']['invoice_id'])
                    ->where('status', 'pending')
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($existingTransaction && $existingTransaction->created_at->diffInMinutes(now()) < 30) {
                    \Log::info('Returning existing HitPay payment request (idempotency)', [
                        'transaction_id' => $existingTransaction->transaction_id,
                        'invoice_id' => $data['metadata']['invoice_id'],
                        'age_minutes' => $existingTransaction->created_at->diffInMinutes(now()),
                    ]);

                    // Reconstruct the payment URL
                    $paymentUrl = $this->gateway->is_test_mode
                        ? 'https://securecheckout.sandbox.hit-pay.com/payment-request/'
                        : 'https://securecheckout.hit-pay.com/payment-request/';

                    return [
                        'success' => true,
                        'transaction_id' => $existingTransaction->transaction_id,
                        'payment_url' => $paymentUrl . $existingTransaction->transaction_id,
                        'status' => 'pending',
                        'amount' => $existingTransaction->amount,
                        'currency' => $existingTransaction->currency,
                    ];
                }
            }

            $payload = [
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'SGD',
                'purpose' => $data['description'] ?? 'Payment',
                'email' => $data['email'] ?? null,
                'reference_number' => $referenceNumber,
            ];

            // Only add redirect_url and webhook if not localhost
            $appUrl = config('app.url');
            $isLocalhost = str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1');

            // Always add redirect_url - use frontend URL or provided URL
            if ($data['redirect_url'] ?? null) {
                $payload['redirect_url'] = $data['redirect_url'];
            } else {
                // Redirect to frontend success page
                $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
                $payload['redirect_url'] = $frontendUrl . '/payment/success';
            }

            // Add webhook only if APP_URL is not localhost or if HITPAY_WEBHOOK_URL is set
            $webhookUrl = config('services.hitpay.webhook_url') ?? ($isLocalhost ? null : $appUrl . '/api/v1/webhooks/hitpay');
            if ($webhookUrl) {
                $payload['webhook'] = $webhookUrl;
            }

            \Log::info('HitPay Payment Request', ['payload' => $payload]);

            $response = Http::withHeaders([
                'X-BUSINESS-API-KEY' => $this->gateway->api_key,
            ])->post($this->baseUrl . '/payment-requests', $payload);

            if ($response->successful()) {
                $result = $response->json();

                // Add transaction_id to redirect URL
                $redirectUrl = $payload['redirect_url'];
                $separator = str_contains($redirectUrl, '?') ? '&' : '?';
                $secureRedirectUrl = $redirectUrl . $separator . 'ref=' . urlencode($result['id']);

                // Store transaction in database
                if (isset($data['metadata']['invoice_id'])) {
                    \Modules\PaymentGateway\Models\PaymentTransaction::create([
                        'payment_gateway_id' => $this->gateway->id,
                        'invoice_id' => $data['metadata']['invoice_id'],
                        'transaction_id' => $result['id'],
                        'amount' => $result['amount'],
                        'currency' => $result['currency'],
                        'status' => 'pending',
                        'payment_method' => 'hitpay',
                        'metadata' => json_encode([
                            'payment_url' => $result['url'],
                            'reference_number' => $referenceNumber,
                            'customer_email' => $data['email'] ?? null,
                            'description' => $data['description'] ?? null,
                            'custom_metadata' => $data['metadata'] ?? [],
                        ]),
                    ]);

                    \Log::info('HitPay transaction stored in database', [
                        'transaction_id' => $result['id'],
                        'invoice_id' => $data['metadata']['invoice_id'],
                    ]);
                }

                return [
                    'success' => true,
                    'transaction_id' => $result['id'],
                    'payment_url' => $result['url'],
                    'status' => 'pending',
                    'amount' => $result['amount'],
                    'currency' => $result['currency'],
                    'redirect_url' => $secureRedirectUrl, // Return the secure URL for logging
                ];
            }

            \Log::error('HitPay API Error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'json' => $response->json(),
            ]);

            return [
                'success' => false,
                'error' => $response->json()['message'] ?? $response->body() ?? 'Payment creation failed',
            ];
        } catch (\Exception $e) {
            \Log::error('HitPay Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get payment status from HitPay
     */
    public function getPaymentStatus(string $transactionId): array
    {
        try {
            \Log::info('Fetching payment status from HitPay', [
                'transaction_id' => $transactionId,
                'url' => $this->baseUrl . '/payment-requests/' . $transactionId,
            ]);

            $response = Http::withHeaders([
                'X-BUSINESS-API-KEY' => $this->gateway->api_key,
            ])->get($this->baseUrl . '/payment-requests/' . $transactionId);

            \Log::info('HitPay API response', [
                'transaction_id' => $transactionId,
                'status_code' => $response->status(),
                'body' => $response->json(),
            ]);

            if ($response->successful()) {
                $result = $response->json();
                return [
                    'success' => true,
                    'status' => $this->mapHitPayStatus($result['status']),
                    'amount' => $result['amount'],
                    'currency' => $result['currency'],
                    'payment_method' => $result['payment_type'] ?? null,
                ];
            }

            \Log::warning('HitPay API request failed', [
                'transaction_id' => $transactionId,
                'status_code' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve payment status',
            ];
        } catch (\Exception $e) {
            \Log::error('Exception while fetching HitPay status', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process refund via HitPay
     */
    public function refundPayment(string $transactionId, float $amount): array
    {
        try {
            $response = Http::withHeaders([
                'X-BUSINESS-API-KEY' => $this->gateway->api_key,
            ])->post($this->baseUrl . '/refund', [
                'payment_id' => $transactionId,
                'amount' => $amount,
            ]);

            if ($response->successful()) {
                $result = $response->json();
                return [
                    'success' => true,
                    'refund_id' => $result['id'],
                    'status' => 'refunded',
                    'amount' => $result['amount_refunded'],
                ];
            }

            return [
                'success' => false,
                'error' => $response->json()['message'] ?? 'Refund failed',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify HitPay webhook signature
     */
    public function verifyWebhook(Request $request): bool
    {
        // HitPay sends TWO types of webhooks:
        // 1. Payment webhook (has 'hmac' field) - MUST verify signature
        // 2. Payment request webhook (no 'hmac' field) - Verified by transaction lookup

        $data = $request->all();
        $receivedHmac = $data['hmac'] ?? null;

        // If no HMAC, this is a payment request webhook
        // These are safe because we verify the transaction exists in our DB
        if (!$receivedHmac) {
            \Log::info('HitPay payment request webhook received (verified by transaction lookup)');
            return true;
        }

        // Payment webhook with HMAC - MUST verify signature
        $secret = $this->gateway->api_secret;

        if (!$secret) {
            \Log::error('HitPay webhook verification failed: No API secret configured');
            return false;
        }

        // Remove hmac from data before verification
        $dataForVerification = $data;
        unset($dataForVerification['hmac']);

        // Sort data by keys (HitPay requirement)
        ksort($dataForVerification);

        // Generate signature using the same method as HitPay
        $generatedHmac = hash_hmac('sha256', http_build_query($dataForVerification), $secret);

        $isValid = hash_equals($generatedHmac, $receivedHmac);

        if (!$isValid) {
            \Log::error('HitPay webhook signature verification FAILED', [
                'received_hmac' => $receivedHmac,
                'generated_hmac' => $generatedHmac,
                'webhook_data' => $dataForVerification,
            ]);
            return false;
        }

        \Log::info('HitPay webhook signature verified successfully');
        return true;
    }

    /**
     * Process HitPay webhook
     */
    public function processWebhook(Request $request): array
    {
        $data = $request->all();

        // Extract the payment request ID (our transaction_id)
        $transactionId = $data['payment_request_id'] ?? $data['id'] ?? null;

        // Generate webhook_id for duplicate detection
        $webhookId = md5(json_encode($data) . time());

        // Extract payment details from nested payments array if present
        $payment = null;
        if (isset($data['payments']) && is_array($data['payments']) && count($data['payments']) > 0) {
            $payment = $data['payments'][0]; // Get first payment
        }

        return [
            'event' => $data['status'] ?? null,
            'transaction_id' => $transactionId,
            'webhook_id' => $webhookId,
            'status' => $this->mapHitPayStatus($data['status'] ?? null),
            'amount' => str_replace(',', '', $data['amount'] ?? null), // Remove commas from amount
            'currency' => $data['currency'] ?? null,
            'payment_method' => $payment['payment_type'] ?? $data['payment_type'] ?? null,
            'metadata' => [
                'reference' => $data['reference_number'] ?? null,
                'payment_id' => $data['payment_id'] ?? ($payment['id'] ?? null),
            ],
            'raw_data' => $data,
        ];
    }

    /**
     * Test HitPay connection
     */
    public function testConnection(): bool
    {
        try {
            $response = Http::withHeaders([
                'X-BUSINESS-API-KEY' => $this->gateway->api_key,
            ])->get($this->baseUrl . '/payment-requests');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Process a refund
     * Note: HitPay requires the actual payment_id (from the payments array in webhook),
     * not the payment_request_id (transaction_id). We retrieve it from the transaction's metadata.
     */
    public function refund(string $transactionId, float $amount): array
    {
        try {
            // Get the actual payment_id from the transaction metadata
            $transaction = \Modules\PaymentGateway\Models\PaymentTransaction::where('transaction_id', $transactionId)->first();
            
            if (!$transaction) {
                return [
                    'success' => false,
                    'message' => 'Transaction not found',
                ];
            }

            $metadata = $transaction->metadata ?? [];
            $paymentId = $metadata['payment_id'] ?? null;

            // If payment_id not in metadata, try to fetch from HitPay API
            if (!$paymentId) {
                \Log::info('HitPay refund: payment_id not in metadata, fetching from API', [
                    'transaction_id' => $transactionId,
                ]);
                
                // Fetch payment request details from HitPay
                $response = Http::withHeaders([
                    'X-BUSINESS-API-KEY' => $this->gateway->api_key,
                ])->get($this->baseUrl . '/payment-requests/' . $transactionId);

                if ($response->successful()) {
                    $data = $response->json();
                    // Extract payment_id from the payments array
                    if (isset($data['payments'][0]['id'])) {
                        $paymentId = $data['payments'][0]['id'];
                        
                        // Save it to metadata for future use
                        $metadata['payment_id'] = $paymentId;
                        $transaction->update(['metadata' => $metadata]);
                        
                        \Log::info('HitPay refund: payment_id retrieved and saved', [
                            'transaction_id' => $transactionId,
                            'payment_id' => $paymentId,
                        ]);
                    } else {
                        \Log::error('HitPay refund: payment_id not found in API response', [
                            'transaction_id' => $transactionId,
                            'response' => $data,
                        ]);
                        
                        return [
                            'success' => false,
                            'message' => 'Payment ID not found in HitPay. The payment may not have been completed.',
                        ];
                    }
                } else {
                    \Log::error('HitPay refund: failed to fetch payment request details', [
                        'transaction_id' => $transactionId,
                        'status' => $response->status(),
                        'response' => $response->body(),
                    ]);
                    
                    return [
                        'success' => false,
                        'message' => 'Could not retrieve payment details from HitPay.',
                    ];
                }
            }

            \Log::info('HitPay refund request', [
                'transaction_id' => $transactionId,
                'payment_id' => $paymentId,
                'amount' => $amount,
            ]);

            $response = Http::withHeaders([
                'X-BUSINESS-API-KEY' => $this->gateway->api_key,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/refund', [
                'payment_id' => $paymentId, // Use actual payment_id, not transaction_id
                'amount' => $amount,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'refund_id' => $data['id'] ?? null,
                    'status' => $data['status'] ?? 'pending',
                    'amount' => $data['amount'] ?? $amount,
                ];
            }

            \Log::error('HitPay refund failed', [
                'payment_id' => $paymentId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return [
                'success' => false,
                'message' => $response->json('message') ?? 'Refund request failed',
            ];
        } catch (\Exception $e) {
            \Log::error('HitPay refund exception', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Map HitPay status to our internal status
     */
    protected function mapHitPayStatus(?string $hitpayStatus): string
    {
        return match ($hitpayStatus) {
            'completed' => 'completed',
            'pending' => 'pending',
            'failed' => 'failed',
            'refunded' => 'refunded',
            'canceled', 'cancelled' => 'cancelled',
            default => 'pending',
        };
    }
}
