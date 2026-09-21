<?php

namespace Modules\Fulfillment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fulfillment\Services\CarrierConfigService;
use Modules\Fulfillment\Services\FulfillmentService;

class ProviderController extends Controller
{
    public function __construct(
        protected FulfillmentService $fulfillmentService,
        protected CarrierConfigService $configService,
    ) {}

    public function index(): JsonResponse
    {
        $providers = $this->fulfillmentService->getActiveProviders(tenant('id'));

        return response()->json([
            'data' => $providers,
        ]);
    }

    public function store(Request $request): JsonResponse
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
            'settings.default_weight_unit' => 'nullable|in:kg,lb',
            'settings.default_dimension_unit' => 'nullable|in:cm,in',
            'settings.default_service' => 'nullable|string',
            'settings.label_format' => 'nullable|in:pdf,zpl,png',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $validated['tenant_id'] = tenant('id');
        $validated['is_active'] = $validated['is_active'] ?? true;

        $provider = $this->fulfillmentService->createProvider($validated);

        return response()->json([
            'data' => $provider,
            'message' => 'Provider created successfully',
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $provider = \Modules\Fulfillment\Models\FulfillmentProvider::findOrFail($id);

        $provider->makeVisible(['config']);

        return response()->json([
            'data' => $provider,
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
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
            'message' => 'Provider updated successfully',
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->fulfillmentService->deleteProvider($id);

        return response()->json([
            'message' => 'Provider deleted successfully',
        ]);
    }

    public function testCredentials(string $id): JsonResponse
    {
        $provider = \Modules\Fulfillment\Models\FulfillmentProvider::findOrFail($id);

        $result = $this->configService->testCredentials($provider);

        return response()->json([
            'success' => $result,
            'message' => $result
                ? 'Credentials are valid'
                : 'Credentials validation failed',
        ]);
    }

    public function getAvailableCarriers(): JsonResponse
    {
        $carriers = [
            [
                'code' => 'dhl',
                'simulated' => $this->configService->isCarrierSimulated('dhl'),
                'name' => 'DHL Express',
                'description' => 'Express delivery worldwide, 12PM and end-of-day options',
                'services' => ['EXPRESS_WORLDWIDE', 'EXPRESS_12PM', 'ECONOMIC_SELECT'],
            ],
            [
                'code' => 'fedex',
                'simulated' => $this->configService->isCarrierSimulated('fedex'),
                'name' => 'FedEx Express',
                'description' => 'International priority and economy shipping',
                'services' => ['INTERNATIONAL_PRIORITY', 'INTERNATIONAL_ECONOMY', 'FEDEX_GROUND'],
            ],
            [
                'code' => 'aramex',
                'simulated' => $this->configService->isCarrierSimulated('aramex'),
                'name' => 'Aramex',
                'description' => 'Express, economy and Drop And Ship services',
                'services' => ['EXPRESS', 'ECONOMY', 'DROP_AND_SHIP', 'DOMESTIC'],
            ],
            [
                'code' => 'gdex',
                'simulated' => $this->configService->isCarrierSimulated('gdex'),
                'name' => 'GD Express (GDEX)',
                'description' => 'Domestic Malaysia & Singapore, COD, GrabExpress same-day',
                'services' => ['STANDARD', 'EXPRESS', 'SAME_DAY', 'COD', 'PICKUP_POINT'],
            ],
        ];

        return response()->json([
            'data' => $carriers,
        ]);
    }
}
