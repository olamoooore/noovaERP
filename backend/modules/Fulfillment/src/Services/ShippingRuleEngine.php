<?php

namespace Modules\Fulfillment\Services;

use Illuminate\Support\Collection;
use Modules\Fulfillment\Contracts\RateRequest;
use Modules\Fulfillment\Contracts\RateResponse;
use Modules\Fulfillment\Models\FulfillmentProvider;
use Modules\Fulfillment\Models\ShippingRule;

class ShippingRuleEngine
{
    public function resolveCarrier(
        string $tenantId,
        array $context
    ): ?array {
        $rules = ShippingRule::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->get();

        foreach ($rules as $rule) {
            if ($rule->matchesConditions($context)) {
                return $rule->getSelectedProvider();
            }
        }

        $defaultProvider = FulfillmentProvider::where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($defaultProvider) {
            return [
                'provider_id' => $defaultProvider->id,
                'carrier_code' => $defaultProvider->carrier_code,
                'service_type' => $defaultProvider->settings['default_service'] ?? null,
            ];
        }

        return null;
    }

    public function getRecommendedRate(
        string $tenantId,
        RateRequest $request,
        array $context
    ): ?RateResponse {
        $providerConfig = $this->resolveCarrier($tenantId, $context);

        if (! $providerConfig) {
            return null;
        }

        $provider = FulfillmentProvider::find($providerConfig['provider_id']);

        if (! $provider) {
            return null;
        }

        $configService = app(CarrierConfigService::class);
        $carrier = $configService->getCarrier($provider);

        $rates = $carrier->calculateRates($request);

        if (! empty($providerConfig['service_type'])) {
            foreach ($rates as $rate) {
                if ($rate->serviceCode === $providerConfig['service_type']) {
                    return $rate;
                }
            }
        }

        return $rates[0] ?? null;
    }

    public function getRules(string $tenantId): Collection
    {
        return ShippingRule::where('tenant_id', $tenantId)
            ->orderBy('priority')
            ->get();
    }

    public function createRule(array $data): ShippingRule
    {
        return ShippingRule::create($data);
    }

    public function updateRule(string $ruleId, array $data): ShippingRule
    {
        $rule = ShippingRule::findOrFail($ruleId);
        $rule->update($data);

        return $rule;
    }

    public function deleteRule(string $ruleId): bool
    {
        return ShippingRule::findOrFail($ruleId)->delete();
    }

    public function reorderRules(string $tenantId, array $ruleIds): void
    {
        foreach ($ruleIds as $priority => $ruleId) {
            ShippingRule::where('id', $ruleId)
                ->where('tenant_id', $tenantId)
                ->update(['priority' => $priority + 1]);
        }
    }
}
