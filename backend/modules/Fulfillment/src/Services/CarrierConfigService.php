<?php

namespace Modules\Fulfillment\Services;

use InvalidArgumentException;
use Modules\Fulfillment\Contracts\CarrierInterface;
use Modules\Fulfillment\Models\FulfillmentProvider;

class CarrierConfigService
{
    protected array $carrierMap = [
        'dhl' => \Modules\Fulfillment\Carriers\DHL\DhlCarrier::class,
        'fedex' => \Modules\Fulfillment\Carriers\FedEx\FedExCarrier::class,
        'aramex' => \Modules\Fulfillment\Carriers\Aramex\AramexCarrier::class,
        'gdex' => \Modules\Fulfillment\Carriers\GDEX\GdexCarrier::class,
    ];

    public function getCarrier(FulfillmentProvider $provider): CarrierInterface
    {
        $carrierClass = $this->carrierMap[$provider->carrier_code] ?? null;

        if (! $carrierClass) {
            throw new InvalidArgumentException("Unknown carrier code: {$provider->carrier_code}");
        }

        $config = $provider->getDecryptedConfig();

        return new $carrierClass($config);
    }

    public function getCarrierClass(string $carrierCode): ?string
    {
        return $this->carrierMap[$carrierCode] ?? null;
    }

    public function registerCarrier(string $code, string $carrierClass): void
    {
        $this->carrierMap[$code] = $carrierClass;
    }

    public function getAvailableCarriers(): array
    {
        return array_keys($this->carrierMap);
    }

    /**
     * Issue #146: whether a carrier code maps to a simulated (non-real-API)
     * integration. Used by the API/UI to label carriers as sandbox-only.
     */
    public function isCarrierSimulated(string $carrierCode): bool
    {
        $carrierClass = $this->carrierMap[$carrierCode] ?? null;

        if (! $carrierClass || ! class_exists($carrierClass)) {
            return false;
        }

        $carrier = new $carrierClass([]);

        return method_exists($carrier, 'isSimulatedCarrier') && $carrier->isSimulatedCarrier();
    }

    public function testCredentials(FulfillmentProvider $provider): bool
    {
        $carrier = $this->getCarrier($provider);

        $config = $provider->getDecryptedConfig();

        return $carrier->validateCredentials($config);
    }
}
