<?php

namespace Modules\Fulfillment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Fulfillment\Contracts\TrackingResponse;
use Modules\Fulfillment\Models\Shipment;
use Modules\Fulfillment\Models\TrackingEvent;

class TrackingService
{
    public function __construct(
        protected CarrierConfigService $configService,
    ) {}

    public function getTracking(Shipment $shipment): TrackingResponse
    {
        $carrier = $this->configService->getCarrier($shipment->provider);

        return $carrier->getTracking($shipment->tracking_number);
    }

    public function syncTracking(Shipment $shipment): void
    {
        $carrier = $this->configService->getCarrier($shipment->provider);

        // Issue #146: never persist fabricated tracking data. Simulated carriers
        // are only synced when sandbox use is explicitly enabled; a real carrier
        // would still require the automated sync to be switched on.
        $simulated = method_exists($carrier, 'isSimulatedCarrier') && $carrier->isSimulatedCarrier();

        if ($simulated && ! config('fulfillment.simulated_carriers_enabled', false)) {
            throw new \RuntimeException(
                'Refusing to sync simulated tracking data for shipment '.
                "{$shipment->shipment_number}: {$carrier->getName()} has no real tracking integration."
            );
        }

        if (! $simulated && ! config('fulfillment.tracking_sync_enabled', false)) {
            throw new \RuntimeException(
                "Automated tracking sync is disabled (FULFILLMENT_TRACKING_SYNC_ENABLED). Shipment {$shipment->shipment_number}."
            );
        }

        $response = $this->getTracking($shipment);

        DB::transaction(function () use ($shipment, $response) {
            $shipment->update([
                'status' => $this->mapStatus($response->status),
                'actual_delivery' => $response->actualDelivery,
            ]);

            if (! empty($response->events)) {
                foreach ($response->events as $eventData) {
                    $event = is_array($eventData) ? (object) $eventData : $eventData;

                    TrackingEvent::updateOrCreate(
                        [
                            'shipment_id' => $shipment->id,
                            'status' => $event->status,
                            'timestamp' => $event->timestamp,
                        ],
                        [
                            'status_code' => $event->status_code ?? null,
                            'description' => $event->description,
                            'location' => $event->location ?? null,
                            'signed_by' => $event->signed_by ?? null,
                        ]
                    );
                }
            }
        });
    }

    public function syncAllActiveShipments(): int
    {
        // Issue #146: this job must not run while every carrier integration is
        // simulated — each run would write fabricated statuses into shipments.
        if (! config('fulfillment.tracking_sync_enabled', false)
            && ! config('fulfillment.simulated_carriers_enabled', false)) {
            \Log::warning(
                'Tracking sync skipped: no real carrier tracking integration is enabled. '.
                'Set FULFILLMENT_TRACKING_SYNC_ENABLED once a real integration exists.'
            );

            return 0;
        }

        $shipments = Shipment::whereIn('status', [
            Shipment::STATUS_CREATED,
            Shipment::STATUS_PICKED_UP,
            Shipment::STATUS_IN_TRANSIT,
            Shipment::STATUS_OUT_FOR_DELIVERY,
        ])
            ->whereDate('created_at', '>', now()->subDays(30))
            ->get();

        $synced = 0;

        foreach ($shipments as $shipment) {
            try {
                $this->syncTracking($shipment);
                $synced++;
            } catch (\Exception $e) {
                \Log::warning("Failed to sync tracking for shipment {$shipment->shipment_number}: ".$e->getMessage());
            }
        }

        return $synced;
    }

    protected function mapStatus(string $carrierStatus): string
    {
        return match ($carrierStatus) {
            'delivered' => Shipment::STATUS_DELIVERED,
            'out_for_delivery' => Shipment::STATUS_OUT_FOR_DELIVERY,
            'in_transit' => Shipment::STATUS_IN_TRANSIT,
            'picked_up', 'collected' => Shipment::STATUS_PICKED_UP,
            'exception' => Shipment::STATUS_EXCEPTION,
            'returned' => Shipment::STATUS_RETURNED,
            'cancelled' => Shipment::STATUS_CANCELLED,
            default => Shipment::STATUS_CREATED,
        };
    }
}
