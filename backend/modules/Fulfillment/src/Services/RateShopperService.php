<?php

namespace Modules\Fulfillment\Services;

use Illuminate\Support\Collection;
use Modules\Fulfillment\Contracts\RateRequest;
use Modules\Fulfillment\Models\FulfillmentProvider;

class RateShopperService
{
    public function __construct(
        protected CarrierConfigService $configService,
        protected ShippingRuleEngine $shippingRuleEngine,
    ) {}

    public function shopRates(RateRequest $request): Collection
    {
        $tenantId = $request->tenantId ?? tenant('id');

        // Issue #148: rule-based carrier selection is consulted FIRST. When a
        // rule (or the default provider) resolves, only that provider is shopped;
        // shopping every active provider remains the fallback when nothing
        // resolves.
        $selection = $this->shippingRuleEngine->resolveCarrier((string) $tenantId, [
            'order_total' => $request->orderTotal,
            'weight' => $request->weight,
            'destination_country' => $request->destinationCountry,
        ]);

        $providers = FulfillmentProvider::where('is_active', true)
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->get();

        if (! empty($selection['provider_id'])) {
            $providers = $providers->where('id', $selection['provider_id'])->values();
        }

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
                \Log::warning("Failed to get rates from provider {$provider->carrier_code}: ".$e->getMessage());

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
