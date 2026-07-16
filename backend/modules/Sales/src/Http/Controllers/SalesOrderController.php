<?php

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\CreditCheckService;
use Modules\Sales\Services\OrderLifecycleService;
use Modules\Sales\Services\SalesOrderService;

class SalesOrderController extends Controller
{
    public function __construct(
        private SalesOrderService $orderService,
        private OrderLifecycleService $lifecycleService,
        private CreditCheckService $creditCheckService,
    ) {}

    public function index(Request $request)
    {
        $query = SalesOrder::with(['customer', 'lines.product', 'channelReferences'])
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->whereIn('status', explode(',', $request->status));
        }

        if ($request->has('channel')) {
            $query->where('channel', $request->channel);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('sales_order_number', 'ILIKE', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'ILIKE', "%{$search}%");
                    });
            });
        }

        if ($request->has('date_from')) {
            $query->where('order_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('order_date', '<=', $request->date_to);
        }

        return response()->json(
            $query->paginate($request->per_page ?? 20)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'channel' => 'nullable|in:admin,pos,ecommerce',
            'source_document_type' => 'nullable|string|max:60',
            'source_document_id' => 'nullable|integer',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'customer_reference' => 'nullable|string|max:100',
            'order_date' => 'nullable|date',
            'required_date' => 'nullable|date',
            'currency_code' => 'nullable|string|size:3',
            'discount_amount' => 'nullable|numeric|min:0',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.variant_id' => 'nullable|integer',
            'lines.*.description' => 'required|string',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.discount_type' => 'nullable|in:percentage,fixed',
            'lines.*.discount_value' => 'nullable|numeric|min:0',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            'lines.*.income_account_id' => 'nullable|exists:chart_of_accounts,id',
            'lines.*.source_line_type' => 'nullable|string|max:60',
            'lines.*.source_line_id' => 'nullable|integer',
            'channel_reference.channel' => 'nullable|in:pos,ecommerce,admin',
            'channel_reference.external_id' => 'nullable|string',
            'channel_reference.external_number' => 'nullable|string',
            'channel_reference.raw_document_ids' => 'nullable|array',
        ]);

        try {
            $order = $this->orderService->createOrder($validated);

            return response()->json($order->load(['customer', 'lines.product', 'statusHistory', 'channelReferences']), 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to create sales order', 'error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $order = SalesOrder::with(['customer', 'lines.product', 'statusHistory.actor', 'channelReferences', 'invoiceLinks.invoice', 'deliveryOrders.lines'])
            ->find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        return response()->json($order);
    }

    public function update(Request $request, $id)
    {
        $order = SalesOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        if (! $order->isDraft()) {
            return response()->json(['message' => 'Only draft orders can be edited'], 403);
        }

        $validated = $request->validate([
            'customer_id' => 'exists:customers,id',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'customer_reference' => 'nullable|string|max:100',
            'required_date' => 'nullable|date',
            'discount_amount' => 'nullable|numeric|min:0',
        ]);

        $order->update($validated);
        $order->calculateTotals();

        return response()->json($order->fresh(['customer', 'lines.product']));
    }

    public function confirm($id)
    {
        $order = SalesOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        $creditCheck = $this->creditCheckService->checkCreditLimit($order->customer, (float) $order->total);

        try {
            $order = $this->orderService->confirmOrder($order);

            return response()->json([
                'message' => 'Order confirmed',
                'order' => $order,
                'credit_check' => $creditCheck,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function allocate($id)
    {
        $order = SalesOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        try {
            $order = $this->orderService->allocateOrder($order);

            return response()->json(['message' => 'Order allocated', 'order' => $order]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function fulfil($id)
    {
        $order = SalesOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        try {
            $order = $this->orderService->fulfilOrder($order);

            return response()->json(['message' => 'Order fulfilled', 'order' => $order]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function createInvoice($id)
    {
        $order = SalesOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        try {
            $invoice = $this->orderService->createInvoice($order);

            return response()->json(['message' => 'Invoice created', 'invoice' => $invoice], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, $id)
    {
        $order = SalesOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        try {
            $order = $this->orderService->cancelOrder($order, $validated['reason']);

            return response()->json(['message' => 'Order cancelled', 'order' => $order]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function history($id)
    {
        $order = SalesOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        return response()->json($order->statusHistory()->with('actor')->orderBy('transitioned_at', 'desc')->get());
    }

    public function invoiceLinks($id)
    {
        $order = SalesOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        return response()->json($order->invoiceLinks()->with('invoice')->get());
    }

    public function channelReferences($id)
    {
        $order = SalesOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        return response()->json($order->channelReferences);
    }

    public function creditPreview($id)
    {
        $order = SalesOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        $creditCheck = $this->creditCheckService->checkCreditLimit($order->customer, (float) $order->total);

        return response()->json($creditCheck);
    }

    public function destroy($id)
    {
        $order = SalesOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        if (! $order->isDraft()) {
            return response()->json(['message' => 'Only draft orders can be deleted'], 403);
        }

        $order->delete();

        return response()->json(['message' => 'Sales order deleted']);
    }

    public function setBillByDelivered(Request $request, $id)
    {
        $order = SalesOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        $validated = $request->validate([
            'bill_by_delivered_qty' => 'required|boolean',
        ]);

        $order->update(['bill_by_delivered_qty' => $validated['bill_by_delivered_qty']]);

        return response()->json([
            'message' => 'Bill-by-delivered setting updated',
            'bill_by_delivered_qty' => $order->bill_by_delivered_qty,
        ]);
    }
}
