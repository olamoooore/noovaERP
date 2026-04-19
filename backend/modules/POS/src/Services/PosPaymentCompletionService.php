<?php

namespace Modules\POS\Services;

use App\Models\StockMovement;
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
        $posOrder->loadMissing('items.variant', 'items.product', 'terminal.warehouse');

        $existingPayment = PosPayment::where('pos_order_id', $posOrder->id)
            ->where('reference', $reference)
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

        if ($warehouseId) {
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
        }

        $posOrder->update([
            'status' => 'paid',
            'paid_at' => $posOrder->paid_at ?? now(),
            'journal_entry_id' => $entry->id,
            'receipt_number' => $posOrder->receipt_number ?: PosOrder::generateReceiptNumber(),
        ]);

        return $posOrder->fresh(['items', 'payments', 'journalEntry', 'customer']);
    }

    private function resolveWarehouseId(PosOrder $posOrder): ?int
    {
        $warehouseId = $posOrder->terminal?->warehouse_id;

        if (!$warehouseId) {
            Log::warning('POS order has no terminal warehouse — stock deduction skipped', [
                'pos_order_id' => $posOrder->id,
            ]);
            return null;
        }

        return (int) $warehouseId;
    }
}
