<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\StockAdjustmentReason;
use Modules\Inventory\Services\StockAdjustmentService;

class InventoryTrackingController extends Controller
{
    public function __construct(private readonly StockAdjustmentService $stockAdjustmentService) {}

    public function batches(Request $request)
    {
        $query = ProductBatch::with(['product:id,name,sku', 'warehouse:id,name,code'])
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->string('search').'%';
                $q->where(function ($inner) use ($search) {
                    $inner->where('batch_number', 'like', $search)
                        ->orWhere('lot_number', 'like', $search)
                        ->orWhereHas('product', fn ($product) => $product->where('name', 'like', $search)->orWhere('sku', 'like', $search));
                });
            })
            ->orderByDesc('created_at');

        return response()->json($query->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function serials(Request $request)
    {
        $query = ProductSerial::with(['product:id,name,sku', 'warehouse:id,name,code', 'batch:id,batch_number,lot_number,expiry_date'])
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->string('search').'%';
                $q->where(function ($inner) use ($search) {
                    $inner->where('serial_number', 'like', $search)
                        ->orWhereHas('product', fn ($product) => $product->where('name', 'like', $search)->orWhere('sku', 'like', $search));
                });
            })
            ->orderByDesc('created_at');

        return response()->json($query->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function template(string $type)
    {
        abort_unless(in_array($type, ['batches', 'serials'], true), 404);
        $header = $type === 'batches'
            ? 'product_sku,warehouse_code,batch_number,quantity,expiry_date,reference,notes'
            : 'product_sku,warehouse_code,serial_number,reference,notes';
        $example = $type === 'batches'
            ? 'SURGICAL-KIT,WH-001,LOT-2026-001,10,2027-12-31,OPENING-001,Initial tracked stock'
            : 'DEVICE-001,WH-001,SN-000001,OPENING-001,Initial tracked stock';

        return response($header."\n".$example."\n", 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$type.'-import-template.csv"',
        ]);
    }

    public function import(Request $request, string $type)
    {
        abort_unless(in_array($type, ['batches', 'serials'], true), 404);
        $validated = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
            'adjustment_reason_id' => 'required|integer|exists:stock_adjustment_reasons,id',
        ]);

        $reason = StockAdjustmentReason::findOrFail($validated['adjustment_reason_id']);
        if (! in_array($reason->type, ['increase', 'both'], true)) {
            throw ValidationException::withMessages(['adjustment_reason_id' => ['Select a stock-in reason.']]);
        }

        $handle = fopen($validated['file']->getRealPath(), 'r');
        $headers = array_map(fn ($value) => strtolower(trim((string) $value)), fgetcsv($handle) ?: []);
        $required = $type === 'batches'
            ? ['product_sku', 'warehouse_code', 'batch_number', 'quantity']
            : ['product_sku', 'warehouse_code', 'serial_number'];
        if (array_diff($required, $headers) !== []) {
            throw ValidationException::withMessages(['file' => ['Missing required columns: '.implode(', ', array_diff($required, $headers))]]);
        }

        $results = [];
        $rowNumber = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $normalizedValues = array_slice(array_pad($values, count($headers), null), 0, count($headers));
            $row = array_combine($headers, $normalizedValues);
            try {
                DB::transaction(function () use ($type, $row, $validated) {
                    $product = Product::where('sku', trim((string) $row['product_sku']))->firstOrFail();
                    $warehouse = Warehouse::where('code', trim((string) $row['warehouse_code']))->firstOrFail();
                    $expectedMethod = $type === 'batches' ? Product::TRACKING_BATCH : Product::TRACKING_SERIAL;
                    if ($product->tracking_method !== $expectedMethod) {
                        throw new \RuntimeException("Product {$product->sku} is not configured for ".($type === 'batches' ? 'batch' : 'serial').' tracking.');
                    }

                    $quantity = $type === 'batches' ? (int) $row['quantity'] : 1;
                    if ($quantity < 1) {
                        throw new \RuntimeException('Quantity must be at least 1.');
                    }
                    $expiry = ! empty($row['expiry_date']) ? new \DateTime($row['expiry_date']) : null;
                    $serials = $type === 'serials' ? [trim((string) $row['serial_number'])] : [];
                    if ($type === 'serials' && $serials[0] === '') {
                        throw new \RuntimeException('Serial number is required.');
                    }

                    $this->stockAdjustmentService->stockIn(
                        productId: $product->id,
                        warehouseId: $warehouse->id,
                        quantity: $quantity,
                        adjustmentReasonId: (int) $validated['adjustment_reason_id'],
                        reference: trim((string) ($row['reference'] ?? '')) ?: null,
                        notes: trim((string) ($row['notes'] ?? '')) ?: null,
                        batchNumber: $type === 'batches' ? trim((string) $row['batch_number']) : null,
                        expiryDate: $expiry,
                        lotNumber: $type === 'batches' ? trim((string) $row['batch_number']) : null,
                        serialNumbers: $serials,
                        // Batch/serial import records tracking data (lot numbers,
                        // serials, expiry) — it must not create a GL journal
                        // entry, which previously failed the whole import against
                        // the journal_entries type check constraint.
                        postToGl: false,
                    );
                });
                $results[] = ['row' => $rowNumber, 'status' => 'imported'];
            } catch (\Throwable $e) {
                $results[] = ['row' => $rowNumber, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }
        fclose($handle);

        return response()->json([
            'imported' => collect($results)->where('status', 'imported')->count(),
            'failed' => collect($results)->where('status', 'failed')->count(),
            'results' => $results,
        ]);
    }
}
