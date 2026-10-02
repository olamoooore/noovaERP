<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BonusSchemeUsage;
use App\Models\Customer;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Services\CustomerWideSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            // Wide: case identity/tag/location/notes, the customer's full
            // surface (name, contacts, addresses, tags) and the case contacts.
            $search = trim((string) $request->search);
            $like = "%{$search}%";

            // Case contacts are CustomerContact records (name/job_title).
            $contactMatches = function ($contact) use ($like) {
                $contact->where('name', 'ilike', $like)
                    ->orWhere('job_title', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like)
                    ->orWhere('mobile', 'ilike', $like);
            };

            $query->where(function ($q) use ($search, $like, $contactMatches) {
                $q->where('case_no', 'ilike', $like)
                    ->orWhere('case_tag', 'ilike', $like)
                    ->orWhere('patient_reference', 'ilike', $like)
                    ->orWhere('location', 'ilike', $like)
                    ->orWhere('delivered_to', 'ilike', $like)
                    ->orWhere('notes', 'ilike', $like)
                    ->orWhereHas('customer', fn ($customer) => app(CustomerWideSearchService::class)->apply($customer, $search))
                    ->orWhereHas('doctorContact', $contactMatches)
                    ->orWhereHas('requestorContact', $contactMatches)
                    ->orWhereHas('buyerContact', $contactMatches);
            });
        }

        $cases = $query->paginate($request->get('per_page', 25));

        return response()->json($cases);
    }

    public function store(Request $request): JsonResponse
    {
        // Issue #187: the contact/address ids must belong to the case's own
        // customer, otherwise a direct API call can attribute a doctor to the
        // wrong hospital.
        $customerId = $request->input('customer_id');

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'delivery_address_id' => ['nullable', Rule::exists('customer_addresses', 'id')->where('customer_id', $customerId)],
            'doctor_contact_id' => ['nullable', Rule::exists('customer_contacts', 'id')->where('customer_id', $customerId)],
            'requestor_contact_id' => ['nullable', Rule::exists('customer_contacts', 'id')->where('customer_id', $customerId)],
            'buyer_contact_id' => ['nullable', Rule::exists('customer_contacts', 'id')->where('customer_id', $customerId)],
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

                if (! empty($validated['items'])) {
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
            'createdBy',
        ])->find($id);

        if (! $case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        return response()->json($case);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $case = MedicalCase::find($id);

        if (! $case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        if ($case->status !== 'draft') {
            return response()->json(['message' => 'Only draft cases can be edited'], 403);
        }

        // Issue #187: validate against the case's (possibly new) customer.
        $customerId = $request->input('customer_id', $case->customer_id);

        $validated = $request->validate([
            'customer_id' => 'sometimes|required|exists:customers,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'delivery_address_id' => ['nullable', Rule::exists('customer_addresses', 'id')->where('customer_id', $customerId)],
            'doctor_contact_id' => ['nullable', Rule::exists('customer_contacts', 'id')->where('customer_id', $customerId)],
            'requestor_contact_id' => ['nullable', Rule::exists('customer_contacts', 'id')->where('customer_id', $customerId)],
            'buyer_contact_id' => ['nullable', Rule::exists('customer_contacts', 'id')->where('customer_id', $customerId)],
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

        if (! $case) {
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

        if (! $case) {
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

        if (! $case) {
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

                    $previouslyDelivered = (float) $item->delivered_qty;

                    $item->update([
                        'delivered_qty' => $itemData['delivered_qty'],
                        'delivery_order_item_id' => $itemData['delivery_order_item_id'] ?? $item->delivery_order_item_id,
                    ]);

                    // Issue #184: accrue bonus usage for the newly delivered
                    // quantity, fanned out over every active scheme assignment
                    // the customer holds (same transaction as the delivery).
                    $newlyDelivered = (float) $itemData['delivered_qty'] - $previouslyDelivered;

                    if ($newlyDelivered > 0) {
                        app(\App\Services\MedicalCaseBonusAccrualService::class)
                            ->accrueForItem($item->fresh(), $newlyDelivered);
                    }
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

        if (! $case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        if (! in_array($case->status, ['allocated', 'delivered'])) {
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

    /**
     * Issue #185: hospital/doctor performance report.
     */
    public function performanceReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'date_field' => 'nullable|in:case_date,procedure_date',
        ]);

        $report = app(\App\Services\MedicalCaseReportingService::class)->performance(
            \Carbon\Carbon::parse($validated['from_date']),
            \Carbon\Carbon::parse($validated['to_date']),
            $validated['date_field'] ?? 'case_date',
        );

        return response()->json(['data' => $report]);
    }

    public function cancel($id): JsonResponse
    {
        $case = MedicalCase::with('items')->find($id);

        if (! $case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        if (in_array($case->status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cannot cancel a completed or already cancelled case'], 403);
        }

        try {
            // Issue #189: cancelling a delivered/invoiced case must reverse the
            // case's own fulfilment figures, otherwise downstream reports keep
            // counting delivered/invoiced quantities for a case the business
            // considers reversed.
            DB::transaction(function () use ($case) {
                if (in_array($case->status, ['delivered', 'invoiced'], true)) {
                    $itemIds = $case->items->pluck('id')->all();

                    foreach ($case->items as $item) {
                        $item->update([
                            'delivered_qty' => 0,
                            'invoiced_qty' => 0,
                        ]);
                    }

                    // Reverse any bonus accrual rows tied to this case's items.
                    if ($itemIds !== []) {
                        BonusSchemeUsage::whereIn('medical_case_item_id', $itemIds)
                            ->where('status', '!=', 'reversed')
                            ->get()
                            ->each(fn (BonusSchemeUsage $usage) => $usage->reverse());
                    }
                }

                $case->update(['status' => 'cancelled']);
            });

            // The linked invoice is deliberately left untouched: spec §9 rule 9
            // forbids editing confirmed invoices directly and rule 5 allows one
            // invoice to cover several cases — corrections go through credit
            // note / return / cancellation. Surface it so the operator can act.
            $warnings = [];

            if ($case->invoice_id) {
                $warnings[] = 'The linked invoice was not modified. Confirmed invoices must be corrected through a credit note, return or cancellation.';
            }

            return response()->json([
                'message' => 'Medical case cancelled successfully',
                'data' => $case->fresh()->load('items'),
                'warnings' => $warnings,
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

        if (! $case) {
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
