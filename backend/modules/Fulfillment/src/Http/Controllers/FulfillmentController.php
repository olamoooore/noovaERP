<?php

namespace Modules\Fulfillment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fulfillment\Contracts\RateRequest;
use Modules\Fulfillment\Contracts\ShipmentRequest;
use Modules\Fulfillment\Services\FulfillmentService;
use Modules\Fulfillment\Services\RateShopperService;
use Modules\Fulfillment\Services\TrackingService;

class FulfillmentController extends Controller
{
    public function __construct(
        protected FulfillmentService $fulfillmentService,
        protected RateShopperService $rateShopperService,
        protected TrackingService $trackingService,
    ) {}

    /**
     * Issue #147: create a Fulfillment shipment for a Sales delivery order and
     * link the two records (carrier/tracking written back to the order).
     */
    public function createShipmentForDeliveryOrder(Request $request, int $deliveryOrderId): JsonResponse
    {
        $deliveryOrder = \Modules\Sales\Models\DeliveryOrder::find($deliveryOrderId);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        $validated = $request->validate([
            'provider_id' => 'nullable|string|exists:fulfillment_providers,id',
        ]);

        $provider = ! empty($validated['provider_id'])
            ? \Modules\Fulfillment\Models\FulfillmentProvider::findOrFail($validated['provider_id'])
            : null;

        try {
            $shipment = app(\Modules\Fulfillment\Services\DeliveryOrderShipmentService::class)
                ->createForDeliveryOrder($deliveryOrder, $provider);

            return response()->json([
                'message' => 'Shipment created for delivery order',
                'data' => $shipment->load('lines', 'provider'),
                'delivery_order' => $deliveryOrder->fresh(),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create shipment',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function calculateRates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'weight' => 'required|numeric|min:0.1',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'origin_country' => 'nullable|string|size:2',
            'origin_postal_code' => 'nullable|string',
            'destination_country' => 'required|string|size:2',
            'destination_postal_code' => 'required|string',
            'weight_unit' => 'nullable|in:kg,lb',
            'dimension_unit' => 'nullable|in:cm,in',
            'service_type' => 'nullable|string',
            'provider_id' => 'nullable|uuid',
            'sort_by' => 'nullable|in:price,transit,delivery',
        ]);

        $tenantId = tenant('id');

        if (! empty($validated['provider_id'])) {
            $provider = \Modules\Fulfillment\Models\FulfillmentProvider::findOrFail($validated['provider_id']);
            $rates = $this->rateShopperService->getSingleProviderRates($provider, new RateRequest(
                ...array_merge($validated, ['tenantId' => $tenantId])
            ));

            return response()->json([
                'data' => $rates,
                'provider' => [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'carrier_code' => $provider->carrier_code,
                ],
            ]);
        }

        $rateRequest = new RateRequest(
            weight: $validated['weight'],
            length: $validated['length'] ?? 0,
            width: $validated['width'] ?? 0,
            height: $validated['height'] ?? 0,
            originCountry: $validated['origin_country'] ?? 'MY',
            originPostalCode: $validated['origin_postal_code'] ?? '',
            destinationCountry: $validated['destination_country'],
            destinationPostalCode: $validated['destination_postal_code'],
            weightUnit: $validated['weight_unit'] ?? 'kg',
            dimensionUnit: $validated['dimension_unit'] ?? 'cm',
            serviceType: $validated['service_type'] ?? null,
        );

        $rateRequest->tenantId = $tenantId;
        $rateRequest->sortBy = $validated['sort_by'] ?? 'price';

        $rates = $this->rateShopperService->shopRates($rateRequest);

        return response()->json([
            'data' => $rates->values(),
            'meta' => [
                'total' => $rates->count(),
                'sort_by' => $rateRequest->sortBy,
            ],
        ]);
    }

    public function createShipment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => 'required|uuid',
            'weight' => 'required|numeric|min:0.1',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'service_type' => 'nullable|string',
            'order_id' => 'nullable|string',
            'order_type' => 'nullable|string|in:sales_order,web_order,pos_order',
            'shipper' => 'required|array',
            'shipper.name' => 'required|string',
            'shipper.company' => 'nullable|string',
            'shipper.phone' => 'required|string',
            'shipper.address' => 'required|string',
            'shipper.city' => 'required|string',
            'shipper.state' => 'required|string',
            'shipper.postal_code' => 'required|string',
            'shipper.country' => 'required|string|size:2',
            'recipient' => 'required|array',
            'recipient.name' => 'required|string',
            'recipient.company' => 'nullable|string',
            'recipient.phone' => 'required|string',
            'recipient.address' => 'required|string',
            'recipient.city' => 'required|string',
            'recipient.state' => 'required|string',
            'recipient.postal_code' => 'required|string',
            'recipient.country' => 'required|string|size:2',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'nullable|string',
            'items.*.name' => 'nullable|string',
            'items.*.quantity' => 'nullable|integer|min:1',
            'items.*.weight' => 'nullable|numeric|min:0',
            'signature_required' => 'nullable|boolean',
            'saturday_delivery' => 'nullable|boolean',
            'insurance_value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $provider = \Modules\Fulfillment\Models\FulfillmentProvider::findOrFail($validated['provider_id']);

        $shipmentRequest = new ShipmentRequest(
            shipper: $validated['shipper'],
            recipient: $validated['recipient'],
            items: $validated['items'],
            weight: $validated['weight'],
            length: $validated['length'] ?? null,
            width: $validated['width'] ?? null,
            height: $validated['height'] ?? null,
            serviceType: $validated['service_type'] ?? null,
            orderId: $validated['order_id'] ?? null,
            orderType: $validated['order_type'] ?? null,
            signatureRequired: $validated['signature_required'] ?? false,
            saturdayDelivery: $validated['saturday_delivery'] ?? false,
            insuranceValue: $validated['insurance_value'] ?? null,
            notes: $validated['notes'] ?? null,
        );

        $shipment = $this->fulfillmentService->createShipment($provider, $shipmentRequest);

        return response()->json([
            'data' => $shipment->fresh(['provider', 'lines']),
            'message' => 'Shipment created successfully',
        ], 201);
    }

    public function getShipment(string $id): JsonResponse
    {
        $shipment = $this->fulfillmentService->getShipment($id);

        return response()->json([
            'data' => $shipment,
        ]);
    }

    public function listShipments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|string',
            'provider_id' => 'nullable|uuid',
            'order_id' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $shipments = $this->fulfillmentService->listShipments(
            tenant('id'),
            $validated
        );

        return response()->json($shipments);
    }

    public function cancelShipment(string $id): JsonResponse
    {
        $shipment = $this->fulfillmentService->getShipment($id);

        $this->fulfillmentService->cancelShipment($shipment);

        return response()->json([
            'message' => 'Shipment cancelled successfully',
            'data' => $shipment->fresh(),
        ]);
    }

    public function getTracking(string $id): JsonResponse
    {
        $shipment = $this->fulfillmentService->getShipment($id);

        if (! $shipment->tracking_number) {
            return response()->json([
                'message' => 'No tracking number available',
            ], 404);
        }

        $tracking = $this->trackingService->getTracking($shipment);

        return response()->json([
            'data' => [
                'shipment' => [
                    'id' => $shipment->id,
                    'shipment_number' => $shipment->shipment_number,
                    'status' => $shipment->status,
                    'tracking_number' => $shipment->tracking_number,
                    'tracking_url' => $shipment->tracking_url,
                ],
                'tracking' => $tracking->toArray(),
            ],
        ]);
    }

    public function syncTracking(string $id): JsonResponse
    {
        $shipment = $this->fulfillmentService->getShipment($id);

        $this->trackingService->syncTracking($shipment);

        return response()->json([
            'message' => 'Tracking synced successfully',
            'data' => $shipment->fresh(['trackingEvents']),
        ]);
    }
}
