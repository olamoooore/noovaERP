<?php

namespace Modules\POS\Services;

use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Services\InventoryService;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;

class PosPaymentCompletionService
{
    public function __construct(
        private readonly PosPostingService $posPostingService,
        private readonly InventoryService $inventoryService,
    ) {}

    public function complete(
        PosOrder $posOrder,
        float $amount,
        string $paymentMethod,
        ?string $reference = null,
        ?int $receivedBy = null,
        ?string $notes = null,
    ): PosOrder {
        return DB::transaction(function () use ($posOrder, $amount, $paymentMethod, $reference, $receivedBy, $notes) {
            $posOrder = PosOrder::whereKey($posOrder->id)->lockForUpdate()->firstOrFail();
            
            $posOrder->loadMissing('items.variant', 'items.product', 'terminal.warehouse');

            if ($posOrder->status === 'paid' || $posOrder->paid_at) {
                Log::info('Skipping already-paid order in completion', [
                    'order_id' => $posOrder->id,
                ]);
                return $posOrder;
            }

            // TASK POS-012: Fix cash payment idempotency
            // For cash payments (null reference), use pos_order_id as the idempotency key
            // For reference-based payments, use the reference as the idempotency key
            $existingPayment = PosPayment::where('pos_order_id', $posOrder->id)
                ->where(function ($q) use ($reference, $paymentMethod) {
                    if ($reference === null) {
                        // For cash/null-reference payments, match by order_id + method
                        // This ensures one cash payment per order (idempotent on order level)
                        $q->whereNull('reference')
                          ->where('method', $paymentMethod);
                    } else {
                        // For reference-based payments, match by reference (existing behavior)
                        $q->where('reference', $reference);
                    }
                })
                ->first();

            if (!$existingPayment) {
                PosPayment::create([
                    'pos_order_id' => $posOrder->id,
                    'method' => $paymentMethod,
                    'amount' => $amount,
                    'reference' => $reference,
                    'notes' => $notes,
                    'received_by' => TenantUserHelper::requireTenantUserId($receivedBy ?? $posOrder->created_by),
                ]);
            }

            $entry = $this->posPostingService->postPaidOrder(
                $posOrder,
                $amount,
                $paymentMethod
            );

            $warehouseId = $this->resolveWarehouseId($posOrder);

            foreach ($posOrder->items as $item) {
                    $productId = $item->variant ? $item->variant->product_id : $item->product_id;
                    $trackInventory = $item->variant
                        ? (bool) $item->variant->track_inventory
                        : (bool) ($item->product?->track_inventory ?? false);

                    if (!$productId || !$trackInventory) {
                        continue;
                    }

                    $alreadyDeducted = (int) StockMovement::where('reference_type', 'PosOrder')
                        ->where('reference_id', $posOrder->id)
                        ->where('type', 'sale')
                        ->where('product_id', $productId)
                        ->sum('quantity');
                    $remainingToDeduct = (int) $item->quantity - $alreadyDeducted;

                    if ($remainingToDeduct <= 0) {
                        continue;
                    }

                    $this->inventoryService->adjustStock(
                        productId: $productId,
                        warehouseId: $warehouseId,
                        quantity: $remainingToDeduct,
                        type: 'sale',
                        reference: $posOrder->order_number,
                        referenceType: 'PosOrder',
                        referenceId: $posOrder->id,
                    );
                }
            $posOrder->update([
                'status' => 'paid',
                'paid_at' => now(),
                'journal_entry_id' => $entry?->id,
            ]);

            return $posOrder->fresh();
        });
    }

    /**
     * Backfill GL posting, payment record, and stock deduction for an order that is
     * already marked as paid (e.g. imported or repaired orders). Unlike complete(),
     * this method does NOT change order status — it only fills in missing artefacts.
     */
    public function backfill(
        PosOrder $posOrder,
        string $paymentMethod,
        string $reference,
        ?int $receivedBy = null,
        ?string $notes = null,
    ): PosOrder {
        return DB::transaction(function () use ($posOrder, $paymentMethod, $reference, $receivedBy, $notes) {
            $posOrder = PosOrder::whereKey($posOrder->id)->lockForUpdate()->firstOrFail();
            $posOrder->loadMissing('items.variant', 'items.product', 'terminal.warehouse');

            // GL posting — only if not already posted
            if (!$posOrder->journal_entry_id) {
                $entry = $this->posPostingService->postPaidOrder($posOrder, $posOrder->total, $paymentMethod);
                if (!$entry) {
                    throw new \RuntimeException("GL posting returned null for order {$posOrder->order_number}");
                }
                $posOrder->update(['journal_entry_id' => $entry->id]);
                $posOrder->refresh();
            }

            // Payment record — idempotent on reference
            $existingPayment = PosPayment::where('pos_order_id', $posOrder->id)
                ->where('reference', $reference)
                ->first();

            if (!$existingPayment) {
                PosPayment::create([
                    'pos_order_id' => $posOrder->id,
                    'method'       => $paymentMethod,
                    'amount'       => (float) $posOrder->total,
                    'reference'    => $reference,
                    'notes'        => $notes,
                    'received_by'  => TenantUserHelper::requireTenantUserId($receivedBy ?? $posOrder->created_by),
                ]);
            }

            // Stock deduction — idempotent via already-deducted check
            $warehouseId = $this->resolveWarehouseId($posOrder);

            foreach ($posOrder->items as $item) {
                $productId = $item->variant ? $item->variant->product_id : $item->product_id;
                $trackInventory = $item->variant
                    ? (bool) $item->variant->track_inventory
                    : (bool) ($item->product?->track_inventory ?? false);

                if (!$productId || !$trackInventory) {
                    continue;
                }

                $alreadyDeducted = (int) StockMovement::where('reference_type', 'PosOrder')
                    ->where('reference_id', $posOrder->id)
                    ->where('type', 'sale')
                    ->where('product_id', $productId)
                    ->sum('quantity');

                $remainingToDeduct = (int) $item->quantity - $alreadyDeducted;

                if ($remainingToDeduct <= 0) {
                    continue;
                }

                $this->inventoryService->adjustStock(
                    productId: $productId,
                    warehouseId: $warehouseId,
                    quantity: $remainingToDeduct,
                    type: 'sale',
                    reference: $posOrder->order_number,
                    referenceType: 'PosOrder',
                    referenceId: $posOrder->id,
                );
            }

            return $posOrder->fresh();
        });
    }

    private function resolveWarehouseId(PosOrder $posOrder): int
    {
        $warehouseId = $posOrder->terminal?->warehouse_id;

        if (!$warehouseId) {
            Log::error('POS order has no terminal warehouse — payment blocked', [
                'pos_order_id' => $posOrder->id,
                'terminal_id' => $posOrder->terminal_id,
            ]);

            throw new \RuntimeException(
                "Terminal {$posOrder->terminal_id} has no warehouse assigned. " .
                "Cannot complete sale without warehouse. " .
                "Please assign a warehouse to the terminal."
            );
        }

        return (int) $warehouseId;
    }
}