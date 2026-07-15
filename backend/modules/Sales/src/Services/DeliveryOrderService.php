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
        private \Modules\Inventory\Services\InventoryService $inventoryService,
        private \Modules\Inventory\Services\StockReservationService $reservationService,
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

        $warehouseId = $data['warehouse_id'] ?? $order->warehouse_id;
        if (!$warehouseId) {
            throw new \Exception('Warehouse is required for delivery orders.');
        }

        return DB::transaction(function () use ($order, $data, $warehouseId) {
            $actorId = TenantUserHelper::requireTenantUserId($data['created_by'] ?? Auth::id());

            $deliveryOrder = DeliveryOrder::create([
                'sales_order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'warehouse_id' => $warehouseId,
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
                    'lot_number' => $lineData['lot_number'] ?? null,
                    'batch_number' => $lineData['batch_number'] ?? null,
                    'expiry_date' => $lineData['expiry_date'] ?? null,
                ]);
            }

            return $deliveryOrder->fresh(['lines.salesOrderLine', 'customer', 'salesOrder']);
        });
    }

    /**
     * Create a standalone delivery order (not linked to a sales order).
     * Lines specify product_id, quantity_delivered directly.
     */
    public function createStandaloneDeliveryOrder(array $data): DeliveryOrder
    {
        $customerId = $data['customer_id'] ?? null;
        if (!$customerId) {
            throw new \Exception('customer_id is required for standalone delivery orders.');
        }

        $lines = $data['lines'] ?? [];
        if (empty($lines)) {
            throw new \Exception('At least one line item is required.');
        }

        $warehouseId = $data['warehouse_id'] ?? null;
        if (!$warehouseId) {
            throw new \Exception('Warehouse is required for delivery orders.');
        }

        return DB::transaction(function () use ($data, $customerId, $warehouseId, $lines) {
            $actorId = TenantUserHelper::requireTenantUserId($data['created_by'] ?? Auth::id());

            $deliveryOrder = DeliveryOrder::create([
                'sales_order_id' => null,
                'customer_id' => $customerId,
                'warehouse_id' => $warehouseId,
                'status' => 'draft',
                'delivery_date' => $data['delivery_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'carrier' => $data['carrier'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            foreach ($lines as $lineData) {
                $quantityDelivered = (float) ($lineData['quantity_delivered'] ?? $lineData['quantity'] ?? 0);
                if ($quantityDelivered <= 0) {
                    throw new \Exception('Each line must have a quantity greater than 0.');
                }

                DeliveryOrderLine::create([
                    'delivery_order_id' => $deliveryOrder->id,
                    'sales_order_line_id' => null,
                    'product_id' => $lineData['product_id'] ?? null,
                    'description' => $lineData['description'] ?? null,
                    'quantity_ordered' => $quantityDelivered,
                    'quantity_delivered' => $quantityDelivered,
                    'unit' => $lineData['unit'] ?? 'pcs',
                    'lot_number' => $lineData['lot_number'] ?? null,
                    'batch_number' => $lineData['batch_number'] ?? null,
                    'expiry_date' => $lineData['expiry_date'] ?? null,
                ]);
            }

            return $deliveryOrder->fresh(['lines.product', 'customer']);
        });
    }

    /**
     * Confirm a delivery order:
     * 1. Updates quantity_delivered on each sales order line
     * 2. Deducts stock from warehouse inventory
     * 3. Fulfils corresponding stock reservations
     */
    public function confirmDeliveryOrder(DeliveryOrder $deliveryOrder): DeliveryOrder
    {
        if (!$deliveryOrder->isDraft()) {
            throw new \Exception('Only draft delivery orders can be confirmed.');
        }

        if (!$deliveryOrder->warehouse_id) {
            throw new \Exception('Cannot confirm a delivery order without a warehouse.');
        }

        return DB::transaction(function () use ($deliveryOrder) {
            $actorId = TenantUserHelper::requireTenantUserId(Auth::id());
            $warehouseId = (int) $deliveryOrder->warehouse_id;

            $deliveryOrder->load(['lines.product', 'lines.salesOrderLine']);

            // Only update SO lines for linked delivery orders
            if ($deliveryOrder->sales_order_id) {
                foreach ($deliveryOrder->lines as $line) {
                    if ($line->salesOrderLine) {
                        $soLine = $line->salesOrderLine;
                        $newDelivered = (float) $soLine->quantity_delivered + (float) $line->quantity_delivered;
                        $soLine->update(['quantity_delivered' => $newDelivered]);
                    }
                }
            }

            // Deduct stock for each line with a stock-tracked product
            foreach ($deliveryOrder->lines as $line) {
                if (!$line->product_id) {
                    continue;
                }

                $product = $line->product;
                if (!$product || !(bool) ($product->track_inventory ?? false)) {
                    continue;
                }

                $qty = (int) round((float) $line->quantity_delivered);
                if ($qty <= 0) {
                    continue;
                }

                $idempotencyKey = "do-confirm-{$deliveryOrder->id}-line-{$line->id}";

                $this->inventoryService->adjustStock(
                    productId: $line->product_id,
                    warehouseId: $warehouseId,
                    quantity: $qty,
                    type: 'sale',
                    reference: $deliveryOrder->delivery_order_number,
                    referenceType: 'DeliveryOrder',
                    referenceId: $deliveryOrder->id,
                    notes: "Stock deducted for delivery order {$deliveryOrder->delivery_order_number}",
                    batchNumber: $line->lot_number ?? $line->batch_number,
                    expiryDate: $line->expiry_date ? new \DateTime($line->expiry_date) : null,
                    idempotencyKey: $idempotencyKey,
                );

                // Fulfil the corresponding stock reservation (if linked to a SO)
                if ($deliveryOrder->sales_order_id && $line->salesOrderLine) {
                    $this->fulfilReservationForLine(
                        $line->product_id,
                        $warehouseId,
                        $qty,
                        (int) $deliveryOrder->sales_order_id,
                        (int) $line->salesOrderLine->id,
                    );
                }
            }

            $deliveryOrder->update([
                'status' => 'confirmed',
                'confirmed_by' => $actorId,
            ]);

            return $deliveryOrder->fresh(['lines.salesOrderLine', 'lines.product', 'customer', 'salesOrder']);
        });
    }

    /**
     * Fulfil (or partially fulfil) the stock reservation linked to a sales order line.
     */
    private function fulfilReservationForLine(int $productId, int $warehouseId, int $qty, int $salesOrderId, int $salesOrderLineId): void
    {
        $reservation = \Modules\Inventory\Models\StockReservation::where([
            'source_document_type' => 'SalesOrder',
            'source_document_id' => $salesOrderId,
            'source_document_line_id' => $salesOrderLineId,
        ])->whereIn('status', ['open', 'allocated'])->first();

        if ($reservation) {
            $fulfilQty = min($qty, $reservation->remaining_quantity);
            if ($fulfilQty > 0) {
                $this->reservationService->fulfil($reservation, $fulfilQty);
            }
        }
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
