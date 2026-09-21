<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Fulfillment\Contracts\ShipmentRequest;
use Modules\Fulfillment\Models\FulfillmentProvider;
use Modules\Fulfillment\Models\Shipment;
use Modules\Fulfillment\Services\CarrierConfigService;
use Modules\Fulfillment\Services\FulfillmentService;
use Modules\Fulfillment\Services\TrackingService;
use Tests\TenantTestCase;

/**
 * Issue #146 (BLOCKER) — carrier integrations are simulated.
 *
 * None of DHL/FedEx/Aramex/GDEX implement real carrier APIs. Before the fix,
 * createShipment() fabricated random tracking numbers, cancelShipment()
 * unconditionally returned true, and getTracking() returned a RANDOM status via
 * array_rand() on every call — so the scheduled sync persisted non-deterministic
 * (and non-monotonic) statuses into shipments.
 *
 * Required behaviour now:
 *  1. Every carrier is explicitly marked simulated.
 *  2. Simulated operations are blocked unless sandbox use is enabled
 *     (defaults off in production).
 *  3. Fabricated tracking numbers are visibly prefixed SIM- and deterministic
 *     (no rand()/uniqid()/random_bytes()).
 *  4. Tracking is a deterministic, monotonic state machine: repeated calls are
 *     identical, and status only ever advances over time.
 *  5. The automated sync refuses to persist simulated data when simulation is
 *     disabled, and syncAllActiveShipments() is a no-op in that case.
 */
class HR146CarrierSimulationSafetyTest extends TenantTestCase
{
    private const STATUS_RANK = [
        'created' => 0,
        'picked_up' => 1,
        'in_transit' => 2,
        'out_for_delivery' => 3,
        'delivered' => 4,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext(Str::uuid()->toString());
        $this->assertTenantSchemaReady(['fulfillment_providers', 'shipments', 'tracking_events']);

        // Simulation is enabled outside production by default.
        config()->set('fulfillment.simulated_carriers_enabled', true);
        config()->set('fulfillment.tracking_sync_enabled', false);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function provider(string $carrierCode): FulfillmentProvider
    {
        return FulfillmentProvider::create([
            'tenant_id' => (string) Str::uuid(),
            'carrier_code' => $carrierCode,
            'name' => strtoupper($carrierCode).' Sandbox',
            'is_active' => true,
            'is_default' => false,
            'config' => ['sandbox' => true],
            'settings' => [],
            'sort_order' => 0,
        ]);
    }

    private function shipmentRequest(): ShipmentRequest
    {
        return new ShipmentRequest(
            shipper: ['name' => 'Sender Sdn Bhd', 'city' => 'Kuala Lumpur', 'country' => 'MY'],
            recipient: ['name' => 'Recipient Pte Ltd', 'city' => 'Singapore', 'country' => 'SG'],
            items: [['sku' => 'SKU-1', 'name' => 'Widget', 'quantity' => 1]],
            weight: 2.5,
            length: 30.0,
            width: 20.0,
            height: 10.0,
            orderId: 'SO-1001',
            orderType: 'sales_order',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. Every carrier is explicitly marked simulated
    // ─────────────────────────────────────────────────────────────────────

    public function test_every_bundled_carrier_is_marked_simulated(): void
    {
        $configService = app(CarrierConfigService::class);

        foreach (['dhl', 'fedex', 'aramex', 'gdex'] as $code) {
            $this->assertTrue(
                $configService->isCarrierSimulated($code),
                "Carrier {$code} must be marked simulated"
            );

            $carrier = $configService->getCarrier($this->provider($code));
            $this->assertTrue($carrier->isSimulatedCarrier(), "{$code}::isSimulatedCarrier() must be true");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2 + 3. Deterministic, explicitly-marked simulated shipment creation
    // ─────────────────────────────────────────────────────────────────────

    public function test_simulated_shipment_creation_is_deterministic_and_marked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 10:15:00'));

        $configService = app(CarrierConfigService::class);

        foreach (['dhl', 'fedex', 'aramex', 'gdex'] as $code) {
            $carrier = $configService->getCarrier($this->provider($code));
            $request = $this->shipmentRequest();

            $first = $carrier->createShipment($request);
            $second = $carrier->createShipment($request);

            $this->assertSame(
                $first->trackingNumber,
                $second->trackingNumber,
                "{$code}: identical requests must produce the same simulated tracking number"
            );
            $this->assertStringStartsWith(
                'SIM-'.strtoupper($code).'-',
                $first->trackingNumber,
                "{$code}: simulated tracking numbers must be visibly marked SIM-"
            );
            $this->assertSame($first->shipmentId, $second->shipmentId, "{$code}: shipment id must be deterministic");
            $this->assertSame($first->shippingCost, $second->shippingCost, "{$code}: cost must be deterministic");

            $this->assertNull($first->trackingUrl, "{$code}: no fake carrier tracking URL may be presented");
            $this->assertNull($first->labelUrl, "{$code}: no fake label URL may be presented");
            $this->assertTrue($first->metadata['simulated'] ?? false, "{$code}: response must be flagged simulated");
            $this->assertSame('simulated_estimate', $first->metadata['cost_source'] ?? null);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Tracking is deterministic and monotonic
    // ─────────────────────────────────────────────────────────────────────

    public function test_tracking_is_identical_across_repeated_calls(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 10:15:00'));

        $carrier = app(CarrierConfigService::class)->getCarrier($this->provider('dhl'));
        $trackingNumber = $carrier->createShipment($this->shipmentRequest())->trackingNumber;

        $first = $carrier->getTracking($trackingNumber);
        $second = $carrier->getTracking($trackingNumber);

        $this->assertSame($first->status, $second->status, 'Repeated tracking calls must not randomize status');
        $this->assertSame(count($first->events), count($second->events));
        $this->assertSame($first->statusDescription, $second->statusDescription);
        $this->assertSame($first->actualDelivery, $second->actualDelivery);
    }

    public function test_tracking_progresses_monotonically_over_time(): void
    {
        $createdAt = Carbon::parse('2026-09-21 10:15:00');
        Carbon::setTestNow($createdAt);

        $carrier = app(CarrierConfigService::class)->getCarrier($this->provider('dhl'));
        $trackingNumber = $carrier->createShipment($this->shipmentRequest())->trackingNumber;

        $observed = [];
        foreach ([0, 10, 30, 70] as $hours) {
            Carbon::setTestNow($createdAt->copy()->addHours($hours));
            $observed[$hours] = $carrier->getTracking($trackingNumber)->status;
        }

        $this->assertSame('picked_up', $observed[0]);
        $this->assertSame('in_transit', $observed[10]);
        $this->assertSame('out_for_delivery', $observed[30]);
        $this->assertSame('delivered', $observed[70]);

        // Rank must never decrease.
        $previous = -1;
        foreach ($observed as $hours => $status) {
            $rank = self::STATUS_RANK[$status];
            $this->assertGreaterThanOrEqual($previous, $rank, "Status went backwards at +{$hours}h ({$status})");
            $previous = $rank;
        }
    }

    public function test_delivered_tracking_response_reports_actual_delivery(): void
    {
        $createdAt = Carbon::parse('2026-09-21 10:15:00');
        Carbon::setTestNow($createdAt);

        $carrier = app(CarrierConfigService::class)->getCarrier($this->provider('gdex'));
        $trackingNumber = $carrier->createShipment($this->shipmentRequest())->trackingNumber;

        Carbon::setTestNow($createdAt->copy()->addHours(70));
        $response = $carrier->getTracking($trackingNumber);

        $this->assertSame('delivered', $response->status);
        $this->assertNotNull($response->actualDelivery);
        $this->assertNotEmpty($response->events);
    }

    public function test_non_simulation_tracking_number_is_rejected(): void
    {
        $carrier = app(CarrierConfigService::class)->getCarrier($this->provider('dhl'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a simulation tracking number');

        $carrier->getTracking('DHL12345678');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Simulation gate (production safety)
    // ─────────────────────────────────────────────────────────────────────

    public function test_simulated_operations_are_blocked_when_disabled(): void
    {
        config()->set('fulfillment.simulated_carriers_enabled', false);
        config()->set('fulfillment.tracking_sync_enabled', false);

        $carrier = app(CarrierConfigService::class)->getCarrier($this->provider('dhl'));

        try {
            $carrier->createShipment($this->shipmentRequest());
            $this->fail('createShipment() must be blocked when simulated carriers are disabled');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('simulated carrier', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $carrier->getTracking('SIM-DHL-202609211015-ABCDEF12');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. Sync job must not persist simulated data when disabled
    // ─────────────────────────────────────────────────────────────────────

    public function test_tracking_sync_refuses_simulated_data_when_disabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 10:15:00'));

        $provider = $this->provider('dhl');
        $shipment = app(FulfillmentService::class)->createShipment($provider, $this->shipmentRequest());
        $this->assertSame(Shipment::STATUS_CREATED, $shipment->status);

        config()->set('fulfillment.simulated_carriers_enabled', false);
        config()->set('fulfillment.tracking_sync_enabled', false);

        $trackingService = app(TrackingService::class);

        try {
            $trackingService->syncTracking($shipment);
            $this->fail('syncTracking() must refuse to persist simulated data when disabled');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to sync simulated tracking data', $e->getMessage());
        }

        $this->assertSame(0, $trackingService->syncAllActiveShipments(), 'The global sync must be a no-op when disabled');
        $this->assertSame(Shipment::STATUS_CREATED, $shipment->fresh()->status, 'Shipment status must be untouched');
        $this->assertSame(0, $shipment->trackingEvents()->count(), 'No fabricated tracking events may be written');
    }

    public function test_tracking_sync_persists_deterministic_monotonic_status(): void
    {
        $createdAt = Carbon::parse('2026-09-21 10:15:00');
        Carbon::setTestNow($createdAt);

        $provider = $this->provider('fedex');
        $shipment = app(FulfillmentService::class)->createShipment($provider, $this->shipmentRequest());

        $trackingService = app(TrackingService::class);

        // Two syncs at the same instant must persist the same status.
        $trackingService->syncTracking($shipment);
        $shipment->refresh();
        $this->assertSame('picked_up', $shipment->status);

        $trackingService->syncTracking($shipment);
        $shipment->refresh();
        $this->assertSame('picked_up', $shipment->status, 'Immediate re-sync must not change the status');

        // Advance time: status only ever moves forward.
        $previous = self::STATUS_RANK[$shipment->status];
        foreach ([10, 30, 70] as $hours) {
            Carbon::setTestNow($createdAt->copy()->addHours($hours));
            $trackingService->syncTracking($shipment);
            $shipment->refresh();

            $rank = self::STATUS_RANK[$shipment->status];
            $this->assertGreaterThanOrEqual($previous, $rank, "Status went backwards at +{$hours}h ({$shipment->status})");
            $previous = $rank;
        }

        $this->assertSame('delivered', $shipment->status);
        $this->assertNotNull($shipment->actual_delivery);
        $this->assertGreaterThan(0, $shipment->trackingEvents()->count());
    }

    public function test_cancelling_a_simulated_shipment_is_a_local_noop(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 10:15:00'));

        $carrier = app(CarrierConfigService::class)->getCarrier($this->provider('aramex'));
        $trackingNumber = $carrier->createShipment($this->shipmentRequest())->trackingNumber;

        $this->assertTrue($carrier->cancelShipment($trackingNumber), 'Simulated cancel is a local no-op');

        try {
            $carrier->cancelShipment('ARN-REAL-123');
            $this->fail('Cancelling a non-simulated reference must throw, not return true');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not implemented', $e->getMessage());
        }
    }
}
