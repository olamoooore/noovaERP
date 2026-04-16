<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Services\InventoryService;
use Illuminate\Support\Str;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class GoodsReceiptService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    public function create(array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($data) {
            $purchaseOrder = PurchaseOrder::findOrFail($data['purchase_order_id']);

            if (!$purchaseOrder->canReceive()) {
                throw new \InvalidArgumentException("Purchase order '{$purchaseOrder->po_number}' cannot receive goods. Status: {$purchaseOrder->status}");
            }

            $grn = GoodsReceipt::create([
                'grn_number' => $this->generateGrnNumber(),
                'purchase_order_id' => $purchaseOrder->id,
                'warehouse_id' => $data['warehouse_id'] ?? $purchaseOrder->warehouse_id,
                'received_by' => TenantUserHelper::requireTenantUserId(),
                'received_date' => $data['received_date'] ?? now(),
                'carrier_reference' => $data['carrier_reference'] ?? null,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['lines'] ?? [] as $lineData) {
                $poLine = $purchaseOrder->items()->findOrFail($lineData['purchase_order_item_id']);

                $receivedQty = $lineData['quantity_received'];
                $acceptedQty = $lineData['quantity_accepted'] ?? $receivedQty;
                $rejectedQty = $lineData['quantity_rejected'] ?? 0;

                GoodsReceiptLine::create([
                    'goods_receipt_id' => $grn->id,
                    'purchase_order_item_id' => $poLine->id,
                    'product_id' => $poLine->product_id,
                    'quantity_ordered' => $poLine->quantity_ordered,
                    'quantity_received' => $receivedQty,
                    'quantity_accepted' => $acceptedQty,
                    'quantity_rejected' => $rejectedQty,
                    'quantity_putaway' => $acceptedQty,
                    'notes' => $lineData['notes'] ?? null,
                ]);
            }

            return $grn->load(['lines', 'purchaseOrder']);
        });
    }

    public function confirm(GoodsReceipt $grn): GoodsReceipt
    {
        return DB::transaction(function () use ($grn) {
            if ($grn->status !== 'draft') {
                throw new \InvalidArgumentException("Cannot confirm GRN with status '{$grn->status}'. Must be 'draft'.");
            }

            $grn->confirm();

            foreach ($grn->lines as $line) {
                if ($line->quantity_accepted > 0) {
                    $this->inventoryService->adjustStock(
                        productId: $line->product_id,
                        warehouseId: $grn->warehouse_id,
                        quantity: $line->quantity_accepted,
                        type: 'purchase',
                        reference: $grn->grn_number,
                        referenceType: 'GoodsReceipt',
                        referenceId: $grn->id,
                        notes: "GRN {$grn->grn_number} — PO {$grn->purchaseOrder->po_number}",
                    );
                }
            }

            $this->updatePurchaseOrderReceivedQty($grn);

            Log::info('Goods receipt confirmed', [
                'grn_number' => $grn->grn_number,
                'purchase_order_id' => $grn->purchase_order_id,
            ]);

            return $grn->fresh(['lines', 'purchaseOrder']);
        });
    }

    public function cancel(GoodsReceipt $grn): GoodsReceipt
    {
        return DB::transaction(function () use ($grn) {
            if ($grn->status === 'confirmed') {
                throw new \InvalidArgumentException('Cannot cancel a confirmed GRN. Create a return instead.');
            }

            $grn->cancel();

            Log::info('Goods receipt cancelled', [
                'grn_number' => $grn->grn_number,
            ]);

            return $grn;
        });
    }

    private function updatePurchaseOrderReceivedQty(GoodsReceipt $grn): void
    {
        $purchaseOrder = $grn->purchaseOrder;

        foreach ($purchaseOrder->items as $poLine) {
            $totalReceived = GoodsReceiptLine::where('purchase_order_item_id', $poLine->id)
                ->whereHas('goodsReceipt', function ($q) {
                    $q->where('status', 'confirmed');
                })
                ->sum('quantity_accepted');

            $poLine->update(['received_quantity' => $totalReceived]);
        }

        $allFullyReceived = $purchaseOrder->items->every(function ($item) {
            return $item->received_quantity >= $item->quantity;
        });

        if ($allFullyReceived && $purchaseOrder->status === 'ordered') {
            $purchaseOrder->markReceived();
        }
    }

    private function generateGrnNumber(): string
    {
        return 'GRN-' . now()->format('Ymd-His') . '-' . Str::upper(Str::random(6));
    }
}
