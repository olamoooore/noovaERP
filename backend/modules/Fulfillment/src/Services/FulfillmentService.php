<?php

namespace Modules\Fulfillment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Fulfillment\Contracts\ShipmentRequest;
use Modules\Fulfillment\Models\FulfillmentProvider;
use Modules\Fulfillment\Models\Shipment;
use Modules\Fulfillment\Models\ShipmentLine;

class FulfillmentService
{
    public function __construct(
        protected CarrierConfigService $configService,
    ) {}

    public function getActiveProviders(string $tenantId): \Illuminate\Database\Eloquent\Collection
    {
        return FulfillmentProvider::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    public function getDefaultProvider(string $tenantId): ?FulfillmentProvider
    {
        return FulfillmentProvider::where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    public function createProvider(array $data): FulfillmentProvider
    {
        if (! empty($data['config'])) {
            foreach ($data['config'] as $key => $value) {
                if (in_array($key, ['api_key', 'api_secret', 'password', 'token'])) {
                    $data['config'][$key] = encrypt($value);
                }
            }
        }

        if (! empty($data['is_default'])) {
            FulfillmentProvider::where('tenant_id', $data['tenant_id'])
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        return FulfillmentProvider::create($data);
    }

    public function updateProvider(string $providerId, array $data): FulfillmentProvider
    {
        $provider = FulfillmentProvider::findOrFail($providerId);

        if (! empty($data['config'])) {
            $existingConfig = $provider->config ?? [];
            foreach ($data['config'] as $key => $value) {
                if (in_array($key, ['api_key', 'api_secret', 'password', 'token']) && ! empty($value)) {
                    $existingConfig[$key] = encrypt($value);
                } else {
                    $existingConfig[$key] = $value;
                }
            }
            $data['config'] = $existingConfig;
        }

        if (! empty($data['is_default'])) {
            FulfillmentProvider::where('tenant_id', $provider->tenant_id)
                ->where('is_default', true)
                ->where('id', '!=', $providerId)
                ->update(['is_default' => false]);
        }

        $provider->update($data);

        return $provider;
    }

    public function deleteProvider(string $providerId): bool
    {
        $provider = FulfillmentProvider::findOrFail($providerId);

        if ($provider->shipments()->count() > 0) {
            throw new \RuntimeException('Cannot delete provider with existing shipments');
        }

        return $provider->delete();
    }

    public function createShipment(
        FulfillmentProvider $provider,
        ShipmentRequest $request
    ): Shipment {
        $carrier = $this->configService->getCarrier($provider);

        $response = $carrier->createShipment($request);

        return DB::transaction(function () use ($provider, $request, $response) {
            $shipment = Shipment::create([
                'tenant_id' => $provider->tenant_id,
                'provider_id' => $provider->id,
                'order_id' => $request->orderId,
                'order_type' => $request->orderType,
                'status' => Shipment::STATUS_CREATED,
                'service_type' => $response->metadata['service_type'] ?? $request->serviceType,
                'tracking_number' => $response->trackingNumber,
                'tracking_url' => $response->trackingUrl,
                'label_url' => $response->labelUrl,
                'label_format' => $response->labelFormat,
                'estimated_delivery' => $response->estimatedDelivery,
                'shipping_cost' => $response->shippingCost,
                'currency_code' => $response->currency,
                'weight' => $request->weight,
                'weight_unit' => 'kg',
                'dimensions' => [
                    'length' => $request->length,
                    'width' => $request->width,
                    'height' => $request->height,
                ],
                'shipper' => $request->shipper,
                'recipient' => $request->recipient,
                'signature_required' => $request->signatureRequired,
                'saturday_delivery' => $request->saturdayDelivery,
                'insurance_enabled' => $request->insuranceValue !== null,
                'insurance_value' => $request->insuranceValue,
                'notes' => $request->notes,
                'metadata' => $response->metadata,
            ]);

            foreach ($request->items as $item) {
                ShipmentLine::create([
                    'shipment_id' => $shipment->id,
                    'order_line_id' => $item['order_line_id'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'name' => $item['name'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'weight' => $item['weight'] ?? null,
                ]);
            }

            return $shipment;
        });
    }

    public function cancelShipment(Shipment $shipment): bool
    {
        if (! $shipment->canCancel()) {
            throw new \RuntimeException('Shipment cannot be cancelled in current status');
        }

        $carrier = $this->configService->getCarrier($shipment->provider);

        $carrier->cancelShipment($shipment->tracking_number);

        $shipment->update(['status' => Shipment::STATUS_CANCELLED]);

        return true;
    }

    public function getShipment(string $shipmentId): Shipment
    {
        return Shipment::with(['provider', 'lines', 'trackingEvents'])->findOrFail($shipmentId);
    }

    public function listShipments(string $tenantId, array $filters = [])
    {
        $query = Shipment::where('tenant_id', $tenantId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['provider_id'])) {
            $query->where('provider_id', $filters['provider_id']);
        }

        if (! empty($filters['order_id'])) {
            $query->where('order_id', $filters['order_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }
}
