<?php

namespace Modules\Fulfillment\Observers;

use Modules\Fulfillment\Models\Shipment;
use Modules\Fulfillment\Services\DeliveryOrderShipmentService;

/**
 * Issue #147: when a shipment's tracking/carrier data changes, the linked
 * Sales delivery order must reflect it — the two records stay consistent
 * without manual re-entry.
 */
class ShipmentObserver
{
    public function updated(Shipment $shipment): void
    {
        if (! $shipment->wasChanged(['tracking_number', 'status', 'provider_id'])) {
            return;
        }

        app(DeliveryOrderShipmentService::class)->syncToDeliveryOrder($shipment);
    }
}
