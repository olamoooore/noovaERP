<?php

declare(strict_types=1);

namespace Tests\Feature\HostileReview;

use Modules\Fulfillment\Models\Shipment;
use Modules\Sales\Models\DeliveryOrder;
use Tests\Feature\HR147DeliveryOrderShipmentIntegrationTest;

/**
 * Hostile review repro (#147): creating a shipment must be idempotent per
 * delivery order and refused for cancelled orders.
 */
class HRV147DuplicateShipmentTest extends HR147DeliveryOrderShipmentIntegrationTest
{
    public function test_repeat_and_cancelled_shipment_creation(): void
    {
        $bind = fn (\Closure $f) => \Closure::bind($f, $this, HR147DeliveryOrderShipmentIntegrationTest::class)();
        $user = $bind(fn () => $this->user);
        $order = $bind(fn () => $this->deliveryOrder());

        $first = $this->actingAs($user)->postJson("/api/v1/fulfillment/delivery-orders/{$order->id}/shipment")->getStatusCode();
        $second = $this->actingAs($user)->postJson("/api/v1/fulfillment/delivery-orders/{$order->id}/shipment")->getStatusCode();
        $count = Shipment::where('order_id', (string) $order->id)->count();

        $cancelled = $bind(fn () => $this->deliveryOrder());
        DeliveryOrder::whereKey($cancelled->id)->update(['status' => 'cancelled']);
        $cancelledStatus = $this->actingAs($user)->postJson("/api/v1/fulfillment/delivery-orders/{$cancelled->id}/shipment")->getStatusCode();

        fwrite(STDERR, sprintf("\n[HRV147] first=%d second=%d shipments_for_same_DO=%d cancelled_DO_shipment_http=%d\n", $first, $second, $count, $cancelledStatus));

        $this->assertSame(1, $count, 'one delivery order must not produce duplicate shipments');
    }
}
