<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\WarehouseTransfer;
use Modules\Inventory\Models\WarehouseTransferItem;
use Modules\Inventory\Services\InventoryService;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class WarehouseTransferController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService
    ) {}

    public function index(Request $request)
    {
        $query = WarehouseTransfer::with(['fromWarehouse', 'toWarehouse', 'createdBy'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            // Wide: transfer number/notes and the source/destination warehouses.
            $search = trim((string) $request->input('search'));
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('transfer_number', 'like', $like)
                    ->orWhere('notes', 'like', $like)
                    ->orWhereHas('fromWarehouse', fn ($warehouse) => $warehouse->where('name', 'like', $like))
                    ->orWhereHas('toWarehouse', fn ($warehouse) => $warehouse->where('name', 'like', $like));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('from_warehouse_id')) {
            $query->where('from_warehouse_id', $request->input('from_warehouse_id'));
        }

        if ($request->filled('to_warehouse_id')) {
            $query->where('to_warehouse_id', $request->input('to_warehouse_id'));
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id' => 'required|exists:warehouses,id|different:from_warehouse_id',
            'transfer_date' => 'required|date',
            'expected_date' => 'nullable|date|after_or_equal:transfer_date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_requested' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
        ]);

        $transfer = DB::transaction(function () use ($validated) {
            $transfer = WarehouseTransfer::create([
                'transfer_number' => WarehouseTransfer::generateTransferNumber(),
                'from_warehouse_id' => $validated['from_warehouse_id'],
                'to_warehouse_id' => $validated['to_warehouse_id'],
                'status' => 'draft',
                'transfer_date' => $validated['transfer_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => TenantUserHelper::resolveTenantUserId(),
            ]);

            foreach ($validated['items'] as $itemData) {
                $transfer->items()->create($itemData);
            }

            return $transfer;
        });

        return response()->json($transfer->load(['fromWarehouse', 'toWarehouse', 'items.product']), 201);
    }

    public function show(int $warehouse_transfer_id)
    {
        $warehouseTransfer = $this->findWarehouseTransferOrFail($warehouse_transfer_id);

        return response()->json($warehouseTransfer->load([
            'fromWarehouse',
            'toWarehouse',
            'items.product',
            'createdBy',
            'completedBy',
        ]));
    }

    public function update(Request $request, int $warehouse_transfer_id)
    {
        $warehouseTransfer = $this->findWarehouseTransferOrFail($warehouse_transfer_id);

        if ($warehouseTransfer->status !== 'draft') {
            return response()->json(['message' => 'Cannot update transfer in current status'], 422);
        }

        $validated = $request->validate([
            'from_warehouse_id' => 'exists:warehouses,id',
            'to_warehouse_id' => 'exists:warehouses,id|different:from_warehouse_id',
            'transfer_date' => 'date',
            'expected_date' => 'nullable|date|after_or_equal:transfer_date',
            'notes' => 'nullable|string',
            'items' => 'array|min:1',
            'items.*.id' => 'nullable|exists:warehouse_transfer_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_requested' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($warehouseTransfer, $validated) {
            $warehouseTransfer->update(collect($validated)->except('items')->toArray());

            if (isset($validated['items'])) {
                $existingIds = [];

                foreach ($validated['items'] as $itemData) {
                    if (! empty($itemData['id'])) {
                        $item = WarehouseTransferItem::find($itemData['id']);
                        if ($item && $item->warehouse_transfer_id === $warehouseTransfer->id) {
                            $item->update($itemData);
                            $existingIds[] = $item->id;
                        }
                    } else {
                        $item = $warehouseTransfer->items()->create($itemData);
                        $existingIds[] = $item->id;
                    }
                }

                $warehouseTransfer->items()->whereNotIn('id', $existingIds)->delete();
            }
        });

        return response()->json($warehouseTransfer->fresh(['fromWarehouse', 'toWarehouse', 'items.product']));
    }

    public function destroy(int $warehouse_transfer_id)
    {
        $warehouseTransfer = $this->findWarehouseTransferOrFail($warehouse_transfer_id);

        if (! in_array($warehouseTransfer->status, ['draft', 'cancelled'])) {
            return response()->json(['message' => 'Cannot delete transfer in current status'], 422);
        }

        $warehouseTransfer->delete();

        return response()->json(['message' => 'Transfer deleted successfully']);
    }

    public function submit(int $warehouse_transfer_id)
    {
        $warehouseTransfer = $this->findWarehouseTransferOrFail($warehouse_transfer_id);

        if ($warehouseTransfer->status !== 'draft') {
            return response()->json(['message' => 'Only draft transfers can be submitted'], 422);
        }

        $warehouseTransfer->update(['status' => 'pending']);

        return response()->json($warehouseTransfer->fresh(['fromWarehouse', 'toWarehouse', 'items.product']));
    }

    public function ship(Request $request, int $warehouse_transfer_id)
    {
        $warehouseTransfer = $this->findWarehouseTransferOrFail($warehouse_transfer_id);

        if ($warehouseTransfer->status !== 'pending') {
            return response()->json(['message' => 'Transfer must be pending to ship'], 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:warehouse_transfer_items,id',
            'items.*.quantity_sent' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($warehouseTransfer, $validated) {
            foreach ($validated['items'] as $itemData) {
                $item = WarehouseTransferItem::find($itemData['id']);
                if ($item && $item->warehouse_transfer_id === $warehouseTransfer->id) {
                    $item->quantity_sent = min($itemData['quantity_sent'], $item->quantity_requested);
                    $item->save();

                    // Deduct from source warehouse
                    if ($item->quantity_sent > 0) {
                        $this->inventoryService->adjustStock(
                            $item->product_id,
                            $warehouseTransfer->from_warehouse_id,
                            $item->quantity_sent,
                            'transfer_out',
                            $warehouseTransfer->transfer_number,
                            'WarehouseTransfer',
                            $warehouseTransfer->id,
                            null,
                            "Transfer to: {$warehouseTransfer->toWarehouse->name}"
                        );
                    }
                }
            }

            $warehouseTransfer->update(['status' => 'in_transit']);
        });

        return response()->json($warehouseTransfer->fresh(['fromWarehouse', 'toWarehouse', 'items.product']));
    }

    public function receive(Request $request, int $warehouse_transfer_id)
    {
        $warehouseTransfer = $this->findWarehouseTransferOrFail($warehouse_transfer_id);

        if ($warehouseTransfer->status !== 'in_transit') {
            return response()->json(['message' => 'Transfer must be in transit to receive'], 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:warehouse_transfer_items,id',
            'items.*.quantity_received' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($warehouseTransfer, $validated) {
            foreach ($validated['items'] as $itemData) {
                $item = WarehouseTransferItem::find($itemData['id']);
                if ($item && $item->warehouse_transfer_id === $warehouseTransfer->id) {
                    $item->quantity_received = min($itemData['quantity_received'], $item->quantity_sent);
                    $item->save();

                    // Add to destination warehouse
                    if ($item->quantity_received > 0) {
                        $this->inventoryService->adjustStock(
                            $item->product_id,
                            $warehouseTransfer->to_warehouse_id,
                            $item->quantity_received,
                            'transfer_in',
                            $warehouseTransfer->transfer_number,
                            'WarehouseTransfer',
                            $warehouseTransfer->id,
                            null,
                            "Transfer from: {$warehouseTransfer->fromWarehouse->name}"
                        );
                    }
                }
            }

            $warehouseTransfer->update([
                'status' => 'completed',
                'completed_date' => now(),
                'completed_by' => TenantUserHelper::requireTenantUserId(),
            ]);
        });

        return response()->json($warehouseTransfer->fresh(['fromWarehouse', 'toWarehouse', 'items.product']));
    }

    public function cancel(int $warehouse_transfer_id)
    {
        $warehouseTransfer = $this->findWarehouseTransferOrFail($warehouse_transfer_id);

        if (in_array($warehouseTransfer->status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cannot cancel this transfer'], 422);
        }

        // If in transit, reverse the stock deduction
        if ($warehouseTransfer->status === 'in_transit') {
            DB::transaction(function () use ($warehouseTransfer) {
                foreach ($warehouseTransfer->items as $item) {
                    if ($item->quantity_sent > 0) {
                        $this->inventoryService->adjustStock(
                            $item->product_id,
                            $warehouseTransfer->from_warehouse_id,
                            $item->quantity_sent,
                            'transfer_in',
                            $warehouseTransfer->transfer_number,
                            'WarehouseTransfer',
                            $warehouseTransfer->id,
                            null,
                            'Transfer cancelled - stock returned'
                        );
                    }
                }
            });
        }

        $warehouseTransfer->update(['status' => 'cancelled']);

        return response()->json($warehouseTransfer->fresh(['fromWarehouse', 'toWarehouse', 'items.product']));
    }

    private function findWarehouseTransferOrFail(int $warehouseTransferId): WarehouseTransfer
    {
        $warehouseTransfer = WarehouseTransfer::query()->find($warehouseTransferId);

        if (! $warehouseTransfer) {
            throw (new ModelNotFoundException)->setModel(WarehouseTransfer::class, [$warehouseTransferId]);
        }

        return $warehouseTransfer;
    }
}
