<?php

namespace Modules\Sales\Services;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\BatchSerialConsumptionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockReservationService;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

class DeliveryOrderService
{
    /** Record that the delivery order was created. */
    public function logCreated(DeliveryOrder $deliveryOrder): void
    {
        $this->logChatter(
            $deliveryOrder,
            'created',
            "Created delivery order {$deliveryOrder->delivery_order_number}",
            [
                'status' => $deliveryOrder->status,
                'total' => (float) $deliveryOrder->total,
                'lines' => $deliveryOrder->lines()->count(),
            ],
        );
    }

    /**
     * Tell the configured approvers that a delivery order is waiting on them.
     *
     * Mirrors InvoiceApprovalService::notifyApprovers. Until this existed the
     * delivery-order flow dispatched NOTHING, so a DO that required approval sat
     * silently in `pending` and the only "requires approval" notifications a
     * reviewer ever saw were the invoice ones.
     */
    public function notifyApprovers(DeliveryOrder $deliveryOrder): void
    {
        if ($deliveryOrder->approval_status !== 'pending') {
            return;
        }

        try {
            $this->dispatchApprovalNotifications($deliveryOrder);
        } catch (\Throwable $e) {
            Log::warning('Failed to notify approvers for delivery order approval', [
                'delivery_order_id' => $deliveryOrder->id,
                'error' => $e->getMessage(),
            ]);

            // Issue #902: a DO waiting on approvers nobody was told about is
            // a business failure, not just a log line — queue it with the
            // affected document and the re-notify retry.
            app(\App\Platform\Exceptions\ExceptionService::class)->report(
                new \App\Platform\Exceptions\ExceptionReport(
                    type: \App\Platform\Exceptions\ExceptionType::SALES_DO_APPROVAL_NOTIFICATION_FAILED,
                    message: "Failed to notify approvers for delivery order {$deliveryOrder->delivery_order_number}: {$e->getMessage()}",
                    documentId: $deliveryOrder->id,
                    documentType: 'delivery_order',
                    legalEntityId: $this->resolveNotificationLegalEntityId($deliveryOrder),
                    payload: [
                        'delivery_order_number' => $deliveryOrder->delivery_order_number,
                        'approval_status' => $deliveryOrder->approval_status,
                        'error' => $e->getMessage(),
                    ],
                ),
            );
        }
    }

    /**
     * Issue #902: the retry path for a failed approver notification. Unlike
     * notifyApprovers(), a notification failure here propagates so the
     * exception queue records the retry as failed.
     */
    public function retryApprovalNotification(DeliveryOrder $deliveryOrder): void
    {
        if ($deliveryOrder->approval_status !== 'pending') {
            throw new \RuntimeException(
                "Delivery order {$deliveryOrder->delivery_order_number} is no longer pending approval."
            );
        }

        $this->dispatchApprovalNotifications($deliveryOrder);
    }

    /**
     * The part of notifyApprovers() that can actually fail — kept separate so
     * the retry handler is not swallowed by the reporting catch.
     */
    private function dispatchApprovalNotifications(DeliveryOrder $deliveryOrder): void
    {
        $submitterId = Auth::id();
        $reviewers = $this->resolveReviewers($submitterId);

        if ($reviewers->isEmpty()) {
            Log::warning('Delivery order approval: no reviewers configured', [
                'delivery_order_id' => $deliveryOrder->id,
            ]);

            return;
        }

        $notificationService = app(\App\Platform\Notifications\NotificationService::class);
        $amount = number_format((float) $deliveryOrder->total, 2);
        $customer = $deliveryOrder->customer?->name;
        $number = $deliveryOrder->delivery_order_number;

        foreach ($reviewers as $reviewer) {
            $notificationService->dispatch('approval.requested', $reviewer, array_merge([
                'subject' => "Delivery order {$number} requires approval",
                'body' => "Delivery order {$number}"
                    .($customer ? " for {$customer}" : '')
                    ." totalling {$amount} requires your approval.",
                'type' => 'delivery_order_approval',
                'amount' => (float) $deliveryOrder->total,
                'delivery_order_id' => $deliveryOrder->id,
                'delivery_order_number' => $number,
                'requested_by' => Auth::user()?->name,
            ], \App\Platform\Notifications\NotificationReferences::forDeliveryOrder($deliveryOrder)));
        }
    }

    /**
     * Issue #902: the legal entity for a queued DO notification failure,
     * resolved best-effort so reporting can never break the flow.
     */
    private function resolveNotificationLegalEntityId(DeliveryOrder $deliveryOrder): ?int
    {
        try {
            return app(\App\Services\LegalEntityContextResolver::class)->resolveEntity(
                salesOrderId: $deliveryOrder->sales_order_id ? (int) $deliveryOrder->sales_order_id : null,
                customerId: $deliveryOrder->customer_id ? (int) $deliveryOrder->customer_id : null,
                required: false,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Record that draft header fields and/or the line items were edited.
     * No-ops when nothing actually changed, so opening and saving the edit
     * form does not spam the history.
     */
    public function logUpdated(DeliveryOrder $deliveryOrder, array $changedFields, bool $linesReplaced): void
    {
        if ($changedFields === [] && ! $linesReplaced) {
            return;
        }

        $this->logChatter(
            $deliveryOrder,
            'updated',
            "Updated delivery order {$deliveryOrder->delivery_order_number}",
            [
                'fields' => $changedFields === []
                    ? 'line items'
                    : implode(', ', $changedFields).($linesReplaced ? ', line items' : ''),
            ],
        );
    }

    /**
     * Mirror a delivery-order lifecycle transition into the polymorphic
     * chatter feed so the document's History tab reflects what happened.
     * Never throws — a logging failure must not break the document flow.
     */
    /**
     * Record a lifecycle event in the document's chatter History feed.
     * Public so the controller can log creation/edits, which happen outside
     * this service.
     */
    public function logChatter(
        DeliveryOrder $deliveryOrder,
        string $event,
        string $description,
        array $properties = [],
    ): void {
        try {
            app(\App\Services\Chatter\ChatterService::class)->logEvent(
                DeliveryOrder::class,
                (int) $deliveryOrder->id,
                $event,
                $description,
                $properties,
                Auth::id(),
                Auth::check() ? get_class(Auth::user()) : null,
            );
        } catch (\Throwable $e) {
            Log::warning('DeliveryOrderService: chatter mirror failed', [
                'delivery_order_id' => $deliveryOrder->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function __construct(
        private OrderLifecycleService $lifecycleService,
        private InventoryService $inventoryService,
        private StockReservationService $reservationService,
        private \App\Services\BundleDecompositionService $bundleDecomposition,
        private \App\Accounting\PostingEngine $postingEngine,
        private \App\Services\GoodsIssueLedger $goodsIssueLedger,
    ) {}

    /**
     * Issue #465 (DO13): stock availability for the standalone-DO create page.
     * A standalone delivery order holds no reservation of its own, so every
     * active hold is somebody else's — the same shared calculation the
     * creation guard uses.
     *
     * Issue #687: incoming supply (open POs / inbound transfers) is attached
     * for the line-editor chip's hover details. The availability arithmetic
     * itself stays in InventoryService::stockAvailability() (#465/#686).
     *
     * @return array{on_hand: float, reserved: float, available: float, incoming: array<int, array<string, mixed>>, incoming_quantity: float}
     */
    public function stockAvailability(int $productId, int $warehouseId): array
    {
        $availability = $this->inventoryService->stockAvailability($productId, $warehouseId);
        $incoming = $this->inventoryService->incomingStock($productId, $warehouseId);

        return [
            ...$availability,
            'incoming' => $incoming,
            'incoming_quantity' => (float) array_sum(array_column($incoming, 'quantity')),
        ];
    }

    /**
     * Issue #687 (UX1): bulk availability for a document's product lines and
     * the order-confirm shortage summary. Delegates to the shared
     * InventoryService computation so every sales path reports the same
     * on-hand / reserved / available figures as the #465 pre-submit check.
     *
     * @param  array<int, array{product_id?: int|string|null, quantity?: int|float|string|null}>  $lines
     * @return array{warehouse_id: int, lines: array<int, array<string, mixed>>, summary: array{line_count: int, lines_short: int}, incoming_note: ?string}
     */
    public function bulkStockAvailability(int $warehouseId, array $lines): array
    {
        return $this->inventoryService->bulkStockAvailability($warehouseId, $lines);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  CREATION
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Create a delivery order from a confirmed/allocated/fulfilled sales order.
     */

    /**
     * Resolve the requires_approval flag for a new delivery order: an explicit
     * caller value always wins, otherwise the tenant's configured default
     * (sales.delivery_order.default_requires_approval) applies. Server-side
     * creation paths (quotation→DO, invoice→DO, imports) must honour the
     * tenant setting exactly like the create form does.
     */
    private function resolveRequiresApproval(array $data): bool
    {
        if (array_key_exists('requires_approval', $data)) {
            return ! empty($data['requires_approval']);
        }

        return (bool) \App\Models\Setting::getValue('sales.delivery_order.default_requires_approval', false);
    }

    /**
     * Send a pending delivery order to the approval channel: an ApprovalRequest
     * (listed by the approvals inbox) plus a notification for every configured
     * reviewer. Mirrors the invoice approval flow, and is idempotent — an
     * existing pending/approved request is never duplicated.
     */
    public function submitForApproval(DeliveryOrder $deliveryOrder): void
    {
        if (! $deliveryOrder->requires_approval || $deliveryOrder->approval_status !== 'pending') {
            return;
        }

        $requester = Auth::user();

        if (! $requester) {
            return;
        }

        try {
            $alreadySubmitted = \App\Models\ApprovalRequest::query()
                ->where('subject_type', DeliveryOrder::class)
                ->where('subject_id', $deliveryOrder->id)
                ->where('request_type', 'delivery_order_approval')
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if (! $alreadySubmitted) {
                \App\Models\ApprovalRequest::create([
                    'request_type' => 'delivery_order_approval',
                    'subject_type' => DeliveryOrder::class,
                    'subject_id' => $deliveryOrder->id,
                    'amount' => (float) ($deliveryOrder->total ?? 0),
                    'reason' => "Delivery order {$deliveryOrder->delivery_order_number} requires approval before it can be confirmed.",
                    'metadata' => [
                        'delivery_order_number' => $deliveryOrder->delivery_order_number,
                        'customer_id' => $deliveryOrder->customer_id,
                        'source' => $deliveryOrder->source,
                    ],
                    'requested_by' => $requester->id,
                    'requested_at' => now(),
                    'status' => 'pending',
                    'expires_at' => now()->addDays(7),
                ]);
            }

            $notificationService = app(\App\Platform\Notifications\NotificationService::class);

            foreach ($this->resolveReviewers((int) $deliveryOrder->created_by) as $reviewer) {
                $notificationService->dispatch('approval.requested', $reviewer, array_merge([
                    'subject' => "Delivery order {$deliveryOrder->delivery_order_number} requires approval",
                    'body' => "Delivery order {$deliveryOrder->delivery_order_number} requires your approval.",
                    'type' => 'delivery_order_approval',
                    'amount' => (float) ($deliveryOrder->total ?? 0),
                    'delivery_order_id' => $deliveryOrder->id,
                    'delivery_order_number' => $deliveryOrder->delivery_order_number,
                    'requested_by' => $requester->name,
                ], \App\Platform\Notifications\NotificationReferences::forDeliveryOrder($deliveryOrder)));
            }
        } catch (\Throwable $e) {
            // A channel hiccup must never roll back the delivery order itself.
            Log::warning('DeliveryOrderService: approval submission failed', [
                'delivery_order_id' => $deliveryOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Settle the delivery order's pending ApprovalRequest after a decision was
     * taken through the document endpoints (so the approvals inbox never shows
     * a stale pending request).
     */
    private function settleApprovalRequest(DeliveryOrder $deliveryOrder, string $status, ?int $reviewedBy = null, ?string $notes = null): void
    {
        try {
            \App\Models\ApprovalRequest::query()
                ->where('subject_type', DeliveryOrder::class)
                ->where('subject_id', $deliveryOrder->id)
                ->where('request_type', 'delivery_order_approval')
                ->where('status', 'pending')
                ->update([
                    'status' => $status,
                    'reviewed_by' => $reviewedBy,
                    'reviewed_at' => now(),
                    'review_notes' => $notes,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('DeliveryOrderService: settling approval request failed', [
                'delivery_order_id' => $deliveryOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function createDeliveryOrder(SalesOrder $order, array $data): DeliveryOrder
    {
        if (! $order->isConfirmed() && ! $order->isAllocated() && ! $order->isFulfilled() && $order->status !== 'invoiced') {
            throw new \Exception('Only confirmed, allocated, fulfilled, or invoiced orders can have delivery orders.');
        }

        $warehouseId = $data['warehouse_id'] ?? $order->warehouse_id ?? Warehouse::getDefault()?->id;
        if (! $warehouseId) {
            throw new \Exception('Warehouse is required for delivery orders.');
        }

        // Auto-resolve quotation_id from the linked invoice if not provided
        $quotationId = $data['quotation_id'] ?? $order->quotation?->id;
        $invoiceId = $data['invoice_id'] ?? null;
        if ($quotationId === null && $invoiceId !== null) {
            $invoice = \App\Models\Invoice::find($invoiceId);
            if ($invoice && $invoice->quotation_id) {
                $quotationId = $invoice->quotation_id;
            }
        }

        $maxRetries = 5;
        $lockKey = self::deliveryOrderNumberLockKey();

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                return DB::transaction(function () use ($order, $data, $warehouseId, $quotationId, $invoiceId, $lockKey) {
                    DB::select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);

                    return $this->createDeliveryOrderInsideTransaction($order, $data, $warehouseId, $quotationId, $invoiceId);
                });
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'delivery_orders_delivery_order_number_unique')) {
                    if ($attempt < $maxRetries - 1) {
                        cache()->forget('document_numbering_delivery_order');
                        Log::warning('DO number collision on insert, retrying', [
                            'attempt' => $attempt + 1,
                        ]);

                        continue;
                    }
                }
                throw $e;
            }
        }

        return DB::transaction(function () use ($order, $data, $warehouseId, $quotationId, $invoiceId, $lockKey) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);

            return $this->createDeliveryOrderInsideTransaction($order, $data, $warehouseId, $quotationId, $invoiceId);
        });
    }

    /**
     * Issue #238: transactional body of createDeliveryOrder(), executed under
     * the tenant-scoped advisory lock (same guarantee as
     * createStandaloneDeliveryOrder — the missed "fourth instance").
     */
    private function createDeliveryOrderInsideTransaction(SalesOrder $order, array $data, int $warehouseId, ?int $quotationId, ?int $invoiceId): DeliveryOrder
    {
        $actorId = TenantUserHelper::requireTenantUserId($data['created_by'] ?? Auth::id());

        $deliveryOrder = DeliveryOrder::create([
            'sales_order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'warehouse_id' => $warehouseId,
            'source' => 'sales_order',
            // MRD pricelist-multicurrency FR-5.6: DOs inherit the SO/quote
            // currency + rate snapshot; delivery never re-prices.
            'currency_code' => $order->currency_code,
            'exchange_rate' => $order->exchange_rate,
            'base_currency_code' => $order->base_currency_code,
            'delivery_date' => $data['delivery_date'] ?? now()->toDateString(),
            // Issue #408: the order's commitment (promised) date is carried to
            // the delivery order unless the caller supplied its own date.
            'expected_date' => $data['expected_date'] ?? $order->commitment_date?->toDateString(),
            'payment_terms' => $data['payment_terms'] ?? null,
            'carrier' => $data['carrier'] ?? null,
            'tracking_number' => $data['tracking_number'] ?? null,
            'shipping_address' => $data['shipping_address'] ?? null,
            'shipping_address_overridden' => ! empty($data['shipping_address']),
            'po_number' => $data['po_number'] ?? null,
            'salesperson' => $data['salesperson'] ?? null,
            'attention_to' => $data['attention_to'] ?? null,
            'invoice_id' => $invoiceId,
            'quotation_id' => $quotationId,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actorId,
            'requires_approval' => $this->resolveRequiresApproval($data),
            'approval_status' => $this->resolveRequiresApproval($data) ? 'pending' : 'not_required',
            // References ride along from the source order so the document
            // family stays traceable from any single reference.
            'po_number' => $data['po_number'] ?? $order->po_number,
            'customer_reference' => $data['customer_reference'] ?? $order->customer_reference,
            'internal_reference' => $data['internal_reference'] ?? $order->internal_reference,
        ]);

        // #900 (PC6): the DB default owns the starting status; register it on
        // the workflow transition log so creation is not a back-door write.
        app(\App\Services\SalesWorkflowService::class)->initialize($deliveryOrder, ['source' => 'create']);

        $lines = $data['lines'] ?? [];

        // Issue #408: snapshot each order line's remaining-to-deliver under the
        // #458 formula BEFORE this DO's lines exist, so the when-all-ready
        // policy check and the over-delivery guard judge against the same
        // quantities (a DO's own open lines never count against themselves).
        $order->loadMissing('lines');
        $remainingByLine = [];
        foreach ($order->lines as $orderLine) {
            $remainingByLine[(int) $orderLine->id] = $this->remainingQuantityForLine($orderLine);
        }

        // Issue #408: 'when_all_ready' means no partial shipments — every line
        // with a remainder must be covered in full by this one delivery order.
        if ($order->requiresAllProductsReady()) {
            $this->assertAllRemainingLinesCovered($order, $lines, $remainingByLine);
        }

        $hasDeliverableProduct = false;
        foreach ($lines as $lineData) {
            $soLineId = $lineData['sales_order_line_id'];
            $soLine = SalesOrderLine::findOrFail($soLineId);
            $lineType = $soLine->line_type ?? $lineData['line_type'] ?? 'product';

            if ((int) $soLine->sales_order_id !== (int) $order->id) {
                throw new \Exception("Sales order line {$soLineId} does not belong to order #{$order->id}.");
            }

            $quantityDelivered = (float) ($lineData['quantity_delivered'] ?? 0);

            // Issue #685: a delivery quantity entered in a sales UoM (2 Box)
            // is converted to stock units (24 pieces) before it is checked
            // against the order's base quantities and stored; every ledger,
            // reservation and COGS comparison below is in stock units.
            $lineUom = (($lineData['uom_id'] ?? null) && $soLine->product_id)
                ? app(\App\Services\ProductUomConverter::class)->resolveOrNull(
                    (int) $soLine->product_id,
                    (int) $lineData['uom_id'],
                )
                : null;

            if ($lineUom && ! $lineUom->isStockUnit()) {
                $quantityDelivered = app(\App\Services\ProductUomConverter::class)->toStock($lineUom, $quantityDelivered);
            }

            if ($lineType === 'product' && $quantityDelivered <= 0) {
                throw new \Exception("Delivery quantity must be greater than zero for sales order line #{$soLineId}.");
            }
            if ($lineType === 'product') {
                $hasDeliverableProduct = true;
            }
            // #458 (DO6): quantities committed to OPEN (draft/confirmed)
            // delivery orders are not reflected in quantity_delivered yet —
            // subtract them so several open delivery orders cannot each pass
            // this check and over-deliver the order. Issue #408: shared
            // remainingQuantityForLine() so the policy check cannot diverge.
            $remainingForThisLine = $remainingByLine[(int) $soLine->id] ?? 0.0;

            if ($quantityDelivered > $remainingForThisLine + 0.0001) {
                throw new \Exception(
                    "Cannot deliver {$quantityDelivered} of line #{$soLineId}. "
                    ."Only {$remainingForThisLine} remaining to deliver."
                );
            }

            // Consume what this line takes so duplicate entries for the same
            // sales-order line in one request stay checked cumulatively
            // (the #458 guard's original behaviour).
            $remainingByLine[(int) $soLine->id] = max($remainingForThisLine - $quantityDelivered, 0.0);

            $lineProduct = $lineType === 'product' && $soLine->product_id
                ? \App\Models\Product::find($soLine->product_id)
                : null;
            $serialNumbers = $lineType === 'product'
                ? $this->resolveSerialNumbers($lineProduct, $lineData, $quantityDelivered)
                : [];

            DeliveryOrderLine::create([
                'line_type' => $lineType,
                'sequence' => $lineData['sequence'] ?? $soLine->sequence ?? 0,
                'delivery_order_id' => $deliveryOrder->id,
                'sales_order_line_id' => $soLine->id,
                'product_id' => $soLine->product_id,
                'model_number' => $lineData['model_number'] ?? $soLine->model_number ?? null,
                'description' => $lineData['description'] ?? $soLine->description,
                'product_notes' => $lineData['product_notes'] ?? $soLine->product_notes ?? null,
                'patient_name' => $lineData['patient_name'] ?? null,
                'quantity_ordered' => $lineType === 'product' ? (float) $soLine->quantity : 0,
                'quantity_delivered' => $lineType === 'product' ? $quantityDelivered : 0,
                'unit' => $lineData['unit'] ?? $soLine->unit,
                // Issue #685: provenance for the sales UoM the operator
                // entered; quantity_delivered above is stock units.
                'uom_id' => $lineUom?->id,
                'unit_price' => $lineType === 'product' ? (float) ($lineData['unit_price'] ?? $soLine->unit_price ?? 0) : null,
                'base_unit_price' => $lineType === 'product'
                    ? (float) ($lineData['base_unit_price'] ?? ($soLine->base_unit_price ?? $soLine->unit_price) ?? 0)
                    : null,
                'pricing_trace' => $lineType === 'product' ? ($lineData['pricing_trace'] ?? $soLine->pricing_trace ?? null) : null,
                'line_total' => $lineType === 'product'
                    ? round((float) ($lineData['unit_price'] ?? $soLine->unit_price ?? 0) * $quantityDelivered, 2)
                    : null,
                'lot_number' => $lineData['lot_number'] ?? null,
                'serial_number' => $lineData['serial_number'] ?? null,
                'product_batch_id' => $lineData['product_batch_id'] ?? null,
                'product_serial_id' => $lineData['product_serial_id'] ?? null,
                'batch_number' => $lineData['batch_number'] ?? null,
                'expiry_date' => $lineData['expiry_date'] ?? null,
                'serial_numbers' => $serialNumbers,
            ]);
        }

        if (! $hasDeliverableProduct) {
            throw new \Exception('Set Deliver Now greater than zero for at least one product before creating the delivery order.');
        }

        $deliveryOrder = $deliveryOrder->fresh(['lines.salesOrderLine', 'customer', 'salesOrder']);
        $this->submitForApproval($deliveryOrder);

        return $deliveryOrder;
    }

    /**
     * Issue #408: the single remainder formula shared by the over-delivery
     * guard (#458) and the when-all-ready policy check. Open (draft/confirmed)
     * delivery orders already committed against the line are subtracted, so
     * neither guard can be satisfied twice by two open DOs.
     */
    private function remainingQuantityForLine(SalesOrderLine $soLine): float
    {
        $openCommitted = (float) DeliveryOrderLine::query()
            ->where('sales_order_line_id', $soLine->id)
            ->whereHas('deliveryOrder', fn ($query) => $query->whereNotIn('status', ['cancelled', 'done']))
            ->sum('quantity_delivered');

        return max(
            (float) $soLine->quantity
                - (float) $soLine->quantity_delivered
                - (float) $soLine->quantity_cancelled
                - $openCommitted,
            0.0
        );
    }

    /**
     * Issue #408: enforce an order's `when_all_ready` shipping policy — the
     * delivery order must cover every order line with a remaining quantity, in
     * full. Fail closed: a missing or short line refuses the DO (the controller
     * maps the exception to 422) instead of silently creating a backorder.
     *
     * @param  array<int, array<string, mixed>>  $lines  DO lines as submitted
     * @param  array<int, float>  $remainingByLine  remaining-to-deliver per SO line, from remainingQuantityForLine()
     */
    private function assertAllRemainingLinesCovered(SalesOrder $order, array $lines, array $remainingByLine): void
    {
        $submittedByLine = [];

        foreach ($lines as $lineData) {
            if (($lineData['line_type'] ?? 'product') !== 'product') {
                continue;
            }

            $soLineId = (int) ($lineData['sales_order_line_id'] ?? 0);
            if ($soLineId <= 0 || ! array_key_exists($soLineId, $remainingByLine)) {
                continue;
            }

            // Same sales-UoM conversion as the write path below, so the policy
            // check and the persisted stock-unit quantity agree.
            $soLine = $order->lines->firstWhere('id', $soLineId);
            $quantity = (float) ($lineData['quantity_delivered'] ?? 0);
            $lineUom = (($lineData['uom_id'] ?? null) && $soLine?->product_id)
                ? app(\App\Services\ProductUomConverter::class)->resolveOrNull(
                    (int) $soLine->product_id,
                    (int) $lineData['uom_id'],
                )
                : null;

            if ($lineUom && ! $lineUom->isStockUnit()) {
                $quantity = app(\App\Services\ProductUomConverter::class)->toStock($lineUom, $quantity);
            }

            $submittedByLine[$soLineId] = ($submittedByLine[$soLineId] ?? 0.0) + $quantity;
        }

        foreach ($remainingByLine as $soLineId => $remaining) {
            if ($remaining <= 0.0001) {
                continue; // fully delivered (or cancelled) lines impose nothing
            }

            $submitted = $submittedByLine[$soLineId] ?? 0.0;

            if (abs($submitted - $remaining) > 0.0001) {
                throw new \Exception(
                    "This order's shipping policy is 'when all products are ready' — "
                    ."line #{$soLineId} must deliver all {$remaining} remaining units in this delivery order "
                    ."({$submitted} submitted). Partial deliveries are not allowed."
                );
            }
        }
    }

    /**
     * Issue #176: DO completion issues inventory from the ledger's perspective
     * too — post Dr COGS / Cr Inventory at completion, independent of invoice
     * posting (the documented no-enforced-DO/Invoice sequencing policy).
     *
     * Issue #682: $issuedQuantities holds, per delivery line, the quantity this
     * completion actually issued. Costing exactly that keeps COGS and stock on
     * one rule — a line whose goods an invoice already issued contributes
     * nothing here, and postDeliveryCogs() posts no entry at all.
     *
     * @param  array<int, float>  $issuedQuantities  keyed by delivery-order line id
     */
    private function postDeliveryCogs(DeliveryOrder $deliveryOrder, int $warehouseId, int $actorId, array $issuedQuantities = []): void
    {
        $totalCogs = $this->deliveryCogsTotal($deliveryOrder, $warehouseId, $issuedQuantities);

        if ($totalCogs <= 0) {
            return;
        }

        $result = $this->postingEngine->domainPosting(new \App\Accounting\DTOs\DomainPostingRequest(
            postingRequestId: \App\Accounting\DTOs\DomainPostingRequest::generateRequestId(),
            idempotencyKey: "delivery_order:{$deliveryOrder->id}:cogs",
            tenantId: (string) (function_exists('tenant') && tenant() ? tenant('id') : ''),
            // Issue #875: DO COGS carries the delivery order's legal entity,
            // resolved from its sales order (or the request entity context /
            // the tenant's only entity for legacy orders).
            entityId: (string) $this->resolveLegalEntityId($deliveryOrder),
            accountingBookId: (string) ($this->resolveEntityBookId($deliveryOrder) ?? ''),
            sourceCapability: 'ACC-012',
            sourceRecordType: 'delivery_order',
            sourceRecordId: (string) $deliveryOrder->id,
            sourceRecordVersion: 1,
            postingProfileCode: 'DELIVERY_ORDER_COGS',
            transactionDate: now()->toDateString(),
            postingDate: now()->toDateString(),
            lines: [
                new \App\Accounting\DTOs\DomainPostingLine(
                    accountRole: 'cogs',
                    amount: round($totalCogs, 2),
                    description: "COGS - DO {$deliveryOrder->delivery_order_number}",
                ),
                new \App\Accounting\DTOs\DomainPostingLine(
                    accountRole: 'inventory',
                    amount: round($totalCogs, 2),
                    description: "Inventory - DO {$deliveryOrder->delivery_order_number}",
                ),
            ],
            userId: $actorId,
            ledgerDescription: "Delivery Order #{$deliveryOrder->delivery_order_number} COGS",
            reference: $deliveryOrder->delivery_order_number,
        ));

        if (! $result->success) {
            throw new \RuntimeException(
                "Delivery order GL posting failed for {$deliveryOrder->delivery_order_number}: ".($result->error ?? 'unknown error')
            );
        }
    }

    /**
     * Issue #176: reversing a completed DO must also reverse its COGS entry.
     * domainPosting() dedupes by source record, so the reversal goes through
     * PostingEngine::reverse() (relation_type=reversal links to the same DO).
     */
    private function postDeliveryCogsReversal(DeliveryOrder $deliveryOrder, int $warehouseId, int $actorId): void
    {
        $link = \App\Models\JournalEntryLink::where('source_document_type', 'delivery_order')
            ->where('source_document_id', $deliveryOrder->id)
            ->where('relation_type', 'post')
            ->first();

        if (! $link) {
            return; // nothing posted (e.g. zero-cost delivery)
        }

        $result = $this->postingEngine->reverse(new \App\Accounting\DTOs\ReversalRequest(
            journalEntryId: (int) $link->journal_entry_id,
            reversalDate: now()->toDateString(),
            reason: 'Delivery order reversed',
            reasonCode: 'DELIVERY_ORDER_REVERSAL',
            actorUserId: $actorId,
            tenantId: (string) (function_exists('tenant') && tenant() ? tenant('id') : ''),
        ));

        if (! $result->success) {
            throw new \RuntimeException(
                "Delivery order COGS reversal posting failed for {$deliveryOrder->delivery_order_number}: ".($result->error ?? 'unknown error')
            );
        }
    }

    /**
     * Issue #875: the legal entity for a delivery order posting, resolved
     * from its sales order / customer / request entity context, or the
     * tenant's only entity.
     */
    private function resolveLegalEntityId(DeliveryOrder $deliveryOrder): ?int
    {
        return app(\App\Services\LegalEntityContextResolver::class)->resolveEntity(
            explicit: null,
            salesOrderId: $deliveryOrder->sales_order_id ? (int) $deliveryOrder->sales_order_id : null,
            customerId: $deliveryOrder->customer_id ? (int) $deliveryOrder->customer_id : null,
            required: true,
        );
    }

    private function resolveEntityBookId(DeliveryOrder $deliveryOrder): ?int
    {
        return app(\App\Services\LegalEntityContextResolver::class)
            ->resolveBookId($this->resolveLegalEntityId($deliveryOrder));
    }

    /**
     * FEFO layer cost when available, bundle component cost otherwise.
     *
     * @param  array<int, float>  $issuedQuantities  quantity actually issued per line (defaults to the delivered quantity)
     */
    private function deliveryCogsTotal(DeliveryOrder $deliveryOrder, int $warehouseId, array $issuedQuantities = []): float
    {
        $batchConsumption = app(\App\Services\BatchSerialConsumptionService::class);
        $total = 0.0;

        foreach ($deliveryOrder->lines as $line) {
            if (($line->line_type ?? 'product') !== 'product' || ! $line->product) {
                continue;
            }

            $qty = array_key_exists($line->id, $issuedQuantities)
                ? (float) $issuedQuantities[$line->id]
                : (float) $line->quantity_delivered;
            if ($qty <= 0) {
                continue;
            }

            $lineCogs = $batchConsumption->costForLine($line->product, $qty, $warehouseId)
                ?? $this->bundleDecomposition->cost($line->product, $qty);

            if ($lineCogs > 0) {
                $total += $lineCogs;
            }
        }

        return $total;
    }

    /**
     * Issue #131: validate + normalise the serial numbers for a line. A
     * serial-tracked product requires exactly one in-stock serial per unit.
     *
     * @return string[]|null
     */
    private function resolveSerialNumbers(?\App\Models\Product $product, array $lineData, float $quantity): ?array
    {
        $provided = $lineData['serial_numbers'] ?? null;

        $isSerialTracked = $product && ($product->tracking_method ?? null) === \App\Models\Product::TRACKING_SERIAL;

        if (! $isSerialTracked) {
            return is_array($provided) && $provided !== [] ? array_values(array_unique($provided)) : null;
        }

        if (! is_array($provided) || $provided === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'lines' => ["Serial numbers are required for serial-tracked product {$product->name}."],
            ]);
        }

        $serials = array_values(array_unique($provided));

        if (count($serials) !== (int) round($quantity)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'lines' => ['Provide exactly '.((int) round($quantity))." serial number(s) for {$product->name}."],
            ]);
        }

        $known = \App\Models\ProductSerial::where('product_id', $product->id)
            ->whereIn('serial_number', $serials)
            ->pluck('status', 'serial_number');

        foreach ($serials as $serial) {
            if (! $known->has($serial)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'lines' => ["Serial {$serial} does not belong to {$product->name}."],
                ]);
            }
        }

        return $serials;
    }

    /**
     * Issue #137: advisory lock key for DO-number generation. Tenant-scoped so
     * one tenant's delivery-order creation never blocks another tenant's on the
     * shared Postgres database (pg_advisory locks ignore search_path).
     */
    public static function deliveryOrderNumberLockKey(?string $tenantId = null): int
    {
        return crc32('delivery_order_create:'.($tenantId ?? tenant('id') ?? ''));
    }

    /**
     * Create a standalone delivery order (not linked to a sales order).
     */
    public function createStandaloneDeliveryOrder(array $data): DeliveryOrder
    {
        $customerId = $data['customer_id'] ?? null;
        if (! $customerId) {
            throw new \Exception('customer_id is required for standalone delivery orders.');
        }

        $lines = $data['lines'] ?? [];
        if (empty($lines)) {
            throw new \Exception('At least one line item is required.');
        }

        $warehouseId = $data['warehouse_id'] ?? Warehouse::getDefault()?->id;
        if (! $warehouseId) {
            throw new \Exception('Warehouse is required for delivery orders.');
        }

        $maxRetries = 5;
        $lockKey = self::deliveryOrderNumberLockKey();

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                return DB::transaction(function () use ($data, $customerId, $warehouseId, $lines, $lockKey) {
                    DB::select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);

                    return $this->createStandaloneDeliveryOrderInsideTransaction($data, $customerId, $warehouseId, $lines);
                });
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'delivery_orders_delivery_order_number_unique')) {
                    if ($attempt < $maxRetries - 1) {
                        cache()->forget('document_numbering_delivery_order');
                        Log::warning('DO number collision on insert, retrying', [
                            'attempt' => $attempt + 1,
                        ]);

                        continue;
                    }
                }
                throw $e;
            }
        }

        return DB::transaction(function () use ($data, $customerId, $warehouseId, $lines, $lockKey) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);

            return $this->createStandaloneDeliveryOrderInsideTransaction($data, $customerId, $warehouseId, $lines);
        });
    }

    private function createStandaloneDeliveryOrderInsideTransaction(array $data, int $customerId, int $warehouseId, array $lines): DeliveryOrder
    {
        // Auto-resolve quotation_id from the linked invoice if not provided
        $quotationId = $data['quotation_id'] ?? null;
        $invoiceId = $data['invoice_id'] ?? null;
        if ($quotationId === null && $invoiceId !== null) {
            $invoice = \App\Models\Invoice::find($invoiceId);
            if ($invoice && $invoice->quotation_id) {
                $quotationId = $invoice->quotation_id;
            }
        }

        $deliveryOrder = DB::transaction(function () use ($data, $customerId, $warehouseId, $lines, $quotationId, $invoiceId) {
            $actorId = TenantUserHelper::requireTenantUserId($data['created_by'] ?? Auth::id());

            // MRD FR-5.6: standalone DOs resolve currency from the source
            // document when linked, else the customer's price list → base.
            $pricing = app(\App\Services\PricingService::class);
            $linkedCurrency = null;
            $linkedRate = null;
            $linkedBase = null;
            if ($invoiceId && ($inv = \App\Models\Invoice::find($invoiceId))) {
                $linkedCurrency = $inv->currency_code;
                $linkedRate = $inv->exchange_rate;
                $linkedBase = $inv->base_currency_code;
            } elseif ($quotationId && ($quo = \App\Models\Quotation::find($quotationId))) {
                $linkedCurrency = $quo->currency_code;
                $linkedRate = $quo->exchange_rate;
                $linkedBase = $quo->base_currency_code;
            }

            $pricingContext = $pricing->documentContext([
                'customer_id' => $customerId,
                'date' => $data['delivery_date'] ?? now()->toDateString(),
                'currency_code' => $data['currency_code'] ?? $linkedCurrency,
                'exchange_rate' => $linkedRate,
                'base_currency_code' => $linkedBase,
            ]);

            $deliveryOrder = DeliveryOrder::create([
                'sales_order_id' => null,
                'customer_id' => $customerId,
                'warehouse_id' => $warehouseId,
                'source' => $data['source'] ?? 'standalone',
                'currency_code' => $pricingContext['document_currency'],
                'exchange_rate' => $pricingContext['exchange_rate'],
                'base_currency_code' => $pricingContext['base_currency'],
                'external_reference' => $data['external_reference'] ?? null,
                'delivery_date' => $data['delivery_date'] ?? now()->toDateString(),
                // Issue #408: a standalone DO created from a quotation carries
                // the quote's commitment date as the expected date.
                'expected_date' => $data['expected_date']
                    ?? ($quotationId ? \App\Models\Quotation::whereKey($quotationId)->value('commitment_date') : null),
                'payment_terms' => $data['payment_terms'] ?? null,
                'carrier' => $data['carrier'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'shipping_address_overridden' => ! empty($data['shipping_address']),
                'po_number' => $data['po_number'] ?? null,
                'salesperson' => $data['salesperson'] ?? null,
                'attention_to' => $data['attention_to'] ?? null,
                'invoice_id' => $invoiceId,
                'quotation_id' => $quotationId,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
                'requires_approval' => $this->resolveRequiresApproval($data),
                'approval_status' => $this->resolveRequiresApproval($data) ? 'pending' : 'not_required',
                // Inherit references from whichever source document triggered
                // this standalone DO (quotation or invoice) when the caller
                // did not pass them explicitly.
                'po_number' => $data['po_number']
                    ?? ($invoiceId ? \App\Models\Invoice::whereKey($invoiceId)->value('po_number') : null)
                    ?? ($quotationId ? \App\Models\Quotation::whereKey($quotationId)->value('customer_reference') : null),
                'customer_reference' => $data['customer_reference']
                    ?? ($invoiceId ? \App\Models\Invoice::whereKey($invoiceId)->value('customer_reference') : null)
                    ?? ($quotationId ? \App\Models\Quotation::whereKey($quotationId)->value('customer_reference') : null),
                'internal_reference' => $data['internal_reference']
                    ?? ($invoiceId ? \App\Models\Invoice::whereKey($invoiceId)->value('internal_reference') : null)
                    ?? ($quotationId ? \App\Models\Quotation::whereKey($quotationId)->value('internal_reference') : null),
            ]);

            // #900 (PC6): initial status is owned by the DB default and
            // registered with the workflow engine.
            app(\App\Services\SalesWorkflowService::class)->initialize($deliveryOrder, ['source' => 'create']);

            foreach ($lines as $lineData) {
                $lineType = $lineData['line_type'] ?? 'product';
                $quantityDelivered = (float) ($lineData['quantity_delivered'] ?? $lineData['quantity'] ?? 0);
                if ($lineType === 'product' && $quantityDelivered <= 0) {
                    throw new \Exception('Each line must have a quantity greater than 0.');
                }

                // Check available stock for stock-tracked products
                $productId = $lineData['product_id'] ?? null;

                // Issue #685: a line entered in a sales UoM (2 Box) is
                // converted to stock units (24 pieces) before the availability
                // check, serial resolution and storage — the ledger stays in
                // one unit.
                $lineUom = (($lineData['uom_id'] ?? null) && $productId)
                    ? app(\App\Services\ProductUomConverter::class)->resolveOrNull(
                        (int) $productId,
                        (int) $lineData['uom_id'],
                    )
                    : null;

                if ($lineUom && ! $lineUom->isStockUnit()) {
                    $quantityDelivered = app(\App\Services\ProductUomConverter::class)->toStock($lineUom, $quantityDelivered);
                }

                if ($productId) {
                    $product = \App\Models\Product::find($productId);
                    if ($product && (bool) ($product->track_inventory ?? false)) {
                        // Issue #465 (DO13): a standalone delivery order holds
                        // no reservation of its own, so availability is on hand
                        // minus every active reservation held by other
                        // documents (the shared #686 formula). Comparing with
                        // on-hand alone let it ship stock a confirmed sales
                        // order was holding.
                        $availability = $this->inventoryService->stockAvailability(
                            (int) $productId,
                            (int) $warehouseId,
                        );
                        $onHand = $availability['on_hand'];
                        $reserved = $availability['reserved'];
                        $available = $availability['available'];

                        if ($quantityDelivered > $available + 0.0000001) {
                            // Document conversions (quotation/invoice → DO) are
                            // allowed to create backorders: the goods may not be
                            // received yet. Manual standalone creation stays
                            // strict unless the caller opts in.
                            if (empty($data['allow_backorder'])) {
                                throw new \Exception(
                                    "Insufficient stock for {$product->name}. "
                                    ."On hand: {$onHand}, reserved: {$reserved}, "
                                    ."available: {$available}, requested: {$quantityDelivered}."
                                );
                            }

                            Log::warning('Standalone delivery order created as a backorder — insufficient stock', [
                                'delivery_order_id' => $deliveryOrder->id,
                                'product_id' => $productId,
                                'on_hand' => $onHand,
                                'reserved' => $reserved,
                                'available' => $available,
                                'requested' => $quantityDelivered,
                            ]);
                        }
                    }
                }

                $lineProduct = $productId ? \App\Models\Product::find($productId) : null;
                $serialNumbers = $this->resolveSerialNumbers($lineProduct, $lineData, $quantityDelivered);

                // Engine pricing when the caller omits the price (FR-4.1);
                // a supplied price stays verbatim for existing callers.
                $mode = $lineData['price_mode'] ?? (((float) ($lineData['unit_price'] ?? 0) > 0) ? 'legacy' : ($lineProduct ? 'auto' : 'legacy'));
                $priced = $lineType === 'product'
                    ? $pricing->priceLineArray($lineProduct, $lineData, $pricingContext, $mode)
                    : [];

                $unitPrice = $priced['unit_price'] ?? $lineData['unit_price'] ?? null;

                // Issue #685: an explicitly supplied price is per sales UoM
                // (300 per Box); store the per-stock-unit equivalent so the
                // base-quantity line total stays qty × price.
                if ($lineUom && ! $lineUom->isStockUnit() && $unitPrice !== null && $mode === 'legacy') {
                    $unitPrice = app(\App\Services\ProductUomConverter::class)->salesPriceToBase($lineUom, (float) $unitPrice);
                }

                DeliveryOrderLine::create(array_merge([
                    'line_type' => $lineType,
                    'sequence' => $lineData['sequence'] ?? 0,
                    'delivery_order_id' => $deliveryOrder->id,
                    'sales_order_line_id' => null,
                    'product_id' => $lineData['product_id'] ?? null,
                    'model_number' => $lineData['model_number'] ?? null,
                    'description' => $lineData['description'] ?? null,
                    'product_notes' => $lineData['product_notes'] ?? null,
                    'patient_name' => $lineData['patient_name'] ?? null,
                    'quantity_ordered' => $quantityDelivered,
                    'quantity_delivered' => $quantityDelivered,
                    'unit' => $lineData['unit'] ?? 'pcs',
                    // Issue #685: provenance for the sales UoM entered;
                    // quantity_delivered is stock units.
                    'uom_id' => $lineUom?->id,
                    'unit_price' => $unitPrice,
                    'line_total' => $unitPrice !== null ? round($unitPrice * $quantityDelivered, 2) : null,
                    'lot_number' => $lineData['lot_number'] ?? null,
                    'serial_number' => $lineData['serial_number'] ?? null,
                    'product_batch_id' => $lineData['product_batch_id'] ?? null,
                    'product_serial_id' => $lineData['product_serial_id'] ?? null,
                    'batch_number' => $lineData['batch_number'] ?? null,
                    'expiry_date' => $lineData['expiry_date'] ?? null,
                    'serial_numbers' => $serialNumbers,
                ], array_filter([
                    'base_unit_price' => $priced['base_unit_price'] ?? null,
                    'pricing_trace' => $priced['pricing_trace'] ?? null,
                ], fn ($v) => $v !== null)));
            }

            // Calculate total from line items
            $total = $deliveryOrder->lines()->sum('line_total');
            if ($total > 0) {
                $deliveryOrder->update(['total' => $total]);
            }

            return $deliveryOrder->fresh(['lines.product', 'customer']);
        });

        $this->submitForApproval($deliveryOrder);

        return $deliveryOrder;
    }

    // ════════════════════════════════════════════════════════════════════════
    //  STATE TRANSITIONS — per spec §5.1
    // ════════════════════════════════════════════════════════════════════════

    /**
     * DRAFT → CONFIRMED
     * Validates customer, warehouse, and lines. No stock effect.
     */
    /**
     * Issue #177: dual confirmation path.
     *
     * @param  array{method?: string, channel?: string|null, signed_document_path?: string|null}  $confirmation
     *                                                                                                           method 'physical' requires an uploaded signed copy;
     *                                                                                                           method 'digital' requires the channel the confirmation came
     *                                                                                                           through (email/web/whatsapp/other).
     */
    public function confirmDeliveryOrder(DeliveryOrder $deliveryOrder, array $confirmation = []): DeliveryOrder
    {
        if (! $deliveryOrder->isDraft()) {
            throw new \Exception('Only draft delivery orders can be confirmed.');
        }

        if ($deliveryOrder->requires_approval && $deliveryOrder->approval_status !== 'approved') {
            throw new \Exception('This delivery order requires approval before it can be confirmed.');
        }

        if (! $deliveryOrder->warehouse_id) {
            throw new \Exception('Warehouse is required before this delivery order can be confirmed.');
        }

        $method = $confirmation['method'] ?? 'digital';

        if (! in_array($method, ['physical', 'digital'], true)) {
            throw new \Exception('Confirmation method must be physical or digital.');
        }

        $channel = $confirmation['channel'] ?? null;
        $signedDocumentPath = $confirmation['signed_document_path'] ?? $deliveryOrder->signed_document_path;

        if ($method === 'physical' && ! $signedDocumentPath) {
            throw new \Exception('Physical confirmation requires the signed delivery order document to be uploaded first.');
        }

        if ($method === 'digital' && ! $channel) {
            $channel = 'system';
        }

        $actorId = TenantUserHelper::requireTenantUserId(Auth::id());

        // #900 (PC6): status through the engine; the remaining fields keep
        // their existing write.
        app(\App\Services\SalesWorkflowService::class)->transition($deliveryOrder, 'confirm');

        $deliveryOrder->update([
            'confirmed_by' => $actorId,
            'confirmation_method' => $method,
            'confirmed_via_channel' => $method === 'digital' ? $channel : null,
            'signed_document_path' => $method === 'physical' ? $signedDocumentPath : $deliveryOrder->signed_document_path,
        ]);

        $this->logChatter(
            $deliveryOrder,
            'status_changed',
            "Confirmed delivery order {$deliveryOrder->delivery_order_number} ({$method} confirmation)",
            ['from_state' => 'draft', 'to_state' => 'confirmed', 'method' => $method],
        );

        return $deliveryOrder->fresh(['lines.salesOrderLine', 'lines.product', 'customer', 'salesOrder']);
    }

    /**
     * CONFIRMED → DONE
     * Posts inventory movements (stock deduction), updates SO line delivered_qty,
     * fulfils stock reservations. Atomic — all or nothing.
     */
    public function completeDeliveryOrder(DeliveryOrder $deliveryOrder, ?string $idempotencyKey = null): DeliveryOrder
    {
        // Allow completing from DRAFT for convenience (auto-confirms first)
        if ($deliveryOrder->isDraft()) {
            $deliveryOrder = $this->confirmDeliveryOrder($deliveryOrder);
        }

        if (! $deliveryOrder->isConfirmed()) {
            // Idempotency: if already DONE, return as-is
            if ($deliveryOrder->isDone()) {
                $this->logChatter(
                    $deliveryOrder,
                    'status_changed',
                    "Completed delivery order {$deliveryOrder->delivery_order_number} — stock posted",
                    ['from_state' => 'confirmed', 'to_state' => 'done'],
                );

                return $deliveryOrder->fresh(['lines.salesOrderLine', 'lines.product', 'customer', 'salesOrder']);
            }
            throw new \Exception('Only confirmed delivery orders can be completed.');
        }

        if (! $deliveryOrder->warehouse_id) {
            throw new \Exception('Cannot complete a delivery order without a warehouse.');
        }

        return DB::transaction(function () use ($deliveryOrder, $idempotencyKey) {
            $actorId = TenantUserHelper::requireTenantUserId(Auth::id());
            $warehouseId = (int) $deliveryOrder->warehouse_id;

            $deliveryOrder->load(['lines.product', 'lines.salesOrderLine']);

            // Update SO line delivered quantities (for linked DOs) — always, before stock deduction.
            // #373 (O4): fulfilment is DERIVED from deliveries — mirror the
            // delivered quantity into quantity_fulfilled (capped so outstanding
            // never goes negative) and, once every line is fully delivered,
            // transition the order to 'fulfilled' while it is still allocated.
            $orderForFulfilment = null;
            if ($deliveryOrder->sales_order_id) {
                $orderForFulfilment = SalesOrder::find($deliveryOrder->sales_order_id);

                // #458 (DO6): re-validate under a row lock on the order lines —
                // two open delivery orders can each pass the creation check
                // before either completes, so only the lock + re-check here
                // guarantees delivered quantity can never exceed ordered.
                $soLineIds = $deliveryOrder->lines
                    ->pluck('sales_order_line_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                $lockedSoLines = $soLineIds === []
                    ? collect()
                    : SalesOrderLine::whereIn('id', $soLineIds)->lockForUpdate()->get()->keyBy('id');

                foreach ($deliveryOrder->lines as $line) {
                    if (! $line->sales_order_line_id) {
                        continue;
                    }

                    $soLine = $lockedSoLines->get($line->sales_order_line_id) ?? $line->salesOrderLine;
                    if (! $soLine) {
                        continue;
                    }

                    $newDelivered = (float) $soLine->quantity_delivered + (float) $line->quantity_delivered;
                    $deliverable = max(
                        (float) $soLine->quantity - (float) $soLine->quantity_cancelled,
                        0
                    );

                    if ($newDelivered > $deliverable + 0.0001) {
                        throw new \Exception(
                            "Cannot complete delivery order {$deliveryOrder->delivery_order_number}: "
                            ."line #{$soLine->id} would deliver {$newDelivered} of {$deliverable} ordered."
                        );
                    }

                    $soLine->update([
                        'quantity_delivered' => $newDelivered,
                        'quantity_fulfilled' => max(
                            (float) $soLine->quantity_fulfilled,
                            min($newDelivered, $deliverable)
                        ),
                    ]);
                }
            }

            // #373 (O4): derive the order's fulfilled state from the delivery
            // that just completed — never from invoicing or a manual action.
            if ($orderForFulfilment
                && $orderForFulfilment->isAllocated()
                && $this->isFullyDelivered($orderForFulfilment->fresh('lines'))
            ) {
                $this->lifecycleService->fulfil($orderForFulfilment, 'All lines delivered');
            }

            // Deduct stock for each stock-tracked line. Issue #130: a bundle
            // sale consumes its components — decompose before mutating stock so
            // component inventory actually moves.
            //
            // Issue #682: only the quantity no document has issued yet leaves
            // the warehouse here, and postDeliveryCogs() below recognises COGS
            // on that SAME quantity — so completion can no longer issue goods
            // (or their cost) that an invoice already issued.
            $issuedQuantities = [];

            foreach ($deliveryOrder->lines as $line) {
                if (! $line->product_id) {
                    continue;
                }

                $product = $line->product;
                if (! $product) {
                    continue;
                }

                // #457 (DO5): stock must move the same fractional base-UOM
                // quantity the COGS recognises — (int) round() moved 0 for
                // 0.4 and 3 for 2.5 while the GL posted the float.
                $qty = (float) $line->quantity_delivered;
                if ($qty <= 0) {
                    continue;
                }

                $components = $this->bundleDecomposition->stockConsumingComponents($product, $qty);

                $unissuedQty = $this->goodsIssueLedger->remainingForDeliveryOrderLine($line, (float) $qty);
                $issuedQuantities[$line->id] = $unissuedQty > 0 ? $unissuedQty : 0.0;

                foreach ($this->bundleDecomposition->stockConsumingComponents($product, $unissuedQty) as $component) {
                    $componentQty = (float) $component['quantity'];
                    if ($componentQty <= 0) {
                        continue;
                    }

                    $key = ($idempotencyKey ?: "do-complete-{$deliveryOrder->id}-line-{$line->id}").
                        ($product->is_bundle ? "-component-{$component['product']->id}" : '');

                    $this->inventoryService->adjustStock(
                        productId: $component['product']->id,
                        warehouseId: $warehouseId,
                        quantity: $componentQty,
                        type: 'sale',
                        reference: $deliveryOrder->delivery_order_number,
                        referenceType: 'DeliveryOrder',
                        referenceId: $deliveryOrder->id,
                        notes: "Stock deducted for delivery order {$deliveryOrder->delivery_order_number}".
                            ($product->is_bundle ? " (bundle: {$product->name})" : ''),
                        batchNumber: $product->is_bundle ? null : ($line->lot_number ?? $line->batch_number),
                        expiryDate: (! $product->is_bundle && $line->expiry_date) ? new \DateTime($line->expiry_date) : null,
                        idempotencyKey: $key,
                        // Issue #686: this issue may consume its own sales
                        // order's reservation; stock held by other documents
                        // is unavailable.
                        reservationSourceType: $deliveryOrder->sales_order_id ? 'SalesOrder' : 'DeliveryOrder',
                        reservationSourceId: (int) ($deliveryOrder->sales_order_id ?: $deliveryOrder->id),
                    );
                }

                if ($components === []) {
                    continue;
                }

                // Issue #131: mark the line's serials sold (customer, date,
                // warranty) now that stock has left for this delivery.
                if (! empty($line->serial_numbers) && $product) {
                    try {
                        app(\App\Services\BatchSerialConsumptionService::class)->markSerialsSold(
                            product: $product,
                            serialNumbers: (array) $line->serial_numbers,
                            customerId: (int) $deliveryOrder->customer_id,
                            saleReference: $deliveryOrder->delivery_order_number,
                            soldAt: now(),
                        );
                    } catch (\Throwable $e) {
                        \Log::error('DeliveryOrderService: serial marking failed (delivery preserved)', [
                            'delivery_order_id' => $deliveryOrder->id,
                            'line_id' => $line->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Fulfil corresponding stock reservation (if linked to SO)
                if ($deliveryOrder->sales_order_id && $line->salesOrderLine) {
                    $this->fulfilReservationForLine(
                        $line->product_id,
                        $warehouseId,
                        $qty,
                        (int) $deliveryOrder->sales_order_id,
                        (int) $line->salesOrderLine->id,
                    );
                }
            }

            // Issue #176: recognise COGS at completion time.
            $this->postDeliveryCogs($deliveryOrder, $warehouseId, $actorId, $issuedQuantities);

            // #900 (PC6): completion status through the engine.
            app(\App\Services\SalesWorkflowService::class)->transition($deliveryOrder, 'complete');

            $deliveryOrder->update([
                'completed_at' => now(),
                'completed_by' => $actorId,
            ]);

            $this->logChatter(
                $deliveryOrder,
                'status_changed',
                "Completed delivery order {$deliveryOrder->delivery_order_number} — stock posted",
                ['from_state' => 'confirmed', 'to_state' => 'done'],
            );

            return $deliveryOrder->fresh(['lines.salesOrderLine', 'lines.product', 'customer', 'salesOrder']);
        });
    }

    /**
     * DONE → CANCELLED (Reversal)
     * Posts compensating inbound stock movements, subtracts delivered quantities
     * from linked Sales Order lines. Never deletes the original.
     *
     * Issue #682: the restock is exactly what this delivery order issued at
     * completion. A delivery whose goods an invoice had already issued restocks
     * nothing — putting the delivered quantity back would conjure stock nobody
     * ever received.
     *
     * Issue #460 (DO8): the restock is now driven by the stock movements
     * completion recorded — the same components, quantities, lots and expiry —
     * instead of being recomputed from the lines, and the serials completion
     * marked sold are returned to stock.
     */
    public function reverseDeliveryOrder(DeliveryOrder $deliveryOrder, string $reason): DeliveryOrder
    {
        if (! $deliveryOrder->isDone()) {
            throw new \Exception('Only completed delivery orders can be reversed.');
        }

        return DB::transaction(function () use ($deliveryOrder, $reason) {
            $actorId = TenantUserHelper::requireTenantUserId(Auth::id());
            $warehouseId = (int) $deliveryOrder->warehouse_id;

            $deliveryOrder->load(['lines.product', 'lines.salesOrderLine']);

            // #460 (DO8): replay the completion's stock movements in reverse —
            // same product/component, quantity, lot and expiry — and un-mark
            // the serials the completion marked sold.
            $this->restockReversingCompletionMovements($deliveryOrder, $reason);
            $this->returnDeliverySerials($deliveryOrder);

            foreach ($deliveryOrder->lines as $line) {
                // Subtract from SO line delivered_qty (always, regardless of stock tracking)
                if ($deliveryOrder->sales_order_id && $line->salesOrderLine) {
                    $soLine = $line->salesOrderLine;
                    $newDelivered = max(0, (float) $soLine->quantity_delivered - (float) $line->quantity_delivered);
                    $soLine->update(['quantity_delivered' => $newDelivered]);
                }
            }

            // Issue #176: reverse the COGS entry that completion posted.
            $this->postDeliveryCogsReversal($deliveryOrder, $warehouseId, $actorId);

            // #900 (PC6): reversal status through the engine.
            app(\App\Services\SalesWorkflowService::class)->transition(
                $deliveryOrder,
                'reverse',
                ['reason' => $reason],
                $reason,
            );

            $deliveryOrder->update([
                // #463 (DO11): track the reversal explicitly; the note stays
                // for continuity but no longer carries the only evidence.
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'notes' => $deliveryOrder->notes
                    ? $deliveryOrder->notes."\n[REVERSED] {$reason}"
                    : "[REVERSED] {$reason}",
            ]);

            Log::info('Delivery order reversed', [
                'do_id' => $deliveryOrder->id,
                'do_number' => $deliveryOrder->delivery_order_number,
                'reason' => $reason,
                'actor' => $actorId,
            ]);

            $this->logChatter(
                $deliveryOrder,
                'reversed',
                "Reversed delivery order {$deliveryOrder->delivery_order_number}",
                ['to_state' => 'cancelled', 'reason' => $reason],
            );

            return $deliveryOrder->fresh(['lines.salesOrderLine', 'lines.product', 'customer', 'salesOrder']);
        });
    }

    /**
     * #459 (DO7): whether this tenant allows billing a delivery order before
     * it is completed. Off by default — the invoice then waits until the goods
     * actually shipped, so no never-shipped invoice can exist. Enabling it does
     * not relax any permission: the conversion still travels the same
     * sales.write route chain as before (no new permission string).
     */
    public function billBeforeDeliveryEnabled(): bool
    {
        return (bool) \App\Models\Setting::getValue('sales.delivery.bill_before_delivery', false);
    }

    /**
     * #459 (DO7): a delivery order may be billed only when its stock already
     * moved (`done`), or — under the explicit tenant policy above — while it is
     * still draft/confirmed. Cancelled/reversed orders are never billable.
     */
    public function isInvoiceableStatus(DeliveryOrder $deliveryOrder): bool
    {
        if ($deliveryOrder->isDone()) {
            return true;
        }

        if ($deliveryOrder->isCancelled()) {
            return false;
        }

        return $this->billBeforeDeliveryEnabled()
            && ($deliveryOrder->isDraft() || $deliveryOrder->isConfirmed());
    }

    /**
     * #459 (DO7): every invoice linked to a delivery order — the legacy
     * `invoice_id` pointer plus the `invoice_delivery_orders` pivot.
     */
    private function linkedInvoices(DeliveryOrder $deliveryOrder): \Illuminate\Support\Collection
    {
        $invoices = collect();

        if ($deliveryOrder->invoice_id) {
            $found = \App\Models\Invoice::find($deliveryOrder->invoice_id);
            if ($found) {
                $invoices->push($found);
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('invoice_delivery_orders')) {
            $pivotIds = \Illuminate\Support\Facades\DB::table('invoice_delivery_orders')
                ->where('delivery_order_id', $deliveryOrder->id)
                ->pluck('invoice_id');

            if ($pivotIds->isNotEmpty()) {
                $invoices = $invoices
                    ->merge(\App\Models\Invoice::whereIn('id', $pivotIds)->get())
                    ->unique('id')
                    ->values();
            }
        }

        return $invoices;
    }

    /**
     * #459 (DO7): a draft/voided/cancelled invoice does not own delivered
     * quantities — the same stale-link states resetToDraft() detaches.
     */
    private function invoiceIsHarmless($invoice): bool
    {
        return ! $invoice // orphaned pointer — nothing to protect
            || in_array($invoice->status, ['draft', 'cancelled'], true)
            || in_array((string) $invoice->lifecycle_status, ['draft', 'cancelled', 'voided', 'reversed', 'superseded'], true);
    }

    /**
     * #459 (DO7): drop stale (harmless) invoice links so a cancelled/reset
     * delivery order cannot be billed again through them. The draft invoice
     * itself is left untouched — it was never posted and owns nothing.
     */
    private function detachInvoiceLinks(DeliveryOrder $deliveryOrder, \Illuminate\Support\Collection $invoices): void
    {
        if ($invoices->isEmpty()) {
            return;
        }

        $deliveryOrder->update(['invoice_id' => null]);

        if (\Illuminate\Support\Facades\Schema::hasTable('invoice_delivery_orders')) {
            \Illuminate\Support\Facades\DB::table('invoice_delivery_orders')
                ->where('delivery_order_id', $deliveryOrder->id)
                ->whereIn('invoice_id', $invoices->pluck('id')->all())
                ->delete();
        }
    }

    /**
     * Issue #460 (DO8): the reverse replay of a completion.
     *
     * Completion records one `sale` movement per component it consumed (bundle
     * decomposition, #130) carrying the line's lot and expiry; this method
     * walks those same rows and posts the compensating `return` for each — same
     * product/component, same quantity, same batch_number and expiry_date. A
     * virtual bundle therefore restocks its components (it has no stock of its
     * own), and a lot delivery restores its named lot with the FEFO trail
     * intact instead of a lot-less aggregate.
     *
     * A delivery whose completion recorded no `sale` movement issued no goods
     * (an invoice already shipped them — #682), and a legacy delivery completed
     * before the movement trail existed has none either; both fall back to
     * {@see restockLegacyLines()}, which restocks only what the goods-issue
     * ledger attributes to this delivery and never conjures stock.
     */
    private function restockReversingCompletionMovements(DeliveryOrder $deliveryOrder, string $reason): void
    {
        $saleMovements = StockMovement::query()
            ->where('reference_type', 'DeliveryOrder')
            ->where('reference_id', $deliveryOrder->id)
            ->where('type', 'sale')
            ->orderBy('id')
            ->get();

        if ($saleMovements->isEmpty()) {
            $this->restockLegacyLines($deliveryOrder, $reason);

            return;
        }

        foreach ($saleMovements as $movement) {
            $this->inventoryService->adjustStock(
                productId: (int) $movement->product_id,
                warehouseId: (int) $movement->warehouse_id,
                quantity: (float) $movement->quantity,
                type: 'return',
                reference: "REVERSAL-{$deliveryOrder->delivery_order_number}",
                referenceType: 'DeliveryOrder',
                referenceId: $deliveryOrder->id,
                notes: "Reversal: {$reason} — reverses stock movement #{$movement->id}",
                batchNumber: $movement->batch_number,
                expiryDate: $movement->expiry_date
                    ? new \DateTime($movement->expiry_date->toDateString())
                    : null,
                idempotencyKey: "do-reverse-{$deliveryOrder->id}-movement-{$movement->id}",
            );
        }
    }

    /**
     * The pre-#460 reversal logic, kept as the fallback for delivery orders
     * with no recorded `sale` movement (a legacy completion, or goods an
     * invoice had already issued — #682). It recomputes the bundle demand and
     * restocks exactly what the goods-issue ledger attributes to this delivery:
     * zero when completion shipped nothing, so it can never conjure stock.
     */
    private function restockLegacyLines(DeliveryOrder $deliveryOrder, string $reason): void
    {
        foreach ($deliveryOrder->lines as $line) {
            if (! $line->product_id) {
                continue;
            }

            $product = $line->product;
            $isStockTracked = $product && (bool) ($product->track_inventory ?? false);

            if (! $isStockTracked) {
                continue;
            }

            // #457 (DO5): reverse exactly what was issued, fraction included.
            $qty = (float) $line->quantity_delivered;

            foreach ($this->bundleDecomposition->stockConsumingComponents($product, $qty) as $component) {
                $issuedQty = (float) $this->goodsIssueLedger->issuedByDeliveryOrder($deliveryOrder, (int) $component['product']->id);

                if ($issuedQty <= 0) {
                    continue;
                }

                // Compensating inbound movement (restock)
                $this->inventoryService->adjustStock(
                    productId: $component['product']->id,
                    warehouseId: (int) $deliveryOrder->warehouse_id,
                    quantity: $issuedQty,
                    type: 'return',
                    reference: "REVERSAL-{$deliveryOrder->delivery_order_number}",
                    referenceType: 'DeliveryOrder',
                    referenceId: $deliveryOrder->id,
                    notes: "Reversal: {$reason}",
                    idempotencyKey: "do-reverse-{$deliveryOrder->id}-line-{$line->id}".
                        ($product->is_bundle ? "-component-{$component['product']->id}" : ''),
                );
            }
        }
    }

    /**
     * Issue #460 (DO8): the inverse of completion's markSerialsSold() — the
     * serials this delivery marked sold return to `available` so they can be
     * sold again.
     *
     * Two guards keep the inverse exact: the serial must currently be `sold`
     * with this delivery order's number as its sale reference (a serial another
     * document sold is never touched), and this delivery must have issued one
     * of the line's stock-consuming products (a delivery whose goods an invoice
     * already shipped recorded no movement and is left for the invoice — #682).
     */
    private function returnDeliverySerials(DeliveryOrder $deliveryOrder): void
    {
        $serialService = app(BatchSerialConsumptionService::class);

        foreach ($deliveryOrder->lines as $line) {
            if (! $line->product || empty($line->serial_numbers)) {
                continue;
            }

            $lineProductIds = array_map(
                fn (array $component) => (int) $component['product']->id,
                $this->bundleDecomposition->stockConsumingComponents(
                    $line->product,
                    (float) $line->quantity_delivered,
                ),
            );

            if ($lineProductIds === []) {
                continue;
            }

            $issuedByThisDelivery = StockMovement::query()
                ->where('reference_type', 'DeliveryOrder')
                ->where('reference_id', $deliveryOrder->id)
                ->where('type', 'sale')
                ->whereIn('product_id', $lineProductIds)
                ->exists();

            if (! $issuedByThisDelivery) {
                continue;
            }

            $serials = ProductSerial::query()
                ->where('product_id', $line->product_id)
                ->whereIn('serial_number', (array) $line->serial_numbers)
                ->where('status', ProductSerial::STATUS_SOLD)
                ->where('sale_reference', $deliveryOrder->delivery_order_number)
                ->pluck('serial_number')
                ->all();

            if ($serials !== []) {
                $serialService->markSerialsReturned($line->product, $serials);
            }
        }
    }

    /**
     * DRAFT|CONFIRMED → CANCELLED (pre-completion cancellation — no stock effect)
     */
    public function cancelDeliveryOrder(DeliveryOrder $deliveryOrder, ?string $reason = null): DeliveryOrder
    {
        if ($deliveryOrder->isDone()) {
            throw new \Exception('Cannot cancel a completed delivery order. Use reversal instead.');
        }

        if ($deliveryOrder->isCancelled()) {
            throw new \Exception('Delivery order is already cancelled.');
        }

        // #459 (DO7): an issued invoice owns the delivered quantities, so a
        // cancellation underneath it would leave a billed-but-never-shipped
        // invoice. Only stale (draft/cancelled/voided) links are detachable.
        $linkedInvoices = $this->linkedInvoices($deliveryOrder);

        $blockingInvoice = $linkedInvoices->first(fn ($invoice) => ! $this->invoiceIsHarmless($invoice));
        if ($blockingInvoice) {
            throw new \Exception(
                'Cannot cancel a delivery order linked to an issued invoice ('.$blockingInvoice->invoice_number.'). Issue a credit note instead.'
            );
        }

        // #900 (PC6): cancellation status through the engine.
        app(\App\Services\SalesWorkflowService::class)->transition(
            $deliveryOrder,
            'cancel',
            ['reason' => $reason],
            $reason,
        );

        $this->detachInvoiceLinks($deliveryOrder, $linkedInvoices);

        $deliveryOrder->update([
            'notes' => $reason
                ? ($deliveryOrder->notes ? $deliveryOrder->notes."\n[CANCELLED] {$reason}" : "[CANCELLED] {$reason}")
                : $deliveryOrder->notes,
        ]);

        // #463 (DO11): the log used to say "Reset ... to draft" with
        // to_state 'draft' although the order was cancelled.
        $this->logChatter(
            $deliveryOrder,
            'status_changed',
            "Cancelled delivery order {$deliveryOrder->delivery_order_number}",
            ['to_state' => 'cancelled', 'reason' => $reason],
        );

        return $deliveryOrder->fresh(['lines.salesOrderLine', 'customer', 'salesOrder']);
    }

    /**
     * CANCELLED → DRAFT
     * Allows re-editing and re-confirming a cancelled delivery order.
     * Blocks if the DO has been invoiced (stock was already deducted via invoice flow).
     */
    public function resetToDraft(DeliveryOrder $deliveryOrder): DeliveryOrder
    {
        // Admin-only route (documents.reset_to_draft). Allowed from a completed
        // document (reversal restores stock + reverses the COGS entry first) or
        // from a cancelled one (stock was already restored on cancel).
        if (! $deliveryOrder->isCancelled() && ! $deliveryOrder->isDone()) {
            throw new \Exception(
                'Only completed or cancelled delivery orders can be reset to draft. Current: '.$deliveryOrder->status
            );
        }

        // An invoice that has been issued (or moved past draft) owns the
        // delivered quantities, so the order cannot be re-opened underneath it.
        // A draft / cancelled / voided invoice does NOT — the link is stale and
        // previously blocked resets forever on a cancelled DO (422), so those
        // links are detached here instead (#459 shares these helpers).
        $linkedInvoices = $this->linkedInvoices($deliveryOrder);

        $blockingInvoice = $linkedInvoices->first(fn ($invoice) => ! $this->invoiceIsHarmless($invoice));
        if ($blockingInvoice) {
            throw new \Exception(
                'Cannot reset a delivery order that is linked to an issued invoice ('.$blockingInvoice->invoice_number.'). Cancel or reset that invoice first.'
            );
        }

        $this->detachInvoiceLinks($deliveryOrder, $linkedInvoices);

        if ($deliveryOrder->isDone()) {
            // DONE → CANCELLED via the canonical reversal: posts compensating
            // inbound stock movements and reverses the inventory/COGS GL entry.
            $this->reverseDeliveryOrder($deliveryOrder, 'Reset to draft');
            $deliveryOrder->refresh();
        }

        // #900 (PC6): reset status through the engine (done is first reversed
        // to cancelled above, so the engine sees cancelled → draft).
        app(\App\Services\SalesWorkflowService::class)->transition($deliveryOrder, 'reset_to_draft');

        $deliveryOrder->update([
            'notes' => $deliveryOrder->notes
                ? $deliveryOrder->notes."\n[RESET TO DRAFT]"
                : '[RESET TO DRAFT]',
        ]);

        return $deliveryOrder->fresh(['lines.salesOrderLine', 'customer', 'salesOrder']);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ════════════════════════════════════════════════════════════════════════

    private function fulfilReservationForLine(int $productId, int $warehouseId, float $qty, int $salesOrderId, int $salesOrderLineId): void
    {
        $reservation = StockReservation::where([
            'source_document_type' => 'SalesOrder',
            'source_document_id' => $salesOrderId,
            'source_document_line_id' => $salesOrderLineId,
        ])->whereIn('status', ['open', 'allocated'])->first();

        if ($reservation) {
            $fulfilQty = min($qty, $reservation->remaining_quantity);
            if ($fulfilQty > 0) {
                $this->reservationService->fulfil($reservation, $fulfilQty);
            }
        }
    }

    public function isFullyDelivered(SalesOrder $order): bool
    {
        $order->loadMissing('lines');

        foreach ($order->lines as $line) {
            $delivered = (float) $line->quantity_delivered;
            $ordered = (float) $line->quantity;
            $cancelled = (float) $line->quantity_cancelled;

            if ($delivered < $ordered - $cancelled - 0.0001) {
                return false;
            }
        }

        return true;
    }

    /**
     * Issue (or refresh) the customer's public acceptance link for a delivery
     * order. Returns the token + frontend URL; the caller sends the email.
     */
    public function issueCustomerLink(DeliveryOrder $deliveryOrder, int $expiresInDays = 30): array
    {
        // Built-in e-signature: the customer link opens the same /sign/{token}
        // page used for invoices, where the recipient signs the real delivery
        // order PDF — instead of the legacy /do-accept upload portal.
        $esign = app(\App\Services\EsignService::class)->request(
            'delivery_order',
            $deliveryOrder->id,
            [[
                'name' => $deliveryOrder->customer?->name,
                'email' => $deliveryOrder->customer?->email,
            ]],
            null,
            $expiresInDays,
        );

        $frontendUrl = rtrim((string) config('frontend.url'), '/');

        return [
            'token' => $esign->token,
            'url' => "{$frontendUrl}/sign/{$esign->token}",
            'expires_at' => $esign->expires_at?->toIso8601String(),
            'esign_request_id' => $esign->id,
        ];
    }

    /**
     * Users who can approve a delivery order.
     *
     * Issue #645: delegates to the one sales reviewer resolver
     * (SalesApprovalGate::resolveReviewers). A tenant may configure a
     * delivery-order-specific list (`sales.delivery_order.reviewer_user_ids` /
     * `_roles`); when nothing is configured the legacy invoice reviewer pool
     * (Sales Settings → Approval Workflow) still applies, as before.
     */
    public function resolveReviewers(?int $submitterId = null): \Illuminate\Support\Collection
    {
        $submitter = $submitterId !== null
            ? \App\Models\User::find($submitterId)
            : null;

        return app(\App\Services\SalesApprovalGate::class)
            ->resolveReviewers(\App\Services\SalesApprovalGate::REQUEST_TYPE_DELIVERY_ORDER, $submitter);
    }

    /**
     * #454 (DO2): the reviewer action must go through the canonical
     * ApprovalService — reviewer pool (Sales Settings → Approval Workflow),
     * pending-only and no self-approval — instead of flipping the flag
     * directly. The decision event mirrors the outcome back through
     * applyApprovedDecision() (DeliveryOrderApprovalDecisionListener).
     */
    public function approveDeliveryOrder(DeliveryOrder $deliveryOrder, \App\Models\User $user): DeliveryOrder
    {
        $request = $this->pendingApprovalRequest($deliveryOrder);

        $this->assertMayDecideApproval($request, $user, 'approval');

        app(\App\Services\ApprovalService::class)->approveRequest((int) $request->id, (int) $user->id);

        return $this->applyApprovedDecision($deliveryOrder->fresh(), $user);
    }

    public function rejectDeliveryOrder(DeliveryOrder $deliveryOrder, \App\Models\User $user, string $reason): DeliveryOrder
    {
        $request = $this->pendingApprovalRequest($deliveryOrder);

        $this->assertMayDecideApproval($request, $user, 'rejection');

        app(\App\Services\ApprovalService::class)->rejectRequest((int) $request->id, (int) $user->id, $reason);

        return $this->applyRejectedDecision($deliveryOrder->fresh(), $user, $reason);
    }

    /**
     * State application for a decision already authorised by ApprovalService
     * (also the callback for inbox decisions via
     * DeliveryOrderApprovalDecisionListener). Idempotent.
     */
    public function applyApprovedDecision(DeliveryOrder $deliveryOrder, \App\Models\User $user): DeliveryOrder
    {
        if ($deliveryOrder->approval_status !== 'approved') {
            $deliveryOrder->update(['approval_status' => 'approved']);
        }

        $this->settleApprovalRequest($deliveryOrder, 'approved', (int) $user->id);

        $this->logChatter($deliveryOrder, 'status_changed', "Approved {$deliveryOrder->delivery_order_number}", [
            'state' => 'pending → approved',
        ]);

        return $deliveryOrder->fresh();
    }

    public function applyRejectedDecision(DeliveryOrder $deliveryOrder, \App\Models\User $user, string $reason): DeliveryOrder
    {
        // #900 (PC6): re-opening the document for editing is a workflow
        // transition; the approval flag keeps its own write.
        app(\App\Services\SalesWorkflowService::class)->transition(
            $deliveryOrder,
            'reject',
            ['reason' => $reason],
            $reason,
        );

        $deliveryOrder->update([
            'approval_status' => 'rejected',
        ]);
        $this->settleApprovalRequest($deliveryOrder, 'rejected', (int) $user->id, $reason);

        $this->logChatter($deliveryOrder, 'status_changed', "Rejected {$deliveryOrder->delivery_order_number}", [
            'state' => 'pending → rejected',
            'reason' => $reason,
        ]);

        return $deliveryOrder->fresh();
    }

    private function pendingApprovalRequest(DeliveryOrder $deliveryOrder): \App\Models\ApprovalRequest
    {
        if ($deliveryOrder->approval_status !== 'pending') {
            throw new \Exception(
                'This delivery order is not pending approval (current: '.($deliveryOrder->approval_status ?? 'none').').'
            );
        }

        $request = \App\Models\ApprovalRequest::query()
            ->where('subject_type', DeliveryOrder::class)
            ->where('subject_id', $deliveryOrder->id)
            ->where('request_type', 'delivery_order_approval')
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if (! $request) {
            throw new \Exception('This delivery order has no pending approval request.');
        }

        return $request;
    }

    private function assertMayDecideApproval(\App\Models\ApprovalRequest $request, \App\Models\User $user, string $action): void
    {
        if ((int) $request->requested_by === (int) $user->id) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Self-{$action} is not permitted.");
        }

        if (! app(\App\Services\ApprovalService::class)->userCanApprove($user, $request->request_type, (float) $request->amount)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'You are not a reviewer for delivery order approvals.'
            );
        }
    }

    /**
     * Locate which tenant owns a given delivery-order customer token.
     * Mirrors InvoiceEvidenceService::resolveTenantForAcceptanceToken for the
     * public /do-accept route.
     */
    public function resolveTenantForCustomerToken(string $token): ?\App\Models\Tenant
    {
        foreach (\App\Models\Tenant::all() as $tenant) {
            try {
                $found = $tenant->run(function () use ($token) {
                    return DeliveryOrder::where('acceptance_token', $token)->exists();
                });

                if ($found) {
                    return $tenant;
                }
            } catch (\Throwable $e) {
                // A tenant whose schema predates the acceptance_token column.
                continue;
            }
        }

        return null;
    }
}
