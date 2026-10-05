<?php

namespace App\Services;

use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Quotation;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;

/**
 * Issue #690 (SALES-INV / INV-UX4): the read model behind the shared
 * "Stock moves" tab on every sales document.
 *
 * It answers three questions for one document:
 *
 *   1. What warehouse movements belong to this document's goods-issue bucket?
 *      The bucket is resolved by {@see GoodsIssueLedger::bucketForDocument()}
 *      — the same #682 definition of "which documents ship these goods" — so
 *      the tab never forks a second interpretation of the trail.
 *   2. Which movements reverse which? A `return` movement and the `sale`
 *      movement it compensates share (reference_type, reference_id,
 *      product_id) and the stock_movements unique constraint allows at most
 *      one of each, so the pair is deterministic without a new column.
 *   3. Does what left the warehouse match what the document sold, line by
 *      line? Bundle lines are compared through their #130 component demand,
 *      and returns reduce the net issued quantity.
 *
 * Legal-entity scoping is inherited from the route group the caller sits in
 * (ResolveEntityContext + EnsureEntityAccess), matching the #905 audit tab.
 */
class DocumentStockMovesService
{
    /**
     * stockMovesSubject => document model and number column. Mirrors
     * DocumentAuditController::SUBJECTS so both read tabs resolve the same
     * five sales documents the same way. Every subject exposes `lines.product`.
     *
     * @var array<string, array{model: class-string, number: string, label: string}>
     */
    public const SUBJECTS = [
        'invoice' => [
            'model' => Invoice::class,
            'number' => 'invoice_number',
            'label' => 'Invoice',
        ],
        'quotation' => [
            'model' => Quotation::class,
            'number' => 'quotation_number',
            'label' => 'Quotation',
        ],
        'sales_order' => [
            'model' => SalesOrder::class,
            'number' => 'sales_order_number',
            'label' => 'Sales Order',
        ],
        'delivery_order' => [
            'model' => DeliveryOrder::class,
            'number' => 'delivery_order_number',
            'label' => 'Delivery Order',
        ],
        'credit_note' => [
            'model' => CustomerCreditNote::class,
            'number' => 'credit_note_number',
            'label' => 'Credit Note',
        ],
    ];

    private const EPSILON = 0.0001;

    public function __construct(
        private GoodsIssueLedger $goodsIssueLedger,
        private BundleDecompositionService $bundleDecomposition,
    ) {}

    /**
     * @return array{document: array<string, mixed>, sales_order_id: int|null, has_mismatch: bool, movements: array<int, array<string, mixed>>, lines: array<int, array<string, mixed>>}
     */
    public function forDocument(string $documentType, int $documentId): array
    {
        $config = self::SUBJECTS[$documentType] ?? null;
        abort_if($config === null, 404);

        $document = $config['model']::query()->with('lines.product')->find($documentId);
        abort_if($document === null, 404, 'Document not found');

        $bucket = $this->goodsIssueLedger->bucketForDocument($documentType, $documentId);

        // The #682 bucket deliberately drops reversed delivery orders (they no
        // longer own unissued goods), but the trail tab must still show the
        // document's own sale + reversal pair. Union the document itself in.
        $references = $this->withOwnReference($bucket['references'], $documentType, $documentId);
        $movements = $this->loadMovements($references);

        $enrichment = $this->sourceLineEnrichment($movements);
        $numbers = $this->sourceNumbers($movements);

        $movementRows = $movements
            ->map(fn (StockMovement $movement) => $this->movementRow($movement, $enrichment, $numbers))
            ->values()
            ->all();

        $movementRows = $this->linkReversals($movementRows);

        [$lines, $unallocated] = $this->lineRows($document->lines, $documentType, $movements);

        $hasMismatch = collect($lines)->contains(fn (array $line) => $line['mismatch'] === true);

        foreach ($unallocated as $residual) {
            if (abs((float) $residual) > self::EPSILON) {
                $hasMismatch = true;
                break;
            }
        }

        return [
            'document' => [
                'type' => $documentType,
                'id' => (int) $documentId,
                'number' => $document->{$config['number']},
                'label' => $config['label'],
            ],
            'sales_order_id' => $bucket['sales_order_id'],
            'has_mismatch' => $hasMismatch,
            'movements' => $movementRows,
            'lines' => $lines,
        ];
    }

    /**
     * The document's own movement reference belongs in its trail whether or
     * not the #682 bucket still lists it (reversed DO) — sales orders,
     * quotations and credit notes never carry movements themselves.
     *
     * @param  array<int, array{0: string, 1: int}>  $references
     * @return array<int, array{0: string, 1: int}>
     */
    private function withOwnReference(array $references, string $documentType, int $documentId): array
    {
        $ownReference = match ($documentType) {
            'delivery_order' => ['DeliveryOrder', $documentId],
            'invoice' => ['Invoice', $documentId],
            default => null,
        };

        if ($ownReference === null) {
            return $references;
        }

        return array_values(array_unique(array_merge($references, [$ownReference]), SORT_REGULAR));
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $references
     * @return Collection<int, StockMovement>
     */
    private function loadMovements(array $references): Collection
    {
        if ($references === []) {
            return collect();
        }

        return StockMovement::query()
            ->with(['product:id,name,sku', 'warehouse:id,name,code', 'createdBy:id,name'])
            ->where(function ($outer) use ($references) {
                foreach ($references as [$referenceType, $referenceId]) {
                    $outer->orWhere(function ($query) use ($referenceType, $referenceId) {
                        $query->where('reference_type', $referenceType)
                            ->where('reference_id', $referenceId);
                    });
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function linkReversals(array $rows): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            $key = ($row['source_document']['type'] ?? '').'|'.($row['source_document']['id'] ?? '').'|'.($row['product_id'] ?? 0);
            $groups[$key][] = $index;
        }

        foreach ($groups as $indexes) {
            $saleIndex = null;
            $returnIndex = null;

            foreach ($indexes as $index) {
                if ($rows[$index]['type'] === 'sale') {
                    $saleIndex = $index;
                } elseif ($rows[$index]['type'] === 'return') {
                    $returnIndex = $index;
                }
            }

            if ($saleIndex !== null && $returnIndex !== null) {
                $rows[$saleIndex]['reversed_by_movement_id'] = $rows[$returnIndex]['id'];
                $rows[$returnIndex]['reverses_movement_id'] = $rows[$saleIndex]['id'];
            }
        }

        return $rows;
    }

    /**
     * Lot / serial enrichment: stock movements carry the lot they consumed
     * (`batch_number`) but no serial, so serials are read from the source
     * document's lines for the same product. A movement with no lot of its own
     * (a bundle component) inherits the line's lot.
     *
     * @return array<string, array<int, array{lot_number: ?string, serial_numbers: array<int, string>}>>
     */
    private function sourceLineEnrichment(Collection $movements): array
    {
        $enrichment = [];
        $idsByType = $movements
            ->groupBy('reference_type')
            ->map(fn (Collection $group) => $group->pluck('reference_id')->filter()->unique()->values()->all());

        $deliveryOrderIds = array_map('intval', $idsByType['DeliveryOrder'] ?? []);

        if ($deliveryOrderIds !== []) {
            foreach (DeliveryOrderLine::whereIn('delivery_order_id', $deliveryOrderIds)->get() as $line) {
                $key = 'DeliveryOrder|'.$line->delivery_order_id;
                $entry = $enrichment[$key][(int) $line->product_id] ?? ['lot_number' => null, 'serial_numbers' => []];
                $entry['lot_number'] ??= $line->lot_number ?: $line->batch_number;
                $entry['serial_numbers'] = array_values(array_unique(array_merge(
                    $entry['serial_numbers'],
                    array_filter([
                        $line->serial_number,
                        ...((array) ($line->serial_numbers ?? [])),
                    ]),
                )));
                $enrichment[$key][(int) $line->product_id] = $entry;
            }
        }

        $invoiceIds = array_map('intval', $idsByType['Invoice'] ?? []);

        if ($invoiceIds !== []) {
            foreach (InvoiceLine::whereIn('invoice_id', $invoiceIds)->get() as $line) {
                $key = 'Invoice|'.$line->invoice_id;
                $entry = $enrichment[$key][(int) $line->product_id] ?? ['lot_number' => null, 'serial_numbers' => []];
                $entry['lot_number'] ??= $line->lot_number;
                if ($line->serial_number) {
                    $entry['serial_numbers'] = array_values(array_unique(array_merge(
                        $entry['serial_numbers'],
                        [(string) $line->serial_number],
                    )));
                }
                $enrichment[$key][(int) $line->product_id] = $entry;
            }
        }

        return $enrichment;
    }

    /**
     * Human-readable document numbers for the movements' source documents.
     *
     * @return array<string, string>
     */
    private function sourceNumbers(Collection $movements): array
    {
        $numbers = [];
        $idsByType = $movements
            ->groupBy('reference_type')
            ->map(fn (Collection $group) => $group->pluck('reference_id')->filter()->unique()->values()->all());

        $deliveryOrderIds = array_map('intval', $idsByType['DeliveryOrder'] ?? []);
        if ($deliveryOrderIds !== []) {
            foreach (DeliveryOrder::whereIn('id', $deliveryOrderIds)->pluck('delivery_order_number', 'id') as $id => $number) {
                $numbers['DeliveryOrder|'.$id] = (string) $number;
            }
        }

        $invoiceIds = array_map('intval', $idsByType['Invoice'] ?? []);
        if ($invoiceIds !== []) {
            foreach (Invoice::whereIn('id', $invoiceIds)->pluck('invoice_number', 'id') as $id => $number) {
                $numbers['Invoice|'.$id] = (string) $number;
            }
        }

        $salesOrderIds = array_map('intval', $idsByType['SalesOrder'] ?? []);
        if ($salesOrderIds !== []) {
            foreach (SalesOrder::whereIn('id', $salesOrderIds)->pluck('sales_order_number', 'id') as $id => $number) {
                $numbers['SalesOrder|'.$id] = (string) $number;
            }
        }

        return $numbers;
    }

    /**
     * @param  array<string, array<int, array{lot_number: ?string, serial_numbers: array<int, string>}>>  $enrichment
     * @param  array<string, string>  $numbers
     * @return array<string, mixed>
     */
    private function movementRow(StockMovement $movement, array $enrichment, array $numbers): array
    {
        $referenceType = (string) $movement->reference_type;
        $referenceId = $movement->reference_id !== null ? (int) $movement->reference_id : null;
        $sourceKey = $referenceType.'|'.$referenceId;
        $line = $enrichment[$sourceKey][(int) $movement->product_id] ?? null;

        return [
            'id' => (int) $movement->id,
            'product_id' => (int) $movement->product_id,
            'type' => (string) $movement->type,
            'direction' => $movement->isIncoming() ? 'in' : 'out',
            'quantity' => (float) $movement->quantity,
            'product' => $movement->product ? [
                'id' => (int) $movement->product->id,
                'name' => $movement->product->name,
                'sku' => $movement->product->sku,
            ] : null,
            'warehouse' => $movement->warehouse ? [
                'id' => (int) $movement->warehouse->id,
                'name' => $movement->warehouse->name,
                'code' => $movement->warehouse->code,
            ] : null,
            'lot_number' => $movement->batch_number ?: ($line['lot_number'] ?? null),
            'batch_number' => $movement->batch_number,
            'expiry_date' => $movement->expiry_date?->toDateString(),
            'serial_numbers' => $line['serial_numbers'] ?? [],
            'date' => $movement->created_at?->toIso8601String(),
            'reference' => $movement->reference,
            'notes' => $movement->notes,
            'source_document' => [
                'type' => $referenceType !== '' ? $referenceType : null,
                'id' => $referenceId,
                'number' => $numbers[$sourceKey] ?? $movement->reference,
            ],
            'created_by' => $movement->createdBy ? [
                'id' => (int) $movement->createdBy->id,
                'name' => $movement->createdBy->name,
            ] : null,
            'reverses_movement_id' => null,
            'reversed_by_movement_id' => null,
        ];
    }

    /**
     * Net issued quantity per component product: `sale`/outgoing movements add,
     * `return`/incoming movements subtract, so a reversal nets the trail back
     * to what is actually still out.
     *
     * @return array<int, float>
     */
    private function netIssuedByProduct(Collection $movements): array
    {
        $net = [];

        foreach ($movements as $movement) {
            $productId = (int) $movement->product_id;
            $delta = $movement->isIncoming() ? -(float) $movement->quantity : (float) $movement->quantity;
            $net[$productId] = ($net[$productId] ?? 0.0) + $delta;
        }

        return $net;
    }

    /**
     * Per-line "what left the warehouse" against the line's own demand.
     *
     * Bundle lines have no stock of their own: the #130 decomposition turns
     * each line into component demand, the family's net issued quantity is
     * allocated to those components in document order, and a line mismatches
     * when its component demand is not fully covered (or a shared component
     * was over-issued, surfaced as an unallocated residual). Non-bundle lines
     * reduce to demand == sold quantity, so the flag reads "net issued ≠ sold"
     * exactly as the issue asks.
     *
     * @param  Collection<int, mixed>  $lines
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, float>}
     */
    private function lineRows(Collection $lines, string $documentType, Collection $movements): array
    {
        $unallocated = $this->netIssuedByProduct($movements);
        $rows = [];

        foreach ($lines as $line) {
            if (! $line->product || ! $this->isProductLine($line)) {
                continue;
            }

            $sold = $this->soldQuantity($line, $documentType);
            $components = $this->bundleDecomposition->stockConsumingComponents($line->product, $sold);
            $stockTracked = $components !== [];

            $requiredTotal = 0.0;
            $allocated = 0.0;
            $componentRows = [];

            foreach ($components as $component) {
                $productId = (int) $component['product']->id;
                $required = (float) $component['quantity'];
                $available = max((float) ($unallocated[$productId] ?? 0.0), 0.0);
                $allocation = min($required, $available);

                $unallocated[$productId] = ($unallocated[$productId] ?? 0.0) - $allocation;
                $requiredTotal += $required;
                $allocated += $allocation;

                $componentRows[] = [
                    'product_id' => $productId,
                    'name' => $component['product']->name,
                    'sku' => $component['product']->sku,
                    'required_quantity' => $required,
                    'net_issued_quantity' => $allocation,
                ];
            }

            $rows[] = [
                'line_id' => (int) $line->id,
                'product_id' => (int) $line->product->id,
                'product' => [
                    'id' => (int) $line->product->id,
                    'name' => $line->product->name,
                    'sku' => $line->product->sku,
                ],
                'description' => $line->description,
                'sold_quantity' => $sold,
                'required_stock_quantity' => $requiredTotal,
                'net_issued_quantity' => $stockTracked ? $allocated : 0.0,
                'stock_tracked' => $stockTracked,
                'mismatch' => $stockTracked && abs($allocated - $requiredTotal) > self::EPSILON,
                'components' => $componentRows,
            ];
        }

        return [$rows, $unallocated];
    }

    private function isProductLine(mixed $line): bool
    {
        $type = $line->line_type ?? 'product';

        return ! in_array($type, ['note', 'section'], true);
    }

    /**
     * The quantity the document says it sold. Sales-order lines subtract
     * cancelled units so a cancelled remainder does not count as sold.
     */
    private function soldQuantity(mixed $line, string $documentType): float
    {
        return match ($documentType) {
            'delivery_order' => (float) ($line->quantity_delivered ?? 0),
            'sales_order' => max((float) ($line->quantity ?? 0) - (float) ($line->quantity_cancelled ?? 0), 0.0),
            default => (float) ($line->quantity ?? 0),
        };
    }
}
