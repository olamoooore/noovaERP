<?php

namespace Modules\Sales\Services;

use App\Models\JournalEntryLink;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\Sales\Models\SalesChannelReference;
use Modules\Sales\Models\SalesInvoiceLink;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesOrderStatusHistory;

class SalesOrderService
{
    public function __construct(
        private OrderLifecycleService $lifecycleService,
        private InvoiceService $invoiceService,
        private \Modules\Inventory\Services\InventoryService $inventoryService,
        private \Modules\Inventory\Services\StockReservationService $reservationService,
        private CreditCheckService $creditCheckService,
        private \App\Services\ProductUomConverter $uomConverter,
    ) {}

    /**
     * Issue #137: advisory lock key for SO-number generation. Tenant-scoped so
     * one tenant's order creation never blocks another tenant's on the shared
     * Postgres database (pg_advisory locks ignore search_path).
     */
    public static function salesOrderNumberLockKey(?string $tenantId = null): int
    {
        return crc32('sales_order_create:'.($tenantId ?? tenant('id') ?? ''));
    }

    public function createOrder(array $data): SalesOrder
    {
        $maxRetries = 5;
        $lockKey = self::salesOrderNumberLockKey();

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                // Advisory lock must be acquired INSIDE the transaction.
                // DB::transaction wraps everything; the lock is held until commit/rollback.
                return DB::transaction(function () use ($data, $lockKey) {
                    // Acquire advisory lock — serializes all SO creation across connections
                    DB::select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);

                    return $this->createOrderInsideTransaction($data);
                });
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'sales_orders_sales_order_number_unique')) {
                    if ($attempt < $maxRetries - 1) {
                        cache()->forget('document_numbering_sales_order');
                        Log::warning('SalesOrder SO number collision on insert, retrying', [
                            'attempt' => $attempt + 1,
                        ]);

                        continue;
                    }
                }
                throw $e;
            }
        }

        // Final attempt — should not reach here
        return DB::transaction(function () use ($data, $lockKey) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [$lockKey]);

            return $this->createOrderInsideTransaction($data);
        });
    }

    private function createOrderInsideTransaction(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $actorId = TenantUserHelper::requireTenantUserId($data['created_by'] ?? Auth::id());

            $data['created_by'] = $actorId;
            $data['status'] = $data['status'] ?? 'draft';
            $data['accounting_status'] = $data['accounting_status'] ?? 'not_posted';
            $data['order_date'] = $data['order_date'] ?? now()->toDateString();

            // Issue #140: stamp the legal entity from the customer (explicit
            // caller-supplied value wins) so orders are entity-scopable.
            if (empty($data['legal_entity_id']) && ! empty($data['customer_id'])) {
                $data['legal_entity_id'] = \App\Models\Customer::whereKey($data['customer_id'])
                    ->value('legal_entity_id');
            }

            $order = SalesOrder::create($data);

            foreach ($lines as $lineData) {
                // Issue #135: a line entered in a non-base UOM must be converted
                // to base units before it can reach stock/COGS. The base-unit
                // price is derived from the UOM rate; an explicit unit_price is
                // treated as the per-base-unit price.
                if (! empty($lineData['uom_id']) && ! empty($lineData['product_id'])) {
                    $uom = $this->uomConverter->resolve((int) $lineData['product_id'], (int) $lineData['uom_id']);
                    $lineData['quantity'] = $this->uomConverter->toBase($uom, (float) ($lineData['quantity'] ?? 0));

                    if (! isset($lineData['unit_price']) || (float) $lineData['unit_price'] <= 0) {
                        $product = \App\Models\Product::find($lineData['product_id']);
                        if ($product) {
                            // Compose with #132 tier pricing: resolve the
                            // per-base tier price first, then apply the UOM
                            // multiplier.
                            $tierBasePrice = $product->getPriceForQuantity(
                                (int) ceil((float) $lineData['quantity']),
                                ! empty($data['customer_id']) ? (int) $data['customer_id'] : null,
                                null,
                                ! empty($lineData['variant_id']) ? (int) $lineData['variant_id'] : null,
                            );

                            $lineData['unit_price'] = $this->uomConverter->baseUnitPrice($product, $uom, $tierBasePrice);
                        }
                    }

                    $lineData['unit'] = $uom->abbreviation ?: ($lineData['unit'] ?? 'pcs');
                }

                // Issue #132: when the caller does not supply a price, resolve it
                // through the product's tier logic (volume/customer/variant/date)
                // instead of leaving the line at zero or the flat catalog price.
                if (! isset($lineData['unit_price']) || (float) $lineData['unit_price'] <= 0) {
                    if (! empty($lineData['product_id'])) {
                        $product = $product ?? \App\Models\Product::find($lineData['product_id']);
                        if ($product) {
                            $lineData['unit_price'] = $product->getPriceForQuantity(
                                (int) ceil((float) ($lineData['quantity'] ?? 0)),
                                ! empty($data['customer_id']) ? (int) $data['customer_id'] : null,
                                null,
                                ! empty($lineData['variant_id']) ? (int) $lineData['variant_id'] : null,
                            );
                        }
                    }
                }

                $line = $order->lines()->create([
                    'legal_entity_id' => $order->legal_entity_id,
                    'line_type' => $lineData['line_type'] ?? 'product',
                    'sequence' => $lineData['sequence'] ?? 0,
                    'product_id' => $lineData['product_id'] ?? null,
                    'model_number' => $lineData['model_number'] ?? null,
                    'product_notes' => $lineData['product_notes'] ?? null,
                    'variant_id' => $lineData['variant_id'] ?? null,
                    'description' => $lineData['description'] ?? '',
                    'sku' => $lineData['sku'] ?? null,
                    'quantity' => $lineData['quantity'] ?? (($lineData['line_type'] ?? 'product') === 'product' ? 1 : 0),
                    'unit' => $lineData['unit'] ?? 'pcs',
                    'unit_price' => $lineData['unit_price'] ?? 0,
                    'list_price' => $lineData['list_price'] ?? null,
                    'discount_type' => $lineData['discount_type'] ?? 'percentage',
                    'discount_value' => $lineData['discount_value'] ?? 0,
                    'discount_amount' => $lineData['discount_amount'] ?? 0,
                    'tax_rate' => $lineData['tax_rate'] ?? 0,
                    'tax_amount' => $lineData['tax_amount'] ?? 0,
                    'income_account_id' => $lineData['income_account_id'] ?? null,
                    'source_line_type' => $lineData['source_line_type'] ?? null,
                    'source_line_id' => $lineData['source_line_id'] ?? null,
                ]);
                $line->calculateLineTotal();
                $line->save();
            }

            $order->refresh();
            $order->calculateTotals();

            // ADR-003A: Populate snapshot fields from authoritative customer/contact records
            app(\App\Services\DocumentSnapshotService::class)->populateSalesOrderSnapshot($order);

            if (! empty($data['channel_reference'])) {
                $this->createChannelReference($order, $data['channel_reference']);
            }

            SalesOrderStatusHistory::create([
                'sales_order_id' => $order->id,
                'from_status' => null,
                'to_status' => 'draft',
                'actor_id' => $actorId,
                'source' => $data['channel'] ?? 'admin',
                'reason' => 'Order created',
                'transitioned_at' => now(),
            ]);

            return $order->fresh(['customer', 'lines.product', 'statusHistory']);
        });
    }

    public function confirmOrder(SalesOrder $order): SalesOrder
    {
        if (! $order->isDraft()) {
            throw new \Exception('Only draft orders can be confirmed.');
        }

        // Issue #139: the credit verdict must gate confirmation. Previously the
        // controller computed it and silently ignored the result, so an order
        // over the limit still confirmed, reserved stock and unlocked invoicing.
        if ($order->customer !== null) {
            $creditCheck = $this->creditCheckService->checkCreditLimit(
                $order->customer,
                (float) $order->total
            );

            if (! $creditCheck->approved) {
                throw new \Exception('Credit check failed: '.$creditCheck->reason);
            }
        }

        return DB::transaction(function () use ($order) {
            $order = $this->lifecycleService->confirm($order, 'Order confirmed');

            $this->freezePricing($order);

            // Reserve stock for stock-tracked lines that have a warehouse
            $this->reserveStockForOrder($order);

            return $order->fresh(['customer', 'lines.product', 'statusHistory']);
        });
    }

    public function allocateOrder(SalesOrder $order): SalesOrder
    {
        if (! $order->isConfirmed()) {
            throw new \Exception('Only confirmed orders can be allocated.');
        }

        return DB::transaction(function () use ($order) {
            $order->loadMissing('lines.product');

            if ($this->hasStockTrackedLines($order)) {
                $this->requireCommitmentWarehouseId($order, 'allocate');
            }

            $order = $this->lifecycleService->allocate($order, 'Stock allocated');

            foreach ($order->lines as $line) {
                if ($line->product_id) {
                    $line->update(['quantity_allocated' => $line->quantity]);
                }
            }

            return $order->fresh(['customer', 'lines.product', 'statusHistory']);
        });
    }

    public function fulfilOrder(SalesOrder $order): SalesOrder
    {
        if (! $order->isAllocated()) {
            throw new \Exception('Only allocated orders can be fulfilled.');
        }

        return DB::transaction(function () use ($order) {
            $order->loadMissing('lines.product');

            // Stock deduction is now handled by DeliveryOrder confirmation.
            // This method only marks the order as fulfilled.
            foreach ($order->lines as $line) {
                $line->update(['quantity_fulfilled' => $line->quantity]);
            }

            $order = $this->lifecycleService->fulfil($order, 'Order fulfilled');

            return $order->fresh(['customer', 'lines.product', 'statusHistory']);
        });
    }

    private function requireCommitmentWarehouseId(SalesOrder $order, string $phase): int
    {
        if (! $order->warehouse_id) {
            Log::error('Sales commitment failed: warehouse_id is required for stock-tracked order', [
                'phase' => $phase,
                'sales_order_id' => $order->id,
                'sales_order_number' => $order->sales_order_number,
            ]);

            throw new \RuntimeException(
                "Cannot {$phase} stock-tracked sales order without explicit warehouse_id."
            );
        }

        return (int) $order->warehouse_id;
    }

    private function hasStockTrackedLines(SalesOrder $order): bool
    {
        return $order->lines->contains(function ($line) {
            if (! $line->product_id) {
                return false;
            }

            return (bool) ($line->product?->track_inventory ?? false);
        });
    }

    public function createInvoice(SalesOrder $order, ?array $totalOverrides = null, ?InvoiceService $invoiceServiceOverride = null): \App\Models\Invoice
    {
        if (! $order->isFulfilled() && ! $order->isAllocated() && ! $order->isConfirmed() && ! $order->isInvoiced()) {
            throw new \Exception('Only confirmed, allocated, fulfilled, or partially invoiced orders can be invoiced.');
        }

        return DB::transaction(function () use ($order, $totalOverrides, $invoiceServiceOverride) {
            $order->loadMissing('lines');
            $invoiceService = $invoiceServiceOverride ?? $this->invoiceService;
            $token = $invoiceService->issueCreationToken();

            $invoiceData = [
                'customer_id' => $order->customer_id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays($order->customer->payment_terms ?? 30)->toDateString(),
                'notes' => "Sales Order #{$order->sales_order_number}",
                'sales_order_id' => $order->id,
            ];

            // ── Build invoice lines using per-line invoicing_policy ─────────
            // invoiceable_qty =
            //   ORDERED_QTY:  max(ordered - invoiced, 0)
            //   DELIVERED_QTY: max(delivered - invoiced, 0)
            $lines = [];
            $totalInvoiceableQty = 0;

            foreach ($order->lines as $line) {
                if (in_array($line->line_type ?? 'product', ['note', 'section'], true)) {
                    $lines[] = [
                        'line_type' => $line->line_type,
                        'sequence' => $line->sequence ?? 0,
                        'description' => $line->description,
                        'quantity' => 0,
                        'unit_price' => 0,
                        'tax_rate' => 0,
                        'discount_amount' => 0,
                    ];

                    continue;
                }
                $policy = $line->invoicing_policy ?? 'ordered_qty';
                $ordered = (float) $line->quantity;
                $delivered = (float) $line->quantity_delivered;
                $invoiced = (float) $line->quantity_invoiced;

                $invoiceableQty = $policy === 'delivered_qty'
                    ? max($delivered - $invoiced, 0)
                    : max($ordered - $invoiced, 0);

                if ($invoiceableQty <= 0) {
                    continue;
                }

                $totalInvoiceableQty += $invoiceableQty;

                [$invoiceQuantity, $invoiceUnitPrice, $description] = $this->normalizeInvoiceLineWithQty(
                    $line,
                    $invoiceableQty,
                );

                $lines[] = [
                    'line_type' => 'product',
                    'sequence' => $line->sequence ?? 0,
                    'product_id' => $line->product_id,
                    'model_number' => $line->model_number,
                    'product_notes' => $line->product_notes,
                    'description' => $description,
                    'quantity' => $invoiceQuantity,
                    'unit_price' => $invoiceUnitPrice,
                    'tax_rate' => $line->tax_rate,
                    'discount_amount' => $line->discount_amount,
                ];
            }

            // ── Block if nothing is invoiceable ───────────────────────────
            if (empty($lines)) {
                $hasDeliveredPolicy = $order->lines->contains(fn ($l) => ($l->invoicing_policy ?? 'ordered_qty') === 'delivered_qty');
                throw new \Exception(
                    $hasDeliveredPolicy
                        ? 'There are no invoiceable quantities. Complete a delivery first for products billed by delivered quantity.'
                        : 'There are no invoiceable quantities for this order.'
                );
            }

            $invoiceData['lines'] = $lines;

            // Merge overrides into creation data so the Invoice creating boot hook
            // respects imported values (especially invoice_number) instead of auto-generating.
            if (! empty($totalOverrides)) {
                $invoiceData = array_merge($invoiceData, $totalOverrides);
            }

            $invoice = $invoiceService->createInvoiceInternal($token, $invoiceData);

            SalesInvoiceLink::create([
                'sales_order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'link_type' => 'primary',
                'link_state' => 'active',
                'invoiced_qty' => $totalInvoiceableQty,
                'invoiced_value' => $order->total,
                'created_by' => TenantUserHelper::requireTenantUserId($order->created_by),
            ]);

            if ($invoice->sales_order_id !== $order->id) {
                $invoice->update(['sales_order_id' => $order->id]);
            }

            $this->synchronizeInvoiceBookkeeping($order->fresh('lines'), $invoice->fresh('lines'));

            $this->lifecycleService->markInvoiced($order, 'Invoice created');

            return $invoice;
        });
    }

    public function recordChannelPayment(\App\Models\Invoice $invoice, array $paymentData): \App\Models\Payment
    {
        return $this->invoiceService->recordChannelPayment($invoice, $paymentData);
    }

    public function cancelOrder(SalesOrder $order, string $reason): SalesOrder
    {
        if ($order->isInvoiced()) {
            throw new \Exception('Invoiced orders cannot be cancelled. Use credit notes instead.');
        }

        return DB::transaction(function () use ($order, $reason) {
            // Release all stock reservations for this order
            $this->releaseStockReservations($order);

            $order = $this->lifecycleService->cancel($order, $reason);

            foreach ($order->lines as $line) {
                $line->update(['quantity_cancelled' => $line->quantity - $line->quantity_fulfilled]);
            }

            return $order->fresh(['customer', 'lines.product', 'statusHistory']);
        });
    }

    public function createFromPosOrder(\Modules\POS\Models\PosOrder $posOrder, ?string $idempotencyKey = null): SalesOrder
    {
        $posOrder->loadMissing('terminal');

        $existing = SalesChannelReference::where('channel', 'pos')
            ->where('external_id', (string) $posOrder->id)
            ->first();

        if ($existing) {
            return $existing->salesOrder;
        }

        $lines = [];
        foreach ($posOrder->items as $item) {
            $lines[] = [
                'product_id' => $item->product_id,
                'variant_id' => $item->product_variant_id ?? null,
                'description' => $item->name,
                'sku' => $item->sku ?? null,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'tax_rate' => $item->tax_rate ?? 0,
                'tax_amount' => $item->tax_amount ?? 0,
                'discount_amount' => 0,
                'source_line_type' => 'PosOrderItem',
                'source_line_id' => $item->id,
            ];
        }

        $order = $this->createOrder([
            'customer_id' => $posOrder->customer_id,
            'channel' => 'pos',
            'warehouse_id' => $posOrder->terminal?->warehouse_id,
            'created_by' => $posOrder->created_by,
            'accounting_status' => $this->resolveSourceAccountingStatus('PosOrder', $posOrder->id, $posOrder->journal_entry_id),
            'source_document_type' => 'PosOrder',
            'source_document_id' => $posOrder->id,
            'notes' => "POS Order #{$posOrder->order_number}",
            'channel_reference' => [
                'channel' => 'pos',
                'external_id' => (string) $posOrder->id,
                'external_number' => $posOrder->order_number,
                'raw_document_ids' => ['pos_order_id' => $posOrder->id],
            ],
            'lines' => $lines,
        ]);

        return $order;
    }

    public function createFromWebOrder(\Modules\Ecommerce\Models\WebOrder $webOrder, ?string $idempotencyKey = null): SalesOrder
    {
        $existing = SalesChannelReference::where('channel', 'ecommerce')
            ->where('external_id', (string) $webOrder->id)
            ->first();

        if ($existing) {
            return $existing->salesOrder;
        }

        $lines = [];
        foreach ($webOrder->items as $item) {
            $lines[] = [
                'product_id' => $item->product_id,
                'description' => $item->product_name,
                'sku' => $item->product_sku ?? null,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'tax_rate' => $item->tax_rate ?? 0,
                'tax_amount' => $item->tax_amount ?? 0,
                'discount_amount' => 0,
                'source_line_type' => 'WebOrderItem',
                'source_line_id' => $item->id,
            ];
        }

        $order = $this->createOrder([
            'customer_id' => $webOrder->customer_id,
            'channel' => 'ecommerce',
            'warehouse_id' => $webOrder->fulfillment_warehouse_id ?: $webOrder->resolveInventoryWarehouseId(),
            'accounting_status' => $this->resolveSourceAccountingStatus('WebOrder', $webOrder->id),
            'source_document_type' => 'WebOrder',
            'source_document_id' => $webOrder->id,
            'notes' => "Web Order #{$webOrder->order_number}",
            'channel_reference' => [
                'channel' => 'ecommerce',
                'external_id' => (string) $webOrder->id,
                'external_number' => $webOrder->order_number,
                'raw_document_ids' => ['web_order_id' => $webOrder->id],
            ],
            'lines' => $lines,
        ]);

        return $order;
    }

    public function createFromWorkOrder(\Modules\FieldService\Models\WorkOrder $workOrder): SalesOrder
    {
        $workOrder->loadMissing(['parts.product', 'timeLogs', 'customer']);

        if ($workOrder->sales_order_id) {
            return SalesOrder::findOrFail($workOrder->sales_order_id)
                ->fresh(['customer', 'lines.product', 'statusHistory']);
        }

        $existing = SalesOrder::where('source_document_type', 'WorkOrder')
            ->where('source_document_id', $workOrder->id)
            ->first();

        if ($existing) {
            $workOrder->forceFill(['sales_order_id' => $existing->id])->saveQuietly();

            return $existing->fresh(['customer', 'lines.product', 'statusHistory']);
        }

        $lines = [];

        foreach ($workOrder->timeLogs->where('is_billable', true) as $log) {
            $amount = (float) $log->duration_hours * (float) $log->hourly_rate;
            if ($amount <= 0) {
                continue;
            }

            $description = 'Labor';
            if ($log->description) {
                $description .= ' - '.$log->description;
            }

            $lines[] = [
                'description' => $description,
                'quantity' => (float) $log->duration_hours,
                'unit' => 'hour',
                'unit_price' => (float) $log->hourly_rate,
                'tax_rate' => 0,
                'discount_amount' => 0,
                'source_line_type' => 'WorkOrderTimeLog',
                'source_line_id' => $log->id,
            ];
        }

        foreach ($workOrder->parts as $part) {
            $amount = (float) $part->quantity * (float) $part->unit_price;
            if ($amount <= 0) {
                continue;
            }

            $description = $part->description ?: ($part->product->name ?? 'Part/Material');

            $lines[] = [
                'product_id' => $part->product_id,
                'description' => $description,
                'quantity' => (float) $part->quantity,
                'unit' => 'pcs',
                'unit_price' => (float) $part->unit_price,
                'tax_rate' => 0,
                'discount_amount' => 0,
                'source_line_type' => 'WorkOrderPart',
                'source_line_id' => $part->id,
            ];
        }

        if (empty($lines)) {
            $lines[] = [
                'description' => "Field Service - {$workOrder->title}",
                'quantity' => 1,
                'unit' => 'job',
                'unit_price' => 0.00,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ];
        }

        $order = $this->createOrder([
            'customer_id' => $workOrder->customer_id,
            'channel' => 'admin',
            'created_by' => $workOrder->created_by,
            'source_document_type' => 'WorkOrder',
            'source_document_id' => $workOrder->id,
            'notes' => "Generated from Work Order {$workOrder->reference}: {$workOrder->title}",
            'lines' => $lines,
        ]);

        $workOrder->forceFill(['sales_order_id' => $order->id])->saveQuietly();

        return $order;
    }

    private function createChannelReference(SalesOrder $order, array $ref): SalesChannelReference
    {
        return SalesChannelReference::create([
            'sales_order_id' => $order->id,
            'channel' => $ref['channel'],
            'external_id' => $ref['external_id'],
            'external_number' => $ref['external_number'] ?? null,
            'raw_document_ids' => $ref['raw_document_ids'] ?? null,
        ]);
    }

    private function freezePricing(SalesOrder $order): void
    {
        foreach ($order->fresh('lines')->lines as $line) {
            $line->update([
                'list_price' => $line->unit_price,
            ]);
        }
    }

    private function synchronizeInvoiceBookkeeping(SalesOrder $order, \App\Models\Invoice $invoice): void
    {
        $invoiceLines = $invoice->lines;
        $billByDelivered = (bool) $order->bill_by_delivered_qty;

        foreach ($order->lines as $orderLine) {
            $matchedInvoiceLine = $invoiceLines->first(function ($invoiceLine) use ($orderLine) {
                if ($orderLine->source_line_type === 'PosOrderItem' || $orderLine->source_line_type === 'WebOrderItem') {
                    return (int) $invoiceLine->product_id === (int) $orderLine->product_id
                        && str_starts_with($invoiceLine->description, $orderLine->description);
                }

                if ($orderLine->product_id) {
                    return (int) $invoiceLine->product_id === (int) $orderLine->product_id
                        && str_starts_with($invoiceLine->description, $orderLine->description);
                }

                return str_starts_with($invoiceLine->description, $orderLine->description);
            });

            $invoicedQty = $matchedInvoiceLine
                ? ($billByDelivered ? (float) $orderLine->quantity_delivered : (float) $orderLine->quantity)
                : 0;

            $orderLine->update([
                'quantity_invoiced' => $invoicedQty,
            ]);
        }
    }

    private function normalizeInvoiceLine(SalesOrderLine $line, bool $billByDelivered = false): array
    {
        $quantity = $billByDelivered
            ? (float) $line->quantity_delivered
            : (float) $line->quantity;

        return $this->normalizeInvoiceLineWithQty($line, $quantity);
    }

    /**
     * Normalize a single invoice line given the exact quantity to bill.
     */
    private function normalizeInvoiceLineWithQty(SalesOrderLine $line, float $quantity): array
    {
        if ($quantity <= 0) {
            return [0, 0, $line->description];
        }

        $roundedQuantity = round($quantity);

        if (abs($quantity - $roundedQuantity) < 0.0001 && $roundedQuantity >= 1) {
            return [(int) $roundedQuantity, (float) $line->unit_price, $line->description];
        }

        $quantityLabel = rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');
        $unitPriceLabel = number_format((float) $line->unit_price, 2, '.', '');
        $description = "{$line->description} ({$quantityLabel} {$line->unit} @ {$unitPriceLabel})";

        return [1, round($quantity * (float) $line->unit_price, 2), $description];
    }

    private function resolveSourceAccountingStatus(string $sourceDocumentType, int $sourceDocumentId, ?int $journalEntryId = null): string
    {
        if ($journalEntryId) {
            return 'fully_posted';
        }

        $documentType = match ($sourceDocumentType) {
            'PosOrder' => 'pos_order',
            'WebOrder' => 'web_order',
            default => null,
        };

        if (! $documentType) {
            return 'not_posted';
        }

        $hasReversal = JournalEntryLink::where('source_document_type', $documentType)
            ->where('source_document_id', $sourceDocumentId)
            ->where('relation_type', 'reversal')
            ->exists();

        if ($hasReversal) {
            return 'reversed';
        }

        $hasPosting = JournalEntryLink::where('source_document_type', $documentType)
            ->where('source_document_id', $sourceDocumentId)
            ->where('relation_type', 'post')
            ->exists();

        return $hasPosting ? 'fully_posted' : 'not_posted';
    }

    /**
     * Reserve stock for all stock-tracked lines on a confirmed sales order.
     * Skips products that don't track inventory or lines without a product.
     *
     * Insufficient stock does NOT abort order creation — the line is simply left
     * unreserved (a backorder) and a warning is logged. This keeps imports and
     * manual order entry working when some products are out of stock.
     */
    private function reserveStockForOrder(SalesOrder $order): void
    {
        $order->loadMissing('lines.product');

        if (! $order->warehouse_id) {
            return;
        }

        $warehouseId = (int) $order->warehouse_id;

        foreach ($order->lines as $line) {
            if (! $line->product_id) {
                continue;
            }

            $product = $line->product;
            if (! $product || ! (bool) ($product->track_inventory ?? false)) {
                continue;
            }

            try {
                $this->reservationService->reserve(
                    productId: $line->product_id,
                    warehouseId: $warehouseId,
                    quantity: (int) round((float) $line->quantity),
                    sourceDocumentType: 'SalesOrder',
                    sourceDocumentId: $order->id,
                    sourceDocumentLineId: $line->id,
                    notes: "Reserved for sales order {$order->sales_order_number}",
                );
            } catch (\Throwable $e) {
                // Out-of-stock (or other reservation error): fall back to a backorder
                // instead of aborting the whole order / import.
                Log::warning('Stock reservation skipped — order proceeds as backorder', [
                    'sales_order_id' => $order->id,
                    'sales_order_number' => $order->sales_order_number,
                    'product_id' => $line->product_id,
                    'quantity' => $line->quantity,
                    'warehouse_id' => $warehouseId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Release all active stock reservations for a cancelled sales order.
     */
    private function releaseStockReservations(SalesOrder $order): void
    {
        $reservations = \Modules\Inventory\Models\StockReservation::where([
            'source_document_type' => 'SalesOrder',
            'source_document_id' => $order->id,
        ])->whereIn('status', ['open', 'allocated'])->get();

        foreach ($reservations as $reservation) {
            try {
                $this->reservationService->release($reservation, "Order {$order->sales_order_number} cancelled");
            } catch (\Throwable $e) {
                Log::warning('Failed to release stock reservation on cancel', [
                    'reservation_id' => $reservation->id,
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
