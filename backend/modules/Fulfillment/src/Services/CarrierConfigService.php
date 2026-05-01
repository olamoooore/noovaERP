<?php

namespace Modules\Fulfillment\Services;

use Modules\Fulfillment\Models\FulfillmentProvider;
use Modules\Fulfillment\Contracts\CarrierInterface;
use InvalidArgumentException;

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

        if (!$carrierClass) {
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

    public function testCredentials(FulfillmentProvider $provider): bool
    {
        $carrier = $this->getCarrier($provider);

        $config = $provider->getDecryptedConfig();

        return $carrier->validateCredentials($config);
    }
}