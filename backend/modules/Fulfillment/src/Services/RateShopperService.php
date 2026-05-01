<?php

namespace Modules\Fulfillment\Services;

use Modules\Fulfillment\Models\FulfillmentProvider;
use Modules\Fulfillment\Contracts\RateRequest;
use Modules\Fulfillment\Contracts\RateResponse;
use Illuminate\Support\Collection;

class RateShopperService
{
    public function __construct(
        protected CarrierConfigService $configService,
    ) {}

    public function shopRates(RateRequest $request): Collection
    {
        $providers = FulfillmentProvider::where('is_active', true)
            ->where('tenant_id', $request->tenantId ?? tenant('id'))
            ->orderBy('sort_order')
            ->get();

        $allRates = collect();

        foreach ($providers as $provider) {
            try {
                $carrier = $this->configService->getCarrier($provider);

                $carrierRates = $carrier->calculateRates($request);

                foreach ($carrierRates as $rate) {
                    $allRates->push((object) array_merge(
                        $rate->toArray(),
                        [
                            'provider_id' => $provider->id,
                            'provider_name' => $provider->name,
                            'provider_code' => $provider->carrier_code,
                        ]
                    ));
                }
            } catch (\Exception $e) {
                \Log::warning("Failed to get rates from provider {$provider->carrier_code}: " . $e->getMessage());
                continue;
            }
        }

        return $this->sortRates($allRates, $request->sortBy ?? 'price');
    }

    public function getSingleProviderRates(
        FulfillmentProvider $provider,
        RateRequest $request
    ): array {
        $carrier = $this->configService->getCarrier($provider);

        return $carrier->calculateRates($request);
    }

    protected function sortRates(Collection $rates, string $sortBy = 'price'): Collection
    {
        return match ($sortBy) {
            'price' => $rates->sortBy('price'),
            'transit' => $rates->sortBy('transit_days'),
            'delivery' => $rates->sortBy('estimated_delivery'),
            default => $rates->sortBy('price'),
        };
    }
}