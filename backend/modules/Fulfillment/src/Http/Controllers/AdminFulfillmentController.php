<?php

namespace Modules\Fulfillment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fulfillment\Models\FulfillmentProvider;
use Modules\Fulfillment\Services\CarrierConfigService;
use Modules\Fulfillment\Services\FulfillmentService;

class AdminFulfillmentController extends Controller
{
    public function __construct(
        protected FulfillmentService $fulfillmentService,
        protected CarrierConfigService $configService,
    ) {}

    public function providers(): JsonResponse
    {
        $providers = $this->fulfillmentService->getActiveProviders(tenant('id'));

        return response()->json([
            'data' => $providers,
        ]);
    }

    public function availableCarriers(): JsonResponse
    {
        $carriers = [
            [
                'code' => 'dhl',
                'name' => 'DHL Express',
                'description' => 'Express delivery worldwide, 12PM and end-of-day options',
                'services' => ['EXPRESS_WORLDWIDE', 'EXPRESS_12PM', 'ECONOMIC_SELECT'],
                'supports_domestic' => true,
                'supports_international' => true,
            ],
            [
                'code' => 'fedex',
                'name' => 'FedEx Express',
                'description' => 'International priority and economy shipping',
                'services' => ['INTERNATIONAL_PRIORITY', 'INTERNATIONAL_ECONOMY', 'FEDEX_GROUND'],
                'supports_domestic' => true,
                'supports_international' => true,
            ],
            [
                'code' => 'aramex',
                'name' => 'Aramex',
                'description' => 'Express, economy and Drop And Ship services',
                'services' => ['EXPRESS', 'ECONOMY', 'DROP_AND_SHIP', 'DOMESTIC'],
                'supports_domestic' => true,
                'supports_international' => true,
            ],
            [
                'code' => 'gdex',
                'name' => 'GD Express (GDEX)',
                'description' => 'Domestic Malaysia & Singapore, COD, GrabExpress same-day',
                'services' => ['STANDARD', 'EXPRESS', 'SAME_DAY', 'COD', 'PICKUP_POINT'],
                'supports_domestic' => true,
                'supports_international' => false,
            ],
        ];

        return response()->json([
            'data' => $carriers,
        ]);
    }

    public function createProvider(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'carrier_code' => 'required|string|in:dhl,fedex,aramex,gdex',
            'name' => 'required|string|max:255',
            'is_active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'config' => 'nullable|array',
            'config.api_key' => 'nullable|string',
            'config.api_secret' => 'nullable|string',
            'config.account_number' => 'nullable|string',
            'config.customer_code' => 'nullable|string',
            'settings' => 'nullable|array',
            'settings.default_service' => 'nullable|string',
            'settings.label_format' => 'nullable|in:pdf,zpl,png',
            'settings.shipping_cost_override' => 'nullable|numeric|min:0',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $validated['tenant_id'] = tenant('id');
        $validated['is_active'] = $validated['is_active'] ?? true;

        $provider = $this->fulfillmentService->createProvider($validated);

        return response()->json([
            'data' => $provider,
            'message' => 'Fulfillment provider created successfully',
        ], 201);
    }

    public function showProvider(string $id): JsonResponse
    {
        $provider = FulfillmentProvider::findOrFail($id);

        $provider->makeVisible(['config']);

        return response()->json([
            'data' => $provider,
        ]);
    }

    public function updateProvider(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'config' => 'sometimes|array',
            'settings' => 'sometimes|array',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $provider = $this->fulfillmentService->updateProvider($id, $validated);

        $provider->makeVisible(['config']);

        return response()->json([
            'data' => $provider,
            'message' => 'Fulfillment provider updated successfully',
        ]);
    }

    public function deleteProvider(string $id): JsonResponse
    {
        $this->fulfillmentService->deleteProvider($id);

        return response()->json([
            'message' => 'Fulfillment provider deleted successfully',
        ]);
    }

    public function testCredentials(string $id): JsonResponse
    {
        $provider = FulfillmentProvider::findOrFail($id);

        $result = $this->configService->testCredentials($provider);

        return response()->json([
            'success' => $result,
            'message' => $result
                ? 'Credentials are valid'
                : 'Credentials validation failed',
        ]);
    }

    public function toggleProvider(Request $request, string $id): JsonResponse
    {
        $provider = FulfillmentProvider::findOrFail($id);

        $provider->update(['is_active' => ! $provider->is_active]);

        return response()->json([
            'data' => $provider,
            'message' => $provider->is_active
                ? 'Provider activated'
                : 'Provider deactivated',
        ]);
    }

    public function shipments(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => 'nullable|string',
            'provider_id' => 'nullable|uuid',
            'order_id' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $shipments = $this->fulfillmentService->listShipments(
            tenant('id'),
            $filters
        );

        return response()->json($shipments);
    }

    public function showShipment(string $id): JsonResponse
    {
        $shipment = $this->fulfillmentService->getShipment($id);

        return response()->json([
            'data' => $shipment,
        ]);
    }

    public function createLabel(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'message' => 'Label generation not yet implemented',
        ]);
    }
}
