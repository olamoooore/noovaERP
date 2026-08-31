<?php

namespace Modules\Sales\Services;

use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockReservationService;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

class DeliveryOrderService
{
    public function __construct(
        private OrderLifecycleService $lifecycleService,
        private InventoryService $inventoryService,
        private StockReservationService $reservationService,
    ) {}

    // ════════════════════════════════════════════════════════════════════════
    //  CREATION
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Create a delivery order from a confirmed/allocated/fulfilled sales order.
     */
    public function createDeliveryOrder(SalesOrder $order, array $data): DeliveryOrder
    {
        if (! $order->isConfirmed() && ! $order->isAllocated() && ! $order->isFulfilled() && $order->status !== 'invoiced') {
            throw new \Exception('Only confirmed, allocated, fulfilled, or invoiced orders can have delivery orders.');
        }

        $warehouseId = $data['warehouse_id'] ?? $order->warehouse_id ?? Warehouse::getDefault()?->id;
        if (! $warehouseId) {
            throw new \Exception('Warehouse is required for delivery orders.');
        }

        // Auto-resolve quotation_id from the linked invoice if not provided
        $quotationId = $data['quotation_id'] ?? null;
        $invoiceId = $data['invoice_id'] ?? null;
        if ($quotationId === null && $invoiceId !== null) {
            $invoice = \App\Models\Invoice::find($invoiceId);
            if ($invoice && $invoice->quotation_id) {
                $quotationId = $invoice->quotation_id;
            }
        }

        return DB::transaction(function () use ($order, $data, $warehouseId, $quotationId, $invoiceId) {
            $actorId = TenantUserHelper::requireTenantUserId($data['created_by'] ?? Auth::id());

            $deliveryOrder = DeliveryOrder::create([
                'sales_order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'warehouse_id' => $warehouseId,
                'status' => 'draft',
                'source' => 'sales_order',
                'delivery_date' => $data['delivery_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'carrier' => $data['carrier'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'shipping_address_overridden' => ! empty($data['shipping_address']),
                'po_number' => $data['po_number'] ?? null,
                'salesperson' => $data['salesperson'] ?? null,
                'attention_to' => $data['attention_to'] ?? null,
                'invoice_id' => $invoiceId,
                'quotation_id' => $quotationId,
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
                        "Cannot deliver {$quantityDelivered} of line #{$soLineId}. "
                        ."Only {$remainingForThisLine} remaining to deliver."
                    );
                }

                DeliveryOrderLine::create([
                    'delivery_order_id' => $deliveryOrder->id,
                    'sales_order_line_id' => $soLine->id,
                    'product_id' => $soLine->product_id,
                    'model_number' => $lineData['model_number'] ?? $soLine->model_number ?? null,
                    'description' => $lineData['description'] ?? $soLine->description,
                    'product_notes' => $lineData['product_notes'] ?? $soLine->product_notes ?? null,
                    'patient_name' => $lineData['patient_name'] ?? null,
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
     */
    public function createStandaloneDeliveryOrder(array $data): DeliveryOrder
    {
        $customerId = $data['customer_id'] ?? null;
        if (! $customerId) {
            throw new \Exception('customer_id is required for standalone delivery orders.');
        }

        $lines = $data['lines'] ?? [];
        if (empty($lines)) {
            throw new \Exception('At least one line item is required.');
        }

        $warehouseId = $data['warehouse_id'] ?? Warehouse::getDefault()?->id;
        if (! $warehouseId) {
            throw new \Exception('Warehouse is required for delivery orders.');
        }

        $maxRetries = 5;
        $lockKey = crc32('delivery_order_create');

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                return DB::transaction(function () use ($data, $customerId, $warehouseId, $lines, $lockKey) {
                    DB::select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);
                    return $this->createStandaloneDeliveryOrderInsideTransaction($data, $customerId, $warehouseId, $lines);
                });
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'delivery_orders_delivery_order_number_unique')) {
                    if ($attempt < $maxRetries - 1) {
                        cache()->forget('document_numbering_delivery_order');
                        Log::warning('DO number collision on insert, retrying', [
                            'attempt' => $attempt + 1,
                        ]);
                        continue;
                    }
                }
                throw $e;
            }
        }

        return DB::transaction(function () use ($data, $customerId, $warehouseId, $lines, $lockKey) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);
            return $this->createStandaloneDeliveryOrderInsideTransaction($data, $customerId, $warehouseId, $lines);
        });
    }

    private function createStandaloneDeliveryOrderInsideTransaction(array $data, int $customerId, int $warehouseId, array $lines): DeliveryOrder
    {
        // Auto-resolve quotation_id from the linked invoice if not provided
        $quotationId = $data['quotation_id'] ?? null;
        $invoiceId = $data['invoice_id'] ?? null;
        if ($quotationId === null && $invoiceId !== null) {
            $invoice = \App\Models\Invoice::find($invoiceId);
            if ($invoice && $invoice->quotation_id) {
                $quotationId = $invoice->quotation_id;
            }
        }

        return DB::transaction(function () use ($data, $customerId, $warehouseId, $lines, $quotationId, $invoiceId) {
            $actorId = TenantUserHelper::requireTenantUserId($data['created_by'] ?? Auth::id());

            $deliveryOrder = DeliveryOrder::create([
                'sales_order_id' => null,
                'customer_id' => $customerId,
                'warehouse_id' => $warehouseId,
                'status' => 'draft',
                'source' => $data['source'] ?? 'standalone',
                'external_reference' => $data['external_reference'] ?? null,
                'delivery_date' => $data['delivery_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'carrier' => $data['carrier'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'shipping_address_overridden' => ! empty($data['shipping_address']),
                'po_number' => $data['po_number'] ?? null,
                'salesperson' => $data['salesperson'] ?? null,
                'attention_to' => $data['attention_to'] ?? null,
                'invoice_id' => $invoiceId,
                'quotation_id' => $quotationId,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            foreach ($lines as $lineData) {
                $lineType = $lineData['line_type'] ?? 'product';
                $quantityDelivered = (float) ($lineData['quantity_delivered'] ?? $lineData['quantity'] ?? 0);
                if ($lineType === 'product' && $quantityDelivered <= 0) {
                    throw new \Exception('Each line must have a quantity greater than 0.');
                }

                // Check available stock for stock-tracked products
                $productId = $lineData['product_id'] ?? null;
                if ($productId) {
                    $product = \App\Models\Product::find($productId);
                    if ($product && (bool) ($product->track_inventory ?? false)) {
                        $stockLevel = $this->inventoryService->getStockLevel((int) $productId, (int) $warehouseId);
                        $available = $stockLevel ? (float) $stockLevel->quantity : 0;
                        if ($quantityDelivered > $available) {
                            throw new \Exception(
                                "Insufficient stock for {$product->name}. "
                                ."Available: {$available}, requested: {$quantityDelivered}."
                            );
                        }
                    }
                }

                DeliveryOrderLine::create([
                    'line_type' => $lineType,
                    'sequence' => $lineData['sequence'] ?? 0,
                    'delivery_order_id' => $deliveryOrder->id,
                    'sales_order_line_id' => null,
                    'product_id' => $lineData['product_id'] ?? null,
                    'model_number' => $lineData['model_number'] ?? null,
                    'description' => $lineData['description'] ?? null,
                    'product_notes' => $lineData['product_notes'] ?? null,
                    'patient_name' => $lineData['patient_name'] ?? null,
                    'quantity_ordered' => $quantityDelivered,
                    'quantity_delivered' => $quantityDelivered,
                    'unit' => $lineData['unit'] ?? 'pcs',
                    'unit_price' => $lineData['unit_price'] ?? null,
                    'line_total' => isset($lineData['unit_price']) ? ($lineData['unit_price'] * $quantityDelivered) : null,
                    'lot_number' => $lineData['lot_number'] ?? null,
                    'batch_number' => $lineData['batch_number'] ?? null,
                    'expiry_date' => $lineData['expiry_date'] ?? null,
                ]);
            }

            // Calculate total from line items
            $total = $deliveryOrder->lines()->sum('line_total');
            if ($total > 0) {
                $deliveryOrder->update(['total' => $total]);
            }

            return $deliveryOrder->fresh(['lines.product', 'customer']);
        });
    }

    // ════════════════════════════════════════════════════════════════════════
    //  STATE TRANSITIONS — per spec §5.1
    // ════════════════════════════════════════════════════════════════════════

    /**
     * DRAFT → CONFIRMED
     * Validates customer, warehouse, and lines. No stock effect.
     */
    public function confirmDeliveryOrder(DeliveryOrder $deliveryOrder): DeliveryOrder
    {
        if (! $deliveryOrder->isDraft()) {
            throw new \Exception('Only draft delivery orders can be confirmed.');
        }

        if (! $deliveryOrder->warehouse_id) {
            throw new \Exception('Warehouse is required before this delivery order can be confirmed.');
        }

        $actorId = TenantUserHelper::requireTenantUserId(Auth::id());

        $deliveryOrder->update([
            'status' => 'confirmed',
            'confirmed_by' => $actorId,
        ]);

        return $deliveryOrder->fresh(['lines.salesOrderLine', 'lines.product', 'customer', 'salesOrder']);
    }

    /**
     * CONFIRMED → DONE
     * Posts inventory movements (stock deduction), updates SO line delivered_qty,
     * fulfils stock reservations. Atomic — all or nothing.
     */
    public function completeDeliveryOrder(DeliveryOrder $deliveryOrder, ?string $idempotencyKey = null): DeliveryOrder
    {
        // Allow completing from DRAFT for convenience (auto-confirms first)
        if ($deliveryOrder->isDraft()) {
            $deliveryOrder = $this->confirmDeliveryOrder($deliveryOrder);
        }

        if (! $deliveryOrder->isConfirmed()) {
            // Idempotency: if already DONE, return as-is
            if ($deliveryOrder->isDone()) {
                return $deliveryOrder->fresh(['lines.salesOrderLine', 'lines.product', 'customer', 'salesOrder']);
            }
            throw new \Exception('Only confirmed delivery orders can be completed.');
        }

        if (! $deliveryOrder->warehouse_id) {
            throw new \Exception('Cannot complete a delivery order without a warehouse.');
        }

        return DB::transaction(function () use ($deliveryOrder, $idempotencyKey) {
            $actorId = TenantUserHelper::requireTenantUserId(Auth::id());
            $warehouseId = (int) $deliveryOrder->warehouse_id;

            $deliveryOrder->load(['lines.product', 'lines.salesOrderLine']);

            // Update SO line delivered quantities (for linked DOs) — always, before stock deduction
            if ($deliveryOrder->sales_order_id) {
                foreach ($deliveryOrder->lines as $line) {
                    if ($line->salesOrderLine) {
                        $soLine = $line->salesOrderLine;
                        $newDelivered = (float) $soLine->quantity_delivered + (float) $line->quantity_delivered;
                        $soLine->update(['quantity_delivered' => $newDelivered]);
                    }
                }
            }

            // Deduct stock for each stock-tracked line
            foreach ($deliveryOrder->lines as $line) {
                if (! $line->product_id) {
                    continue;
                }

                $product = $line->product;
                if (! $product || ! (bool) ($product->track_inventory ?? false)) {
                    continue;
                }

                $qty = (int) round((float) $line->quantity_delivered);
                if ($qty <= 0) {
                    continue;
                }

                $key = $idempotencyKey ?: "do-complete-{$deliveryOrder->id}-line-{$line->id}";

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
                    idempotencyKey: $key,
                );

                // Fulfil corresponding stock reservation (if linked to SO)
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
                'status' => 'done',
                'completed_at' => now(),
                'completed_by' => $actorId,
            ]);

            return $deliveryOrder->fresh(['lines.salesOrderLine', 'lines.product', 'customer', 'salesOrder']);
        });
    }

    /**
     * DONE → CANCELLED (Reversal)
     * Posts compensating inbound stock movements, subtracts delivered quantities
     * from linked Sales Order lines. Never deletes the original.
     */
    public function reverseDeliveryOrder(DeliveryOrder $deliveryOrder, string $reason): DeliveryOrder
    {
        if (! $deliveryOrder->isDone()) {
            throw new \Exception('Only completed delivery orders can be reversed.');
        }

        return DB::transaction(function () use ($deliveryOrder, $reason) {
            $actorId = TenantUserHelper::requireTenantUserId(Auth::id());
            $warehouseId = (int) $deliveryOrder->warehouse_id;

            $deliveryOrder->load(['lines.product', 'lines.salesOrderLine']);

            foreach ($deliveryOrder->lines as $line) {
                if (! $line->product_id) {
                    continue;
                }

                $product = $line->product;
                $isStockTracked = $product && (bool) ($product->track_inventory ?? false);

                if ($isStockTracked) {
                    $qty = (int) round((float) $line->quantity_delivered);
                    if ($qty <= 0) {
                        continue;
                    }

                    // Compensating inbound movement (restock)
                    $this->inventoryService->adjustStock(
                        productId: $line->product_id,
                        warehouseId: $warehouseId,
                        quantity: $qty,
                        type: 'return',
                        reference: "REVERSAL-{$deliveryOrder->delivery_order_number}",
                        referenceType: 'DeliveryOrder',
                        referenceId: $deliveryOrder->id,
                        notes: "Reversal: {$reason}",
                        idempotencyKey: "do-reverse-{$deliveryOrder->id}-line-{$line->id}",
                    );
                }

                // Subtract from SO line delivered_qty (always, regardless of stock tracking)
                if ($deliveryOrder->sales_order_id && $line->salesOrderLine) {
                    $soLine = $line->salesOrderLine;
                    $newDelivered = max(0, (float) $soLine->quantity_delivered - (float) $line->quantity_delivered);
                    $soLine->update(['quantity_delivered' => $newDelivered]);
                }
            }

            $deliveryOrder->update([
                'status' => 'cancelled',
                'notes' => $deliveryOrder->notes
                    ? $deliveryOrder->notes."\n[REVERSED] {$reason}"
                    : "[REVERSED] {$reason}",
            ]);

            Log::info('Delivery order reversed', [
                'do_id' => $deliveryOrder->id,
                'do_number' => $deliveryOrder->delivery_order_number,
                'reason' => $reason,
                'actor' => $actorId,
            ]);

            return $deliveryOrder->fresh(['lines.salesOrderLine', 'lines.product', 'customer', 'salesOrder']);
        });
    }

    /**
     * DRAFT|CONFIRMED → CANCELLED (pre-completion cancellation — no stock effect)
     */
    public function cancelDeliveryOrder(DeliveryOrder $deliveryOrder, ?string $reason = null): DeliveryOrder
    {
        if ($deliveryOrder->isDone()) {
            throw new \Exception('Cannot cancel a completed delivery order. Use reversal instead.');
        }

        if ($deliveryOrder->isCancelled()) {
            throw new \Exception('Delivery order is already cancelled.');
        }

        $deliveryOrder->update([
            'status' => 'cancelled',
            'notes' => $reason
                ? ($deliveryOrder->notes ? $deliveryOrder->notes."\n[CANCELLED] {$reason}" : "[CANCELLED] {$reason}")
                : $deliveryOrder->notes,
        ]);

        return $deliveryOrder->fresh(['lines.salesOrderLine', 'customer', 'salesOrder']);
    }

    /**
     * CANCELLED → DRAFT
     * Allows re-editing and re-confirming a cancelled delivery order.
     * Blocks if the DO has been invoiced (stock was already deducted via invoice flow).
     */
    public function resetToDraft(DeliveryOrder $deliveryOrder): DeliveryOrder
    {
        if (! $deliveryOrder->isCancelled()) {
            throw new \Exception('Only cancelled delivery orders can be reset to draft.');
        }

        if ($deliveryOrder->invoice_id) {
            throw new \Exception('Cannot reset a delivery order that is linked to an invoice. Create a new delivery order instead.');
        }

        $deliveryOrder->update([
            'status' => 'draft',
            'notes' => $deliveryOrder->notes
                ? $deliveryOrder->notes."\n[RESET TO DRAFT]"
                : '[RESET TO DRAFT]',
        ]);

        return $deliveryOrder->fresh(['lines.salesOrderLine', 'customer', 'salesOrder']);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ════════════════════════════════════════════════════════════════════════

    private function fulfilReservationForLine(int $productId, int $warehouseId, int $qty, int $salesOrderId, int $salesOrderLineId): void
    {
        $reservation = StockReservation::where([
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
