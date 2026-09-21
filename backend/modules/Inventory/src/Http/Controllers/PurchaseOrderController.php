<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\GoodsReceiptService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private GoodsReceiptService $goodsReceiptService
    ) {}

    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['supplier', 'warehouse', 'createdBy'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('order_date', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('order_date', '<=', $request->input('to_date'));
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.line_type' => 'nullable|in:product,note,section',
            'items.*.sequence' => 'nullable|integer|min:0',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.description' => 'nullable|string',
            'items.*.quantity_ordered' => 'nullable|integer|min:0',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        $purchaseOrder = DB::transaction(function () use ($validated) {
            $po = PurchaseOrder::create([
                'po_number' => PurchaseOrder::generatePoNumber(),
                'supplier_id' => $validated['supplier_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'status' => 'draft',
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'shipping_cost' => $validated['shipping_cost'] ?? 0,
                'currency' => $validated['currency'] ?? 'USD',
                'notes' => $validated['notes'] ?? null,
                'internal_notes' => $validated['internal_notes'] ?? null,
                'created_by' => TenantUserHelper::resolveTenantUserId(),
            ]);

            foreach ($validated['items'] as $itemData) {
                $item = new PurchaseOrderItem([
                    'line_type' => $itemData['line_type'] ?? 'product',
                    'sequence' => $itemData['sequence'] ?? 0,
                    'product_id' => $itemData['product_id'] ?? null,
                    'description' => $itemData['description'] ?? null,
                    'quantity_ordered' => $itemData['quantity_ordered'] ?? 0,
                    'unit_cost' => $itemData['unit_cost'] ?? 0,
                    'tax_rate' => $itemData['tax_rate'] ?? 0,
                    'discount_percent' => $itemData['discount_percent'] ?? 0,
                ]);
                $item->calculateLineTotal();
                $po->items()->save($item);
            }

            $po->calculateTotals();
            $po->save();

            return $po;
        });

        return response()->json($purchaseOrder->load(['supplier', 'warehouse', 'items.product']), 201);
    }

    public function show(int $purchase_order_id)
    {
        $purchaseOrder = $this->findPurchaseOrderOrFail($purchase_order_id);

        return response()->json($purchaseOrder->load([
            'supplier',
            'warehouse',
            'items.product',
            'createdBy',
            'approvedBy',
        ]));
    }

    public function update(Request $request, int $purchase_order_id)
    {
        $purchaseOrder = $this->findPurchaseOrderOrFail($purchase_order_id);

        if (! in_array($purchaseOrder->status, ['draft', 'pending'])) {
            return response()->json(['message' => 'Cannot update purchase order in current status'], 422);
        }

        $validated = $request->validate([
            'supplier_id' => 'exists:suppliers,id',
            'warehouse_id' => 'exists:warehouses,id',
            'order_date' => 'date',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'items' => 'array|min:1',
            'items.*.id' => 'nullable|exists:purchase_order_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.description' => 'nullable|string',
            'items.*.quantity_ordered' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        DB::transaction(function () use ($purchaseOrder, $validated) {
            $purchaseOrder->update(collect($validated)->except('items')->toArray());

            if (isset($validated['items'])) {
                $existingIds = [];

                foreach ($validated['items'] as $itemData) {
                    if (! empty($itemData['id'])) {
                        $item = PurchaseOrderItem::find($itemData['id']);
                        if ($item && $item->purchase_order_id === $purchaseOrder->id) {
                            $item->fill($itemData);
                            $item->calculateLineTotal();
                            $item->save();
                            $existingIds[] = $item->id;
                        }
                    } else {
                        $item = new PurchaseOrderItem($itemData);
                        $item->calculateLineTotal();
                        $purchaseOrder->items()->save($item);
                        $existingIds[] = $item->id;
                    }
                }

                // Delete removed items
                $purchaseOrder->items()->whereNotIn('id', $existingIds)->delete();
            }

            $purchaseOrder->calculateTotals();
            $purchaseOrder->save();
        });

        return response()->json($purchaseOrder->fresh(['supplier', 'warehouse', 'items.product']));
    }

    public function destroy(int $purchase_order_id)
    {
        $purchaseOrder = $this->findPurchaseOrderOrFail($purchase_order_id);

        if (! in_array($purchaseOrder->status, ['draft', 'cancelled'])) {
            return response()->json(['message' => 'Cannot delete purchase order in current status'], 422);
        }

        $purchaseOrder->delete();

        return response()->json(['message' => 'Purchase order deleted successfully']);
    }

    public function approve(int $purchase_order_id)
    {
        $purchaseOrder = $this->findPurchaseOrderOrFail($purchase_order_id);

        if ($purchaseOrder->status !== 'pending') {
            return response()->json(['message' => 'Only pending orders can be approved'], 422);
        }

        $purchaseOrder->update([
            'status' => 'approved',
            'approved_by' => TenantUserHelper::requireTenantUserId(),
            'approved_at' => now(),
        ]);

        return response()->json($purchaseOrder->fresh(['supplier', 'warehouse', 'items.product']));
    }

    public function submit(int $purchase_order_id)
    {
        $purchaseOrder = $this->findPurchaseOrderOrFail($purchase_order_id);

        if ($purchaseOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be submitted'], 422);
        }

        $purchaseOrder->update(['status' => 'pending']);

        return response()->json($purchaseOrder->fresh(['supplier', 'warehouse', 'items.product']));
    }

    public function markOrdered(int $purchase_order_id)
    {
        $purchaseOrder = $this->findPurchaseOrderOrFail($purchase_order_id);

        if ($purchaseOrder->status !== 'approved') {
            return response()->json(['message' => 'Order must be approved first'], 422);
        }

        $purchaseOrder->update(['status' => 'ordered']);

        return response()->json($purchaseOrder->fresh(['supplier', 'warehouse', 'items.product']));
    }

    public function receive(Request $request, int $purchase_order_id)
    {
        $purchaseOrder = $this->findPurchaseOrderOrFail($purchase_order_id);

        if (! in_array($purchaseOrder->status, ['ordered', 'partial'])) {
            return response()->json(['message' => 'Order must be in ordered or partial status'], 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:purchase_order_items,id',
            'items.*.quantity_received' => 'required|integer|min:0',
            'items.*.uom_id' => 'nullable|integer|exists:product_uoms,id',
            'invoice_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($purchaseOrder, $validated) {
            $allReceived = true;
            $receivedLines = [];

            foreach ($validated['items'] as $itemData) {
                $item = PurchaseOrderItem::find($itemData['id']);
                if ($item && $item->purchase_order_id === $purchaseOrder->id) {
                    $requestedQty = (int) $itemData['quantity_received'];

                    // Issue #135: convert a non-base UOM quantity to base units
                    // before it touches PO received-quantity arithmetic or stock.
                    if (! empty($itemData['uom_id']) && $item->product_id) {
                        $uom = app(\App\Services\ProductUomConverter::class)
                            ->resolve((int) $item->product_id, (int) $itemData['uom_id']);
                        $requestedQty = (int) round($uom->convertToBase($requestedQty));
                    }

                    $remainingQty = max(0, (int) $item->quantity_ordered - (int) $item->quantity_received);
                    $acceptedQty = min($requestedQty, $remainingQty);
                    $newReceived = $item->quantity_received + $acceptedQty;
                    $item->quantity_received = min($newReceived, $item->quantity_ordered);
                    $item->save();

                    // Collect accepted quantities for GoodsReceiptService delegation.
                    if ($acceptedQty > 0) {
                        $receivedLines[] = [
                            'purchase_order_item_id' => $item->id,
                            'quantity_received' => (float) $acceptedQty,
                            'quantity_accepted' => (float) $acceptedQty,
                            'quantity_rejected' => 0,
                        ];
                    }

                    if (! $item->isFullyReceived()) {
                        $allReceived = false;
                    }
                }
            }

            if (! empty($receivedLines)) {
                // Delegate GRN creation and stock adjustment to GoodsReceiptService
                // (single canonical path — eliminates competing GRN backbone).
                $grn = $this->goodsReceiptService->create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'warehouse_id' => $purchaseOrder->warehouse_id,
                    'received_date' => now()->toDateString(),
                    'notes' => $validated['notes'] ?? "Received from PO {$purchaseOrder->po_number}",
                    'lines' => $receivedLines,
                ]);

                $this->goodsReceiptService->confirm($grn);
            }

            $purchaseOrder->update([
                'status' => $allReceived ? 'received' : 'partial',
                'received_date' => $allReceived ? now() : null,
                'invoice_number' => $validated['invoice_number'] ?? $purchaseOrder->invoice_number,
            ]);
        });

        return response()->json($purchaseOrder->fresh(['supplier', 'warehouse', 'items.product']));
    }

    public function cancel(int $purchase_order_id)
    {
        $purchaseOrder = $this->findPurchaseOrderOrFail($purchase_order_id);

        if (in_array($purchaseOrder->status, ['received', 'cancelled'])) {
            return response()->json(['message' => 'Cannot cancel this order'], 422);
        }

        $purchaseOrder->update(['status' => 'cancelled']);

        return response()->json($purchaseOrder->fresh(['supplier', 'warehouse', 'items.product']));
    }

    private function findPurchaseOrderOrFail(int $purchaseOrderId): PurchaseOrder
    {
        $purchaseOrder = PurchaseOrder::query()->find($purchaseOrderId);

        if (! $purchaseOrder) {
            throw (new ModelNotFoundException)->setModel(PurchaseOrder::class, [$purchaseOrderId]);
        }

        return $purchaseOrder;
    }
}
