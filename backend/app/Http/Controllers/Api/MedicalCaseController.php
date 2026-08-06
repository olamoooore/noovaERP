<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MedicalCaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MedicalCase::with(['customer', 'invoice', 'items.product', 'doctorContact', 'requestorContact', 'buyerContact'])
            ->latest();

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('case_no', 'ilike', "%{$search}%")
                    ->orWhere('patient_reference', 'ilike', "%{$search}%")
                    ->orWhereHas('customer', function ($q2) use ($search) {
                        $q2->where('company_name', 'ilike', "%{$search}%");
                    });
            });
        }

        $cases = $query->paginate($request->get('per_page', 25));

        return response()->json($cases);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'bonus_scheme_id' => 'nullable|exists:bonus_schemes,id',
            'delivery_address_id' => 'nullable|exists:customer_addresses,id',
            'doctor_contact_id' => 'nullable|exists:customer_contacts,id',
            'requestor_contact_id' => 'nullable|exists:customer_contacts,id',
            'buyer_contact_id' => 'nullable|exists:customer_contacts,id',
            'patient_reference' => 'nullable|string|max:255',
            'patient_name_encrypted' => 'nullable|string',
            'case_date' => 'nullable|date',
            'procedure_date' => 'nullable|date',
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.sales_order_item_id' => 'required_with:items|exists:sales_order_lines,id',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.lot_id' => 'nullable|exists:product_lots,id',
            'items.*.allocated_qty' => 'required_with:items|numeric|min:0',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.is_foc' => 'nullable|boolean',
            'items.*.notes' => 'nullable|string',
        ]);

        try {
            $case = DB::transaction(function () use ($validated, $request) {
                $case = MedicalCase::create([
                    'customer_id' => $validated['customer_id'],
                    'invoice_id' => $validated['invoice_id'] ?? null,
                    'bonus_scheme_id' => $validated['bonus_scheme_id'] ?? null,
                    'delivery_address_id' => $validated['delivery_address_id'] ?? null,
                    'doctor_contact_id' => $validated['doctor_contact_id'] ?? null,
                    'requestor_contact_id' => $validated['requestor_contact_id'] ?? null,
                    'buyer_contact_id' => $validated['buyer_contact_id'] ?? null,
                    'patient_reference' => $validated['patient_reference'] ?? null,
                    'patient_name_encrypted' => $validated['patient_name_encrypted'] ?? null,
                    'case_date' => $validated['case_date'] ?? null,
                    'procedure_date' => $validated['procedure_date'] ?? null,
                    'location' => $validated['location'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'created_by' => $request->user()->id,
                ]);

                if (!empty($validated['items'])) {
                    foreach ($validated['items'] as $item) {
                        MedicalCaseItem::create([
                            'medical_case_id' => $case->id,
                            'sales_order_item_id' => $item['sales_order_item_id'],
                            'product_id' => $item['product_id'],
                            'lot_id' => $item['lot_id'] ?? null,
                            'allocated_qty' => $item['allocated_qty'],
                            'unit_price' => $item['unit_price'],
                            'is_foc' => $item['is_foc'] ?? false,
                            'notes' => $item['notes'] ?? null,
                        ]);
                    }
                }

                return $case;
            });

            return response()->json([
                'message' => 'Medical case created successfully',
                'data' => $case->load(['customer', 'items.product', 'doctorContact', 'requestorContact', 'buyerContact']),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create medical case',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        $case = MedicalCase::with([
            'customer',
            'invoice',
            'items.product',
            'items.salesOrderItem',
            'items.invoiceItem',
            'items.deliveryOrderItem',
            'items.lot',
            'doctorContact',
            'requestorContact',
            'buyerContact',
            'deliveryAddress',
            'bonusScheme',
            'createdBy',
        ])->find($id);

        if (!$case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        return response()->json($case);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $case = MedicalCase::find($id);

        if (!$case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        if ($case->status !== 'draft') {
            return response()->json(['message' => 'Only draft cases can be edited'], 403);
        }

        $validated = $request->validate([
            'customer_id' => 'sometimes|required|exists:customers,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'bonus_scheme_id' => 'nullable|exists:bonus_schemes,id',
            'delivery_address_id' => 'nullable|exists:customer_addresses,id',
            'doctor_contact_id' => 'nullable|exists:customer_contacts,id',
            'requestor_contact_id' => 'nullable|exists:customer_contacts,id',
            'buyer_contact_id' => 'nullable|exists:customer_contacts,id',
            'patient_reference' => 'nullable|string|max:255',
            'patient_name_encrypted' => 'nullable|string',
            'case_date' => 'nullable|date',
            'procedure_date' => 'nullable|date',
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        try {
            $case->update($validated);

            return response()->json([
                'message' => 'Medical case updated successfully',
                'data' => $case->load(['customer', 'items.product', 'doctorContact', 'requestorContact', 'buyerContact']),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update medical case',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        $case = MedicalCase::find($id);

        if (!$case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        if ($case->status !== 'draft') {
            return response()->json(['message' => 'Only draft cases can be deleted'], 403);
        }

        try {
            $case->delete();

            return response()->json(['message' => 'Medical case deleted successfully']);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to delete medical case',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function allocate(Request $request, $id): JsonResponse
    {
        $case = MedicalCase::with('items')->find($id);

        if (!$case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        if ($case->status !== 'draft') {
            return response()->json(['message' => 'Only draft cases can be allocated'], 403);
        }

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:medical_case_items,id',
            'items.*.allocated_qty' => 'required|numeric|min:0',
        ]);

        try {
            DB::transaction(function () use ($case, $validated) {
                foreach ($validated['items'] as $itemData) {
                    $item = MedicalCaseItem::where('medical_case_id', $case->id)
                        ->where('id', $itemData['id'])
                        ->firstOrFail();

                    $item->update(['allocated_qty' => $itemData['allocated_qty']]);
                }

                $case->update(['status' => 'allocated']);
            });

            return response()->json([
                'message' => 'Medical case allocated successfully',
                'data' => $case->fresh()->load(['customer', 'items.product']),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to allocate medical case',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deliver(Request $request, $id): JsonResponse
    {
        $case = MedicalCase::with('items')->find($id);

        if (!$case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        if ($case->status !== 'allocated') {
            return response()->json(['message' => 'Only allocated cases can be marked as delivered'], 403);
        }

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:medical_case_items,id',
            'items.*.delivered_qty' => 'required|numeric|min:0',
            'items.*.delivery_order_item_id' => 'nullable|exists:delivery_order_lines,id',
        ]);

        try {
            DB::transaction(function () use ($case, $validated) {
                foreach ($validated['items'] as $itemData) {
                    $item = MedicalCaseItem::where('medical_case_id', $case->id)
                        ->where('id', $itemData['id'])
                        ->firstOrFail();

                    if ($itemData['delivered_qty'] > $item->allocated_qty) {
                        throw new \RuntimeException("Delivered quantity cannot exceed allocated quantity for item {$item->id}");
                    }

                    $item->update([
                        'delivered_qty' => $itemData['delivered_qty'],
                        'delivery_order_item_id' => $itemData['delivery_order_item_id'] ?? $item->delivery_order_item_id,
                    ]);
                }

                $case->update(['status' => 'delivered']);
            });

            return response()->json([
                'message' => 'Medical case delivery recorded successfully',
                'data' => $case->fresh()->load(['customer', 'items.product']),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to record delivery',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function invoice(Request $request, $id): JsonResponse
    {
        $case = MedicalCase::with('items')->find($id);

        if (!$case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        if (!in_array($case->status, ['allocated', 'delivered'])) {
            return response()->json(['message' => 'Case must be allocated or delivered before invoicing'], 403);
        }

        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'items' => 'required|array',
            'items.*.id' => 'required|exists:medical_case_items,id',
            'items.*.invoiced_qty' => 'required|numeric|min:0',
            'items.*.invoice_item_id' => 'nullable|exists:invoice_lines,id',
        ]);

        try {
            DB::transaction(function () use ($case, $validated) {
                foreach ($validated['items'] as $itemData) {
                    $item = MedicalCaseItem::where('medical_case_id', $case->id)
                        ->where('id', $itemData['id'])
                        ->firstOrFail();

                    if ($itemData['invoiced_qty'] > $item->delivered_qty) {
                        throw new \RuntimeException("Invoiced quantity cannot exceed delivered quantity for item {$item->id}");
                    }

                    $item->update([
                        'invoiced_qty' => $itemData['invoiced_qty'],
                        'invoice_item_id' => $itemData['invoice_item_id'] ?? $item->invoice_item_id,
                    ]);
                }

                $case->update([
                    'invoice_id' => $validated['invoice_id'],
                    'status' => 'invoiced',
                ]);
            });

            return response()->json([
                'message' => 'Medical case invoiced successfully',
                'data' => $case->fresh()->load(['customer', 'items.product', 'invoice']),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to invoice medical case',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancel($id): JsonResponse
    {
        $case = MedicalCase::find($id);

        if (!$case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        if (in_array($case->status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cannot cancel a completed or already cancelled case'], 403);
        }

        try {
            $case->update(['status' => 'cancelled']);

            return response()->json([
                'message' => 'Medical case cancelled successfully',
                'data' => $case->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to cancel medical case',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function complete($id): JsonResponse
    {
        $case = MedicalCase::with('items')->find($id);

        if (!$case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        if ($case->status !== 'invoiced') {
            return response()->json(['message' => 'Only invoiced cases can be completed'], 403);
        }

        try {
            $case->update(['status' => 'completed']);

            return response()->json([
                'message' => 'Medical case completed successfully',
                'data' => $case->fresh()->load(['customer', 'items.product']),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to complete medical case',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
