<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

class DeliveryOrderService
{
    public function __construct(
        private OrderLifecycleService $lifecycleService,
    ) {}

    /**
     * Create a delivery order from a confirmed/allocated sales order.
     * Lines specify quantity_delivered per sales order line (partial deliveries supported).
     */
    public function createDeliveryOrder(SalesOrder $order, array $data): DeliveryOrder
    {
        if (!$order->isConfirmed() && !$order->isAllocated() && !$order->isFulfilled()) {
            throw new \Exception('Only confirmed, allocated, or fulfilled orders can have delivery orders.');
        }

        return DB::transaction(function () use ($order, $data) {
            $actorId = TenantUserHelper::requireTenantUserId($data['created_by'] ?? Auth::id());

            $deliveryOrder = DeliveryOrder::create([
                'sales_order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'warehouse_id' => $order->warehouse_id,
                'status' => 'draft',
                'delivery_date' => $data['delivery_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'carrier' => $data['carrier'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            $lines = $data['lines'] ?? [];
            foreach ($lines as $lineData) {
                $soLineId = $lineData['sales_order_line_id'];
                $soLine = SalesOrderLine::findOrFail($soLineId);

                if ((int) $soLine->sales_order_id !== (int) $order->id) {
                    throw new \Exception("Sales order line {$soLineId} does not belong to order #{$order->id}.");
                }

                $quantityDelivered = (float) ($lineData['quantity_delivered'] ?? 0);
                $remainingForThisLine = (float) $soLine->quantity
                    - (float) $soLine->quantity_delivered
                    - (float) $soLine->quantity_cancelled;

                if ($quantityDelivered > $remainingForThisLine + 0.0001) {
                    throw new \Exception(
                        "Cannot deliver {$quantityDelivered} of line #{$soLineId}. " .
                        "Only {$remainingForThisLine} remaining to deliver."
                    );
                }

                DeliveryOrderLine::create([
                    'delivery_order_id' => $deliveryOrder->id,
                    'sales_order_line_id' => $soLine->id,
                    'product_id' => $soLine->product_id,
                    'description' => $lineData['description'] ?? $soLine->description,
                    'quantity_ordered' => (float) $soLine->quantity,
                    'quantity_delivered' => $quantityDelivered,
                    'unit' => $lineData['unit'] ?? $soLine->unit,
                ]);
            }

            return $deliveryOrder->fresh(['lines.salesOrderLine', 'customer', 'salesOrder']);
        });
    }

    /**
     * Confirm a delivery order: updates quantity_delivered on each sales order line.
     */
    public function confirmDeliveryOrder(DeliveryOrder $deliveryOrder): DeliveryOrder
    {
        if (!$deliveryOrder->isDraft()) {
            throw new \Exception('Only draft delivery orders can be confirmed.');
        }

        return DB::transaction(function () use ($deliveryOrder) {
            $actorId = TenantUserHelper::requireTenantUserId(Auth::id());

            foreach ($deliveryOrder->lines as $line) {
                $soLine = $line->salesOrderLine;
                $newDelivered = (float) $soLine->quantity_delivered + (float) $line->quantity_delivered;

                $soLine->update(['quantity_delivered' => $newDelivered]);
            }

            $deliveryOrder->update([
                'status' => 'confirmed',
                'confirmed_by' => $actorId,
            ]);

            return $deliveryOrder->fresh(['lines.salesOrderLine', 'customer', 'salesOrder']);
        });
    }

    /**
     * Cancel a delivery order (only draft can be cancelled).
     */
    public function cancelDeliveryOrder(DeliveryOrder $deliveryOrder, ?string $reason = null): DeliveryOrder
    {
        if (!$deliveryOrder->isDraft()) {
            throw new \Exception('Only draft delivery orders can be cancelled.');
        }

        $deliveryOrder->update(['status' => 'cancelled']);

        return $deliveryOrder->fresh(['lines.salesOrderLine', 'customer', 'salesOrder']);
    }

    /**
     * Check if all lines on a sales order have been fully delivered.
     */
    public function isFullyDelivered(SalesOrder $order): bool
    {
        $order->loadMissing('lines');

        foreach ($order->lines as $line) {
            $delivered = (float) $line->quantity_delivered;
            $ordered = (float) $line->quantity;
            $cancelled = (float) $line->quantity_cancelled;

            if ($delivered < $ordered - $cancelled - 0.0001) {
                return false;
            }
        }

        return true;
    }
}
