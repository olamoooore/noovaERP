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
use App\Services\SalesWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Services\StockReservationService;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrderLine;

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

    public function show($id): JsonResponse
    {
        $with = [
            'customer',
            'invoice',
            'items.product',
            'items.salesOrderItem',
            'items.invoiceItem',
            'items.deliveryOrderItem',
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

        $case = MedicalCase::with($with)->find($id);

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
        $case = MedicalCase::with('items')->find($id);

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
            'items.*.id' => 'required|exists:medical_case_items,id',
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

    public function invoice(Request $request, $id): JsonResponse
    {
        $case = MedicalCase::with('items')->find($id);

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
            'items.*.id' => 'required|exists:medical_case_items,id',
            'items.*.invoiced_qty' => 'required|numeric|min:0',
            // Issue #566 (MC4): every case line maps to a line of the invoice.
            'items.*.invoice_item_id' => 'required|integer|exists:invoice_lines,id',
        ]);

        $this->assertLinkableInvoice((int) $validated['invoice_id'], (int) $case->customer_id);

        try {
            DB::transaction(function () use ($case, $validated) {
                $items = collect($validated['items'])->map(function ($itemData) use ($case) {
                    return MedicalCaseItem::where('medical_case_id', $case->id)
                        ->where('id', $itemData['id'])
                        ->firstOrFail();
                });

                $invoiceLineIds = collect($validated['items'])->pluck('invoice_item_id')->unique()->values()->all();

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

                foreach ($validated['items'] as $index => $itemData) {
                    $item = $items[$index];
                    $quantity = (float) $itemData['invoiced_qty'];

                    if (round($quantity, 4) > round((float) $item->delivered_qty, 4)) {
                        throw ValidationException::withMessages([
                            "items.{$index}.invoiced_qty" => "The invoiced quantity cannot exceed the delivered quantity of {$item->delivered_qty} for this case line.",
                        ]);
                    }

                    $invoiceLine = $invoiceLines->get((int) $itemData['invoice_item_id']);

                    if (! $invoiceLine || (int) $invoiceLine->invoice_id !== (int) $validated['invoice_id']) {
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

                $case->update(['invoice_id' => $validated['invoice_id']]);

                // Issue #573 (MC8): the case is `invoiced` only once every used
                // line is fully delivered and fully invoiced (FOC lines are
                // redeemed free of charge). A partial invoice leaves the
                // derived delivery status in place.
                $fresh = $case->fresh('items');

                if ($fresh->status === 'delivered' && $fresh->fulfilmentBlocker() === null) {
                    app(SalesWorkflowService::class)->transition($fresh, 'invoice');
                }
            });

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

    public function complete($id): JsonResponse
    {
        $case = MedicalCase::with('items')->find($id);

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
