<?php

namespace Modules\Sales\Services;

use App\Models\JournalEntryLink;
use App\Models\Customer;
use App\Services\InvoiceCreationToken;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesInvoiceLink;
use Modules\Sales\Models\SalesChannelReference;
use Modules\Sales\Models\SalesOrderStatusHistory;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    public function __construct(
        private OrderLifecycleService $lifecycleService,
        private InvoiceService $invoiceService,
        private \Modules\Inventory\Services\InventoryService $inventoryService,
    ) {}

    public function createOrder(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $actorId = TenantUserHelper::requireTenantUserId($data['created_by'] ?? Auth::id());

            $data['created_by'] = $actorId;
            $data['status'] = $data['status'] ?? 'draft';
            $data['accounting_status'] = $data['accounting_status'] ?? 'not_posted';
            $data['order_date'] = $data['order_date'] ?? now()->toDateString();

            $order = SalesOrder::create($data);

            foreach ($lines as $lineData) {
                $line = $order->lines()->create([
                    'product_id' => $lineData['product_id'] ?? null,
                    'variant_id' => $lineData['variant_id'] ?? null,
                    'description' => $lineData['description'] ?? '',
                    'sku' => $lineData['sku'] ?? null,
                    'quantity' => $lineData['quantity'] ?? 1,
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

            if (!empty($data['channel_reference'])) {
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
        if (!$order->isDraft()) {
            throw new \Exception('Only draft orders can be confirmed.');
        }

        $order = $this->lifecycleService->confirm($order, 'Order confirmed');

        $this->freezePricing($order);

        return $order->fresh(['customer', 'lines.product', 'statusHistory']);
    }

    public function allocateOrder(SalesOrder $order): SalesOrder
    {
        if (!$order->isConfirmed()) {
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
        if (!$order->isAllocated()) {
            throw new \Exception('Only allocated orders can be fulfilled.');
        }

        return DB::transaction(function () use ($order) {
            $order->loadMissing('lines.product');

            $stockTrackedLines = $order->lines->filter(function ($line) {
                if (!$line->product_id) {
                    return false;
                }

                return (bool) ($line->product?->track_inventory ?? true);
            });
            $warehouseId = null;

            if ($stockTrackedLines->isNotEmpty()) {
                $warehouseId = $this->requireCommitmentWarehouseId($order, 'fulfil');
            }

            // Deduct stock for each line item
            foreach ($order->lines as $line) {
                if ($line->product_id && (bool) ($line->product?->track_inventory ?? true)) {
                    $this->inventoryService->adjustStock(
                        productId: $line->product_id,
                        warehouseId: $warehouseId,
                        quantity: (int) $line->quantity,
                        type: 'sale',
                        reference: $order->sales_order_number,
                        referenceType: 'SalesOrder',
                        referenceId: $order->id,
                        notes: "Stock deducted for sales order fulfillment"
                    );
                }

                $line->update(['quantity_fulfilled' => $line->quantity]);
            }

            $order = $this->lifecycleService->fulfil($order, 'Order fulfilled');

            return $order->fresh(['customer', 'lines.product', 'statusHistory']);
        });
    }

    private function requireCommitmentWarehouseId(SalesOrder $order, string $phase): int
    {
        if (!$order->warehouse_id) {
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
            if (!$line->product_id) {
                return false;
            }

            return (bool) ($line->product?->track_inventory ?? true);
        });
    }

    public function createInvoice(SalesOrder $order, ?array $totalOverrides = null, ?InvoiceService $invoiceServiceOverride = null): \App\Models\Invoice
    {
        if (!$order->isFulfilled() && !$order->isAllocated()) {
            throw new \Exception('Only fulfilled or allocated orders can be invoiced.');
        }

        return DB::transaction(function () use ($order, $totalOverrides, $invoiceServiceOverride) {
            $invoiceService = $invoiceServiceOverride ?? $this->invoiceService;
            $token = $invoiceService->issueCreationToken();

            $invoiceData = [
                'customer_id' => $order->customer_id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays($order->customer->payment_terms ?? 30)->toDateString(),
                'notes' => "Sales Order #{$order->sales_order_number}",
                'sales_order_id' => $order->id,
            ];

            $lines = [];
            foreach ($order->lines as $line) {
                [$invoiceQuantity, $invoiceUnitPrice, $description] = $this->normalizeInvoiceLine($line);

                $lines[] = [
                    'product_id' => $line->product_id,
                    'description' => $description,
                    'quantity' => $invoiceQuantity,
                    'unit_price' => $invoiceUnitPrice,
                    'tax_rate' => $line->tax_rate,
                    'discount_amount' => $line->discount_amount,
                ];
            }
            $invoiceData['lines'] = $lines;

            $invoice = $invoiceService->createInvoiceInternal($token, $invoiceData);

            SalesInvoiceLink::create([
                'sales_order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'link_type' => 'primary',
                'link_state' => 'active',
                'invoiced_qty' => $order->lines->sum('quantity'),
                'invoiced_value' => $order->total,
                'created_by' => TenantUserHelper::requireTenantUserId($order->created_by),
            ]);

            $invoice->update(['sales_order_id' => $order->id]);

            // Apply channel-level total overrides (e.g. pre-paid POS/web amounts) BEFORE
            // synchronizeInvoiceBookkeeping() so that quantity_invoiced is based on final totals.
            if (!empty($totalOverrides)) {
                $invoice->update($totalOverrides);
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
                $description .= ' - ' . $log->description;
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

            $orderLine->update([
                'quantity_invoiced' => $matchedInvoiceLine ? (float) $orderLine->quantity : 0,
            ]);
        }
    }

    private function normalizeInvoiceLine(SalesOrderLine $line): array
    {
        $quantity = (float) $line->quantity;
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

        if (!$documentType) {
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
}
