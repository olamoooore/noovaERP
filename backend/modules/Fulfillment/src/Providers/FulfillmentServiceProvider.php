<?php

namespace Modules\Fulfillment\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Fulfillment\Carriers\Aramex\AramexCarrier;
use Modules\Fulfillment\Carriers\DHL\DhlCarrier;
use Modules\Fulfillment\Carriers\FedEx\FedExCarrier;
use Modules\Fulfillment\Carriers\GDEX\GdexCarrier;
use Modules\Fulfillment\Contracts\CarrierInterface;

class FulfillmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CarrierInterface::class, function ($app, $params) {
            $carrierCode = $params['carrier_code'] ?? 'dhl';

            return match ($carrierCode) {
                'dhl' => new DhlCarrier,
                'fedex' => new FedExCarrier,
                'aramex' => new AramexCarrier,
                'gdex' => new GdexCarrier,
                default => throw new \InvalidArgumentException("Unknown carrier: {$carrierCode}"),
            };
        });

        $this->app->bind(\Modules\Fulfillment\Services\FulfillmentService::class);
        $this->app->bind(\Modules\Fulfillment\Services\RateShopperService::class);
        $this->app->bind(\Modules\Fulfillment\Services\TrackingService::class);
        $this->app->bind(\Modules\Fulfillment\Services\ShippingRuleEngine::class);
        $this->app->bind(\Modules\Fulfillment\Services\CarrierConfigService::class);
        $this->app->bind(\Modules\Fulfillment\Services\DeliveryOrderShipmentService::class);
    }

    public function boot(): void
    {
        // Issue #147: keep the linked Sales delivery order's carrier/tracking
        // fields in sync when a shipment is updated.
        \Modules\Fulfillment\Models\Shipment::observe(
            \Modules\Fulfillment\Observers\ShipmentObserver::class
        );
    }
}
