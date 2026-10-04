<?php

namespace App\Services;

use App\Models\BatchConsumption;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\ProductBatch;
use App\Models\ProductLot;
use App\Models\ProductSerial;
use App\Models\StockMovement;
use App\Platform\DataSecurity\FieldMaskingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Models\Supplier;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;

/**
 * Issue #583 [SALES-ODOO]: lot/serial traceability report.
 *
 * Given a lot (product_batches.batch_number / lot_number) or a serial
 * (product_serials.serial_number), walk every stored link and return the
 * ordered timeline: supplier receipt → stock moves → delivery order →
 * hospital/customer → medical case → invoice.
 *
 * The report only surfaces links the data actually carries:
 *   - upstream:   product_batches.supplier_id / received_date, plus stock
 *                 movements that carry the lot (batch_number) and, when the
 *                 movement names a GoodsReceipt, the GRN → PO → supplier.
 *   - stock:      stock_movements.batch_number and the #131
 *                 batch_consumptions ledger.
 *   - downstream: delivery_order_lines / invoice_lines carrying
 *                 product_batch_id, lot/ batch number, product_serial_id or
 *                 serial_number, plus the batch_consumptions reference
 *                 (DeliveryOrder / Invoice), the invoice↔DO pivot, and
 *                 medical_case_items linked by delivery_order_item_id,
 *                 invoice_item_id or the legacy lot_id.
 *
 * There is no GRN→batch link today (GoodsReceiptService never stamps the lot
 * on the stock movement), and medical-case lines only carry a lot when one of
 * those FK columns is populated (MC3/UX4). Those gaps are reported in the
 * response's `gaps` array instead of being papered over.
 *
 * PHI: patient names are masked server-side unless the caller holds
 * medical_cases.phi.view (#567). The caller decides via $canViewPhi; this
 * service fails closed when not explicitly told otherwise.
 */
class LotSerialTraceabilityService
{
    public const SEGMENT_RECEIPT = 'supplier_receipt';

    public const SEGMENT_STOCK = 'stock_move';

    public const SEGMENT_DELIVERY = 'delivery';

    public const SEGMENT_CASE = 'medical_case';

    public const SEGMENT_INVOICE = 'invoice';

    private const SEGMENT_ORDER = [
        self::SEGMENT_RECEIPT => 10,
        self::SEGMENT_STOCK => 20,
        self::SEGMENT_DELIVERY => 30,
        self::SEGMENT_CASE => 40,
        self::SEGMENT_INVOICE => 50,
    ];

    private const INCOMING_MOVEMENT_TYPES = ['in', 'purchase', 'return', 'transfer_in'];

    /**
     * Trace a lot ('lot') or a serial ('serial'). Unknown types fail closed.
     *
     * @return array<string, mixed>
     */
    public function trace(string $type, string $value, bool $canViewPhi = false): array
    {
        return match ($type) {
            'lot' => $this->traceLot($value, $canViewPhi),
            'serial' => $this->traceSerial($value, $canViewPhi),
            default => throw new \InvalidArgumentException("Unsupported traceability type '{$type}'."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function traceLot(string $lotNumber, bool $canViewPhi = false): array
    {
        $value = trim($lotNumber);

        $batches = ProductBatch::query()
            ->where(function ($query) use ($value) {
                $query->where('batch_number', $value)->orWhere('lot_number', $value);
            })
            ->with(['product:id,name,sku', 'warehouse:id,name,code'])
            ->orderBy('id')
            ->get();

        return $this->assemble(
            type: 'lot',
            value: $value,
            batches: $batches,
            serials: collect(),
            serialNumbers: [],
            canViewPhi: $canViewPhi,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function traceSerial(string $serialNumber, bool $canViewPhi = false): array
    {
        $value = trim($serialNumber);

        $serials = ProductSerial::query()
            ->where('serial_number', $value)
            ->with(['product:id,name,sku', 'warehouse:id,name,code'])
            ->orderBy('id')
            ->get();

        $batchIds = $serials->pluck('batch_id')->filter()->unique()->values()->all();

        $batches = $batchIds === []
            ? collect()
            : ProductBatch::query()
                ->whereIn('id', $batchIds)
                ->with(['product:id,name,sku', 'warehouse:id,name,code'])
                ->orderBy('id')
                ->get();

        return $this->assemble(
            type: 'serial',
            value: $value,
            batches: $batches,
            serials: $serials,
            serialNumbers: [$value],
            canViewPhi: $canViewPhi,
        );
    }

    /**
     * @param  Collection<int, ProductBatch>  $batches
     * @param  Collection<int, ProductSerial>  $serials
     * @param  string[]  $serialNumbers
     * @return array<string, mixed>
     */
    private function assemble(
        string $type,
        string $value,
        Collection $batches,
        Collection $serials,
        array $serialNumbers,
        bool $canViewPhi,
    ): array {
        $batchIds = $batches->pluck('id')->map(fn ($id) => (int) $id)->all();
        $serialIds = $serials->pluck('id')->map(fn ($id) => (int) $id)->all();
        $lotNumbers = $batches
            ->flatMap(fn (ProductBatch $batch) => array_filter([$batch->batch_number, $batch->lot_number]))
            ->unique()
            ->values()
            ->all();

        if ($lotNumbers === [] && $type === 'lot') {
            $lotNumbers = [$value];
        }

        $consumptions = $batchIds === []
            ? collect()
            : BatchConsumption::query()->whereIn('batch_id', $batchIds)->orderBy('id')->get();

        $movements = $this->resolveMovements($lotNumbers, $consumptions);
        $deliveryLines = $this->resolveDeliveryOrderLines($batchIds, $lotNumbers, $serialIds, $serialNumbers);
        $caseItems = $this->resolveMedicalCaseItems(
            deliveryLineIds: $deliveryLines->pluck('id')->all(),
            invoiceLineIds: [],
            lotIds: $this->resolveLegacyLotIds($lotNumbers),
        );

        $invoiceLines = $this->resolveInvoiceLines($batchIds, $lotNumbers, $serialIds, $serialNumbers);
        $deliveryOrders = $this->resolveDeliveryOrders($deliveryLines, $consumptions);
        $invoiceIds = $this->resolveInvoiceIds($invoiceLines, $consumptions, $deliveryOrders, $caseItems);
        $invoices = $invoiceIds === []
            ? collect()
            : Invoice::query()
                ->whereIn('id', $invoiceIds)
                ->with('customer:id,name')
                ->orderBy('id')
                ->get();

        // Cases can be reached directly from the DO line, from the invoice
        // line, from the legacy lot link, or via the invoice itself.
        $caseItems = $this->resolveMedicalCaseItems(
            deliveryLineIds: $deliveryLines->pluck('id')->all(),
            invoiceLineIds: $invoiceLines->pluck('id')->all(),
            lotIds: $this->resolveLegacyLotIds($lotNumbers),
        );

        $cases = $this->resolveMedicalCases($caseItems, $invoices);

        $events = [];

        foreach ($batches as $batch) {
            $this->pushEvent($events, $this->batchEvent($batch));
        }

        foreach ($serials as $serial) {
            $this->pushEvent($events, $this->serialRecordedEvent($serial));
        }

        foreach ($movements as $movement) {
            $this->pushEvent($events, $this->movementEvent($movement, $consumptions));
        }

        foreach ($serials as $serial) {
            if ($serial->status === ProductSerial::STATUS_SOLD || $serial->sold_at !== null) {
                $this->pushEvent($events, $this->serialSoldEvent($serial));
            }
        }

        foreach ($deliveryOrders as $deliveryOrder) {
            $this->pushEvent($events, $this->deliveryOrderEvent($deliveryOrder, $deliveryLines));
        }

        foreach ($cases as $case) {
            $this->pushEvent($events, $this->medicalCaseEvent($case));
        }

        foreach ($invoices as $invoice) {
            $this->pushEvent($events, $this->invoiceEvent($invoice, $invoiceLines));
        }

        $timeline = array_values($events);
        usort($timeline, function (array $a, array $b) {
            return [$a['_sort_at'], $a['_sort_segment'], $a['_sort_reference']]
                <=> [$b['_sort_at'], $b['_sort_segment'], $b['_sort_reference']];
        });

        $timeline = array_map(function (array $event) {
            unset($event['_sort_at'], $event['_sort_segment'], $event['_sort_reference']);

            return $event;
        }, $timeline);

        if (! $canViewPhi) {
            $timeline = $this->maskPhi($timeline);
        }

        return [
            'query' => [
                'type' => $type,
                'value' => $value,
            ],
            'phi_masked' => ! $canViewPhi,
            'timeline' => $timeline,
            'gaps' => $this->gaps($type),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Resolution
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, BatchConsumption>  $consumptions
     * @return Collection<int, StockMovement>
     */
    private function resolveMovements(array $lotNumbers, Collection $consumptions): Collection
    {
        $movementIds = $consumptions->pluck('stock_movement_id')->filter()->unique()->values()->all();

        if ($lotNumbers === [] && $movementIds === []) {
            return collect();
        }

        return StockMovement::query()
            ->where(function ($query) use ($lotNumbers, $movementIds) {
                if ($lotNumbers !== []) {
                    $query->whereIn('batch_number', $lotNumbers);
                }
                if ($movementIds !== []) {
                    $query->orWhereIn('id', $movementIds);
                }
            })
            ->with(['product:id,name,sku', 'warehouse:id,name,code'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, DeliveryOrderLine>
     */
    private function resolveDeliveryOrderLines(array $batchIds, array $lotNumbers, array $serialIds, array $serialNumbers): Collection
    {
        $hasCriteria = $batchIds !== [] || $lotNumbers !== [] || $serialIds !== [] || $serialNumbers !== [];
        $hasSerialNumbersColumn = Schema::hasColumn('delivery_order_lines', 'serial_numbers');

        if (! $hasCriteria) {
            return collect();
        }

        return DeliveryOrderLine::query()
            ->where(function ($query) use ($batchIds, $lotNumbers, $serialIds, $serialNumbers, $hasSerialNumbersColumn) {
                if ($batchIds !== []) {
                    $query->orWhereIn('product_batch_id', $batchIds);
                }
                if ($lotNumbers !== []) {
                    $query->orWhereIn('batch_number', $lotNumbers)
                        ->orWhereIn('lot_number', $lotNumbers);
                }
                if ($serialIds !== []) {
                    $query->orWhereIn('product_serial_id', $serialIds);
                }
                if ($serialNumbers !== []) {
                    $query->orWhereIn('serial_number', $serialNumbers);
                    if ($hasSerialNumbersColumn) {
                        foreach ($serialNumbers as $serialNumber) {
                            $query->orWhereJsonContains('serial_numbers', $serialNumber);
                        }
                    }
                }
            })
            ->with(['product:id,name,sku', 'deliveryOrder.customer:id,name'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, InvoiceLine>
     */
    private function resolveInvoiceLines(array $batchIds, array $lotNumbers, array $serialIds, array $serialNumbers): Collection
    {
        $hasCriteria = $batchIds !== [] || $lotNumbers !== [] || $serialIds !== [] || $serialNumbers !== [];

        if (! $hasCriteria) {
            return collect();
        }

        return InvoiceLine::query()
            ->where(function ($query) use ($batchIds, $lotNumbers, $serialIds, $serialNumbers) {
                if ($batchIds !== []) {
                    $query->orWhereIn('product_batch_id', $batchIds);
                }
                if ($lotNumbers !== []) {
                    $query->orWhereIn('lot_number', $lotNumbers);
                }
                if ($serialIds !== []) {
                    $query->orWhereIn('product_serial_id', $serialIds);
                }
                if ($serialNumbers !== []) {
                    $query->orWhereIn('serial_number', $serialNumbers);
                }
            })
            ->with(['product:id,name,sku', 'invoice.customer:id,name'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Invoice ids reachable from the lot/serial: matching invoice lines, the
     * #131 consumption ledger, the invoice↔delivery-order pivot, the legacy
     * delivery_orders.invoice_id pointer, and invoices already linked to a
     * traced medical case.
     *
     * @param  Collection<int, InvoiceLine>  $invoiceLines
     * @param  Collection<int, BatchConsumption>  $consumptions
     * @param  Collection<int, DeliveryOrder>  $deliveryOrders
     * @param  Collection<int, MedicalCaseItem>  $caseItems
     * @return int[]
     */
    private function resolveInvoiceIds(
        Collection $invoiceLines,
        Collection $consumptions,
        Collection $deliveryOrders,
        Collection $caseItems,
    ): array {
        $ids = $invoiceLines->pluck('invoice_id')->filter()->all();

        foreach ($consumptions as $consumption) {
            if ($this->referenceIs($consumption->reference_type, 'Invoice') && $consumption->reference_id) {
                $ids[] = (int) $consumption->reference_id;
            }
        }

        $ids = array_merge($ids, $deliveryOrders->pluck('invoice_id')->filter()->all());

        $deliveryOrderIds = $deliveryOrders->pluck('id')->all();
        if ($deliveryOrderIds !== [] && Schema::hasTable('invoice_delivery_orders')) {
            $ids = array_merge($ids, \Illuminate\Support\Facades\DB::table('invoice_delivery_orders')
                ->whereIn('delivery_order_id', $deliveryOrderIds)
                ->pluck('invoice_id')
                ->all());
        }

        $caseIds = $caseItems->pluck('medical_case_id')->filter()->unique()->all();
        if ($caseIds !== []) {
            $ids = array_merge($ids, MedicalCase::query()
                ->whereIn('id', $caseIds)
                ->whereNotNull('invoice_id')
                ->pluck('invoice_id')
                ->all());
        }

        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }

    /**
     * @param  Collection<int, DeliveryOrderLine>  $deliveryLines
     * @param  Collection<int, BatchConsumption>  $consumptions
     * @return Collection<int, DeliveryOrder>
     */
    private function resolveDeliveryOrders(Collection $deliveryLines, Collection $consumptions): Collection
    {
        $ids = $deliveryLines->pluck('delivery_order_id')->filter()->all();

        foreach ($consumptions as $consumption) {
            if ($this->referenceIs($consumption->reference_type, 'DeliveryOrder') && $consumption->reference_id) {
                $ids[] = (int) $consumption->reference_id;
            }
        }

        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));

        if ($ids === []) {
            return collect();
        }

        return DeliveryOrder::query()
            ->whereIn('id', $ids)
            ->with(['customer:id,name', 'salesOrder:id,legal_entity_id', 'lines.product:id,name,sku'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return int[]
     */
    private function resolveLegacyLotIds(array $lotNumbers): array
    {
        if ($lotNumbers === [] || ! Schema::hasTable('product_lots')) {
            return [];
        }

        return ProductLot::query()->whereIn('lot_no', $lotNumbers)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  int[]  $deliveryLineIds
     * @param  int[]  $invoiceLineIds
     * @param  int[]  $lotIds
     * @return Collection<int, MedicalCaseItem>
     */
    private function resolveMedicalCaseItems(array $deliveryLineIds, array $invoiceLineIds, array $lotIds): Collection
    {
        if ($deliveryLineIds === [] && $invoiceLineIds === [] && $lotIds === []) {
            return collect();
        }

        return MedicalCaseItem::query()
            ->where(function ($query) use ($deliveryLineIds, $invoiceLineIds, $lotIds) {
                if ($deliveryLineIds !== []) {
                    $query->orWhereIn('delivery_order_item_id', $deliveryLineIds);
                }
                if ($invoiceLineIds !== []) {
                    $query->orWhereIn('invoice_item_id', $invoiceLineIds);
                }
                if ($lotIds !== []) {
                    $query->orWhereIn('lot_id', $lotIds);
                }
            })
            ->with('product:id,name,sku')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, MedicalCaseItem>  $caseItems
     * @param  Collection<int, Invoice>  $invoices
     * @return Collection<int, MedicalCase>
     */
    private function resolveMedicalCases(Collection $caseItems, Collection $invoices): Collection
    {
        $ids = $caseItems->pluck('medical_case_id')->filter()->all();

        $invoiceIds = $invoices->pluck('id')->all();
        if ($invoiceIds !== []) {
            $ids = array_merge($ids, MedicalCase::query()
                ->whereIn('invoice_id', $invoiceIds)
                ->pluck('id')
                ->all());
        }

        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));

        if ($ids === []) {
            return collect();
        }

        return MedicalCase::query()
            ->whereIn('id', $ids)
            ->with(['customer:id,name', 'items.product:id,name,sku'])
            ->orderBy('id')
            ->get();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Events
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function batchEvent(ProductBatch $batch): array
    {
        $supplierName = $batch->supplier_id
            ? Supplier::query()->whereKey($batch->supplier_id)->value('name')
            : null;

        return $this->event(
            segment: self::SEGMENT_RECEIPT,
            event: 'lot_recorded',
            occurredAt: $batch->received_date ?: $batch->created_at,
            referenceType: 'ProductBatch',
            referenceId: (int) $batch->id,
            reference: $batch->batch_number ?: $batch->lot_number,
            title: "Lot {$batch->batch_number} recorded from supplier",
            details: [
                'product_id' => $batch->product_id,
                'product_name' => $batch->product?->name,
                'sku' => $batch->product?->sku,
                'warehouse_id' => $batch->warehouse_id,
                'warehouse' => $batch->warehouse?->name,
                'batch_number' => $batch->batch_number,
                'lot_number' => $batch->lot_number,
                'supplier_id' => $batch->supplier_id,
                'supplier_name' => $supplierName,
                'supplier_batch_number' => $batch->supplier_batch_number,
                'manufacturing_date' => $batch->manufacturing_date?->toDateString(),
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'received_date' => $batch->received_date?->toDateString(),
                'quantity' => (int) $batch->quantity,
                'quantity_reserved' => (int) $batch->quantity_reserved,
                'status' => $batch->status,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serialRecordedEvent(ProductSerial $serial): array
    {
        $supplierName = $serial->supplier_id
            ? Supplier::query()->whereKey($serial->supplier_id)->value('name')
            : null;

        return $this->event(
            segment: self::SEGMENT_RECEIPT,
            event: 'serial_recorded',
            occurredAt: $serial->created_at,
            referenceType: 'ProductSerial',
            referenceId: (int) $serial->id,
            reference: $serial->serial_number,
            title: "Serial {$serial->serial_number} recorded",
            details: [
                'product_id' => $serial->product_id,
                'product_name' => $serial->product?->name,
                'sku' => $serial->product?->sku,
                'warehouse_id' => $serial->warehouse_id,
                'warehouse' => $serial->warehouse?->name,
                'serial_number' => $serial->serial_number,
                'batch_id' => $serial->batch_id,
                'supplier_id' => $serial->supplier_id,
                'supplier_name' => $supplierName,
                'manufacturing_date' => $serial->manufacturing_date?->toDateString(),
                'status' => $serial->status,
            ],
        );
    }

    /**
     * @param  Collection<int, BatchConsumption>  $consumptions
     * @return array<string, mixed>
     */
    private function movementEvent(StockMovement $movement, Collection $consumptions): array
    {
        $isIncoming = in_array($movement->type, self::INCOMING_MOVEMENT_TYPES, true);
        $linkedConsumptions = $consumptions
            ->where('stock_movement_id', $movement->id)
            ->map(fn (BatchConsumption $consumption) => [
                'batch_id' => $consumption->batch_id,
                'quantity' => (int) $consumption->quantity,
                'unit_cost' => (float) $consumption->unit_cost,
                'reference_type' => $consumption->reference_type,
                'reference_id' => $consumption->reference_id,
            ])
            ->values()
            ->all();

        $details = [
            'product_id' => $movement->product_id,
            'product_name' => $movement->product?->name,
            'sku' => $movement->product?->sku,
            'warehouse_id' => $movement->warehouse_id,
            'warehouse' => $movement->warehouse?->name,
            'type' => $movement->type,
            'direction' => $isIncoming ? 'in' : 'out',
            'quantity' => (float) $movement->quantity,
            'old_quantity' => (float) $movement->old_quantity,
            'balance_after' => (float) $movement->balance_after,
            'batch_number' => $movement->batch_number,
            'expiry_date' => $movement->expiry_date?->toDateString(),
            'reference' => $movement->reference,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'notes' => $movement->notes,
            'batch_consumptions' => $linkedConsumptions,
        ];

        // A movement that names a goods receipt resolves upstream to its
        // purchase order and supplier (where those links exist).
        if ($movement->reference_id && $this->referenceIs($movement->reference_type, 'GoodsReceipt')) {
            $grn = GoodsReceipt::query()
                ->with('purchaseOrder:id,po_number,supplier_id')
                ->find($movement->reference_id);

            if ($grn) {
                $supplierId = $grn->purchaseOrder?->supplier_id;
                $details['goods_receipt'] = [
                    'grn_number' => $grn->grn_number,
                    'received_date' => $grn->received_date?->toDateString(),
                    'purchase_order_id' => $grn->purchase_order_id,
                    'po_number' => $grn->purchaseOrder?->po_number,
                    'supplier_id' => $supplierId,
                    'supplier_name' => $supplierId
                        ? Supplier::query()->whereKey($supplierId)->value('name')
                        : null,
                ];
            }
        }

        return $this->event(
            segment: $isIncoming ? self::SEGMENT_RECEIPT : self::SEGMENT_STOCK,
            event: $isIncoming ? 'stock_received' : 'stock_issued',
            occurredAt: $movement->created_at,
            referenceType: 'StockMovement',
            referenceId: (int) $movement->id,
            reference: $movement->reference ?: 'MOV-'.$movement->id,
            title: $isIncoming
                ? "Stock received ({$movement->quantity})"
                : "Stock issued ({$movement->quantity})",
            details: $details,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serialSoldEvent(ProductSerial $serial): array
    {
        $customerName = $serial->customer_id
            ? \App\Models\Customer::query()->whereKey($serial->customer_id)->value('name')
            : null;

        return $this->event(
            segment: self::SEGMENT_DELIVERY,
            event: 'serial_sold',
            occurredAt: $serial->sold_at ?: $serial->updated_at,
            referenceType: 'ProductSerial',
            referenceId: (int) $serial->id,
            reference: $serial->sale_reference ?: $serial->serial_number,
            title: "Serial {$serial->serial_number} sold",
            details: [
                'serial_number' => $serial->serial_number,
                'product_id' => $serial->product_id,
                'product_name' => $serial->product?->name,
                'customer_id' => $serial->customer_id,
                'customer_name' => $customerName,
                'sold_at' => $serial->sold_at?->toDateTimeString(),
                'sale_reference' => $serial->sale_reference,
                'warranty_start_date' => $serial->warranty_start_date?->toDateString(),
                'warranty_end_date' => $serial->warranty_end_date?->toDateString(),
                'status' => $serial->status,
            ],
        );
    }

    /**
     * @param  Collection<int, DeliveryOrderLine>  $deliveryLines
     * @return array<string, mixed>
     */
    private function deliveryOrderEvent(DeliveryOrder $deliveryOrder, Collection $deliveryLines): array
    {
        $mapLine = fn (DeliveryOrderLine $line) => [
            'line_id' => (int) $line->id,
            'product_id' => $line->product_id,
            'product_name' => $line->product?->name,
            'quantity_ordered' => (float) $line->quantity_ordered,
            'quantity_delivered' => (float) $line->quantity_delivered,
            'batch_number' => $line->batch_number,
            'lot_number' => $line->lot_number,
            'serial_number' => $line->serial_number,
            'serial_numbers' => $line->serial_numbers,
            'patient_name' => $line->patient_name,
        ];

        $lines = $deliveryLines
            ->where('delivery_order_id', $deliveryOrder->id)
            ->map($mapLine)
            ->values();

        // A DO reached through the #131 consumption ledger may not have a line
        // among the matched lines — fall back to its own lines for context.
        if ($lines->isEmpty()) {
            $lines = $deliveryOrder->lines->map($mapLine)->values();
        }

        $lines = $lines->all();

        return $this->event(
            segment: self::SEGMENT_DELIVERY,
            event: 'delivery_order',
            occurredAt: $deliveryOrder->completed_at ?: $deliveryOrder->delivered_at ?: $deliveryOrder->created_at,
            referenceType: 'DeliveryOrder',
            referenceId: (int) $deliveryOrder->id,
            reference: $deliveryOrder->delivery_order_number,
            title: "Delivered to {$deliveryOrder->customer?->name}",
            legalEntityId: $deliveryOrder->salesOrder?->legal_entity_id,
            details: [
                'customer_id' => $deliveryOrder->customer_id,
                'customer_name' => $deliveryOrder->customer?->name,
                'status' => $deliveryOrder->status,
                'warehouse_id' => $deliveryOrder->warehouse_id,
                'sales_order_id' => $deliveryOrder->sales_order_id,
                'legal_entity_id' => $deliveryOrder->salesOrder?->legal_entity_id,
                'lines' => $lines,
                'patient_names' => array_values(array_filter(array_map(
                    fn (array $line) => $line['patient_name'],
                    $lines
                ))),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function medicalCaseEvent(MedicalCase $case): array
    {
        return $this->event(
            segment: self::SEGMENT_CASE,
            event: 'medical_case',
            occurredAt: $case->case_date ?: $case->created_at,
            referenceType: 'MedicalCase',
            referenceId: (int) $case->id,
            reference: $case->case_no,
            title: "Medical case {$case->case_no}",
            details: [
                'case_no' => $case->case_no,
                'status' => $case->status,
                'customer_id' => $case->customer_id,
                'customer_name' => $case->customer?->name,
                'patient_name' => $case->patient_name,
                'patient_reference' => $case->patient_reference,
                'invoice_id' => $case->invoice_id,
                'procedure_date' => $case->procedure_date?->toDateString(),
                'location' => $case->location,
                'items' => $case->items->map(fn (MedicalCaseItem $item) => [
                    'item_id' => (int) $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name,
                    'allocated_qty' => (float) $item->allocated_qty,
                    'delivered_qty' => (float) $item->delivered_qty,
                    'invoiced_qty' => (float) $item->invoiced_qty,
                    'delivery_order_item_id' => $item->delivery_order_item_id,
                    'invoice_item_id' => $item->invoice_item_id,
                ])->values()->all(),
            ],
        );
    }

    /**
     * @param  Collection<int, InvoiceLine>  $invoiceLines
     * @return array<string, mixed>
     */
    private function invoiceEvent(Invoice $invoice, Collection $invoiceLines): array
    {
        $lines = $invoiceLines
            ->where('invoice_id', $invoice->id)
            ->map(fn (InvoiceLine $line) => [
                'line_id' => (int) $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product?->name,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'lot_number' => $line->lot_number,
                'serial_number' => $line->serial_number,
            ])
            ->values()
            ->all();

        return $this->event(
            segment: self::SEGMENT_INVOICE,
            event: 'invoice',
            occurredAt: $invoice->invoice_date ?: $invoice->created_at,
            referenceType: 'Invoice',
            referenceId: (int) $invoice->id,
            reference: $invoice->invoice_number,
            title: "Invoiced {$invoice->invoice_number}",
            legalEntityId: $invoice->legal_entity_id,
            details: [
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer?->name,
                'total' => (float) $invoice->total,
                'currency_code' => $invoice->currency_code,
                'legal_entity_id' => $invoice->legal_entity_id,
                'case_number' => $invoice->case_number,
                'patient_name' => $invoice->patient_name,
                'lines' => $lines,
            ],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function referenceIs(?string $referenceType, string $expected): bool
    {
        if ($referenceType === null) {
            return false;
        }

        return class_basename($referenceType) === $expected
            || ltrim($referenceType, '\\') === $expected;
    }

    /**
     * @param  array<string, array<string, mixed>>  $events
     * @param  array<string, mixed>  $event
     */
    private function pushEvent(array &$events, array $event): void
    {
        $key = implode('|', [
            $event['segment'],
            $event['event'],
            $event['reference_type'],
            $event['reference_id'],
            $event['occurred_at'],
            $event['reference'],
        ]);

        $events[$key] = $event;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function event(
        string $segment,
        string $event,
        mixed $occurredAt,
        string $referenceType,
        int $referenceId,
        ?string $reference,
        string $title,
        array $details,
        ?int $legalEntityId = null,
    ): array {
        $occurred = $this->toCarbon($occurredAt);

        return [
            'segment' => $segment,
            'event' => $event,
            'occurred_at' => $occurred?->toDateTimeString(),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference' => $reference,
            'title' => $title,
            'legal_entity_id' => $legalEntityId,
            'details' => $details,
            '_sort_at' => $occurred?->format('Y-m-d H:i:s') ?? '9999-12-31 23:59:59',
            '_sort_segment' => self::SEGMENT_ORDER[$segment] ?? 99,
            '_sort_reference' => (string) ($reference ?: $referenceId),
        ];
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        return Carbon::parse($value);
    }

    /**
     * @param  array<int, array<string, mixed>>  $timeline
     * @return array<int, array<string, mixed>>
     */
    private function maskPhi(array $timeline): array
    {
        return array_map(function (array $event) {
            return $this->maskPatientNames($event);
        }, $timeline);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function maskPatientNames(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($key === 'patient_names' && is_array($value)) {
                $payload[$key] = array_map(
                    fn ($name) => is_string($name) && $name !== ''
                        ? FieldMaskingService::maskDefault($name)
                        : $name,
                    $value
                );
            } elseif (is_array($value)) {
                $payload[$key] = $this->maskPatientNames($value);
            } elseif (in_array($key, ['patient_name', 'patient_reference'], true) && is_string($value) && $value !== '') {
                $payload[$key] = FieldMaskingService::maskDefault($value);
            }
        }

        return $payload;
    }

    /**
     * @return string[]
     */
    private function gaps(string $type): array
    {
        $gaps = [
            'Medical-case lines only carry a lot when medical_case_items.lot_id, delivery_order_item_id or invoice_item_id is populated (MC3/UX4); a case created without one of those links cannot be traced and is not shown.',
            'Goods receipts do not stamp the lot on stock_movements (GoodsReceiptService.confirm leaves batch_number null), so a lot received through a GRN has no receipt movement on the timeline; the lot\'s supplier/received-date datum is shown in its place.',
        ];

        if ($type === 'serial') {
            $gaps[] = 'Serial traceability depends on delivery_order_lines / invoice_lines carrying product_serial_id or serial_number; lines created before #131/#683 may not carry them.';
        }

        return $gaps;
    }
}
