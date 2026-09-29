<?php

namespace Modules\Sales\Services;

use App\Models\Product;
use App\Models\Warehouse;
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
        } catch (\Throwable $e) {
            Log::warning('Failed to notify approvers for delivery order approval', [
                'delivery_order_id' => $deliveryOrder->id,
                'error' => $e->getMessage(),
            ]);
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
    ) {}

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
            'status' => 'draft',
            'source' => 'sales_order',
            'delivery_date' => $data['delivery_date'] ?? now()->toDateString(),
            'expected_date' => $data['expected_date'] ?? null,
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

        $lines = $data['lines'] ?? [];
        $hasDeliverableProduct = false;
        foreach ($lines as $lineData) {
            $soLineId = $lineData['sales_order_line_id'];
            $soLine = SalesOrderLine::findOrFail($soLineId);
            $lineType = $soLine->line_type ?? $lineData['line_type'] ?? 'product';

            if ((int) $soLine->sales_order_id !== (int) $order->id) {
                throw new \Exception("Sales order line {$soLineId} does not belong to order #{$order->id}.");
            }

            $quantityDelivered = (float) ($lineData['quantity_delivered'] ?? 0);
            if ($lineType === 'product' && $quantityDelivered <= 0) {
                throw new \Exception("Delivery quantity must be greater than zero for sales order line #{$soLineId}.");
            }
            if ($lineType === 'product') {
                $hasDeliverableProduct = true;
            }
            $remainingForThisLine = (float) $soLine->quantity
                - (float) $soLine->quantity_delivered
                - (float) $soLine->quantity_cancelled;

            if ($quantityDelivered > $remainingForThisLine + 0.0001) {
                throw new \Exception(
                    "Cannot deliver {$quantityDelivered} of line #{$soLineId}. "
                    ."Only {$remainingForThisLine} remaining to deliver."
                );
            }

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
                'unit_price' => $lineType === 'product' ? (float) ($lineData['unit_price'] ?? $soLine->unit_price ?? 0) : null,
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
     * Issue #176: DO completion issues inventory from the ledger's perspective
     * too — post Dr COGS / Cr Inventory at completion, independent of invoice
     * posting (the documented no-enforced-DO/Invoice sequencing policy).
     */
    private function postDeliveryCogs(DeliveryOrder $deliveryOrder, int $warehouseId, int $actorId): void
    {
        $totalCogs = $this->deliveryCogsTotal($deliveryOrder, $warehouseId);

        if ($totalCogs <= 0) {
            return;
        }

        $result = $this->postingEngine->domainPosting(new \App\Accounting\DTOs\DomainPostingRequest(
            postingRequestId: \App\Accounting\DTOs\DomainPostingRequest::generateRequestId(),
            idempotencyKey: "delivery_order:{$deliveryOrder->id}:cogs",
            tenantId: (string) (function_exists('tenant') && tenant() ? tenant('id') : ''),
            entityId: '',
            accountingBookId: '',
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
     * FEFO layer cost when available, bundle component cost otherwise.
     */
    private function deliveryCogsTotal(DeliveryOrder $deliveryOrder, int $warehouseId): float
    {
        $batchConsumption = app(\App\Services\BatchSerialConsumptionService::class);
        $total = 0.0;

        foreach ($deliveryOrder->lines as $line) {
            if (($line->line_type ?? 'product') !== 'product' || ! $line->product) {
                continue;
            }

            $qty = (float) $line->quantity_delivered;
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

            $deliveryOrder = DeliveryOrder::create([
                'sales_order_id' => null,
                'customer_id' => $customerId,
                'warehouse_id' => $warehouseId,
                'status' => 'draft',
                'source' => $data['source'] ?? 'standalone',
                'external_reference' => $data['external_reference'] ?? null,
                'delivery_date' => $data['delivery_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
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

            foreach ($lines as $lineData) {
                $lineType = $lineData['line_type'] ?? 'product';
                $quantityDelivered = (float) ($lineData['quantity_delivered'] ?? $lineData['quantity'] ?? 0);
                if ($lineType === 'product' && $quantityDelivered <= 0) {
                    throw new \Exception('Each line must have a quantity greater than 0.');
                }

                // Check available stock for stock-tracked products
                $productId = $lineData['product_id'] ?? null;
                if ($productId) {
                    $product = \App\Models\Product::find($productId);
                    if ($product && (bool) ($product->track_inventory ?? false)) {
                        $stockLevel = $this->inventoryService->getStockLevel((int) $productId, (int) $warehouseId);
                        $available = $stockLevel ? (float) $stockLevel->quantity : 0;
                        if ($quantityDelivered > $available) {
                            // Document conversions (quotation/invoice → DO) are
                            // allowed to create backorders: the goods may not be
                            // received yet. Manual standalone creation stays
                            // strict unless the caller opts in.
                            if (empty($data['allow_backorder'])) {
                                throw new \Exception(
                                    "Insufficient stock for {$product->name}. "
                                    ."Available: {$available}, requested: {$quantityDelivered}."
                                );
                            }

                            Log::warning('Standalone delivery order created as a backorder — insufficient stock', [
                                'delivery_order_id' => $deliveryOrder->id,
                                'product_id' => $productId,
                                'available' => $available,
                                'requested' => $quantityDelivered,
                            ]);
                        }
                    }
                }

                $lineProduct = $productId ? \App\Models\Product::find($productId) : null;
                $serialNumbers = $this->resolveSerialNumbers($lineProduct, $lineData, $quantityDelivered);

                DeliveryOrderLine::create([
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
                    'unit_price' => $lineData['unit_price'] ?? null,
                    'line_total' => isset($lineData['unit_price']) ? ($lineData['unit_price'] * $quantityDelivered) : null,
                    'lot_number' => $lineData['lot_number'] ?? null,
                    'serial_number' => $lineData['serial_number'] ?? null,
                    'product_batch_id' => $lineData['product_batch_id'] ?? null,
                    'product_serial_id' => $lineData['product_serial_id'] ?? null,
                    'batch_number' => $lineData['batch_number'] ?? null,
                    'expiry_date' => $lineData['expiry_date'] ?? null,
                    'serial_numbers' => $serialNumbers,
                ]);
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

        $deliveryOrder->update([
            'status' => 'confirmed',
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

            // Update SO line delivered quantities (for linked DOs) — always, before stock deduction
            if ($deliveryOrder->sales_order_id) {
                foreach ($deliveryOrder->lines as $line) {
                    if ($line->salesOrderLine) {
                        $soLine = $line->salesOrderLine;
                        $newDelivered = (float) $soLine->quantity_delivered + (float) $line->quantity_delivered;
                        $soLine->update(['quantity_delivered' => $newDelivered]);
                    }
                }
            }

            // Deduct stock for each stock-tracked line. Issue #130: a bundle
            // sale consumes its components — decompose before mutating stock so
            // component inventory actually moves.
            foreach ($deliveryOrder->lines as $line) {
                if (! $line->product_id) {
                    continue;
                }

                $product = $line->product;
                if (! $product) {
                    continue;
                }

                $qty = (int) round((float) $line->quantity_delivered);
                if ($qty <= 0) {
                    continue;
                }

                $components = $this->bundleDecomposition->stockConsumingComponents($product, $qty);

                foreach ($components as $component) {
                    $componentQty = (int) round($component['quantity']);
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
            $this->postDeliveryCogs($deliveryOrder, $warehouseId, $actorId);

            $deliveryOrder->update([
                'status' => 'done',
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

            foreach ($deliveryOrder->lines as $line) {
                if (! $line->product_id) {
                    continue;
                }

                $product = $line->product;
                $isStockTracked = $product && (bool) ($product->track_inventory ?? false);

                if ($isStockTracked) {
                    $qty = (int) round((float) $line->quantity_delivered);
                    if ($qty <= 0) {
                        continue;
                    }

                    // Compensating inbound movement (restock)
                    $this->inventoryService->adjustStock(
                        productId: $line->product_id,
                        warehouseId: $warehouseId,
                        quantity: $qty,
                        type: 'return',
                        reference: "REVERSAL-{$deliveryOrder->delivery_order_number}",
                        referenceType: 'DeliveryOrder',
                        referenceId: $deliveryOrder->id,
                        notes: "Reversal: {$reason}",
                        idempotencyKey: "do-reverse-{$deliveryOrder->id}-line-{$line->id}",
                    );
                }

                // Subtract from SO line delivered_qty (always, regardless of stock tracking)
                if ($deliveryOrder->sales_order_id && $line->salesOrderLine) {
                    $soLine = $line->salesOrderLine;
                    $newDelivered = max(0, (float) $soLine->quantity_delivered - (float) $line->quantity_delivered);
                    $soLine->update(['quantity_delivered' => $newDelivered]);
                }
            }

            // Issue #176: reverse the COGS entry that completion posted.
            $this->postDeliveryCogsReversal($deliveryOrder, $warehouseId, $actorId);

            $deliveryOrder->update([
                'status' => 'cancelled',
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
                'cancelled',
                "Cancelled delivery order {$deliveryOrder->delivery_order_number}",
                ['reason' => $reason],
            );

            return $deliveryOrder->fresh(['lines.salesOrderLine', 'lines.product', 'customer', 'salesOrder']);
        });
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

        $deliveryOrder->update([
            'status' => 'cancelled',
            'notes' => $reason
                ? ($deliveryOrder->notes ? $deliveryOrder->notes."\n[CANCELLED] {$reason}" : "[CANCELLED] {$reason}")
                : $deliveryOrder->notes,
        ]);

        $this->logChatter(
            $deliveryOrder,
            'status_changed',
            "Reset delivery order {$deliveryOrder->delivery_order_number} to draft",
            ['to_state' => 'draft'],
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
        // links are detached here instead.
        $harmlessInvoiceStates = ['draft', 'cancelled', 'voided', 'reversed', 'superseded'];
        $invoiceIsHarmless = fn ($invoice): bool => ! $invoice // orphaned pointer — nothing to protect
            || in_array($invoice->status, ['draft', 'cancelled'], true)
            || in_array((string) $invoice->lifecycle_status, $harmlessInvoiceStates, true);

        $linkedInvoices = collect();
        if ($deliveryOrder->invoice_id) {
            $linkedInvoices->push(\App\Models\Invoice::find($deliveryOrder->invoice_id));
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('invoice_delivery_orders')) {
            $pivotInvoiceIds = \Illuminate\Support\Facades\DB::table('invoice_delivery_orders')
                ->where('delivery_order_id', $deliveryOrder->id)
                ->pluck('invoice_id');
            if ($pivotInvoiceIds->isNotEmpty()) {
                $linkedInvoices = $linkedInvoices
                    ->merge(\App\Models\Invoice::whereIn('id', $pivotInvoiceIds)->get())
                    ->filter();
            }
        }

        $blockingInvoice = $linkedInvoices->first(fn ($invoice) => ! $invoiceIsHarmless($invoice));
        if ($blockingInvoice) {
            throw new \Exception(
                'Cannot reset a delivery order that is linked to an issued invoice ('.$blockingInvoice->invoice_number.'). Cancel or reset that invoice first.'
            );
        }

        if ($linkedInvoices->isNotEmpty()) {
            // Detach every non-issued link so the order can be edited and billed again.
            $deliveryOrder->update(['invoice_id' => null]);
            if (\Illuminate\Support\Facades\Schema::hasTable('invoice_delivery_orders')) {
                \Illuminate\Support\Facades\DB::table('invoice_delivery_orders')
                    ->where('delivery_order_id', $deliveryOrder->id)
                    ->whereIn('invoice_id', $linkedInvoices->pluck('id')->all())
                    ->delete();
            }
        }

        if ($deliveryOrder->isDone()) {
            // DONE → CANCELLED via the canonical reversal: posts compensating
            // inbound stock movements and reverses the inventory/COGS GL entry.
            $this->reverseDeliveryOrder($deliveryOrder, 'Reset to draft');
            $deliveryOrder->refresh();
        }

        $deliveryOrder->update([
            'status' => 'draft',
            'notes' => $deliveryOrder->notes
                ? $deliveryOrder->notes."\n[RESET TO DRAFT]"
                : '[RESET TO DRAFT]',
        ]);

        return $deliveryOrder->fresh(['lines.salesOrderLine', 'customer', 'salesOrder']);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ════════════════════════════════════════════════════════════════════════

    private function fulfilReservationForLine(int $productId, int $warehouseId, int $qty, int $salesOrderId, int $salesOrderLineId): void
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
     * Reuses the invoice approval reviewer pool (Sales Settings → Approval
     * Workflow) so one set of reviewers covers invoices and delivery orders.
     * Falls back to the sales.write permission holder if nothing is configured.
     */
    public function resolveReviewers(?int $submitterId = null): \Illuminate\Support\Collection
    {
        $users = collect();

        $configuredIds = \App\Models\Setting::getValue('sales.invoice.reviewer_user_ids', []);
        $configuredRoles = \App\Models\Setting::getValue('sales.invoice.reviewer_roles', []);

        if (is_array($configuredIds) && $configuredIds !== []) {
            $users = $users->merge(\App\Models\User::whereIn('id', array_map('intval', $configuredIds))->get());
        }

        if (is_array($configuredRoles) && $configuredRoles !== []) {
            $spatieNames = collect($configuredRoles)
                ->map(fn ($role) => \App\Enums\UserRole::tryFrom(strtolower((string) $role))?->spatieName())
                ->filter()
                ->values()
                ->all();

            if ($spatieNames !== []) {
                $users = $users->merge(\App\Models\User::role($spatieNames)->get());
            }
        }

        $users = $users
            ->unique('id')
            ->reject(fn ($u) => $submitterId !== null && (int) $u->id === (int) $submitterId)
            ->values();

        return $users;
    }

    public function approveDeliveryOrder(DeliveryOrder $deliveryOrder, \App\Models\User $user): DeliveryOrder
    {
        if ($deliveryOrder->approval_status === 'approved') {
            return $deliveryOrder;
        }

        $deliveryOrder->update(['approval_status' => 'approved']);
        $this->settleApprovalRequest($deliveryOrder, 'approved', (int) $user->id);

        $this->logChatter($deliveryOrder, 'status_changed', "Approved {$deliveryOrder->delivery_order_number}", [
            'state' => 'pending → approved',
        ]);

        return $deliveryOrder->fresh();
    }

    public function rejectDeliveryOrder(DeliveryOrder $deliveryOrder, \App\Models\User $user, string $reason): DeliveryOrder
    {
        $deliveryOrder->update([
            'approval_status' => 'rejected',
            'status' => 'draft',
        ]);
        $this->settleApprovalRequest($deliveryOrder, 'rejected', (int) $user->id, $reason);

        $this->logChatter($deliveryOrder, 'status_changed', "Rejected {$deliveryOrder->delivery_order_number}", [
            'state' => 'pending → rejected',
            'reason' => $reason,
        ]);

        return $deliveryOrder->fresh();
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
