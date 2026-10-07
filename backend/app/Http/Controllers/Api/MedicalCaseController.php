<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BonusSchemeUsage;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\ProductBatch;
use App\Models\ProductLot;
use App\Platform\Workflow\WorkflowDeniedException;
use App\Services\CustomerWideSearchService;
use App\Services\EntityAccessService;
use App\Services\SalesWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Services\StockReservationService;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\DeliveryOrderService;

class MedicalCaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MedicalCase::with(['customer', 'invoice', 'items.product', 'doctorContact', 'requestorContact', 'buyerContact'])
            ->latest();

        // Issue #575 (MC11): only the requester's legal entities.
        $this->applyEntityScope($request, $query);

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

    /**
     * Issue #575 (MC11): the requester's accessible legal entities, or null
     * when the user has no entity grants (historical unscoped view — the same
     * fail-open default as EnsureEntityAccess / #140 / #462).
     *
     * @return int[]|null
     */
    private function accessibleEntityIds(Request $request): ?array
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        $entityIds = app(EntityAccessService::class)->accessibleEntityIds((int) $user->id);

        return $entityIds === [] ? null : $entityIds;
    }

    private function applyEntityScope(Request $request, $query): void
    {
        $entityIds = $this->accessibleEntityIds($request);

        if ($entityIds !== null) {
            $query->accessibleBy($entityIds);
        }
    }

    /**
     * Issue #575 (MC11): resolve a case inside the requester's entity scope;
     * out-of-scope ids behave as missing (404).
     *
     * @param  array<int, string>  $with
     */
    private function findScopedCase(Request $request, $id, array $with = []): ?MedicalCase
    {
        $query = MedicalCase::query()->with($with);
        $this->applyEntityScope($request, $query);

        return $query->find($id);
    }

    /**
     * Issue #575 (MC11): a case may only be raised for a customer inside the
     * requester's legal entities.
     *
     * @throws ValidationException
     */
    private function assertCustomerInEntityScope(Request $request, int $customerId): void
    {
        $entityIds = $this->accessibleEntityIds($request);

        if ($entityIds === null) {
            return;
        }

        if (! Customer::query()->whereKey($customerId)->accessibleBy($entityIds)->exists()) {
            throw ValidationException::withMessages([
                'customer_id' => 'The customer belongs to a legal entity you do not have access to.',
            ]);
        }
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
            // Issue #565 (MC3): the line is cross-checked against the linked
            // sales order line below (customer, product, lot, open quantity).
            // The legacy `product_lots` table has no migration in this repo, so
            // the inline `exists` rule is gone — it 500s when lot_id is sent.
            'items.*.lot_id' => 'nullable|integer',
            'items.*.allocated_qty' => 'required_with:items|numeric|min:0',
            // Issue #563 (MC2): the client value is ignored — the case line
            // price is derived below from the linked sales order line.
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.is_foc' => 'nullable|boolean',
            'items.*.notes' => 'nullable|string',
        ]);

        // Issue #567: a user who can't read the patient name can't write it.
        $validated = (new MedicalCase)->filterSensitiveWrite($validated);

        $this->assertCustomerInEntityScope($request, (int) $validated['customer_id']);

        $this->assertLinkableInvoice($validated['invoice_id'] ?? null, (int) $validated['customer_id']);

        $items = $this->prepareItems($validated['items'] ?? [], (int) $validated['customer_id']);
        unset($validated['items']);

        try {
            $case = DB::transaction(function () use ($validated, $items, $request) {
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

                if ($items !== []) {
                    foreach ($items as $item) {
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

    /**
     * Issue #563 (MC2): a case line's `unit_price` is the final approved
     * price snapshot of the linked sales order line — not free client input.
     * It drives the percentage reward and the performance report, so it is
     * derived here and any submitted value is ignored.
     *
     * The sales order line is the canonical source: the case item requires
     * `sales_order_item_id` at creation, and a delivery order line (when one
     * exists) points back to the same sales order line via
     * `sales_order_line_id`. FOC lines are free of charge and always
     * snapshot 0 (module spec §9 rule 8: is_foc = true, unit_price = 0).
     *
     * Issue #565 (MC3): the same pass cross-checks the line against its sales
     * order: the order must belong to the case customer, the product must be
     * the line's product, the lot must belong to that product, and the
     * allocated quantity may not exceed the line's open quantity after every
     * other case's allocation (and earlier lines of the same payload).
     *
     * Fails closed: without a resolvable linked line there is no approved
     * price to snapshot, so the case is rejected.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function prepareItems(array $items, int $customerId): array
    {
        if ($items === []) {
            return [];
        }

        $lineIds = array_column($items, 'sales_order_item_id');

        $lines = SalesOrderLine::query()
            ->with('salesOrder')
            ->whereIn('id', $lineIds)
            ->get()
            ->keyBy('id');

        // Allocated quantities already held by other, non-cancelled cases.
        $allocatedElsewhere = MedicalCaseItem::query()
            ->whereIn('sales_order_item_id', $lineIds)
            ->whereHas('medicalCase', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->groupBy('sales_order_item_id')
            ->selectRaw('sales_order_item_id, SUM(allocated_qty) as total_allocated')
            ->pluck('total_allocated', 'sales_order_item_id');

        $allocatedInPayload = [];

        foreach ($items as $index => $item) {
            $line = $lines->get($item['sales_order_item_id']);

            if (! $line) {
                throw ValidationException::withMessages([
                    "items.{$index}.sales_order_item_id" => 'The linked sales order line could not be found, so the case line price cannot be derived.',
                ]);
            }

            $order = $line->salesOrder;

            if (! $order || (int) $order->customer_id !== $customerId) {
                throw ValidationException::withMessages([
                    "items.{$index}.sales_order_item_id" => 'The linked sales order line must belong to the case customer.',
                ]);
            }

            if (($line->line_type ?? 'product') !== 'product' || (int) $line->product_id !== (int) $item['product_id']) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'The product must match the linked sales order line.',
                ]);
            }

            $lotId = $item['lot_id'] ?? null;

            if ($lotId !== null && ! $this->lotBelongsToProduct((int) $lotId, (int) $item['product_id'])) {
                throw ValidationException::withMessages([
                    "items.{$index}.lot_id" => 'The selected lot does not belong to the case line product.',
                ]);
            }

            $alreadyAllocated = (float) ($allocatedElsewhere[$line->id] ?? 0)
                + (float) ($allocatedInPayload[$line->id] ?? 0);
            $openQty = round((float) $line->quantity - $alreadyAllocated, 4);

            if (round((float) $item['allocated_qty'], 4) > $openQty) {
                throw ValidationException::withMessages([
                    "items.{$index}.allocated_qty" => "The allocated quantity exceeds the linked sales order line's open quantity of {$openQty}.",
                ]);
            }

            $allocatedInPayload[$line->id] = (float) ($allocatedInPayload[$line->id] ?? 0)
                + (float) $item['allocated_qty'];

            $items[$index]['unit_price'] = ($item['is_foc'] ?? false)
                ? 0.0
                : (float) $line->unit_price;
        }

        return $items;
    }

    /**
     * Issue #565 (MC3): `medical_case_items.lot_id` documents a `product_lots`
     * (ProductLot) row, but that legacy table has no migration in this repo.
     * When it exists, the lot must belong to the product. Otherwise the real
     * `product_batches` (ProductBatch) ledger is the lot source. If neither
     * exists the lot cannot be proven, so the line is rejected (fail closed).
     */
    private function lotBelongsToProduct(int $lotId, int $productId): bool
    {
        if (Schema::hasTable('product_lots')) {
            return ProductLot::query()
                ->whereKey($lotId)
                ->where('product_id', $productId)
                ->exists();
        }

        if (Schema::hasTable('product_batches')) {
            return ProductBatch::query()
                ->whereKey($lotId)
                ->where('product_id', $productId)
                ->exists();
        }

        return false;
    }

    public function show(Request $request, $id): JsonResponse
    {
        $with = [
            'customer',
            'invoice',
            'items.product',
            'items.salesOrderItem',
            'items.invoiceItem',
            'items.deliveryOrderItem.deliveryOrder',
            'doctorContact',
            'requestorContact',
            'buyerContact',
            'deliveryAddress',
            'createdBy',
        ];

        // The lot relation reads the legacy `product_lots` table, which may not
        // exist; eager loading it with non-null lot ids would 500 the request.
        if (Schema::hasTable('product_lots')) {
            $with[] = 'items.lot';
        }

        $case = $this->findScopedCase($request, $id, $with);

        if (! $case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        // Issue #578 (UX3): the linked DO(s) and invoice, with their status,
        // for the chips on the case header.
        $case->setAttribute('linked_delivery_orders', $this->linkedDeliveryOrders($case));

        return response()->json($case);
    }

    /**
     * Issue #578: the distinct delivery orders the case lines are linked to.
     *
     * @return array<int, array<string, mixed>>
     */
    private function linkedDeliveryOrders(MedicalCase $case): array
    {
        $case->loadMissing('items.deliveryOrderItem.deliveryOrder');

        return $case->items
            ->map(fn (MedicalCaseItem $item) => $item->deliveryOrderItem?->deliveryOrder)
            ->filter()
            ->unique('id')
            ->sortBy('id')
            ->map(fn (DeliveryOrder $order) => [
                'id' => $order->id,
                'delivery_order_number' => $order->delivery_order_number,
                'status' => $order->status,
                'delivery_date' => $order->delivery_date,
            ])
            ->values()
            ->all();
    }

    public function update(Request $request, $id): JsonResponse
    {
        $case = $this->findScopedCase($request, $id);

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

        // Issue #567: a user who can't read the patient name can't write it;
        // the masked value echoed back by the edit form is never saved.
        $validated = $case->filterSensitiveWrite($validated);

        if (array_key_exists('customer_id', $validated)) {
            $this->assertCustomerInEntityScope($request, (int) $validated['customer_id']);
        }

        // Issue #566 (MC4): the (possibly new) invoice must still belong to
        // the (possibly new) case customer.
        if (array_key_exists('invoice_id', $validated) || array_key_exists('customer_id', $validated)) {
            $this->assertLinkableInvoice(
                array_key_exists('invoice_id', $validated) ? $validated['invoice_id'] : $case->invoice_id,
                (int) ($validated['customer_id'] ?? $case->customer_id),
            );
        }

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

    public function destroy(Request $request, $id): JsonResponse
    {
        $case = $this->findScopedCase($request, $id);

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
        $case = $this->findScopedCase($request, $id, ['items']);

        if (! $case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        if ($case->status !== 'draft') {
            return response()->json(['message' => 'Only draft cases can be allocated'], 403);
        }

        $validated = $request->validate([
            'items' => 'required|array',
            // Issue #575 (MC11): a line of another case is a validation
            // failure (422), not a ModelNotFoundException surfaced as 500.
            'items.*.id' => ['required', Rule::exists('medical_case_items', 'id')->where('medical_case_id', $case->id)],
            'items.*.allocated_qty' => 'required|numeric|min:0',
        ]);

        try {
            DB::transaction(function () use ($case, $validated) {
                $items = collect($validated['items'])->map(function ($itemData) use ($case) {
                    return MedicalCaseItem::where('medical_case_id', $case->id)
                        ->where('id', $itemData['id'])
                        ->firstOrFail();
                });

                $lineIds = $items->pluck('sales_order_item_id')->unique()->values()->all();

                $lines = SalesOrderLine::query()
                    ->whereIn('id', $lineIds)
                    ->get()
                    ->keyBy('id');

                // Quantities held by every line not being re-allocated in this
                // payload (other cases and this case's untouched items).
                $allocatedElsewhere = MedicalCaseItem::query()
                    ->whereIn('sales_order_item_id', $lineIds)
                    ->whereNotIn('id', $items->pluck('id')->all())
                    ->whereHas('medicalCase', fn ($query) => $query->where('status', '!=', 'cancelled'))
                    ->groupBy('sales_order_item_id')
                    ->selectRaw('sales_order_item_id, SUM(allocated_qty) as total_allocated')
                    ->pluck('total_allocated', 'sales_order_item_id');

                $allocatedInPayload = [];

                foreach ($validated['items'] as $index => $itemData) {
                    $item = $items[$index];
                    $line = $lines->get($item->sales_order_item_id);
                    $quantity = (float) $itemData['allocated_qty'];

                    if (! $line) {
                        throw ValidationException::withMessages([
                            "items.{$index}.allocated_qty" => 'The linked sales order line could not be found.',
                        ]);
                    }

                    $alreadyAllocated = (float) ($allocatedElsewhere[$line->id] ?? 0)
                        + (float) ($allocatedInPayload[$line->id] ?? 0);
                    $openQty = round((float) $line->quantity - $alreadyAllocated, 4);

                    if (round($quantity, 4) > $openQty) {
                        throw ValidationException::withMessages([
                            "items.{$index}.allocated_qty" => "The allocated quantity exceeds the linked sales order line's open quantity of {$openQty}.",
                        ]);
                    }

                    $allocatedInPayload[$line->id] = (float) ($allocatedInPayload[$line->id] ?? 0) + $quantity;

                    $item->update(['allocated_qty' => $itemData['allocated_qty']]);

                    $this->reserveCaseItem($case, $item, $quantity, $index);
                }

                app(SalesWorkflowService::class)->transition($case, 'allocate');
            });

            return response()->json([
                'message' => 'Medical case allocated successfully',
                'data' => $case->fresh()->load(['customer', 'items.product']),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to allocate medical case',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Issue #565 (MC3): allocation now holds stock through the platform
     * StockReservationService instead of only writing `allocated_qty`.
     *
     * The warehouse comes from the linked sales order (the case has none).
     * Products that do not track inventory, or orders without a warehouse,
     * have nothing to reserve against — mirroring SalesOrderService's
     * confirmation path. Insufficient stock fails the allocation as a 422.
     */
    private function reserveCaseItem(MedicalCase $case, MedicalCaseItem $item, float $quantity, int $index): void
    {
        if (round($quantity, 4) <= 0) {
            return;
        }

        $item->loadMissing(['product', 'salesOrderItem.salesOrder']);

        $order = $item->salesOrderItem?->salesOrder;

        if (! $item->product || ! (bool) $item->product->track_inventory || ! $order?->warehouse_id) {
            return;
        }

        try {
            app(StockReservationService::class)->reserve(
                productId: (int) $item->product_id,
                warehouseId: (int) $order->warehouse_id,
                quantity: $quantity,
                sourceDocumentType: 'MedicalCase',
                sourceDocumentId: (int) $case->id,
                sourceDocumentLineId: (int) $item->id,
                notes: "Reserved for medical case {$case->case_no}",
            );
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                "items.{$index}.allocated_qty" => $e->getMessage(),
            ]);
        }
    }

    public function deliver(Request $request, $id): JsonResponse
    {
        $case = $this->findScopedCase($request, $id, ['items']);

        if (! $case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        // Issue #570 (MC7): a first partial delivery leaves the case
        // `partially_delivered`, and the remaining lines may be delivered in a
        // later call — only a case already fully delivered is closed off.
        if (! in_array($case->status, ['allocated', 'partially_delivered'], true)) {
            return response()->json(['message' => 'Only allocated or partially delivered cases can be delivered'], 403);
        }

        $validated = $request->validate([
            'items' => 'required|array',
            // Issue #575 (MC11): a line of another case is a validation
            // failure (422), not a ModelNotFoundException surfaced as 500.
            'items.*.id' => ['required', Rule::exists('medical_case_items', 'id')->where('medical_case_id', $case->id)],
            'items.*.delivered_qty' => 'required|numeric|min:0',
            // Issue #562 (MC1): a case delivery must be recorded from a real
            // shipped document — a hand-typed quantity with no completed DO
            // line can no longer earn rewards.
            'items.*.delivery_order_item_id' => 'required|integer|exists:delivery_order_lines,id',
        ]);

        try {
            DB::transaction(function () use ($case, $validated) {
                foreach ($validated['items'] as $index => $itemData) {
                    $item = MedicalCaseItem::where('medical_case_id', $case->id)
                        ->where('id', $itemData['id'])
                        ->firstOrFail();

                    $deliveryOrderLine = DeliveryOrderLine::with('deliveryOrder')
                        ->lockForUpdate()
                        ->find($itemData['delivery_order_item_id']);

                    // Issue #570 (MC7): every call links an additional chunk
                    // from a completed DO line; the line's cumulative delivered
                    // quantity (capped by the case line's allocated quantity)
                    // is what the derived case status is judged on.
                    $previouslyDelivered = (float) $item->delivered_qty;
                    $newlyDelivered = (float) $itemData['delivered_qty'];
                    $deliveredTotal = round($previouslyDelivered + $newlyDelivered, 4);

                    if ($deliveredTotal > round((float) $item->allocated_qty, 4)) {
                        throw ValidationException::withMessages([
                            "items.{$index}.delivered_qty" => "The delivered quantity cannot exceed the allocated quantity of {$item->allocated_qty} for this case line.",
                        ]);
                    }

                    $this->assertDeliverableFromLine($case, $item, $deliveryOrderLine, $newlyDelivered, $index);

                    $item->update([
                        'delivered_qty' => $deliveredTotal,
                        'delivery_order_item_id' => $deliveryOrderLine->id,
                    ]);

                    // Issue #184: accrue bonus usage for the newly delivered
                    // quantity, fanned out over every active scheme assignment
                    // the customer holds (same transaction as the delivery).
                    if ($newlyDelivered > 0) {
                        app(\App\Services\MedicalCaseBonusAccrualService::class)
                            ->accrueForItem($item->fresh(), $newlyDelivered);
                    }
                }

                // Issue #573: the derived status is applied through the
                // WorkflowEngine, never stamped directly.
                $fresh = $case->fresh('items');
                $action = $this->derivedDeliveryStatus($fresh) === 'delivered' ? 'deliver' : 'deliver_partial';
                app(SalesWorkflowService::class)->transition($fresh, $action);
            });

            return response()->json([
                'message' => 'Medical case delivery recorded successfully',
                'data' => $case->fresh()->load(['customer', 'items.product']),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to record delivery',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Issue #562 (MC1): the delivered quantity may only be recorded from a
     * product line of a completed delivery order for the same customer, whose
     * delivered quantity still has room after every other case already linked
     * to that line.
     */
    private function assertDeliverableFromLine(
        MedicalCase $case,
        MedicalCaseItem $item,
        ?DeliveryOrderLine $line,
        float $deliveredQty,
        int $index,
    ): void {
        $error = fn (string $message) => ValidationException::withMessages([
            "items.{$index}.delivery_order_item_id" => $message,
        ]);

        if (! $line) {
            throw $error('The delivery order line does not exist.');
        }

        $order = $line->deliveryOrder;

        if (! $order) {
            throw $error('The delivery order line has no delivery order.');
        }

        if (($line->line_type ?? 'product') !== 'product') {
            throw $error('Only product lines of a completed delivery order can back a case delivery.');
        }

        if (! $order->isDone()) {
            throw $error('The delivery order line must belong to a completed delivery order.');
        }

        if ((int) $order->customer_id !== (int) $case->customer_id) {
            throw $error('The delivery order line must belong to the same customer as the medical case.');
        }

        if ((int) $line->product_id !== (int) $item->product_id) {
            throw $error('The delivery order line must be for the same product as the medical case item.');
        }

        $alreadyLinked = (float) MedicalCaseItem::query()
            ->where('delivery_order_item_id', $line->id)
            ->where('id', '!=', $item->id)
            ->sum('delivered_qty');

        // Issue #570 (MC7): when the same case line is delivered in several
        // chunks from the same DO line, the chunks this item already linked
        // must be subtracted too — otherwise each call could spend the line's
        // full delivered quantity again.
        $alreadyLinkedByThisItem = (int) $item->delivery_order_item_id === (int) $line->id
            ? (float) $item->delivered_qty
            : 0.0;

        $remaining = round((float) $line->quantity_delivered - $alreadyLinked - $alreadyLinkedByThisItem, 4);

        if (round($deliveredQty, 4) > $remaining) {
            throw $error("The delivered quantity exceeds the delivery order line's remaining quantity of {$remaining}.");
        }
    }

    /**
     * Issue #570 (MC7): the case status is derived, never stamped. A case is
     * `delivered` only once every line's allocated quantity has been linked to
     * completed DO lines; until then it stays `partially_delivered`.
     */
    private function derivedDeliveryStatus(MedicalCase $case): string
    {
        foreach ($case->items as $item) {
            if (round((float) $item->delivered_qty, 4) < round((float) $item->allocated_qty, 4)) {
                return 'partially_delivered';
            }
        }

        return 'delivered';
    }

    /**
     * Issue #578 (UX3): "Create DO" from the case lines. The delivery order is
     * created through DeliveryOrderService (one per linked sales order) for
     * each line's undelivered allocation, and every case line is linked to its
     * new DO line. Nothing is delivered yet: once the DO is completed the
     * delivery is recorded against that line (#562), which is what earns
     * usage and moves the case status.
     */
    public function createDeliveryOrder(Request $request, $id): JsonResponse
    {
        $case = $this->findScopedCase($request, $id, [
            'items.salesOrderItem.salesOrder',
            'items.deliveryOrderItem.deliveryOrder',
        ]);

        if (! $case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        if (! in_array($case->status, ['allocated', 'partially_delivered'], true)) {
            throw ValidationException::withMessages([
                'status' => 'A delivery order can only be created for an allocated or partially delivered case.',
            ]);
        }

        $groups = [];

        foreach ($case->items as $item) {
            $remaining = round((float) $item->allocated_qty - (float) $item->delivered_qty, 4);

            if ($remaining <= 0) {
                continue;
            }

            $linkedOrder = $item->deliveryOrderItem?->deliveryOrder;

            if ($linkedOrder && ! $linkedOrder->isDone() && ! $linkedOrder->isCancelled()) {
                throw ValidationException::withMessages([
                    'items' => "Case line {$item->id} is already on open delivery order {$linkedOrder->delivery_order_number}. Complete or cancel it first.",
                ]);
            }

            $order = $item->salesOrderItem?->salesOrder;

            if (! $order) {
                throw ValidationException::withMessages([
                    'items' => "Case line {$item->id} has no linked sales order to deliver from.",
                ]);
            }

            $groups[$order->id]['order'] = $order;
            $groups[$order->id]['items'][] = ['item' => $item, 'quantity' => $remaining];
        }

        if ($groups === []) {
            throw ValidationException::withMessages([
                'items' => 'Every case line is already delivered; there is nothing left to put on a delivery order.',
            ]);
        }

        try {
            $deliveryOrders = DB::transaction(function () use ($case, $groups) {
                $created = [];

                foreach ($groups as $group) {
                    $deliveryOrder = app(DeliveryOrderService::class)->createDeliveryOrder($group['order'], [
                        'lines' => array_map(fn (array $entry) => [
                            'sales_order_line_id' => (int) $entry['item']->sales_order_item_id,
                            'quantity_delivered' => $entry['quantity'],
                        ], $group['items']),
                        'notes' => "Medical case {$case->case_no}",
                    ]);

                    // Link each case line to the DO line created for it (same
                    // sales order line, consumed in submission order).
                    $unused = $deliveryOrder->lines->sortBy('id')->values()->all();

                    foreach ($group['items'] as $entry) {
                        foreach ($unused as $key => $line) {
                            if ((int) $line->sales_order_line_id === (int) $entry['item']->sales_order_item_id) {
                                $entry['item']->update(['delivery_order_item_id' => $line->id]);
                                unset($unused[$key]);

                                break;
                            }
                        }
                    }

                    $created[] = $deliveryOrder;
                }

                return $created;
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            // DeliveryOrderService signals business-rule refusals (order
            // status, remaining quantity, shipping policy) as \Exception —
            // the same 422 mapping as DeliveryOrderController::store.
            return response()->json([
                'message' => 'Failed to create the delivery order: '.$e->getMessage(),
                'errors' => ['delivery_order' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'message' => 'Delivery order created from the medical case',
            'data' => [
                'delivery_orders' => array_map(fn (DeliveryOrder $order) => [
                    'id' => $order->id,
                    'delivery_order_number' => $order->delivery_order_number,
                    'status' => $order->status,
                ], $deliveryOrders),
                'case' => $case->fresh()->load(['customer', 'items.product']),
            ],
        ], 201);
    }

    /**
     * Issue #578 (UX3): "Link to DO line" — the completed delivery order
     * lines of the case customer that could back each case line's delivery
     * (same product, delivered quantity not yet claimed by other cases).
     * Keyed by case item id.
     */
    public function deliveryOrderLines(Request $request, $id): JsonResponse
    {
        $case = $this->findScopedCase($request, $id, ['items']);

        if (! $case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        $data = [];

        foreach ($case->items as $item) {
            $lines = DeliveryOrderLine::query()
                ->with('deliveryOrder:id,delivery_order_number,delivery_date,status,customer_id')
                ->where('product_id', $item->product_id)
                ->where(fn ($query) => $query->whereNull('line_type')->orWhere('line_type', 'product'))
                ->whereHas('deliveryOrder', fn ($query) => $query
                    ->where('status', 'done')
                    ->where('customer_id', $case->customer_id))
                ->latest('id')
                ->limit(50)
                ->get();

            $claimed = MedicalCaseItem::query()
                ->whereIn('delivery_order_item_id', $lines->pluck('id')->all())
                ->where('id', '!=', $item->id)
                ->groupBy('delivery_order_item_id')
                ->selectRaw('delivery_order_item_id, SUM(delivered_qty) as total_delivered')
                ->pluck('total_delivered', 'delivery_order_item_id');

            $data[$item->id] = $lines
                ->map(function (DeliveryOrderLine $line) use ($item, $claimed) {
                    $ownLinked = (int) $item->delivery_order_item_id === (int) $line->id
                        ? (float) $item->delivered_qty
                        : 0.0;

                    return [
                        'id' => $line->id,
                        'delivery_order_id' => $line->delivery_order_id,
                        'delivery_order_number' => $line->deliveryOrder?->delivery_order_number,
                        'delivery_date' => $line->deliveryOrder?->delivery_date,
                        'quantity_delivered' => (float) $line->quantity_delivered,
                        'remaining_qty' => round((float) $line->quantity_delivered - (float) ($claimed[$line->id] ?? 0) - $ownLinked, 4),
                        'is_linked' => (int) $item->delivery_order_item_id === (int) $line->id,
                    ];
                })
                ->filter(fn (array $line) => $line['remaining_qty'] > 0)
                ->values()
                ->all();
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Issue #578 (UX3): "Create invoice" from the case. Bills each chargeable
     * case line's delivered-but-uninvoiced quantity from the completed DO
     * line it was delivered from, through the shared delivery-order
     * invoicing path (DeliveryOrderService::createInvoiceFromDeliveryLines:
     * tax, terms, currency, DO/SO invoiced quantities). The new invoice lines
     * are then linked to the case lines through the same rules as linking an
     * existing invoice (#566). FOC lines are redeemed free of charge and are
     * not billed (#573 fulfilment rule).
     */
    public function createInvoice(Request $request, $id): JsonResponse
    {
        $case = $this->findScopedCase($request, $id, ['items.deliveryOrderItem.deliveryOrder']);

        if (! $case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        // Same precondition as linking an invoice (#573 MC8).
        if (! in_array($case->status, ['partially_delivered', 'delivered'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Case must be partially delivered or delivered before invoicing.',
            ]);
        }

        $deliveryService = app(DeliveryOrderService::class);
        $entries = [];
        $billedItems = [];

        foreach ($case->items as $item) {
            if ($item->is_foc) {
                continue;
            }

            $toBill = round((float) $item->delivered_qty - (float) $item->invoiced_qty, 4);

            if ($toBill <= 0) {
                continue;
            }

            if (round((float) $item->invoiced_qty, 4) > 0) {
                // A case line maps to a single invoice line (#566).
                throw ValidationException::withMessages([
                    'items' => "Case line {$item->id} is already partly invoiced; link the remaining quantity to an existing invoice instead.",
                ]);
            }

            $line = $item->deliveryOrderItem;
            $deliveryOrder = $line?->deliveryOrder;

            if (! $line || ! $deliveryOrder || ! $deliveryService->isInvoiceableStatus($deliveryOrder)) {
                throw ValidationException::withMessages([
                    'items' => "Case line {$item->id} has no completed delivery order line to bill from.",
                ]);
            }

            if ($toBill > $deliveryOrder->invoiceableQuantityForLine($line)) {
                throw ValidationException::withMessages([
                    'items' => "Delivery order {$deliveryOrder->delivery_order_number} has already been invoiced for case line {$item->id}; link that invoice instead.",
                ]);
            }

            $entries[] = ['line' => $line, 'quantity' => $toBill];
            $billedItems[] = $item;
        }

        if ($entries === []) {
            throw ValidationException::withMessages([
                'items' => 'There is nothing left to invoice on this case.',
            ]);
        }

        // Same approval gate as DO "Create Invoice", per delivery order.
        foreach (collect($entries)->map(fn (array $entry) => $entry['line']->deliveryOrder)->unique('id') as $deliveryOrder) {
            if ($gate = $deliveryService->requestInvoiceApprovalIfNeeded($deliveryOrder, $request->user())) {
                return response()->json([
                    'message' => "Approval required before delivery order {$deliveryOrder->delivery_order_number} can be invoiced",
                    'requires_approval' => true,
                    'approval_request' => $gate['approval_request'],
                    'threshold' => $gate['threshold'],
                ], 202);
            }
        }

        try {
            $invoice = DB::transaction(function () use ($case, $deliveryService, $entries, $billedItems) {
                $invoice = $deliveryService->createInvoiceFromDeliveryLines($entries);

                // Invoice lines are created in the order of $entries.
                $invoiceLines = InvoiceLine::query()
                    ->where('invoice_id', $invoice->id)
                    ->where(fn ($query) => $query->whereNull('line_type')->orWhere('line_type', 'product'))
                    ->orderBy('id')
                    ->get()
                    ->values();

                $payload = [];
                foreach ($billedItems as $index => $item) {
                    $payload[] = [
                        'id' => $item->id,
                        'invoiced_qty' => $entries[$index]['quantity'],
                        'invoice_item_id' => $invoiceLines[$index]->id,
                    ];
                }

                $this->linkInvoiceLines($case, (int) $invoice->id, $payload);

                return $invoice;
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => 'Failed to create the invoice: '.$e->getMessage(),
                'errors' => ['invoice' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'message' => 'Invoice created from the medical case',
            'data' => [
                'invoice' => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'status' => $invoice->status,
                ],
                'case' => $case->fresh()->load(['customer', 'items.product', 'invoice']),
            ],
        ], 201);
    }

    public function invoice(Request $request, $id): JsonResponse
    {
        $case = $this->findScopedCase($request, $id, ['items']);

        if (! $case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        // Issue #573 (MC8): from `allocated` nothing is delivered, so the
        // invoiced quantity could only be 0 — yet the case was stamped
        // invoiced and could then be completed. Invoicing needs delivered
        // quantities to bill against.
        if (! in_array($case->status, ['partially_delivered', 'delivered'], true)) {
            return response()->json(['message' => 'Case must be partially delivered or delivered before invoicing'], 403);
        }

        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'items' => 'required|array',
            // Issue #575 (MC11): a line of another case is a validation
            // failure (422), not a ModelNotFoundException surfaced as 500.
            'items.*.id' => ['required', Rule::exists('medical_case_items', 'id')->where('medical_case_id', $case->id)],
            'items.*.invoiced_qty' => 'required|numeric|min:0',
            // Issue #566 (MC4): every case line maps to a line of the invoice.
            'items.*.invoice_item_id' => 'required|integer|exists:invoice_lines,id',
        ]);

        $this->assertLinkableInvoice((int) $validated['invoice_id'], (int) $case->customer_id);

        try {
            DB::transaction(fn () => $this->linkInvoiceLines($case, (int) $validated['invoice_id'], $validated['items']));

            return response()->json([
                'message' => 'Medical case invoiced successfully',
                'data' => $case->fresh()->load(['customer', 'items.product', 'invoice']),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to invoice medical case',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Issue #566 (MC4): map each case line to a line of the invoice (same
     * product, within the invoice line's remaining quantity and the case
     * line's delivered quantity), link the invoice to the case and — once
     * every used line is delivered and invoiced — move the case to
     * `invoiced` through the WorkflowEngine (#573).
     *
     * Issue #578: shared by the "link an existing invoice" route and the
     * case "Create invoice" route. Runs inside the caller's transaction.
     *
     * @param  array<int, array{id: int|string, invoiced_qty: int|float|string, invoice_item_id: int|string}>  $itemsPayload
     *
     * @throws ValidationException
     */
    private function linkInvoiceLines(MedicalCase $case, int $invoiceId, array $itemsPayload): void
    {
        $items = collect($itemsPayload)->map(function ($itemData) use ($case) {
            return MedicalCaseItem::where('medical_case_id', $case->id)
                ->where('id', $itemData['id'])
                ->firstOrFail();
        });

        $invoiceLineIds = collect($itemsPayload)->pluck('invoice_item_id')->unique()->values()->all();

        $invoiceLines = InvoiceLine::query()
            ->whereIn('id', $invoiceLineIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Quantities other live case lines already invoiced against
        // the same invoice lines (this payload's items excluded).
        $invoicedElsewhere = MedicalCaseItem::query()
            ->whereIn('invoice_item_id', $invoiceLineIds)
            ->whereNotIn('id', $items->pluck('id')->all())
            ->whereHas('medicalCase', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->groupBy('invoice_item_id')
            ->selectRaw('invoice_item_id, SUM(invoiced_qty) as total_invoiced')
            ->pluck('total_invoiced', 'invoice_item_id');

        $invoicedInPayload = [];

        foreach ($itemsPayload as $index => $itemData) {
            $item = $items[$index];
            $quantity = (float) $itemData['invoiced_qty'];

            if (round($quantity, 4) > round((float) $item->delivered_qty, 4)) {
                throw ValidationException::withMessages([
                    "items.{$index}.invoiced_qty" => "The invoiced quantity cannot exceed the delivered quantity of {$item->delivered_qty} for this case line.",
                ]);
            }

            $invoiceLine = $invoiceLines->get((int) $itemData['invoice_item_id']);

            if (! $invoiceLine || (int) $invoiceLine->invoice_id !== $invoiceId) {
                throw ValidationException::withMessages([
                    "items.{$index}.invoice_item_id" => 'The invoice line must belong to the selected invoice.',
                ]);
            }

            if (($invoiceLine->line_type ?? 'product') !== 'product' || (int) $invoiceLine->product_id !== (int) $item->product_id) {
                throw ValidationException::withMessages([
                    "items.{$index}.invoice_item_id" => 'The invoice line must be for the same product as the case line.',
                ]);
            }

            $alreadyInvoiced = (float) ($invoicedElsewhere[$invoiceLine->id] ?? 0)
                + (float) ($invoicedInPayload[$invoiceLine->id] ?? 0);
            $openQty = round((float) $invoiceLine->quantity - $alreadyInvoiced, 4);

            if (round($quantity, 4) > $openQty) {
                throw ValidationException::withMessages([
                    "items.{$index}.invoiced_qty" => "The invoiced quantity exceeds the invoice line's remaining quantity of {$openQty}.",
                ]);
            }

            $invoicedInPayload[$invoiceLine->id] = (float) ($invoicedInPayload[$invoiceLine->id] ?? 0) + $quantity;

            $item->update([
                'invoiced_qty' => $itemData['invoiced_qty'],
                'invoice_item_id' => $invoiceLine->id,
            ]);
        }

        $case->update(['invoice_id' => $invoiceId]);

        // Issue #573 (MC8): the case is `invoiced` only once every used
        // line is fully delivered and fully invoiced (FOC lines are
        // redeemed free of charge). A partial invoice leaves the
        // derived delivery status in place.
        $fresh = $case->fresh('items');

        if ($fresh->status === 'delivered' && $fresh->fulfilmentBlocker() === null) {
            app(SalesWorkflowService::class)->transition($fresh, 'invoice');
        }
    }

    /**
     * Issue #566 (MC4): a case may only be linked to an invoice of its own
     * customer (module spec §9 rule 6) that is still live — not cancelled,
     * voided, reversed or superseded.
     *
     * @throws ValidationException
     */
    private function assertLinkableInvoice(?int $invoiceId, int $customerId): void
    {
        if ($invoiceId === null) {
            return;
        }

        $invoice = Invoice::query()->find($invoiceId);

        if (! $invoice || (int) $invoice->customer_id !== $customerId) {
            throw ValidationException::withMessages([
                'invoice_id' => 'The invoice must belong to the case customer.',
            ]);
        }

        if ($invoice->status === 'cancelled'
            || in_array($invoice->lifecycle_status, ['cancelled', 'voided', 'reversed', 'superseded'], true)) {
            throw ValidationException::withMessages([
                'invoice_id' => 'A cancelled, voided, reversed or superseded invoice cannot be linked to a case.',
            ]);
        }
    }

    /**
     * Issue #185: hospital/doctor performance report.
     * Issue #608: also the Product usage pivot (`usage`), with optional
     * filters and PHI masking on the drill-down cases.
     */
    public function performanceReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'date_field' => 'nullable|in:case_date,procedure_date',
            'legal_entity_id' => 'nullable|integer',
            'hospital_group_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'doctor_contact_id' => 'nullable|integer',
            'salesperson_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
        ]);

        $report = app(\App\Services\MedicalCaseReportingService::class)->performance(
            \Carbon\Carbon::parse($validated['from_date']),
            \Carbon\Carbon::parse($validated['to_date']),
            $validated['date_field'] ?? 'case_date',
            // Issue #575 (MC11): report only the requester's legal entities.
            $this->accessibleEntityIds($request),
            array_map('intval', array_filter(\Illuminate\Support\Arr::only($validated, [
                'legal_entity_id', 'hospital_group_id', 'customer_id', 'doctor_contact_id',
                'salesperson_id', 'product_id', 'category_id',
            ]), fn ($value) => $value !== null && $value !== '')),
            // Issue #608: patient names in the drill-down are masked unless
            // the caller holds medical_cases.phi.view (#567).
            \App\Http\Middleware\RequirePermission::allows($request, 'medical_cases.phi.view'),
        );

        return response()->json(['data' => $report]);
    }

    public function cancel(Request $request, $id): JsonResponse
    {
        $case = $this->findScopedCase($request, $id, ['items']);

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
                // Issue #565 (MC3): allocation holds stock via
                // StockReservationService; cancelling the case must give the
                // hold back or it leaks forever.
                $reservations = StockReservation::where('source_document_type', 'MedicalCase')
                    ->where('source_document_id', $case->id)
                    ->whereIn('status', ['open', 'allocated'])
                    ->get();

                foreach ($reservations as $reservation) {
                    app(StockReservationService::class)
                        ->release($reservation, "Medical case {$case->case_no} cancelled");
                }

                // Issue #570 (MC7): a partially delivered case carries real
                // delivered quantities and accrued usage just like a fully
                // delivered one, so it must be reversed on cancellation too.
                if (in_array($case->status, ['partially_delivered', 'delivered', 'invoiced'], true)) {
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

                app(SalesWorkflowService::class)->transition($case, 'cancel');
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

    public function complete(Request $request, $id): JsonResponse
    {
        $case = $this->findScopedCase($request, $id, ['items']);

        if (! $case) {
            return response()->json(['message' => 'Medical case not found'], 404);
        }

        if ($case->status !== 'invoiced') {
            return response()->json(['message' => 'Only invoiced cases can be completed'], 403);
        }

        // Issue #573 (MC8): `completed` requires every used line to be
        // delivered and invoiced (or FOC-redeemed). The engine guard enforces
        // it, so a case stamped `invoiced` with nothing behind it (legacy
        // rows) is refused as a business-rule failure.
        try {
            app(SalesWorkflowService::class)->transition($case, 'complete');
        } catch (WorkflowDeniedException $e) {
            return response()->json([
                'message' => 'The medical case cannot be completed: '.$e->getMessage(),
                'errors' => ['status' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'message' => 'Medical case completed successfully',
            'data' => $case->fresh()->load(['customer', 'items.product']),
        ]);
    }
}
