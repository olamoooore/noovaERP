<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Issue #688 (INV-UX2): delivery progress on invoices ("Delivered 6/10") and
 * invoicing progress on delivery orders ("Invoiced 10/10").
 *
 * Nothing here records new state — every number is derived from the link data
 * the posting / delivery paths already maintain:
 *
 *   • invoice line ↔ sales-order line (`invoice_lines.sales_order_line_id`)
 *     and the order line's `quantity_delivered`, which delivery completion
 *     mirrors (#461) and reversal unwinds — the same "delivered" measure the
 *     #602 Sales Analysis pivot inherits for order-backed invoice lines;
 *   • delivery-order line `quantity_invoiced`, which DO → Invoice and the SO
 *     invoice sync (#455) maintain — the same column the #602 unbilled
 *     deliveries view and `DeliveryOrder::invoiceableQuantityForLine()` read;
 *   • the invoice ↔ delivery-order pair (`delivery_orders.invoice_id` plus the
 *     `invoice_delivery_orders` pivot) for documents with no sales order — an
 *     invoice-first DO, or a DO billed through an invoice.
 *
 * An order-backed invoice line is delivered first-in-first-out: the order
 * line's delivered quantity covers its invoices in invoice order, so two
 * invoices for the same 10 units never both claim the 6 that shipped. The
 * mirror rule covers a delivery order raised AFTER its order line (or its
 * paired invoice) was billed: the line's own `quantity_invoiced` is still 0,
 * so it takes its first-in-first-out share of what was already invoiced.
 * Quantities are compared in stock units (#685: an invoice line may be
 * entered as 2 Box) and reported back in the line's own unit.
 *
 * Every query is set-based, so list endpoints load a whole page in one round
 * trip, and the list filters reuse the very same SQL as the chips.
 */
class SalesDocumentProgress
{
    private const EPSILON = 0.0001;

    /**
     * @param  array<int, int>  $invoiceIds
     * @return array<int, array<string, mixed>> progress keyed by invoice id
     */
    public function forInvoices(array $invoiceIds): array
    {
        $invoiceIds = $this->ids($invoiceIds);
        if ($invoiceIds === []) {
            return [];
        }

        [$sql, $bindings] = $this->invoiceLineSql($invoiceIds);
        $lines = collect(DB::select($sql, $bindings))->groupBy('invoice_id');

        $links = $this->deliveryOrdersForInvoices($invoiceIds);

        $progress = [];
        foreach ($invoiceIds as $invoiceId) {
            $rows = $lines->get($invoiceId, collect())
                ->filter(fn ($row) => $row->delivered_stock !== null);

            $lineProgress = [];
            $quantity = 0.0;
            $delivered = 0.0;
            $open = false;

            foreach ($rows as $row) {
                $lineQuantity = (float) $row->quantity;
                $lineDelivered = (float) $row->delivered_stock / (float) $row->factor;
                $quantity += $lineQuantity;
                $delivered += $lineDelivered;
                if ((float) $row->delivered_stock + self::EPSILON < (float) $row->stock_quantity) {
                    $open = true;
                }

                $lineProgress[(int) $row->invoice_line_id] = [
                    'quantity' => round($lineQuantity, 4),
                    'delivered_quantity' => round($lineDelivered, 4),
                ];
            }

            $progress[$invoiceId] = [
                'status' => $rows->isEmpty()
                    ? 'not_tracked'
                    : ($delivered <= self::EPSILON ? 'not_delivered' : ($open ? 'partially_delivered' : 'fully_delivered')),
                'quantity' => round($quantity, 4),
                'delivered_quantity' => round($delivered, 4),
                'lines' => (object) $lineProgress,
                'delivery_orders' => $links[$invoiceId] ?? [],
            ];
        }

        return $progress;
    }

    /**
     * @param  array<int, int>  $deliveryOrderIds
     * @return array<int, array<string, mixed>> progress keyed by delivery order id
     */
    public function forDeliveryOrders(array $deliveryOrderIds): array
    {
        $deliveryOrderIds = $this->ids($deliveryOrderIds);
        if ($deliveryOrderIds === []) {
            return [];
        }

        [$sql, $bindings] = $this->deliveryOrderLineSql($deliveryOrderIds);
        $lines = collect(DB::select($sql, $bindings))->groupBy('delivery_order_id');

        $links = $this->invoicesForDeliveryOrders($deliveryOrderIds);

        $progress = [];
        foreach ($deliveryOrderIds as $deliveryOrderId) {
            $rows = $lines->get($deliveryOrderId, collect())
                ->filter(fn ($row) => (float) $row->target_quantity > self::EPSILON);

            $lineProgress = [];
            $quantity = 0.0;
            $invoiced = 0.0;
            $open = false;

            foreach ($rows as $row) {
                $target = (float) $row->target_quantity;
                $lineInvoiced = (float) $row->invoiced_quantity;
                $quantity += $target;
                $invoiced += $lineInvoiced;
                if ($lineInvoiced + self::EPSILON < $target) {
                    $open = true;
                }

                $lineProgress[(int) $row->delivery_order_line_id] = [
                    'quantity' => round($target, 4),
                    'invoiced_quantity' => round($lineInvoiced, 4),
                ];
            }

            $progress[$deliveryOrderId] = [
                'status' => $rows->isEmpty()
                    ? 'nothing_to_invoice'
                    : ($invoiced <= self::EPSILON ? 'not_invoiced' : ($open ? 'partially_invoiced' : 'fully_invoiced')),
                'quantity' => round($quantity, 4),
                'invoiced_quantity' => round($invoiced, 4),
                'lines' => (object) $lineProgress,
                'invoices' => $links[$deliveryOrderId] ?? [],
            ];
        }

        return $progress;
    }

    /**
     * List filter "invoiced not delivered": invoices with at least one
     * delivery-tracked line whose delivered quantity is short of its invoiced
     * quantity. Invoices with no order/delivery link (`not_tracked`) never
     * match — there is no delivery document to be missing.
     */
    public function scopeInvoicedNotDelivered(Builder $query): void
    {
        [$sql, $bindings] = $this->invoiceLineSql(null);

        $query->whereIn($query->getModel()->getTable().'.id', function ($sub) use ($sql, $bindings) {
            $sub->select('p.invoice_id')
                ->fromRaw("({$sql}) AS p", $bindings)
                ->whereNotNull('p.delivered_stock')
                ->whereRaw('p.delivered_stock + ? < p.stock_quantity', [self::EPSILON]);
        });
    }

    /**
     * List filter "delivered not invoiced": completed delivery orders with at
     * least one line whose invoiced quantity is short of what it delivered.
     */
    public function scopeDeliveredNotInvoiced(Builder $query): void
    {
        [$sql, $bindings] = $this->deliveryOrderLineSql(null);
        $table = $query->getModel()->getTable();

        $query->where($table.'.status', 'done')
            ->whereIn($table.'.id', function ($sub) use ($sql, $bindings) {
                $sub->select('p.delivery_order_id')
                    ->fromRaw("({$sql}) AS p", $bindings)
                    ->whereRaw('p.invoiced_quantity + ? < p.target_quantity', [self::EPSILON]);
            });
    }

    /** Issue #411: the fulfilment stages a line can be stuck at, in chain order. */
    public const LINE_STAGES = ['reserve', 'deliver', 'invoice', 'pay'];

    /**
     * Issue #411 (DX1): the per-line fulfilment chain of one sales order —
     * ordered → reserved → delivered → invoiced → paid — with the delivery
     * orders, invoices and payments behind each stage.
     *
     * Every figure is read from bookkeeping the live paths already keep:
     *   • ordered   = quantity − quantity_cancelled;
     *   • reserved  = quantity_allocated (#380), or what was delivered when
     *                 that is more — allocation is recorded once and not
     *                 consumed by delivery, and shipped goods were available;
     *   • delivered = quantity_delivered (#461 delivery-completion mirror);
     *   • invoiced  = quantity_invoiced (#455 invoicing mirror); the amount is
     *                 the active invoice lines linked by sales_order_line_id;
     *   • paid      — rule: each linked invoice line's paid share is the
     *                 invoice's live payment applications (#155: not reversed,
     *                 payment not deleted / reversed / cancelled) ÷ the
     *                 invoice total, capped at 1, times the line's quantity and
     *                 amount. Credit notes are not payments and do not count.
     *                 An invoice whose total is zero has nothing to collect and
     *                 counts as paid.
     * Each stage is capped at the ordered quantity (paid at invoiced). Lines
     * with no stock to move (services, shipping, free text) skip the reserve
     * and deliver stages: they count as reached. Quantities are compared in
     * stock units (#685) and reported in the line's own unit.
     *
     * `stuck_at` is the first stage a line has not completed, on an order
     * that is in fulfilment (not draft or cancelled); null when the line is
     * complete or the order is not in fulfilment.
     *
     * @return array{sales_order_id: int, lines: array<int, array<string, mixed>>}
     */
    public function forSalesOrderLines(int $salesOrderId): array
    {
        [$sql, $bindings] = $this->salesOrderLineChainSql($salesOrderId);
        $rows = DB::select($sql.' ORDER BY c.sequence NULLS LAST, c.sales_order_line_id', $bindings);

        [$deliveryOrders, $invoices, $payments] = $this->salesOrderLineDocuments($salesOrderId);

        $lines = [];
        foreach ($rows as $row) {
            $lineId = (int) $row->sales_order_line_id;
            $factor = (float) $row->factor;
            $ordered = (float) $row->ordered_stock;
            $lineTotal = (float) $row->line_total;
            $share = fn (float $stock): float => $ordered > self::EPSILON ? round($lineTotal * $stock / $ordered, 2) : 0.0;
            $quantity = fn (float $stock): float => round($stock / $factor, 4);

            $lines[] = [
                'sales_order_line_id' => $lineId,
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'product_name' => $row->product_name,
                'description' => $row->description,
                'unit' => $row->unit,
                'delivery_tracked' => (bool) $row->delivery_tracked,
                'stuck_at' => $row->stuck_at,
                'ordered' => ['quantity' => $quantity($ordered), 'amount' => round($lineTotal, 2)],
                'reserved' => [
                    'quantity' => $quantity((float) $row->reserved_stock),
                    'amount' => $share((float) $row->reserved_stock),
                ],
                'delivered' => [
                    'quantity' => $quantity((float) $row->delivered_stock),
                    'amount' => $share((float) $row->delivered_stock),
                    'delivery_orders' => $deliveryOrders[$lineId] ?? [],
                ],
                'invoiced' => [
                    'quantity' => $quantity((float) $row->invoiced_stock),
                    'amount' => round((float) $row->invoiced_amount, 2),
                    'invoices' => $invoices[$lineId] ?? [],
                ],
                'paid' => [
                    'quantity' => $quantity((float) $row->paid_stock),
                    'amount' => round((float) $row->paid_amount, 2),
                    'payments' => $payments[$lineId] ?? [],
                ],
            ];
        }

        return ['sales_order_id' => $salesOrderId, 'lines' => $lines];
    }

    /**
     * Issue #411 list filter "lines stuck at stage X": sales orders with at
     * least one line whose first incomplete stage is $stage — the very same
     * SQL as the per-line chain above.
     */
    public function scopeSalesOrdersStuckAt(Builder $query, string $stage): void
    {
        [$sql, $bindings] = $this->salesOrderLineChainSql(null);

        $query->whereIn($query->getModel()->getTable().'.id', function ($sub) use ($sql, $bindings, $stage) {
            $sub->select('s.sales_order_id')
                ->fromRaw("({$sql}) AS s", $bindings)
                ->where('s.stuck_at', $stage);
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // SQL
    // ─────────────────────────────────────────────────────────────────────

    /** Issue #411: a live receipt application (#155 / C-3). */
    private function livePaymentApplicationSql(string $application, string $payment): string
    {
        return "{$application}.reversed_at IS NULL AND {$payment}.deleted_at IS NULL"
            ." AND COALESCE({$payment}.payment_status::text, '') NOT IN ('reversed', 'cancelled', 'voided')";
    }

    /**
     * Issue #411: one row per non-text sales-order line with each stage's
     * reached quantity in stock units and its first incomplete stage.
     *
     * @param  int|null  $salesOrderId  null = every order (list filter)
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function salesOrderLineChainSql(?int $salesOrderId): array
    {
        $bindings = [];
        $lineScope = '';
        $invoiceScope = '';
        $paymentScope = '';

        if ($salesOrderId !== null) {
            $paymentScope = ' AND pa.invoice_id IN (SELECT il2.invoice_id FROM invoice_lines il2'
                .' JOIN sales_order_lines s3 ON s3.id = il2.sales_order_line_id WHERE s3.sales_order_id = ?)';
            $invoiceScope = ' AND il.sales_order_line_id IN (SELECT s2.id FROM sales_order_lines s2 WHERE s2.sales_order_id = ?)';
            $lineScope = ' AND sol.sales_order_id = ?';
            $bindings = [$salesOrderId, $salesOrderId, $salesOrderId];
        }

        $activeInvoice = $this->activeInvoiceSql('i');
        $livePayment = $this->livePaymentApplicationSql('pa', 'pm');
        $epsilon = self::EPSILON;
        $factor = 'COALESCE(NULLIF(spu.conversion_factor, 0), 1)';
        $ordered = "(GREATEST(COALESCE(sol.quantity, 0) - COALESCE(sol.quantity_cancelled, 0), 0) * {$factor})";
        $tracked = "(sol.product_id IS NOT NULL AND COALESCE(p.type::text, 'product') <> 'service'"
            .' AND NOT COALESCE(sol.is_delivery, false))';
        $invoicedStock = "LEAST({$ordered}, GREATEST(COALESCE(sol.quantity_invoiced, 0), 0))";

        $sql = <<<SQL
            SELECT c.*,
                   CASE
                       WHEN c.in_fulfilment AND c.ordered_stock > {$epsilon} THEN
                           CASE
                               WHEN c.reserved_stock + {$epsilon} < c.ordered_stock THEN 'reserve'
                               WHEN c.delivered_stock + {$epsilon} < c.ordered_stock THEN 'deliver'
                               WHEN c.invoiced_stock + {$epsilon} < c.ordered_stock THEN 'invoice'
                               WHEN c.paid_stock + {$epsilon} < c.ordered_stock THEN 'pay'
                           END
                   END AS stuck_at
            FROM (
                SELECT sol.id AS sales_order_line_id,
                       sol.sales_order_id,
                       sol.sequence,
                       sol.product_id,
                       p.name AS product_name,
                       sol.description,
                       sol.unit,
                       COALESCE(sol.line_total, 0) AS line_total,
                       {$factor} AS factor,
                       {$tracked} AS delivery_tracked,
                       (so.status::text NOT IN ('draft', 'cancelled')) AS in_fulfilment,
                       {$ordered} AS ordered_stock,
                       CASE WHEN {$tracked} THEN LEAST({$ordered}, GREATEST(
                           COALESCE(sol.quantity_allocated, 0), COALESCE(sol.quantity_delivered, 0), 0))
                       ELSE {$ordered} END AS reserved_stock,
                       CASE WHEN {$tracked} THEN LEAST({$ordered}, GREATEST(COALESCE(sol.quantity_delivered, 0), 0))
                       ELSE {$ordered} END AS delivered_stock,
                       {$invoicedStock} AS invoiced_stock,
                       LEAST({$invoicedStock}, COALESCE(inv.paid_stock, 0)) AS paid_stock,
                       COALESCE(inv.invoiced_amount, 0) AS invoiced_amount,
                       COALESCE(inv.paid_amount, 0) AS paid_amount
                FROM sales_order_lines sol
                JOIN sales_orders so ON so.id = sol.sales_order_id AND so.deleted_at IS NULL
                LEFT JOIN products p ON p.id = sol.product_id
                LEFT JOIN product_uoms spu ON spu.id = sol.uom_id
                LEFT JOIN (
                    SELECT il.sales_order_line_id,
                           SUM(COALESCE(il.line_total, 0)) AS invoiced_amount,
                           SUM(COALESCE(il.quantity, 0) * COALESCE(NULLIF(pu.conversion_factor, 0), 1) * r.ratio) AS paid_stock,
                           SUM(COALESCE(il.line_total, 0) * r.ratio) AS paid_amount
                    FROM invoice_lines il
                    JOIN invoices i ON i.id = il.invoice_id
                    LEFT JOIN product_uoms pu ON pu.id = il.uom_id
                    LEFT JOIN (
                        SELECT pa.invoice_id, SUM(pa.applied_amount) AS paid
                        FROM payment_applications pa
                        JOIN payments pm ON pm.id = pa.payment_id
                        WHERE {$livePayment}{$paymentScope}
                        GROUP BY pa.invoice_id
                    ) paid ON paid.invoice_id = i.id
                    CROSS JOIN LATERAL (
                        SELECT CASE WHEN COALESCE(i.total, 0) <= 0.005 THEN 1
                                    ELSE LEAST(1, COALESCE(paid.paid, 0) / i.total) END AS ratio
                    ) r
                    WHERE {$activeInvoice}
                      AND il.sales_order_line_id IS NOT NULL
                      {$invoiceScope}
                    GROUP BY il.sales_order_line_id
                ) inv ON inv.sales_order_line_id = sol.id
                WHERE COALESCE(sol.line_type::text, 'product') NOT IN ('note', 'section')
                  {$lineScope}
            ) c
            SQL;

        return [$sql, $bindings];
    }

    /**
     * Issue #411: the delivery orders, invoices and payments behind each line
     * of one sales order — three set-based queries, keyed by order line id.
     *
     * @return array{0: array<int, array<int, array<string, mixed>>>, 1: array<int, array<int, array<string, mixed>>>, 2: array<int, array<int, array<string, mixed>>>}
     */
    private function salesOrderLineDocuments(int $salesOrderId): array
    {
        $activeDeliveryOrder = $this->activeDeliveryOrderSql('d');
        $activeInvoice = $this->activeInvoiceSql('i');
        $livePayment = $this->livePaymentApplicationSql('pa', 'pm');

        $deliveryOrders = [];
        foreach (DB::select(<<<SQL
            SELECT dol.sales_order_line_id, d.id, d.delivery_order_number, d.status,
                   SUM(GREATEST(COALESCE(dol.quantity_delivered, 0) - COALESCE(dol.quantity_returned, 0), 0)) AS quantity
            FROM delivery_order_lines dol
            JOIN delivery_orders d ON d.id = dol.delivery_order_id
            JOIN sales_order_lines sol ON sol.id = dol.sales_order_line_id
            WHERE sol.sales_order_id = ? AND {$activeDeliveryOrder}
            GROUP BY dol.sales_order_line_id, d.id, d.delivery_order_number, d.status
            ORDER BY dol.sales_order_line_id, d.id
            SQL, [$salesOrderId]) as $row) {
            $deliveryOrders[(int) $row->sales_order_line_id][] = [
                'id' => (int) $row->id,
                'delivery_order_number' => $row->delivery_order_number,
                'status' => $row->status,
                'quantity' => round((float) $row->quantity, 4),
            ];
        }

        $invoices = [];
        foreach (DB::select(<<<SQL
            SELECT il.sales_order_line_id, i.id, i.invoice_number, i.status, i.lifecycle_status, i.total,
                   COALESCE(paid.paid, 0) AS invoice_paid,
                   SUM(COALESCE(il.quantity, 0)) AS quantity,
                   SUM(COALESCE(il.line_total, 0)) AS amount
            FROM invoice_lines il
            JOIN invoices i ON i.id = il.invoice_id
            JOIN sales_order_lines sol ON sol.id = il.sales_order_line_id
            LEFT JOIN (
                SELECT pa.invoice_id, SUM(pa.applied_amount) AS paid
                FROM payment_applications pa
                JOIN payments pm ON pm.id = pa.payment_id
                WHERE {$livePayment}
                GROUP BY pa.invoice_id
            ) paid ON paid.invoice_id = i.id
            WHERE sol.sales_order_id = ? AND {$activeInvoice}
            GROUP BY il.sales_order_line_id, i.id, i.invoice_number, i.status, i.lifecycle_status, i.total, paid.paid
            ORDER BY il.sales_order_line_id, i.id
            SQL, [$salesOrderId]) as $row) {
            $total = (float) $row->total;
            $ratio = $total <= 0.005 ? 1.0 : min(1.0, (float) $row->invoice_paid / $total);
            $invoices[(int) $row->sales_order_line_id][] = [
                'id' => (int) $row->id,
                'invoice_number' => $row->invoice_number,
                'status' => $row->status,
                'lifecycle_status' => $row->lifecycle_status,
                'quantity' => round((float) $row->quantity, 4),
                'amount' => round((float) $row->amount, 2),
                'paid_ratio' => round($ratio, 4),
                'paid_amount' => round((float) $row->amount * $ratio, 2),
            ];
        }

        $payments = [];
        foreach (DB::select(<<<SQL
            SELECT DISTINCT sol.id AS sales_order_line_id, pa.id AS application_id, pm.id, pm.payment_number,
                   pm.payment_date, pa.invoice_id, pa.applied_amount
            FROM payment_applications pa
            JOIN payments pm ON pm.id = pa.payment_id
            JOIN invoices i ON i.id = pa.invoice_id
            JOIN invoice_lines il ON il.invoice_id = i.id
            JOIN sales_order_lines sol ON sol.id = il.sales_order_line_id
            WHERE sol.sales_order_id = ? AND {$activeInvoice} AND {$livePayment}
            ORDER BY sales_order_line_id, payment_date, id
            SQL, [$salesOrderId]) as $row) {
            $payments[(int) $row->sales_order_line_id][] = [
                'id' => (int) $row->id,
                'payment_number' => $row->payment_number,
                'payment_date' => $row->payment_date,
                'invoice_id' => (int) $row->invoice_id,
                'applied_amount' => round((float) $row->applied_amount, 2),
            ];
        }

        return [$deliveryOrders, $invoices, $payments];
    }

    /** An invoice that still counts: not deleted, cancelled, voided or superseded. */
    private function activeInvoiceSql(string $alias): string
    {
        return "{$alias}.deleted_at IS NULL"
            ." AND COALESCE({$alias}.status::text, '') NOT IN ('cancelled', 'voided')"
            ." AND COALESCE({$alias}.lifecycle_status::text, '') NOT IN ('cancelled', 'voided', 'reversed', 'superseded')";
    }

    /** A delivery order that still counts: not deleted, cancelled or reversed. */
    private function activeDeliveryOrderSql(string $alias): string
    {
        return "{$alias}.deleted_at IS NULL AND {$alias}.status::text NOT IN ('cancelled', 'reversed')";
    }

    /** Invoice ↔ delivery order pair: the legacy pointer or the pivot. */
    private function pairSql(string $deliveryOrder, string $invoiceId): string
    {
        return "({$deliveryOrder}.invoice_id = {$invoiceId} OR EXISTS ("
            ."SELECT 1 FROM invoice_delivery_orders ido WHERE ido.delivery_order_id = {$deliveryOrder}.id"
            ." AND ido.invoice_id = {$invoiceId}))";
    }

    /**
     * One row per goods invoice line: its quantity in its own unit and in
     * stock units, and its delivered quantity in stock units (NULL when the
     * line has no sales-order line and its invoice no delivery order).
     *
     * @param  array<int, int>|null  $invoiceIds  null = every invoice (list filter)
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function invoiceLineSql(?array $invoiceIds): array
    {
        $bindings = [];
        $innerScope = '';
        $outerScope = '';

        if ($invoiceIds !== null) {
            $in = implode(',', array_fill(0, count($invoiceIds), '?'));
            // Earlier invoices on the same order lines are needed for the
            // first-in-first-out allocation, so the inner set is wider.
            $innerScope = " AND (il.invoice_id IN ({$in}) OR il.sales_order_line_id IN ("
                ."SELECT sil.sales_order_line_id FROM invoice_lines sil WHERE sil.invoice_id IN ({$in})"
                .' AND sil.sales_order_line_id IS NOT NULL))';
            $outerScope = " WHERE x.invoice_id IN ({$in})";
            $bindings = array_merge($invoiceIds, $invoiceIds, $invoiceIds);
        }

        $activeInvoice = $this->activeInvoiceSql('i');
        $activeDeliveryOrder = $this->activeDeliveryOrderSql('d');
        $pair = $this->pairSql('d', 'x.invoice_id');
        $stockQuantity = 'COALESCE(il.quantity, 0) * COALESCE(NULLIF(pu.conversion_factor, 0), 1)';

        $sql = <<<SQL
            SELECT x.invoice_line_id,
                   x.invoice_id,
                   x.quantity,
                   x.factor,
                   x.stock_quantity,
                   CASE
                       WHEN x.sales_order_line_id IS NOT NULL THEN
                           LEAST(x.stock_quantity, GREATEST(0,
                               COALESCE(sol.quantity_delivered, 0) - (x.order_cumulative - x.stock_quantity)))
                       WHEN EXISTS (SELECT 1 FROM delivery_orders d WHERE {$activeDeliveryOrder} AND {$pair}) THEN
                           LEAST(x.stock_quantity, GREATEST(0,
                               COALESCE((
                                   SELECT SUM(dol.quantity_delivered)
                                   FROM delivery_order_lines dol
                                   JOIN delivery_orders d ON d.id = dol.delivery_order_id
                                   WHERE d.status::text = 'done'
                                     AND d.deleted_at IS NULL
                                     AND dol.product_id = x.product_id
                                     AND COALESCE(dol.line_type::text, 'product') = 'product'
                                     AND {$pair}
                               ), 0) - (x.invoice_cumulative - x.stock_quantity)))
                       ELSE NULL
                   END AS delivered_stock
            FROM (
                SELECT il.id AS invoice_line_id,
                       il.invoice_id,
                       il.product_id,
                       il.sales_order_line_id,
                       COALESCE(il.quantity, 0) AS quantity,
                       COALESCE(NULLIF(pu.conversion_factor, 0), 1) AS factor,
                       {$stockQuantity} AS stock_quantity,
                       SUM({$stockQuantity}) OVER (
                           PARTITION BY il.sales_order_line_id ORDER BY il.invoice_id, il.id
                       ) AS order_cumulative,
                       SUM({$stockQuantity}) OVER (
                           PARTITION BY il.invoice_id, il.product_id, (il.sales_order_line_id IS NULL) ORDER BY il.id
                       ) AS invoice_cumulative
                FROM invoice_lines il
                JOIN invoices i ON i.id = il.invoice_id
                LEFT JOIN product_uoms pu ON pu.id = il.uom_id
                LEFT JOIN products p ON p.id = il.product_id
                WHERE {$activeInvoice}
                  AND COALESCE(il.line_type::text, 'product') = 'product'
                  AND il.product_id IS NOT NULL
                  AND COALESCE(p.type::text, 'product') <> 'service'
                  {$innerScope}
            ) x
            LEFT JOIN sales_order_lines sol ON sol.id = x.sales_order_line_id
            {$outerScope}
            SQL;

        return [$sql, $bindings];
    }

    /**
     * One row per goods delivery-order line of an active delivery order: the
     * quantity it is expected to bill (delivered, or planned before
     * completion, net of returns — `invoiceableQuantityForLine()`'s basis)
     * and how much of it is invoiced: the line's own `quantity_invoiced`, or —
     * when the order line was invoiced before this DO existed (invoice-first)
     * or the DO was raised from an invoice — the share of the order line's /
     * paired invoice's billed quantity that covers it, first in first out.
     *
     * @param  array<int, int>|null  $deliveryOrderIds  null = every DO (list filter)
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function deliveryOrderLineSql(?array $deliveryOrderIds): array
    {
        $bindings = [];
        $scope = '';

        $outerScope = '';

        if ($deliveryOrderIds !== null) {
            $in = implode(',', array_fill(0, count($deliveryOrderIds), '?'));
            // Other delivery orders on the same order lines are needed for the
            // first-in-first-out allocation, so the inner set is wider.
            $scope = " AND (dol.delivery_order_id IN ({$in}) OR dol.sales_order_line_id IN ("
                ."SELECT sdol.sales_order_line_id FROM delivery_order_lines sdol WHERE sdol.delivery_order_id IN ({$in})"
                .' AND sdol.sales_order_line_id IS NOT NULL))';
            $outerScope = " WHERE x.delivery_order_id IN ({$in})";
            $bindings = array_merge($deliveryOrderIds, $deliveryOrderIds, $deliveryOrderIds);
        }

        $activeDeliveryOrder = $this->activeDeliveryOrderSql('d');
        $activeInvoice = $this->activeInvoiceSql('i');
        $pair = $this->pairSql('pd', 'i.id');
        $target = 'GREATEST(COALESCE(NULLIF(dol.quantity_delivered, 0), dol.quantity_ordered, 0)'
            .' - COALESCE(dol.quantity_returned, 0), 0)';

        $sql = <<<SQL
            SELECT x.delivery_order_line_id,
                   x.delivery_order_id,
                   x.target_quantity,
                   CASE
                       WHEN x.sales_order_line_id IS NOT NULL THEN
                           GREATEST(x.quantity_invoiced, LEAST(x.target_quantity, GREATEST(0,
                               COALESCE(sol.quantity_invoiced, 0) - (x.line_cumulative - x.target_quantity))))
                       ELSE GREATEST(x.quantity_invoiced, LEAST(x.target_quantity, GREATEST(0,
                           COALESCE((
                               SELECT SUM(COALESCE(il.quantity, 0) * COALESCE(NULLIF(pu.conversion_factor, 0), 1))
                               FROM invoice_lines il
                               JOIN invoices i ON i.id = il.invoice_id
                               JOIN delivery_orders pd ON pd.id = x.delivery_order_id
                               LEFT JOIN product_uoms pu ON pu.id = il.uom_id
                               WHERE {$activeInvoice}
                                 AND il.product_id = x.product_id
                                 AND COALESCE(il.line_type::text, 'product') = 'product'
                                 AND {$pair}
                           ), 0) - (x.order_cumulative - x.target_quantity))))
                   END AS invoiced_quantity
            FROM (
                SELECT dol.id AS delivery_order_line_id,
                       dol.delivery_order_id,
                       dol.product_id,
                       dol.sales_order_line_id,
                       COALESCE(dol.quantity_invoiced, 0) AS quantity_invoiced,
                       {$target} AS target_quantity,
                       SUM({$target}) OVER (
                           PARTITION BY dol.delivery_order_id, dol.product_id, (dol.sales_order_line_id IS NULL)
                           ORDER BY dol.id
                       ) AS order_cumulative,
                       SUM({$target}) OVER (
                           PARTITION BY dol.sales_order_line_id ORDER BY dol.delivery_order_id, dol.id
                       ) AS line_cumulative
                FROM delivery_order_lines dol
                JOIN delivery_orders d ON d.id = dol.delivery_order_id
                WHERE {$activeDeliveryOrder}
                  AND COALESCE(dol.line_type::text, 'product') = 'product'
                  AND dol.product_id IS NOT NULL
                  {$scope}
            ) x
            LEFT JOIN sales_order_lines sol ON sol.id = x.sales_order_line_id
            {$outerScope}
            SQL;

        return [$sql, $bindings];
    }

    /**
     * Delivery orders shipping each invoice's goods: paired through the
     * pointer / pivot, or delivering the sales-order lines it bills.
     *
     * @param  array<int, int>  $invoiceIds
     * @return array<int, array<int, array{id: int, delivery_order_number: string|null, status: string|null}>>
     */
    private function deliveryOrdersForInvoices(array $invoiceIds): array
    {
        $in = implode(',', array_fill(0, count($invoiceIds), '?'));
        $active = $this->activeDeliveryOrderSql('d');

        $rows = DB::select(<<<SQL
            SELECT DISTINCT link.invoice_id, d.id, d.delivery_order_number, d.status
            FROM (
                SELECT d0.invoice_id, d0.id AS delivery_order_id FROM delivery_orders d0 WHERE d0.invoice_id IN ({$in})
                UNION
                SELECT ido.invoice_id, ido.delivery_order_id FROM invoice_delivery_orders ido WHERE ido.invoice_id IN ({$in})
                UNION
                SELECT il.invoice_id, dol.delivery_order_id
                FROM invoice_lines il
                JOIN delivery_order_lines dol ON dol.sales_order_line_id = il.sales_order_line_id
                WHERE il.invoice_id IN ({$in}) AND il.sales_order_line_id IS NOT NULL
            ) link
            JOIN delivery_orders d ON d.id = link.delivery_order_id
            WHERE {$active}
            ORDER BY link.invoice_id, d.id
            SQL, array_merge($invoiceIds, $invoiceIds, $invoiceIds));

        $links = [];
        foreach ($rows as $row) {
            $links[(int) $row->invoice_id][] = [
                'id' => (int) $row->id,
                'delivery_order_number' => $row->delivery_order_number,
                'status' => $row->status,
            ];
        }

        return $links;
    }

    /**
     * Invoices billing each delivery order: paired through the pointer /
     * pivot, or billing the sales-order lines it delivers.
     *
     * @param  array<int, int>  $deliveryOrderIds
     * @return array<int, array<int, array{id: int, invoice_number: string|null, status: string|null, lifecycle_status: string|null}>>
     */
    private function invoicesForDeliveryOrders(array $deliveryOrderIds): array
    {
        $in = implode(',', array_fill(0, count($deliveryOrderIds), '?'));
        $active = $this->activeInvoiceSql('i');

        $rows = DB::select(<<<SQL
            SELECT DISTINCT link.delivery_order_id, i.id, i.invoice_number, i.status, i.lifecycle_status
            FROM (
                SELECT d0.id AS delivery_order_id, d0.invoice_id FROM delivery_orders d0
                WHERE d0.id IN ({$in}) AND d0.invoice_id IS NOT NULL
                UNION
                SELECT ido.delivery_order_id, ido.invoice_id FROM invoice_delivery_orders ido WHERE ido.delivery_order_id IN ({$in})
                UNION
                SELECT dol.delivery_order_id, il.invoice_id
                FROM delivery_order_lines dol
                JOIN invoice_lines il ON il.sales_order_line_id = dol.sales_order_line_id
                WHERE dol.delivery_order_id IN ({$in}) AND dol.sales_order_line_id IS NOT NULL
            ) link
            JOIN invoices i ON i.id = link.invoice_id
            WHERE {$active}
            ORDER BY link.delivery_order_id, i.id
            SQL, array_merge($deliveryOrderIds, $deliveryOrderIds, $deliveryOrderIds));

        $links = [];
        foreach ($rows as $row) {
            $links[(int) $row->delivery_order_id][] = [
                'id' => (int) $row->id,
                'invoice_number' => $row->invoice_number,
                'status' => $row->status,
                'lifecycle_status' => $row->lifecycle_status,
            ];
        }

        return $links;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
    }
}
