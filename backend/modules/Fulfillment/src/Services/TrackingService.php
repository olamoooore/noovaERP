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
