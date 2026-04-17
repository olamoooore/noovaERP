<?php

namespace Modules\PaymentGateway\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\PaymentGateway\Models\PaymentGateway;

class PayPalGateway implements PaymentGatewayInterface
{
    protected PaymentGateway $gateway;
    protected string $baseUrl;
    protected ?string $accessToken = null;

    public function __construct(PaymentGateway $gateway)
    {
        $this->gateway = $gateway;
        $this->baseUrl = $this->gateway->is_test_mode
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    /**
     * Get OAuth access token
     */
    protected function getAccessToken(): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        try {
            $response = Http::withBasicAuth(
                $this->gateway->api_key, // Client ID
                $this->gateway->api_secret // Client Secret
            )->asForm()->post($this->baseUrl . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

            if ($response->successful()) {
                $this->accessToken = $response->json()['access_token'];
                return $this->accessToken;
            }

            \Log::error('PayPal OAuth failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return null;
        } catch (\Exception $e) {
            \Log::error('PayPal OAuth exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Create a PayPal order
     */
    public function createPayment(array $data): array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                return [
                    'success' => false,
                    'error' => 'Failed to authenticate with PayPal',
                ];
            }

            // Check for existing pending transaction (idempotency)
            if (isset($data['metadata']['invoice_id'])) {
                $existingTransaction = \Modules\PaymentGateway\Models\PaymentTransaction::where('invoice_id', $data['metadata']['invoice_id'])
                    ->where('status', 'pending')
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($existingTransaction && $existingTransaction->created_at->diffInMinutes(now()) < 30) {
                    \Log::info('Returning existing PayPal order (idempotency)', [
                        'transaction_id' => $existingTransaction->transaction_id,
                        'invoice_id' => $data['metadata']['invoice_id'],
                    ]);

                    $approveLink = $existingTransaction->metadata['approve_link'] ?? null;

                    // If approve_link not in metadata, fetch from PayPal API
                    if (!$approveLink) {
                        \Log::info('Approve link not in metadata, fetching from PayPal API');

                        $token = $this->getAccessToken();
                        if ($token) {
                            $response = Http::withToken($token)
                                ->get($this->baseUrl . '/v2/checkout/orders/' . $existingTransaction->transaction_id);

                            if ($response->successful()) {
                                $orderData = $response->json();
                                foreach ($orderData['links'] ?? [] as $link) {
                                    if (isset($link['rel']) && $link['rel'] === 'approve') {
                                        $approveLink = $link['href'];

                                        // Save it to metadata for future use
                                        $metadata = $existingTransaction->metadata ?? [];
                                        $metadata['approve_link'] = $approveLink;
                                        $existingTransaction->update(['metadata' => $metadata]);

                                        \Log::info('Approve link retrieved and saved', [
                                            'transaction_id' => $existingTransaction->transaction_id,
                                            'approve_link' => $approveLink,
                                        ]);
                                        break;
                                    }
                                }
                            }
                        }
                    }

                    return [
                        'success' => true,
                        'transaction_id' => $existingTransaction->transaction_id,
                        'payment_url' => $approveLink,
                        'status' => 'pending',
                        'amount' => $existingTransaction->amount,
                        'currency' => $existingTransaction->currency,
                    ];
                }
            }

            // Build return URL
            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
            $returnUrl = $data['redirect_url'] ?? ($frontendUrl . '/payment/success');
            $cancelUrl = $data['cancel_url'] ?? ($frontendUrl . '/checkout');

            $payload = [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'amount' => [
                            'currency_code' => $data['currency'] ?? 'USD',
                            'value' => number_format($data['amount'], 2, '.', ''),
                        ],
                        'description' => $data['description'] ?? 'Payment',
                        'invoice_id' => $data['metadata']['invoice_id'] ?? null,
                    ],
                ],
                'application_context' => [
                    'brand_name' => config('app.name', 'NoovaERP'),
                    'landing_page' => 'BILLING',
                    'user_action' => 'PAY_NOW',
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                ],
            ];

            \Log::info('PayPal Order Request', ['payload' => $payload]);

            $response = Http::withToken($token)
                ->post($this->baseUrl . '/v2/checkout/orders', $payload);

            if ($response->successful()) {
                $result = $response->json();

                \Log::info('PayPal Order Created Successfully', [
                    'order_id' => $result['id'] ?? 'N/A',
                    'status' => $result['status'] ?? 'N/A',
                    'links' => $result['links'] ?? [],
                ]);

                // Extract approval URL
                $approveLink = null;
                if (isset($result['links']) && is_array($result['links'])) {
                    foreach ($result['links'] as $link) {
                        if (isset($link['rel']) && $link['rel'] === 'approve') {
                            $approveLink = $link['href'] ?? null;
                            break;
                        }
                    }
                }

                if (!$approveLink) {
                    \Log::error('PayPal approve link not found in response', [
                        'order_id' => $result['id'] ?? 'N/A',
                        'links' => $result['links'] ?? [],
                    ]);

                    return [
                        'success' => false,
                        'error' => 'PayPal approval link not found in response',
                    ];
                }

                // Add transaction_id to return URL
                $secureReturnUrl = $returnUrl;
                $separator = str_contains($secureReturnUrl, '?') ? '&' : '?';
                $secureReturnUrl .= $separator . 'ref=' . urlencode($result['id']);

                \Log::info('PayPal order ready for approval', [
                    'order_id' => $result['id'],
                    'approve_link' => $approveLink,
                ]);

                return [
                    'success' => true,
                    'transaction_id' => $result['id'],
                    'payment_url' => $approveLink,
                    'status' => 'pending',
                    'amount' => $data['amount'],
                    'currency' => $data['currency'] ?? 'USD',
                    'metadata' => [
                        'approve_link' => $approveLink,
                    ],
                ];
            }

            \Log::error('PayPal API Error', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [
                'success' => false,
                'error' => $response->json()['message'] ?? 'Payment creation failed',
            ];
        } catch (\Exception $e) {
            \Log::error('PayPal Exception', [
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
     * Get PayPal order status
     */
    public function getPaymentStatus(string $transactionId): array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                return [
                    'success' => false,
                    'error' => 'Authentication failed',
                ];
            }

            $response = Http::withToken($token)
                ->get($this->baseUrl . '/v2/checkout/orders/' . $transactionId);

            if ($response->successful()) {
                $result = $response->json();

                $status = $this->mapPayPalStatus($result['status']);

                // Get amount from purchase units
                $amount = $result['purchase_units'][0]['amount']['value'] ?? null;
                $currency = $result['purchase_units'][0]['amount']['currency_code'] ?? null;

                return [
                    'success' => true,
                    'status' => $status,
                    'amount' => $amount,
                    'currency' => $currency,
                    'payment_method' => 'paypal',
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to retrieve payment status',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Capture a PayPal order (complete the payment)
     */
    public function capturePayment(string $orderId): array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                return [
                    'success' => false,
                    'error' => 'Authentication failed',
                ];
            }

            \Log::info('Attempting to capture PayPal order', [
                'order_id' => $orderId,
            ]);

            $response = Http::withToken($token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->withBody('{}', 'application/json')
                ->post($this->baseUrl . '/v2/checkout/orders/' . $orderId . '/capture');

            \Log::info('PayPal capture response', [
                'order_id' => $orderId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            if ($response->successful()) {
                $result = $response->json();

                // Extract capture ID and other metadata
                $captureId = $result['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;
                $paymentMethod = $result['purchase_units'][0]['payments']['captures'][0]['final_capture'] ?? null;

                return [
                    'success' => true,
                    'status' => $this->mapPayPalStatus($result['status']),
                    'payment_method' => 'paypal',
                    'metadata' => [
                        'capture_id' => $captureId,
                        'payer_id' => $result['payer']['payer_id'] ?? null,
                    ],
                ];
            }

            \Log::error('PayPal capture failed', [
                'order_id' => $orderId,
                'status' => $response->status(),
                'error' => $response->json(),
            ]);

            return [
                'success' => false,
                'error' => $response->json()['message'] ?? $response->json()['details'][0]['description'] ?? 'Capture failed',
            ];
        } catch (\Exception $e) {
            \Log::error('PayPal capture exception', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process refund via PayPal (interface method)
     */
    public function refundPayment(string $transactionId, float $amount): array
    {
        return $this->refund($transactionId, $amount);
    }

    /**
     * Process refund via PayPal
     */
    public function refund(string $transactionId, float $amount): array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'Authentication failed',
                ];
            }

            // Get transaction to find capture_id
            $transaction = \Modules\PaymentGateway\Models\PaymentTransaction::where('transaction_id', $transactionId)->first();

            if (!$transaction) {
                return [
                    'success' => false,
                    'message' => 'Transaction not found',
                ];
            }

            $captureId = $transaction->metadata['capture_id'] ?? null;

            // If no capture_id, try to get it from PayPal API
            if (!$captureId) {
                $orderResponse = Http::withToken($token)
                    ->get($this->baseUrl . '/v2/checkout/orders/' . $transactionId);

                if ($orderResponse->successful()) {
                    $orderData = $orderResponse->json();
                    $captureId = $orderData['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;

                    if ($captureId) {
                        // Save it for future use
                        $metadata = $transaction->metadata ?? [];
                        $metadata['capture_id'] = $captureId;
                        $transaction->update(['metadata' => $metadata]);
                    }
                }
            }

            if (!$captureId) {
                return [
                    'success' => false,
                    'message' => 'Capture ID not found. Payment may not have been captured yet.',
                ];
            }

            $payload = [
                'amount' => [
                    'value' => number_format($amount, 2, '.', ''),
                    'currency_code' => $transaction->currency,
                ],
            ];

            $response = Http::withToken($token)
                ->post($this->baseUrl . '/v2/payments/captures/' . $captureId . '/refund', $payload);

            if ($response->successful()) {
                $result = $response->json();
                return [
                    'success' => true,
                    'refund_id' => $result['id'] ?? null,
                    'status' => $this->mapPayPalStatus($result['status'] ?? 'pending'),
                    'amount' => $result['amount']['value'] ?? $amount,
                ];
            }

            \Log::error('PayPal refund failed', [
                'capture_id' => $captureId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            $errorResponse = $response->json();
            $errorMessage = $errorResponse['message'] ?? 'Refund request failed';

            // Extract specific error details if available
            if (isset($errorResponse['details'][0])) {
                $issue = $errorResponse['details'][0]['issue'] ?? '';
                $description = $errorResponse['details'][0]['description'] ?? '';

                // Provide user-friendly messages for common issues
                if ($issue === 'CAPTURE_FULLY_REFUNDED') {
                    $errorMessage = 'This payment has already been fully refunded.';
                } elseif ($description) {
                    $errorMessage = $description;
                }
            }

            return [
                'success' => false,
                'message' => $errorMessage,
            ];
        } catch (\Exception $e) {
            \Log::error('PayPal refund exception', [
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
     * Verify PayPal webhook signature
     */
    public function verifyWebhook(Request $request): bool
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                return false;
            }

            $webhookId = config('services.paypal.webhook_id');
            if (!$webhookId) {
                \Log::warning('PayPal webhook ID not configured');
                return false;
            }

            $headers = [
                'PAYPAL-TRANSMISSION-ID' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'PAYPAL-TRANSMISSION-TIME' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                'PAYPAL-TRANSMISSION-SIG' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'PAYPAL-CERT-URL' => $request->header('PAYPAL-CERT-URL'),
                'PAYPAL-AUTH-ALGO' => $request->header('PAYPAL-AUTH-ALGO'),
            ];

            $payload = [
                'transmission_id' => $headers['PAYPAL-TRANSMISSION-ID'],
                'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'],
                'cert_url' => $headers['PAYPAL-CERT-URL'],
                'auth_algo' => $headers['PAYPAL-AUTH-ALGO'],
                'transmission_sig' => $headers['PAYPAL-TRANSMISSION-SIG'],
                'webhook_id' => $webhookId,
                'webhook_event' => $request->all(),
            ];

            $response = Http::withToken($token)
                ->post($this->baseUrl . '/v1/notifications/verify-webhook-signature', $payload);

            if ($response->successful()) {
                $result = $response->json();
                return $result['verification_status'] === 'SUCCESS';
            }

            return false;
        } catch (\Exception $e) {
            \Log::error('PayPal webhook verification exception', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Process PayPal webhook
     */
    public function processWebhook(Request $request): array
    {
        $data = $request->all();
        $eventType = $data['event_type'] ?? null;
        $resource = $data['resource'] ?? [];

        $transactionId = $resource['supplementary_data']['related_ids']['order_id']
            ?? $resource['id']
            ?? null;
        $webhookId = $data['id'] ?? null;

        // Map event type to status
        $status = match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED' => 'completed',
            'PAYMENT.CAPTURE.DENIED' => 'failed',
            'PAYMENT.CAPTURE.REFUNDED' => 'refunded',
            'CHECKOUT.ORDER.APPROVED' => 'pending',
            'CHECKOUT.ORDER.COMPLETED' => 'completed',
            default => 'pending',
        };

        return [
            'event' => $eventType,
            'transaction_id' => $transactionId,
            'webhook_id' => $webhookId,
            'status' => $status,
            'amount' => $resource['amount']['value'] ?? null,
            'currency' => $resource['amount']['currency_code'] ?? null,
            'payment_method' => 'paypal',
            'metadata' => [
                'capture_id' => $resource['id'] ?? null,
                'event_type' => $eventType,
            ],
            'raw_data' => $data,
        ];
    }

    /**
     * Test PayPal connection
     */
    public function testConnection(): bool
    {
        try {
            $token = $this->getAccessToken();
            return $token !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Map PayPal status to internal status
     */
    protected function mapPayPalStatus(?string $paypalStatus): string
    {
        return match ($paypalStatus) {
            'COMPLETED', 'APPROVED' => 'completed',
            'CREATED', 'SAVED', 'PAYER_ACTION_REQUIRED' => 'pending',
            'VOIDED', 'DECLINED' => 'failed',
            'REFUNDED' => 'refunded',
            default => 'pending',
        };
    }
}
