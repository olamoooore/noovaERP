<?php

namespace Modules\WhatsApp\Controllers;

use App\Http\Controllers\Controller;
use Modules\WhatsApp\Models\WhatsAppApiKey;
use Modules\WhatsApp\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class WhatsAppApiKeyController extends Controller
{
    protected WhatsAppService $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Get all WhatsApp API keys for the current tenant.
     */
    public function index()
    {
        $apiKeys = WhatsAppApiKey::orderBy('created_at', 'desc')
            ->get()
            ->map(function ($apiKey) {
                return [
                    'id' => $apiKey->id,
                    'name' => $apiKey->name,
                    'description' => $apiKey->description,
                    'platform_name' => $apiKey->platform_name,
                    'platform_url' => $apiKey->platform_url,
                    'whatsapp_provider' => $apiKey->whatsapp_provider,
                    'api_endpoint' => $apiKey->api_endpoint,
                    'masked_key' => $apiKey->getMaskedKey(),
                    'is_active' => $apiKey->is_active,
                    'is_expired' => $apiKey->isExpired(),
                    'usage_count' => $apiKey->usage_count,
                    'last_used_at' => $apiKey->last_used_at?->toISOString(),
                    'expires_at' => $apiKey->expires_at?->toISOString(),
                    'created_at' => $apiKey->created_at->toISOString(),
                    'created_by' => $apiKey->created_by,
                ];
            });

        $stats = [
            'total' => WhatsAppApiKey::count(),
            'active' => WhatsAppApiKey::active()->count(),
            'inactive' => WhatsAppApiKey::where('is_active', false)->count(),
            'expired' => WhatsAppApiKey::expired()->count(),
            'total_api_calls' => WhatsAppApiKey::sum('usage_count'),
        ];

        return response()->json([
            'api_keys' => $apiKeys,
            'stats' => $stats,
        ]);
    }

    /**
     * Generate a new WhatsApp API key.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'platform_name' => 'nullable|string|max:255',
            'platform_url' => 'nullable|url|max:500',
            'whatsapp_provider' => 'required|in:custom,twilio,vonage',
            'api_endpoint' => 'nullable|url|max:500',
            'api_key' => 'nullable|string|max:1000', // User's own API key
            'webhook_secret' => 'nullable|string|max:500',
            'expires_in_days' => 'nullable|integer|min:1|max:3650',
        ]);

        try {
            $expiresAt = null;
            if (isset($validated['expires_in_days'])) {
                $expiresAt = now()->addDays($validated['expires_in_days']);
            }

            $apiKey = WhatsAppApiKey::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'platform_name' => $validated['platform_name'] ?? null,
                'platform_url' => $validated['platform_url'] ?? null,
                'whatsapp_provider' => $validated['whatsapp_provider'],
                'api_endpoint' => $validated['api_endpoint'] ?? null,
                'key' => $validated['api_key'] ?? WhatsAppApiKey::generateApiKey(), // Use user's key or generate one
                'webhook_secret' => $validated['webhook_secret'] ?? null,
                'expires_at' => $expiresAt,
                'created_by' => Auth::id(),
            ]);

            // Return the full key only once (when created)
            $plainKey = $apiKey->key;

            // Reload to get encrypted version
            $apiKey->refresh();

            return response()->json([
                'message' => 'WhatsApp API key generated successfully',
                'api_key' => [
                    'id' => $apiKey->id,
                    'name' => $apiKey->name,
                    'description' => $apiKey->description,
                    'platform_name' => $apiKey->platform_name,
                    'platform_url' => $apiKey->platform_url,
                    'whatsapp_provider' => $apiKey->whatsapp_provider,
                    'api_endpoint' => $apiKey->api_endpoint,
                    'key' => $plainKey, // Full key shown only once
                    'masked_key' => $apiKey->getMaskedKey(),
                    'is_active' => $apiKey->is_active,
                    'expires_at' => $apiKey->expires_at?->toISOString(),
                    'created_at' => $apiKey->created_at->toISOString(),
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create WhatsApp API key', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to create API key',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an existing API key.
     */
    public function update(Request $request, $id)
    {
        $apiKey = WhatsAppApiKey::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'platform_name' => 'nullable|string|max:255',
            'platform_url' => 'nullable|url|max:500',
            'whatsapp_provider' => 'sometimes|in:custom,twilio,vonage',
            'api_endpoint' => 'nullable|url|max:500',
            'api_key' => 'nullable|string|max:1000', // Allow updating the API key
            'webhook_secret' => 'nullable|string|max:500',
            'expires_at' => 'nullable|date',
        ]);

        try {
            // Only update provided fields
            $updateData = array_filter($validated, function ($value) {
                return $value !== null;
            });

            $apiKey->update($updateData);

            return response()->json([
                'message' => 'API key updated successfully',
                'api_key' => [
                    'id' => $apiKey->id,
                    'name' => $apiKey->name,
                    'description' => $apiKey->description,
                    'platform_name' => $apiKey->platform_name,
                    'platform_url' => $apiKey->platform_url,
                    'whatsapp_provider' => $apiKey->whatsapp_provider,
                    'api_endpoint' => $apiKey->api_endpoint,
                    'masked_key' => $apiKey->getMaskedKey(),
                    'is_active' => $apiKey->is_active,
                    'expires_at' => $apiKey->expires_at?->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update API key', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to update API key',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an API key.
     */
    public function destroy($id)
    {
        try {
            $apiKey = WhatsAppApiKey::findOrFail($id);
            $apiKey->delete();

            return response()->json([
                'message' => 'API key deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete API key', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to delete API key',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Revoke (deactivate) an API key.
     */
    public function revoke($id)
    {
        try {
            $apiKey = WhatsAppApiKey::findOrFail($id);
            $apiKey->revoke();

            return response()->json([
                'message' => 'API key revoked successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to revoke API key',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate an API key.
     */
    public function activate($id)
    {
        try {
            $apiKey = WhatsAppApiKey::findOrFail($id);
            $apiKey->activate();

            return response()->json([
                'message' => 'API key activated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to activate API key',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test an API key by sending a test message.
     */
    public function test(Request $request, $id)
    {
        Log::info('WhatsApp test request received', [
            'api_key_id' => $id,
            'data' => $request->all(),
        ]);

        $validated = $request->validate([
            'phone_number' => 'required|string|max:20',
            'message' => 'nullable|string|max:1000',
            'dry_run' => 'nullable|boolean', // Allow simulation mode
        ]);

        try {
            $apiKey = WhatsAppApiKey::findOrFail($id);

            Log::info('API key found for test', [
                'api_key_id' => $id,
                'provider' => $apiKey->whatsapp_provider,
                'endpoint' => $apiKey->api_endpoint,
                'is_active' => $apiKey->is_active,
                'is_valid' => $apiKey->isValid(),
            ]);

            // Check if API key is valid
            if (!$apiKey->isValid()) {
                Log::warning('API key is invalid', [
                    'api_key_id' => $id,
                    'is_active' => $apiKey->is_active,
                    'is_expired' => $apiKey->isExpired(),
                ]);

                return response()->json([
                    'error' => 'API key is invalid',
                    'details' => $apiKey->is_active ? 'API key has expired' : 'API key is not active',
                ], 400);
            }

            // Dry run mode - simulate without actual sending
            if ($validated['dry_run'] ?? false) {
                Log::info('Dry run test successful', ['api_key_id' => $id]);

                return response()->json([
                    'message' => 'Test simulation successful',
                    'simulated' => true,
                    'api_key' => [
                        'name' => $apiKey->name,
                        'provider' => $apiKey->whatsapp_provider,
                        'endpoint' => $apiKey->api_endpoint ?? 'Not configured',
                        'is_active' => $apiKey->is_active,
                    ],
                    'test_data' => [
                        'phone_number' => $validated['phone_number'],
                        'message_preview' => substr($validated['message'] ?? 'Test message', 0, 50),
                    ],
                ]);
            }

            // Check if endpoint is configured for custom provider
            if ($apiKey->whatsapp_provider === 'custom' && !$apiKey->api_endpoint) {
                Log::warning('Custom provider missing endpoint', ['api_key_id' => $id]);

                return response()->json([
                    'error' => 'API endpoint not configured',
                    'details' => 'Custom WhatsApp provider requires an API endpoint. Please configure the API endpoint in the integration settings or enable "Dry Run" to test without sending.',
                    'provider' => 'custom',
                ], 400);
            }

            // For custom provider, send as webhook format (order-created)
            if ($apiKey->whatsapp_provider === 'custom') {
                $testOrderData = [
                    'order_id' => 'TEST-' . time(),
                    'customer_name' => 'Test Customer',
                    'customer_phone' => $validated['phone_number'],
                    'customer_email' => 'test@example.com',
                    'total_amount' => 100.00,
                    'currency' => 'MYR',
                    'items' => [
                        [
                            'product_name' => 'Test Product',
                            'quantity' => 1,
                            'price' => 100.00,
                        ],
                    ],
                    'order_url' => 'https://store.com/orders/test',
                ];

                $message = $validated['message'] ?? $this->whatsappService->formatOrderConfirmation($testOrderData);

                $result = $this->whatsappService->sendMessage(
                    $apiKey,
                    $validated['phone_number'],
                    $message,
                    $testOrderData // Pass order data for webhook format
                );
            } else {
                // For Twilio/Vonage, send simple message format
                $message = $validated['message'] ?? "🧪 Test message from {$apiKey->name}\n\nThis is a test to verify your WhatsApp integration is working correctly. ✅";

                $result = $this->whatsappService->sendMessage(
                    $apiKey,
                    $validated['phone_number'],
                    $message
                );
            }

            if ($result['success']) {
                return response()->json([
                    'message' => 'Test message sent successfully',
                    'result' => $result,
                ]);
            }

            return response()->json([
                'error' => $result['error'] ?? 'Failed to send test message',
                'details' => $result['error'] ?? 'The message could not be delivered. Please check your API configuration and try again.',
                'result' => $result,
            ], 400);
        } catch (\Exception $e) {
            Log::error('Failed to test API key', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to test API key',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
