<?php

namespace Modules\Sales\Services;

use App\Models\Customer;
use App\Services\InvoiceService;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesInvoiceLink;
use Modules\Sales\Models\SalesChannelReference;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    public function __construct(
        private OrderLifecycleService $lifecycleService,
    ) {}

    public function createOrder(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['created_by'] = auth()->id();
            $data['status'] = $data['status'] ?? 'draft';
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
                'actor_id' => auth()->id(),
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
            $order = $this->lifecycleService->fulfil($order, 'Order fulfilled');

            foreach ($order->lines as $line) {
                if ($line->product_id) {
                    $line->update(['quantity_fulfilled' => $line->quantity]);
                }
            }

            return $order->fresh(['customer', 'lines.product', 'statusHistory']);
        });
    }

    public function createInvoice(SalesOrder $order, ?InvoiceService $invoiceService = null): \App\Models\Invoice
    {
        if (!$order->isFulfilled() && !$order->isAllocated()) {
            throw new \Exception('Only fulfilled or allocated orders can be invoiced.');
        }

        return DB::transaction(function () use ($order, $invoiceService) {
            $invoiceService = $invoiceService ?? app(InvoiceService::class);

            $invoiceData = [
                'customer_id' => $order->customer_id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays($order->customer->payment_terms ?? 30)->toDateString(),
                'notes' => "Sales Order #{$order->sales_order_number}",
                'sales_order_id' => $order->id,
            ];

            $lines = [];
            foreach ($order->lines as $line) {
                $lines[] = [
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'quantity' => (int) $line->quantity,
                    'unit_price' => $line->unit_price,
                    'tax_rate' => $line->tax_rate,
                    'discount_amount' => $line->discount_amount,
                ];
            }
            $invoiceData['lines'] = $lines;

            $invoice = $invoiceService->createInvoice($invoiceData);

            SalesInvoiceLink::create([
                'sales_order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'link_type' => 'primary',
                'link_state' => 'active',
                'invoiced_qty' => $order->lines->sum('quantity'),
                'invoiced_value' => $order->total,
                'created_by' => auth()->id(),
            ]);

            $invoice->update(['sales_order_id' => $order->id]);

            $this->lifecycleService->markInvoiced($order, 'Invoice created');

            return $invoice;
        });
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
}