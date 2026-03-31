<?php

namespace Modules\WhatsApp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Modules\WhatsApp\Models\WhatsAppApiKey;
use Modules\WhatsApp\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;

class WhatsAppWebhookController extends Controller
{
    protected WhatsAppService $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Authenticate the request using API key from header and initialize tenant context.
     */
    protected function authenticateApiKey(Request $request): ?WhatsAppApiKey
    {
        $apiKeyValue = $request->header('x-api-key');

        if (!$apiKeyValue) {
            return null;
        }

        $cacheKey = 'whatsapp_api_key:' . hash('sha256', $apiKeyValue);

        // Fast path: check cache for known tenant mapping
        $cachedTenantId = Cache::get($cacheKey);
        if ($cachedTenantId) {
            $tenant = Tenant::find($cachedTenantId);
            if ($tenant) {
                $apiKey = null;
                $tenant->run(function () use ($apiKeyValue, &$apiKey) {
                    $keys = WhatsAppApiKey::where('is_active', true)->get();
                    foreach ($keys as $key) {
                        if ($key->key === $apiKeyValue && $key->isValid()) {
                            $apiKey = $key;
                            return;
                        }
                    }
                });

                if ($apiKey) {
                    tenancy()->initialize($tenant);
                    return $apiKey;
                }

                // Cached mapping is stale (key revoked/expired), clear it
                Cache::forget($cacheKey);
            }
        }

        // Slow path: scan all tenants (first request per key, or after cache miss)
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $apiKey = null;
            $tenant->run(function () use ($apiKeyValue, &$apiKey) {
                try {
                    $keys = WhatsAppApiKey::where('is_active', true)->get();

                    foreach ($keys as $key) {
                        if ($key->key === $apiKeyValue && $key->isValid()) {
                            $apiKey = $key;
                            return;
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug('Error checking tenant for API key', [
                        'tenant' => tenancy()->tenant->id ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            });

            if ($apiKey) {
                // Cache the mapping for 1 hour to avoid future full scans
                Cache::put($cacheKey, $tenant->id, 3600);
                tenancy()->initialize($tenant);
                return $apiKey;
            }
        }

        return null;
    }

    /**
     * Handle order created webhook.
     */
    public function orderCreated(Request $request)
    {
        $apiKey = $this->authenticateApiKey($request);
        if (!$apiKey) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'order_id' => 'required|string',
            'customer_name' => 'required|string',
            'customer_phone' => 'required|string',
            'total_amount' => 'required|numeric',
            'currency' => 'required|string',
            'items' => 'nullable|array',
            'items.*.product_name' => 'required|string',
            'items.*.quantity' => 'required|integer',
            'items.*.price' => 'required|numeric',
            'order_url' => 'nullable|url',
        ]);

        try {
            $message = $this->whatsappService->formatOrderConfirmation($validated);
            $result = $this->whatsappService->sendMessage($apiKey, $validated['customer_phone'], $message);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Order confirmation sent',
                    'data' => $result,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to send message',
            ], 400);
        } catch (\Exception $e) {
            Log::error('Order created webhook error', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle payment confirmed webhook.
     */
    public function paymentConfirmed(Request $request)
    {
        $apiKey = $this->authenticateApiKey($request);
        if (!$apiKey) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'order_id' => 'required|string',
            'customer_name' => 'required|string',
            'customer_phone' => 'required|string',
            'amount' => 'required|numeric',
            'currency' => 'required|string',
            'payment_method' => 'required|string',
            'transaction_id' => 'nullable|string',
        ]);

        try {
            $message = $this->whatsappService->formatPaymentConfirmation($validated);
            $result = $this->whatsappService->sendMessage($apiKey, $validated['customer_phone'], $message);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment confirmation sent',
                    'data' => $result,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to send message',
            ], 400);
        } catch (\Exception $e) {
            Log::error('Payment confirmed webhook error', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle order shipped webhook.
     */
    public function orderShipped(Request $request)
    {
        $apiKey = $this->authenticateApiKey($request);
        if (!$apiKey) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'order_id' => 'required|string',
            'customer_name' => 'required|string',
            'customer_phone' => 'required|string',
            'tracking_number' => 'required|string',
            'carrier' => 'required|string',
            'estimated_delivery' => 'nullable|string',
        ]);

        try {
            $message = $this->whatsappService->formatShippingNotification($validated);
            $result = $this->whatsappService->sendMessage($apiKey, $validated['customer_phone'], $message);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Shipping notification sent',
                    'data' => $result,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to send message',
            ], 400);
        } catch (\Exception $e) {
            Log::error('Order shipped webhook error', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle order delivered webhook.
     */
    public function orderDelivered(Request $request)
    {
        $apiKey = $this->authenticateApiKey($request);
        if (!$apiKey) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'order_id' => 'required|string',
            'customer_name' => 'required|string',
            'customer_phone' => 'required|string',
            'delivered_at' => 'required|string',
        ]);

        try {
            $message = $this->whatsappService->formatDeliveryConfirmation($validated);
            $result = $this->whatsappService->sendMessage($apiKey, $validated['customer_phone'], $message);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Delivery confirmation sent',
                    'data' => $result,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to send message',
            ], 400);
        } catch (\Exception $e) {
            Log::error('Order delivered webhook error', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle order cancelled webhook.
     */
    public function orderCancelled(Request $request)
    {
        $apiKey = $this->authenticateApiKey($request);
        if (!$apiKey) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'order_id' => 'required|string',
            'customer_name' => 'required|string',
            'customer_phone' => 'required|string',
            'cancellation_reason' => 'required|string',
            'refund_amount' => 'nullable|numeric',
            'refund_method' => 'nullable|string',
        ]);

        try {
            $message = $this->whatsappService->formatCancellation($validated);
            $result = $this->whatsappService->sendMessage($apiKey, $validated['customer_phone'], $message);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Cancellation notification sent',
                    'data' => $result,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to send message',
            ], 400);
        } catch (\Exception $e) {
            Log::error('Order cancelled webhook error', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send a custom message.
     */
    public function sendCustomMessage(Request $request)
    {
        $apiKey = $this->authenticateApiKey($request);
        if (!$apiKey) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'phone_number' => 'required|string',
            'message' => 'required|string|max:4096',
        ]);

        try {
            $result = $this->whatsappService->sendMessage(
                $apiKey,
                $validated['phone_number'],
                $validated['message']
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Message sent successfully',
                    'data' => $result,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to send message',
            ], 400);
        } catch (\Exception $e) {
            Log::error('Custom message error', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Health check endpoint.
     */
    public function health()
    {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'service' => 'WhatsApp E-commerce Integration',
        ]);
    }
}
