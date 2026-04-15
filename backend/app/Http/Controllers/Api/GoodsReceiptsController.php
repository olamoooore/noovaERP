<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Services\GoodsReceiptService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GoodsReceiptsController extends Controller
{
    public function __construct(
        private readonly GoodsReceiptService $goodsReceiptService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = GoodsReceipt::with(['purchaseOrder', 'warehouse', 'receivedBy', 'lines'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('purchase_order_id')) {
            $query->where('purchase_order_id', $request->purchase_order_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        return response()->json($query->paginate($request->input('per_page', 20)));
    }

    public function show(GoodsReceipt $goodsReceipt): JsonResponse
    {
        return response()->json($goodsReceipt->load([
            'purchaseOrder.items.product',
            'warehouse',
            'receivedBy',
            'lines.product',
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'warehouse_id' => 'sometimes|exists:warehouses,id',
            'received_date' => 'sometimes|date',
            'carrier_reference' => 'sometimes|string|max:255',
            'notes' => 'sometimes|string',
            'lines' => 'required|array|min:1',
            'lines.*.purchase_order_item_id' => 'required|exists:purchase_order_items,id',
            'lines.*.quantity_received' => 'required|integer|min:1',
            'lines.*.quantity_accepted' => 'sometimes|integer|min:0',
            'lines.*.quantity_rejected' => 'sometimes|integer|min:0',
            'lines.*.notes' => 'sometimes|string',
        ]);

        $grn = $this->goodsReceiptService->create($validated);

        return response()->json($grn, 201);
    }

    public function confirm(GoodsReceipt $goodsReceipt): JsonResponse
    {
        $grn = $this->goodsReceiptService->confirm($goodsReceipt);

        return response()->json($grn);
    }

    public function cancel(GoodsReceipt $goodsReceipt): JsonResponse
    {
        $grn = $this->goodsReceiptService->cancel($goodsReceipt);

        return response()->json($grn);
    }
}
