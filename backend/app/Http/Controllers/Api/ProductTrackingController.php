<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\InventoryService;

/**
 * Product Tracking Controller
 * Manages batch/lot and serial number tracking for products
 */
class ProductTrackingController extends Controller
{
    /**
     * Return eligible inventory choices for an invoice or delivery-order line.
     * Expiry is authoritative from the batch record and must not be user-entered.
     */
    public function availability(Request $request, $product): JsonResponse
    {
        $product = Product::findOrFail($product);
        $validated = $request->validate([
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
        ]);

        $warehouseId = $validated['warehouse_id'] ?? Warehouse::getDefault()?->id;
        if (! $warehouseId) {
            return response()->json(['message' => 'Select a warehouse before choosing a lot or serial number.'], 422);
        }

        $method = $product->tracking_method ?? Product::TRACKING_NONE;
        $payload = [
            'product_id' => $product->id,
            'warehouse_id' => (int) $warehouseId,
            'tracking_method' => $method,
            'batches' => [],
            'serials' => [],
        ];

        if ($method === Product::TRACKING_BATCH) {
            $payload['batches'] = ProductBatch::query()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->where('status', ProductBatch::STATUS_AVAILABLE)
                ->whereRaw('quantity - COALESCE(quantity_reserved, 0) > 0')
                ->where(function ($query) {
                    $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString());
                })
                ->orderByRaw('expiry_date ASC NULLS LAST')
                ->orderBy('received_date')
                ->get()
                ->map(fn (ProductBatch $batch) => [
                    'id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'lot_number' => $batch->lot_number ?: $batch->batch_number,
                    'expiry_date' => $batch->expiry_date?->toDateString(),
                    'available_quantity' => max(0, (int) $batch->quantity - (int) $batch->quantity_reserved),
                    'status' => $batch->status,
                ])->values();
        }

        if ($method === Product::TRACKING_SERIAL) {
            $payload['serials'] = ProductSerial::query()
                ->with('batch:id,batch_number,lot_number,expiry_date')
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->where('status', ProductSerial::STATUS_IN_STOCK)
                ->where(function ($query) {
                    $query->whereDoesntHave('batch')
                        ->orWhereHas('batch', function ($batchQuery) {
                            $batchQuery->where('status', ProductBatch::STATUS_AVAILABLE)
                                ->where(function ($expiryQuery) {
                                    $expiryQuery->whereNull('expiry_date')
                                        ->orWhereDate('expiry_date', '>=', now()->toDateString());
                                });
                        });
                })
                ->orderBy('serial_number')
                ->get()
                ->map(fn (ProductSerial $serial) => [
                    'id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'batch_id' => $serial->batch_id,
                    'lot_number' => $serial->batch?->lot_number ?: $serial->batch?->batch_number,
                    'expiry_date' => $serial->batch?->expiry_date?->toDateString(),
                    'status' => $serial->status,
                ])->values();
        }

        return response()->json($payload);
    }

    // ==================== BATCH MANAGEMENT ====================

    /**
     * List batches for a product
     */
    public function batches($product, Request $request): JsonResponse
    {
        $product = Product::findOrFail($product);
        $query = $product->batches();

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by warehouse
        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        // Filter by expiry
        if ($request->has('expiring_within_days')) {
            $expiryDate = now()->addDays($request->expiring_within_days);
            $query->whereNotNull('expiry_date')
                ->where('expiry_date', '<=', $expiryDate)
                ->where('expiry_date', '>', now());
        }

        if ($request->has('expired')) {
            if ($request->expired === 'true' || $request->expired === true) {
                $query->where('expiry_date', '<', now());
            } else {
                $query->where(function ($q) {
                    $q->whereNull('expiry_date')
                        ->orWhere('expiry_date', '>=', now());
                });
            }
        }

        // Sort
        $sortField = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = $request->get('per_page', 20);
        $batches = $query->paginate($perPage);

        return response()->json($batches);
    }

    /**
     * Create a new batch
     */
    public function createBatch(Request $request, $product): JsonResponse
    {
        $product = Product::findOrFail($product);
        if ($product->tracking_method !== Product::TRACKING_BATCH) {
            return response()->json([
                'message' => 'This product is not configured for batch tracking',
            ], 422);
        }

        $validated = $request->validate([
            'batch_number' => 'required|string|max:100',
            'lot_number' => 'nullable|string|max:100',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'manufacturing_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:manufacturing_date',
            'received_date' => 'nullable|date',
            'supplier_batch_number' => 'nullable|string|max:100',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        // Check for duplicate batch number
        if ($product->batches()->where('batch_number', $validated['batch_number'])->exists()) {
            return response()->json([
                'message' => 'A batch with this number already exists for this product',
            ], 422);
        }

        $warehouseId = $validated['warehouse_id'] ?? Warehouse::getDefault()?->id;
        if (! $warehouseId) {
            return response()->json(['message' => 'No warehouse specified and no default warehouse configured.'], 422);
        }

        $batch = $product->batches()->create(array_merge($validated, [
            'warehouse_id' => $warehouseId,
            'status' => 'available',
            'quantity_reserved' => 0,
        ]));

        // Update stock via InventoryService — canonical path, stock_levels stays authoritative
        app(InventoryService::class)->adjustStock(
            productId: $product->id,
            warehouseId: $warehouseId,
            quantity: $validated['quantity'],
            type: 'purchase',
            reference: "Batch received: {$validated['batch_number']}",
            referenceType: 'ProductBatch',
            referenceId: $batch->id,
            batchNumber: $validated['batch_number'],
            expiryDate: isset($validated['expiry_date']) ? new \DateTime($validated['expiry_date']) : null,
        );

        return response()->json([
            'message' => 'Batch created successfully',
            'batch' => $batch,
        ], 201);
    }

    /**
     * Update batch information
     */
    public function updateBatch(Request $request, $product, $batch): JsonResponse
    {
        $product = Product::findOrFail($product);
        $batch = ProductBatch::findOrFail($batch);
        if ($batch->product_id !== $product->id) {
            return response()->json(['message' => 'Batch not found'], 404);
        }

        $validated = $request->validate([
            'lot_number' => 'nullable|string|max:100',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'manufacturing_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'supplier_batch_number' => 'nullable|string|max:100',
            'cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:available,quarantine,expired,recalled',
        ]);

        $batch->update($validated);

        return response()->json([
            'message' => 'Batch updated successfully',
            'batch' => $batch,
        ]);
    }

    /**
     * Adjust batch quantity
     */
    public function adjustBatch(Request $request, $product, $batch): JsonResponse
    {
        $product = Product::findOrFail($product);
        $batch = ProductBatch::findOrFail($batch);
        if ($batch->product_id !== $product->id) {
            return response()->json(['message' => 'Batch not found'], 404);
        }

        $validated = $request->validate([
            'adjustment' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        $newQuantity = $batch->quantity + $validated['adjustment'];

        if ($newQuantity < $batch->quantity_reserved) {
            return response()->json([
                'message' => "Cannot reduce quantity below reserved amount ({$batch->quantity_reserved})",
            ], 422);
        }

        if ($newQuantity < 0) {
            return response()->json([
                'message' => 'Quantity cannot be negative',
            ], 422);
        }

        DB::transaction(function () use ($batch, $product, $validated, $newQuantity) {
            $oldQuantity = $batch->quantity;
            $batch->update(['quantity' => $newQuantity]);

            // Adjust stock via InventoryService — canonical path
            $adjustment = $validated['adjustment'];
            $warehouseId = $batch->warehouse_id ?? Warehouse::getDefault()?->id;

            if ($warehouseId && $adjustment !== 0) {
                app(InventoryService::class)->adjustStock(
                    productId: $product->id,
                    warehouseId: $warehouseId,
                    quantity: abs($adjustment),
                    type: $adjustment > 0 ? 'in' : 'out',
                    reference: "Batch adjustment: {$validated['reason']}",
                    referenceType: 'ProductBatch',
                    referenceId: $batch->id,
                    batchNumber: $batch->batch_number,
                );
            }
        });

        return response()->json([
            'message' => 'Batch quantity adjusted successfully',
            'batch' => $batch->fresh(),
        ]);
    }

    /**
     * Reserve batch quantity
     */
    public function reserveBatch(Request $request, $product, $batch): JsonResponse
    {
        $product = Product::findOrFail($product);
        $batch = ProductBatch::findOrFail($batch);
        if ($batch->product_id !== $product->id) {
            return response()->json(['message' => 'Batch not found'], 404);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
            'reference' => 'nullable|string|max:100',
        ]);

        if (! $batch->reserve($validated['quantity'])) {
            return response()->json([
                'message' => 'Insufficient available quantity in batch',
            ], 422);
        }

        return response()->json([
            'message' => 'Quantity reserved successfully',
            'batch' => $batch->fresh(),
        ]);
    }

    /**
     * Release reserved batch quantity
     */
    public function releaseBatch(Request $request, $product, $batch): JsonResponse
    {
        $product = Product::findOrFail($product);
        $batch = ProductBatch::findOrFail($batch);
        if ($batch->product_id !== $product->id) {
            return response()->json(['message' => 'Batch not found'], 404);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        if (! $batch->release($validated['quantity'])) {
            return response()->json([
                'message' => 'Cannot release more than reserved quantity',
            ], 422);
        }

        return response()->json([
            'message' => 'Quantity released successfully',
            'batch' => $batch->fresh(),
        ]);
    }

    /**
     * Get batches expiring soon
     */
    public function expiringBatches(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);
        $expiryDate = now()->addDays($days);

        $batches = ProductBatch::with(['product:id,name,sku', 'warehouse:id,name'])
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $expiryDate)
            ->where('expiry_date', '>', now())
            ->where('status', 'available')
            ->where('quantity', '>', 0)
            ->orderBy('expiry_date', 'asc')
            ->paginate($request->get('per_page', 20));

        return response()->json($batches);
    }

    /**
     * Get expired batches
     */
    public function expiredBatches(Request $request): JsonResponse
    {
        $batches = ProductBatch::with(['product:id,name,sku', 'warehouse:id,name'])
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', now())
            ->where('quantity', '>', 0)
            ->orderBy('expiry_date', 'asc')
            ->paginate($request->get('per_page', 20));

        return response()->json($batches);
    }

    // ==================== SERIAL NUMBER MANAGEMENT ====================

    /**
     * List serial numbers for a product
     */
    public function serials($product, Request $request): JsonResponse
    {
        $product = Product::findOrFail($product);
        $query = $product->serials();

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by warehouse
        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('serial_number', 'like', "%{$search}%")
                    ->orWhere('mac_address', 'like', "%{$search}%")
                    ->orWhere('imei', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortField = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = $request->get('per_page', 20);
        $serials = $query->paginate($perPage);

        return response()->json($serials);
    }

    /**
     * Create a new serial number
     */
    public function createSerial(Request $request, $product): JsonResponse
    {
        $product = Product::findOrFail($product);
        if ($product->tracking_method !== Product::TRACKING_SERIAL) {
            return response()->json([
                'message' => 'This product is not configured for serial number tracking',
            ], 422);
        }

        $validated = $request->validate([
            'serial_number' => 'required|string|max:100',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'batch_id' => 'nullable|exists:product_batches,id',
            'mac_address' => 'nullable|string|max:20',
            'imei' => 'nullable|string|max:20',
            'manufacturing_date' => 'nullable|date',
            'warranty_start_date' => 'nullable|date',
            'warranty_end_date' => 'nullable|date|after_or_equal:warranty_start_date',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        // Check for duplicate serial number (globally unique)
        if (ProductSerial::where('serial_number', $validated['serial_number'])->exists()) {
            return response()->json([
                'message' => 'This serial number already exists in the system',
            ], 422);
        }

        $warehouseId = $validated['warehouse_id'] ?? Warehouse::getDefault()?->id;
        if (! $warehouseId) {
            return response()->json(['message' => 'No warehouse specified and no default warehouse configured.'], 422);
        }

        $serial = $product->serials()->create(array_merge($validated, [
            'warehouse_id' => $warehouseId,
            'status' => 'available',
        ]));

        // Update stock via InventoryService — canonical path (1 unit per serial number)
        app(InventoryService::class)->adjustStock(
            productId: $product->id,
            warehouseId: $warehouseId,
            quantity: 1,
            type: 'purchase',
            reference: "Serial number received: {$validated['serial_number']}",
            referenceType: 'ProductSerial',
            referenceId: $serial->id,
        );

        return response()->json([
            'message' => 'Serial number created successfully',
            'serial' => $serial,
        ], 201);
    }

    /**
     * Bulk create serial numbers
     */
    public function createSerialsBulk(Request $request, $product): JsonResponse
    {
        $product = Product::findOrFail($product);
        if ($product->tracking_method !== Product::TRACKING_SERIAL) {
            return response()->json([
                'message' => 'This product is not configured for serial number tracking',
            ], 422);
        }

        $validated = $request->validate([
            'serial_numbers' => 'required|array|min:1|max:1000',
            'serial_numbers.*' => 'required|string|max:100',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'batch_id' => 'nullable|exists:product_batches,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'cost' => 'nullable|numeric|min:0',
        ]);

        // Check for duplicates
        $existingSerials = ProductSerial::whereIn('serial_number', $validated['serial_numbers'])
            ->pluck('serial_number')
            ->toArray();

        if (! empty($existingSerials)) {
            return response()->json([
                'message' => 'Some serial numbers already exist',
                'duplicates' => $existingSerials,
            ], 422);
        }

        $warehouseId = $validated['warehouse_id'] ?? Warehouse::getDefault()?->id;
        if (! $warehouseId) {
            return response()->json(['message' => 'No warehouse specified and no default warehouse configured.'], 422);
        }

        $created = [];
        DB::transaction(function () use ($product, $validated, $warehouseId, &$created) {
            foreach ($validated['serial_numbers'] as $serialNumber) {
                $serial = $product->serials()->create([
                    'serial_number' => $serialNumber,
                    'warehouse_id' => $warehouseId,
                    'batch_id' => $validated['batch_id'] ?? null,
                    'supplier_id' => $validated['supplier_id'] ?? null,
                    'cost' => $validated['cost'] ?? null,
                    'status' => 'available',
                ]);
                $created[] = $serial;
            }

            // Update stock via InventoryService — all serials go to the same warehouse as a single movement
            if (count($created) > 0) {
                app(InventoryService::class)->adjustStock(
                    productId: $product->id,
                    warehouseId: $warehouseId,
                    quantity: count($created),
                    type: 'purchase',
                    reference: 'Bulk serial numbers received',
                    referenceType: 'ProductSerial',
                    referenceId: null,
                );
            }
        });

        return response()->json([
            'message' => count($created).' serial numbers created successfully',
            'count' => count($created),
        ], 201);
    }

    /**
     * Update serial number information
     */
    public function updateSerial(Request $request, $product, $serial): JsonResponse
    {
        $product = Product::findOrFail($product);
        $serial = ProductSerial::findOrFail($serial);
        if ($serial->product_id !== $product->id) {
            return response()->json(['message' => 'Serial not found'], 404);
        }

        $validated = $request->validate([
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'batch_id' => 'nullable|exists:product_batches,id',
            'mac_address' => 'nullable|string|max:20',
            'imei' => 'nullable|string|max:20',
            'warranty_start_date' => 'nullable|date',
            'warranty_end_date' => 'nullable|date',
            'cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:available,reserved,sold,returned,defective,scrapped',
        ]);

        $serial->update($validated);

        return response()->json([
            'message' => 'Serial number updated successfully',
            'serial' => $serial,
        ]);
    }

    /**
     * Mark serial as sold
     */
    public function markSerialSold(Request $request, $product, $serial): JsonResponse
    {
        $product = Product::findOrFail($product);
        $serial = ProductSerial::findOrFail($serial);
        if ($serial->product_id !== $product->id) {
            return response()->json(['message' => 'Serial not found'], 404);
        }

        if ($serial->status !== 'available' && $serial->status !== 'reserved') {
            return response()->json([
                'message' => 'Serial number is not available for sale',
            ], 422);
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'sold_at' => 'nullable|date',
            'sale_reference' => 'nullable|string|max:100',
        ]);

        $serial->markSold(
            $validated['customer_id'] ?? null,
            $validated['sold_at'] ?? now(),
            $validated['sale_reference'] ?? null
        );

        return response()->json([
            'message' => 'Serial marked as sold successfully',
            'serial' => $serial->fresh(),
        ]);
    }

    /**
     * Mark serial as returned
     */
    public function markSerialReturned(Request $request, $product, $serial): JsonResponse
    {
        $product = Product::findOrFail($product);
        $serial = ProductSerial::findOrFail($serial);
        if ($serial->product_id !== $product->id) {
            return response()->json(['message' => 'Serial not found'], 404);
        }

        if ($serial->status !== 'sold') {
            return response()->json([
                'message' => 'Only sold items can be returned',
            ], 422);
        }

        $validated = $request->validate([
            'return_to_stock' => 'boolean',
            'reason' => 'nullable|string|max:255',
        ]);

        $serial->markReturned($validated['return_to_stock'] ?? true);

        if (! empty($validated['reason'])) {
            $serial->update(['notes' => $serial->notes."\nReturn reason: ".$validated['reason']]);
        }

        return response()->json([
            'message' => 'Serial marked as returned successfully',
            'serial' => $serial->fresh(),
        ]);
    }

    /**
     * Find a serial number
     */
    public function findSerial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'serial_number' => 'required|string',
        ]);

        $serial = ProductSerial::with(['product:id,name,sku,image', 'customer:id,name,email', 'warehouse:id,name'])
            ->where('serial_number', $validated['serial_number'])
            ->first();

        if (! $serial) {
            return response()->json([
                'message' => 'Serial number not found',
            ], 404);
        }

        return response()->json($serial);
    }

    /**
     * Get warranty status for a serial
     */
    public function warrantyStatus($serial): JsonResponse
    {
        $serial = ProductSerial::findOrFail($serial);

        return response()->json([
            'serial_number' => $serial->serial_number,
            'product' => $serial->product ? [
                'id' => $serial->product->id,
                'name' => $serial->product->name,
                'sku' => $serial->product->sku,
            ] : null,
            'warranty' => [
                'start_date' => $serial->warranty_start_date,
                'end_date' => $serial->warranty_end_date,
                'is_under_warranty' => $serial->isUnderWarranty(),
                'days_remaining' => $serial->warranty_end_date
                    ? max(0, now()->diffInDays($serial->warranty_end_date, false))
                    : null,
            ],
            'purchase' => [
                'sold_at' => $serial->sold_at,
                'customer_id' => $serial->customer_id,
            ],
            'status' => $serial->status,
        ]);
    }
}
