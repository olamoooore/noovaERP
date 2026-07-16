<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fulfillment\Contracts\RateRequest;
use Modules\Fulfillment\Services\RateShopperService;

class ShippingRatesController extends Controller
{
    public function __construct(
        protected RateShopperService $rateShopperService,
    ) {}

    /**
     * Get available shipping rates for checkout
     * Called from storefront when user enters shipping address
     */
    public function getRates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'destination_country' => 'required|string|size:2',
            'destination_postal_code' => 'required|string',
            'weight' => 'required|numeric|min:0.1',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'items_count' => 'nullable|integer|min:1',
        ]);

        $country = $validated['destination_country'];
        $postalCode = $validated['destination_postal_code'];
        $weight = (float) $validated['weight'];

        $originCountry = 'MY';

        $rateRequest = new RateRequest(
            weight: $weight,
            length: $validated['length'] ?? 0,
            width: $validated['width'] ?? 0,
            height: $validated['height'] ?? 0,
            originCountry: $originCountry,
            originPostalCode: '',
            destinationCountry: $country,
            destinationPostalCode: $postalCode,
            weightUnit: 'kg',
            dimensionUnit: 'cm',
            serviceType: null,
        );

        $rates = $this->rateShopperService->shopRates($rateRequest);

        $formattedRates = $rates->map(function ($rate) {
            return [
                'id' => $rate->provider_code.'_'.$rate->service_code,
                'provider_code' => $rate->provider_code,
                'provider_name' => $rate->provider_name,
                'service_code' => $rate->service_code,
                'service_name' => $rate->service_name,
                'price' => (float) $rate->price,
                'currency' => $rate->currency,
                'transit_days' => $rate->transit_days,
                'estimated_delivery' => $rate->estimated_delivery,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'rates' => $formattedRates,
            'has_rates' => $formattedRates->count() > 0,
        ]);
    }
}
