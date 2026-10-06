<?php

namespace Modules\Sales\Services;

use App\Models\JournalEntryLink;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\Sales\Exceptions\CreditLimitExceededException;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesChannelReference;
use Modules\Sales\Models\SalesInvoiceLink;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesOrderStatusHistory;

class SalesOrderService
{
    /**
     * Issue #407: tenant option — when enabled, confirming an order locks it
     * immediately. Off by default so existing tenants keep the amendable
     * confirmation behaviour until they opt in.
     */
    public const AUTO_LOCK_ON_CONFIRM_SETTING = 'sales.orders.auto_lock_on_confirm';

    private ?CreditCheckResult $lastCreditCheck = null;

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

    /**
     * Mirror a sales-order lifecycle event into the document's chatter feed.
     *
     * Sales orders were the one chatter-enabled document type that logged
     * nothing at all, so their History tab was permanently empty. Never
     * throws — a logging failure must not break the order flow.
     */
    public function logChatter(
        SalesOrder $order,
        string $event,
        string $description,
        array $properties = [],
    ): void {
        try {
            app(\App\Services\Chatter\ChatterService::class)->logEvent(
                SalesOrder::class,
                (int) $order->id,
                $event,
                $description,
                $properties,
                Auth::id(),
                Auth::check() ? get_class(Auth::user()) : null,
            );
        } catch (\Throwable $e) {
            Log::warning('SalesOrderService: chatter mirror failed', [
                'sales_order_id' => $order->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
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
            $data['accounting_status'] = $data['accounting_status'] ?? 'not_posted';
            $data['order_date'] = $data['order_date'] ?? now()->toDateString();

            // Issue #553: creation defaults from Sales Settings — default
            // salesperson = current user and the user's default team. Explicit
            // caller values (POS/storefront conversions, quote conversions)
            // always win.
            $data = app(\App\Services\SalesDefaultsService::class)
                ->applyCreationDefaults($data, $actorId);

            // Issue #140: stamp the legal entity from the customer (explicit
            // caller-supplied value wins) so orders are entity-scopable.
            if (empty($data['legal_entity_id']) && ! empty($data['customer_id'])) {
                $data['legal_entity_id'] = \App\Models\Customer::whereKey($data['customer_id'])
                    ->value('legal_entity_id');
            }

            // ── MRD pricelist-multicurrency FR-4.1/FR-5.1 ─────────────────
            // One pricing context per document: currency + FX snapshot from
            // the caller (conversions pass it through), else default = the
            // customer's price-list currency → entity base. price_list_id is
            // transient (audited per line in pricing_trace).
            $pricing = app(\App\Services\PricingService::class);
            $priceListId = isset($data['price_list_id']) && $data['price_list_id'] !== null
                ? (int) $data['price_list_id']
                : null;
            unset($data['price_list_id']);

            $pricingContext = $pricing->documentContext([
                'customer_id' => $data['customer_id'] ?? null,
                'legal_entity_id' => $data['legal_entity_id'] ?? null,
                'date' => $data['order_date'],
                'currency_code' => $data['currency_code'] ?? null,
                'exchange_rate' => $data['exchange_rate'] ?? null,
                'base_currency_code' => $data['base_currency_code'] ?? null,
                'price_list_id' => $priceListId,
            ]);

            $data['currency_code'] = $pricingContext['document_currency'];
            $data['exchange_rate'] = $pricingContext['exchange_rate'];
            $data['base_currency_code'] = $pricingContext['base_currency'];

            $order = SalesOrder::create($data);

            // #900 (PC6): record the initial status on the workflow transition
            // log; the DB default owns the value. Creation must not be a
            // back-door status write outside the engine.
            app(\App\Services\SalesWorkflowService::class)->initialize($order, ['source' => 'create']);

            foreach ($lines as $lineIndex => $lineData) {
                $this->createPricedLine($order, (array) $lineData, (int) $lineIndex, $pricing, $pricingContext, $data['order_date'] ?? null);
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

            $this->logChatter($order, 'created', "Created sales order {$order->sales_order_number}", [
                'status' => $order->status,
                'total' => (float) $order->total,
            ]);

            return $order->fresh(['customer', 'lines.product', 'statusHistory']);
        });
    }

    /**
     * Build, price and persist one order line. This is the single line path
     * shared by order creation and draft line editing (#374), so both apply
     * the same rules: sales-UoM to base conversion (#135/#247/#685),
     * PricingService as the only price authority (MRD FR-4.1/FR-4.2),
     * engine-first tax-inclusive resolution (#303) and the TaxEngineService
     * line arithmetic in SalesOrderLine::calculateLineTotal().
     *
     * @param  array<string, mixed>  $lineData
     * @param  array<string, mixed>  $pricingContext
     */
    private function createPricedLine(
        SalesOrder $order,
        array $lineData,
        int $lineIndex,
        \App\Services\PricingService $pricing,
        array $pricingContext,
        ?string $orderDate,
    ): SalesOrderLine {
        $uom = null;

        $lineProduct = ! empty($lineData['product_id'])
            ? \App\Models\Product::find($lineData['product_id'])
            : null;

        $priceMode = $lineData['price_mode']
            ?? (((float) ($lineData['unit_price'] ?? 0) > 0) ? 'legacy' : 'auto');

        // Issue #135: a line entered in a non-base UOM must be converted
        // to base units before it can reach stock/COGS. The base-unit
        // price is derived from the UOM rate; an explicit unit_price is
        // treated as the per-base-unit price.
        if (! empty($lineData['uom_id']) && ! empty($lineData['product_id'])) {
            $uom = $this->uomConverter->resolve((int) $lineData['product_id'], (int) $lineData['uom_id']);
            $lineData['quantity'] = $this->uomConverter->toBase($uom, (float) ($lineData['quantity'] ?? 0));

            // Issue #247: the persisted quantity is now BASE units — label
            // it with the product's base unit, not the entered UOM (1 Box
            // of 12 was displayed as "12 Box").
            $baseUnitLabel = \App\Models\ProductUom::where('product_id', $lineData['product_id'])
                ->where('is_base_unit', true)
                ->where('is_active', true)
                ->value('abbreviation');
            $lineData['unit'] = $baseUnitLabel
                ?: (\App\Models\Product::whereKey($lineData['product_id'])->value('unit') ?? 'pcs');
        }

        // MRD FR-4.1/FR-4.2: PricingService is the only price authority.
        // 'legacy' mode keeps caller prices verbatim so every existing
        // internal caller (POS/storefront/web conversions, work orders)
        // is byte-compatible; 'auto' resolves list→tier→catalogue;
        // 'manual' runs the override floor guard; 'carried' propagates.
        $priced = $pricing->priceLineArray($lineProduct, $lineData, $pricingContext, $priceMode);

        if (! empty($lineData['uom_id']) && $lineProduct && $priceMode === 'auto' && ($priced['unit_price'] ?? 0) > 0) {
            $uom = $uom ?? $this->uomConverter->resolve((int) $lineData['product_id'], (int) $lineData['uom_id']);
            $priced['unit_price'] = $this->uomConverter->baseUnitPrice($lineProduct, $uom, (float) $priced['unit_price']);
            if (isset($priced['base_unit_price'])) {
                $priced['base_unit_price'] = $this->uomConverter->baseUnitPrice($lineProduct, $uom, (float) $priced['base_unit_price']);
            }
            if (isset($priced['pricing_trace']) && is_array($priced['pricing_trace'])) {
                $priced['pricing_trace']['uom_multiplier_applied'] = true;
            }
        }

        $line = $order->lines()->create(array_merge([
            'legal_entity_id' => $order->legal_entity_id,
            'line_type' => $lineData['line_type'] ?? 'product',
            'sequence' => $lineData['sequence'] ?? $lineIndex,
            'product_id' => $lineData['product_id'] ?? null,
            'model_number' => $lineData['model_number'] ?? null,
            'product_notes' => $lineData['product_notes'] ?? null,
            'variant_id' => $lineData['variant_id'] ?? null,
            'description' => $lineData['description'] ?? '',
            'sku' => $lineData['sku'] ?? null,
            'quantity' => $lineData['quantity'] ?? (($lineData['line_type'] ?? 'product') === 'product' ? 1 : 0),
            'unit' => $lineData['unit'] ?? 'pcs',
            // Issue #685: remember the sales UoM the line was entered
            // in. The persisted quantity remains in stock units (see
            // the #135 conversion above); this is the provenance the
            // invoice and DO read to re-express the line in its sales
            // unit without re-implementing the conversion.
            'uom_id' => $lineData['uom_id'] ?? null,
            'unit_price' => $lineData['unit_price'] ?? 0,
            'list_price' => $lineData['list_price'] ?? null,
            'discount_type' => $lineData['discount_type'] ?? 'percentage',
            'discount_value' => $lineData['discount_value'] ?? 0,
            'discount_amount' => $lineData['discount_amount'] ?? 0,
            'tax_rate' => $lineData['tax_rate'] ?? 0,
            // #303 (X3): an explicit per-line flag wins; otherwise the
            // engine (rule-linked tax / product override / custom mode)
            // resolves it, so an inclusive-priced product is inclusive on
            // every document without the client having to say so.
            'tax_inclusive' => array_key_exists('tax_inclusive', $lineData)
                ? (bool) $lineData['tax_inclusive']
                : ($lineProduct !== null
                    && app(\App\Services\TaxResolutionService::class)->isInclusiveForProduct($lineProduct, null, $orderDate)),
            'tax_amount' => $lineData['tax_amount'] ?? 0,
            'income_account_id' => $lineData['income_account_id'] ?? null,
            // Issue #884 (UX2): the line's analytic dimensions travel
            // quotation → order → invoice alongside the income account.
            'project_id' => $lineData['project_id'] ?? null,
            'department_id' => $lineData['department_id'] ?? null,
            // Issue #151: preserve the line's invoicing policy through
            // any caller that reproduces lines (recurring orders).
            'invoicing_policy' => $lineData['invoicing_policy'] ?? 'ordered_qty',
            'source_line_type' => $lineData['source_line_type'] ?? null,
            'source_line_id' => $lineData['source_line_id'] ?? null,
        ], array_filter([
            'unit_price' => array_key_exists('unit_price', $priced) ? $priced['unit_price'] : null,
            'base_unit_price' => $priced['base_unit_price'] ?? null,
            'pricing_trace' => $priced['pricing_trace'] ?? null,
            'list_price' => $priced['list_price'] ?? null,
        ], fn ($v) => $v !== null)));
        $line->calculateLineTotal();
        $line->save();

        return $line;
    }

    /**
     * Issue #374: replace the lines of a DRAFT order with the submitted set.
     *
     * Every submitted line is rebuilt through createPricedLine() — the same
     * path order creation uses — against the order's own pricing snapshot
     * (currency + exchange rate are not re-derived, same as the quotation
     * draft edit), then the header totals are recalculated. Only drafts are
     * accepted: a confirmed order is amended through amendOrderLines() under
     * the #407 lock/amend rules, every other state is read-only.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function replaceDraftLines(SalesOrder $order, array $lines): SalesOrder
    {
        if (! $order->isDraft()) {
            throw new \DomainException('Only draft orders can have their lines replaced.');
        }

        if ($lines === []) {
            throw new \InvalidArgumentException('A sales order needs at least one line.');
        }

        foreach (array_values($lines) as $index => $lineData) {
            if (trim((string) ($lineData['description'] ?? '')) === '') {
                $position = $index + 1;
                throw new \InvalidArgumentException("A description is required on line {$position}.");
            }
        }

        // A draft that was reset from cancelled may still carry fulfilment
        // history; its lines are referenced by deliveries and must not be
        // deleted and re-created.
        if ($order->deliveryOrders()->exists() || $order->invoices()->exists()) {
            throw new \DomainException('This draft order already has deliveries or invoices; its lines cannot be replaced.');
        }

        return DB::transaction(function () use ($order, $lines) {
            $pricing = app(\App\Services\PricingService::class);
            $pricingContext = $pricing->documentContext([
                'customer_id' => $order->customer_id,
                'legal_entity_id' => $order->legal_entity_id,
                'date' => $order->order_date?->toDateString(),
                'currency_code' => $order->currency_code,
                'exchange_rate' => $order->exchange_rate,
                'base_currency_code' => $order->base_currency_code,
            ]);

            $order->lines()->delete();

            foreach (array_values($lines) as $lineIndex => $lineData) {
                $lineData = (array) $lineData;
                // Ids address existing lines only on the confirmed-order
                // amendment path; a draft edit is a full replacement.
                unset($lineData['id']);

                $this->createPricedLine(
                    $order,
                    $lineData,
                    $lineIndex,
                    $pricing,
                    $pricingContext,
                    $order->order_date?->toDateString(),
                );
            }

            $order->refresh();
            $order->calculateTotals();

            return $order;
        });
    }

    /**
     * Issue #383: the credit verdict the most recent confirmOrder() call
     * gated on (null when the order has no customer). The controller returns
     * this instead of running a second, differently-based check.
     */
    public function lastCreditCheck(): ?CreditCheckResult
    {
        return $this->lastCreditCheck;
    }

    public function confirmOrder(SalesOrder $order): SalesOrder
    {
        $this->lastCreditCheck = null;

        if (! $order->isDraft()) {
            throw new \Exception('Only draft orders can be confirmed.');
        }

        // Issue #139: the credit verdict must gate confirmation.
        // Issue #240: include the customer's other confirmed-but-uninvoiced
        // orders (they are not in total_outstanding yet) and take a row lock on
        // the customer so concurrent confirmations cannot both pass the check.
        return DB::transaction(function () use ($order) {
            if ($order->customer_id !== null) {
                $customer = \App\Models\Customer::whereKey($order->customer_id)->lockForUpdate()->first();

                if ($customer !== null) {
                    // Issue #384: every open order counts with its UNINVOICED
                    // REMAINDER (an order with one partial invoice used to drop
                    // out entirely), and all amounts are in base currency.
                    $committedOrdersTotal = SalesOrder::query()
                        ->where('customer_id', $customer->id)
                        ->whereKeyNot($order->id)
                        ->whereNotIn('status', ['draft', 'cancelled', 'completed'])
                        ->get()
                        ->sum(fn (SalesOrder $open) => $this->baseCurrencyAmount($open, $this->uninvoicedRemainder($open)));

                    $creditCheck = $this->creditCheckService->checkCreditLimit(
                        $customer,
                        $this->baseCurrencyAmount($order, (float) $order->total),
                        (float) $committedOrdersTotal
                    );
                    $this->lastCreditCheck = $creditCheck;

                    if (! $creditCheck->approved) {
                        // Issue #903: typed so the route boundary can notify the
                        // account owner after this transaction has rolled back.
                        throw new CreditLimitExceededException($creditCheck, $customer, $order);
                    }
                }
            }

            $order = $this->lifecycleService->confirm($order, 'Order confirmed');

            $this->freezePricing($order);

            // Reserve stock for stock-tracked lines that have a warehouse
            $this->reserveStockForOrder($order);

            // Issue #407: tenant option — lock the order as part of
            // confirmation. Audited with metadata.auto = true.
            if (self::autoLockOnConfirmEnabled()) {
                $order = $this->lockOrder($order, true);
            }

            // #377 (O8): log BEFORE returning — this call used to sit after
            // the return inside the closure and never ran, so confirmed orders
            // had no chatter entry.
            $this->logChatter($order, 'status_changed', "Confirmed sales order {$order->sales_order_number}", ['to_state' => 'confirmed']);

            return $order->fresh(['customer', 'lines.product', 'statusHistory']);

        });
    }

    /**
     * Issue #384: the part of an order not yet invoiced into AR. Invoices
     * that reached AR (or were paid) are in the customer's outstanding
     * balance; draft invoices are not, so they stay in the order's exposure.
     * Cancelled invoices reverse their link and drop out of the sum.
     */
    private function uninvoicedRemainder(SalesOrder $order): float
    {
        $invoiced = (float) \Modules\Sales\Models\SalesInvoiceLink::query()
            ->where('sales_order_id', $order->id)
            ->where('link_state', 'active')
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', \App\Models\Customer::PURCHASE_INVOICE_STATUSES))
            ->sum('invoiced_value');

        return max(0.0, round((float) $order->total - $invoiced, 2));
    }

    /**
     * Issue #384: express a document-currency amount in the order's base
     * currency. The order's rate snapshot was taken through the platform
     * ExchangeRateService when the order was priced (same convention as
     * SalesApprovalGate, #649); an order without a usable snapshot is
     * converted by ExchangeRateService at the order date. No rate → the
     * service throws and confirmation fails rather than guessing.
     */
    private function baseCurrencyAmount(SalesOrder $order, float $amount): float
    {
        $base = app(\App\Services\SalesApprovalGate::class)->baseCurrencyAmount($order, $amount);

        if ($base !== null) {
            return $base;
        }

        return (float) app(\App\Services\ExchangeRateService::class)->convert(
            $amount,
            strtoupper((string) $order->currency_code),
            strtoupper((string) $order->base_currency_code),
            $order->order_date?->toDateString(),
        )['converted_amount'];
    }

    /**
     * Issue #407: is the tenant's auto-lock-on-confirm option enabled?
     */
    public static function autoLockOnConfirmEnabled(): bool
    {
        return (bool) \App\Models\Setting::getValue(self::AUTO_LOCK_ON_CONFIRM_SETTING, false);
    }

    /**
     * Issue #407: lock a confirmed order against amendments. `$auto` records
     * that the tenant option applied it during confirmation (surfaced in the
     * audit metadata); a user-initiated lock passes false.
     */
    public function lockOrder(SalesOrder $order, bool $auto = false): SalesOrder
    {
        if (! $order->isConfirmed()) {
            throw new \RuntimeException('Only confirmed orders can be locked.');
        }

        if ($order->isLocked()) {
            throw new \RuntimeException('This sales order is already locked.');
        }

        return $this->setLockState($order, true, $auto);
    }

    /**
     * Issue #407: unlock an order so its header/lines can be amended again.
     */
    public function unlockOrder(SalesOrder $order): SalesOrder
    {
        if (! $order->isLocked()) {
            throw new \RuntimeException('This sales order is not locked.');
        }

        return $this->setLockState($order, false, false);
    }

    private function setLockState(SalesOrder $order, bool $locked, bool $auto): SalesOrder
    {
        return DB::transaction(function () use ($order, $locked, $auto) {
            $beforeState = ['is_locked' => $order->isLocked()];

            $order->forceFill([
                'is_locked' => $locked,
                'locked_at' => $locked ? now() : null,
                'locked_by' => $locked ? TenantUserHelper::resolveTenantUserId() : null,
            ])->save();

            $this->writeAuditLog(
                $locked ? 'sales_order_locked' : 'sales_order_unlocked',
                (int) $order->id,
                $beforeState,
                ['is_locked' => $locked],
                [
                    'sales_order_number' => $order->sales_order_number,
                    'auto' => $auto,
                ],
            );

            $this->logChatter(
                $order,
                $locked ? 'locked' : 'unlocked',
                $locked
                    ? "Locked sales order {$order->sales_order_number}"
                    : "Unlocked sales order {$order->sales_order_number}",
                ['auto' => $auto],
            );

            return $order->fresh(['customer', 'lines.product', 'statusHistory']);
        });
    }

    /**
     * Issue #407: apply an amendment to an unlocked confirmed order. Lines
     * with an `id` are updated in place (delivered/invoiced quantities are
     * preserved — only the ordered quantity and the descriptive/pricing fields
     * change); lines without an `id` are added. The delivery and invoice
     * remainders follow automatically because both read the ordered quantity
     * from the line.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return SalesOrderLine[] the lines created by this amendment
     */
    public function amendOrderLines(SalesOrder $order, array $lines): array
    {
        $order->loadMissing('lines');

        $created = [];
        $nextSequence = (int) $order->lines->max('sequence') + 1;

        foreach ($lines as $lineData) {
            $lineData = (array) $lineData;
            $lineId = isset($lineData['id']) ? (int) $lineData['id'] : null;

            if ($lineId !== null) {
                $line = $order->lines->firstWhere('id', $lineId);

                if ($line === null) {
                    throw new \RuntimeException("Sales order line {$lineId} does not belong to this order.");
                }

                $this->applyLineAmendment($line, $lineData);

                continue;
            }

            $line = $order->lines()->create($this->amendmentLineAttributes(
                $order,
                $lineData,
                array_key_exists('sequence', $lineData) ? (int) $lineData['sequence'] : $nextSequence++,
            ));
            $line->calculateLineTotal();
            $line->save();

            $created[] = $line;
        }

        // A new stock-tracked line joins the order's reservations the same way
        // confirmation does, through the shared reservation service.
        if ($created !== []) {
            $this->reserveStockForLines($order, $created);
        }

        return $created;
    }

    /**
     * Update one existing order line in place. Reducing the ordered quantity
     * below what was already delivered/invoiced is refused — an amendment must
     * not turn a delivered line into an over-delivery.
     */
    private function applyLineAmendment(SalesOrderLine $line, array $lineData): void
    {
        if (
            array_key_exists('product_id', $lineData)
            && (int) $lineData['product_id'] !== (int) $line->product_id
            && ((float) $line->quantity_delivered > 0.0001 || (float) $line->quantity_invoiced > 0.0001)
        ) {
            throw new \RuntimeException(
                "Line #{$line->id} already has delivered or invoiced quantity and its product cannot change."
            );
        }

        if (array_key_exists('quantity', $lineData)) {
            $newQuantity = (float) $lineData['quantity'];
            $committed = max((float) $line->quantity_delivered, (float) $line->quantity_invoiced);

            if ($newQuantity + 0.0001 < $committed) {
                throw new \RuntimeException(
                    "Line #{$line->id} cannot be reduced to {$newQuantity} — {$committed} units are already delivered or invoiced."
                );
            }
        }

        $updates = array_intersect_key($lineData, array_flip([
            'line_type', 'sequence', 'product_id', 'variant_id', 'description',
            'product_notes', 'sku', 'model_number', 'quantity', 'unit', 'uom_id',
            'unit_price', 'discount_type', 'discount_value', 'discount_amount',
            'tax_rate', 'tax_inclusive', 'income_account_id', 'invoicing_policy',
        ]));

        if ($updates === []) {
            return;
        }

        $line->fill($updates);
        $line->calculateLineTotal();
        $line->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function amendmentLineAttributes(SalesOrder $order, array $lineData, int $sequence): array
    {
        $lineType = $lineData['line_type'] ?? 'product';
        $product = ($lineType === 'product' && ! empty($lineData['product_id']))
            ? \App\Models\Product::find($lineData['product_id'])
            : null;

        if (trim((string) ($lineData['description'] ?? '')) === '') {
            throw new \RuntimeException('A description is required for a new sales order line.');
        }

        return [
            'legal_entity_id' => $order->legal_entity_id,
            'line_type' => $lineType,
            'sequence' => $sequence,
            'product_id' => $lineData['product_id'] ?? null,
            'model_number' => $lineData['model_number'] ?? null,
            'product_notes' => $lineData['product_notes'] ?? null,
            'variant_id' => $lineData['variant_id'] ?? null,
            'description' => $lineData['description'] ?? '',
            'sku' => $lineData['sku'] ?? $product?->sku,
            'quantity' => $lineData['quantity'] ?? ($lineType === 'product' ? 1 : 0),
            'unit' => $lineData['unit'] ?? $product?->unit ?? 'pcs',
            'uom_id' => $lineData['uom_id'] ?? null,
            'unit_price' => $lineData['unit_price'] ?? 0,
            'discount_type' => $lineData['discount_type'] ?? 'percentage',
            'discount_value' => $lineData['discount_value'] ?? 0,
            'discount_amount' => $lineData['discount_amount'] ?? 0,
            'tax_rate' => $lineData['tax_rate'] ?? 0,
            // #303 (X3): an explicit flag wins; otherwise the engine resolves
            // it from the product exactly like the create path.
            'tax_inclusive' => array_key_exists('tax_inclusive', $lineData)
                ? (bool) $lineData['tax_inclusive']
                : ($product !== null
                    && app(\App\Services\TaxResolutionService::class)->isInclusiveForProduct(
                        $product,
                        null,
                        $order->order_date?->toDateString(),
                    )),
            'income_account_id' => $lineData['income_account_id'] ?? null,
            'invoicing_policy' => $lineData['invoicing_policy'] ?? 'ordered_qty',
            'backorder_quantity' => 0,
        ];
    }

    /**
     * Issue #895 (SALES-PLAT / PC1): append a tamper-evident audit row for a
     * sales-order lifecycle transition. Deliberately uncaught — an audit
     * failure surfaces as an error instead of silently succeeding.
     */
    public function writeAuditLog(
        string $operation,
        int $orderId,
        ?array $beforeState = null,
        ?array $afterState = null,
        array $metadata = [],
    ): void {
        app(\App\Services\AuditLogger::class)->log(
            operation: $operation,
            referenceType: 'sales_order',
            referenceId: $orderId,
            beforeState: $beforeState,
            afterState: $afterState,
            metadata: $metadata + [
                'request_id' => request()?->header(\App\Http\Middleware\CorrelationIdMiddleware::HEADER),
            ],
        );
    }

    public function allocateOrder(SalesOrder $order): SalesOrder
    {
        if (! $order->isConfirmed()) {
            throw new \Exception('Only confirmed orders can be allocated.');
        }

        return DB::transaction(function () use ($order) {
            $order->loadMissing('lines.product');

            $stockTracked = $this->hasStockTrackedLines($order);
            if ($stockTracked) {
                $this->requireCommitmentWarehouseId($order, 'allocate');
            }

            // Issue #380: allocation reflects real reserved / available stock
            // per line (partial allocation supported) instead of copying the
            // ordered quantity.
            $allocations = [];
            $stockAllocated = 0.0;
            foreach ($order->lines as $line) {
                if (! $line->product_id) {
                    continue;
                }

                if (! (bool) ($line->product?->track_inventory ?? false)) {
                    // Nothing to check a non-stock line against.
                    $allocations[$line->id] = [$line, (float) $line->quantity];

                    continue;
                }

                $allocated = $this->allocateStockForLine($order, $line);
                $allocations[$line->id] = [$line, $allocated];
                $stockAllocated += $allocated;
            }

            if ($stockTracked && $stockAllocated <= 0.0) {
                throw new \RuntimeException(
                    "Cannot allocate sales order {$order->sales_order_number}: no stock is available in the order's warehouse."
                );
            }

            $order = $this->lifecycleService->allocate($order, 'Stock allocated');

            foreach ($allocations as [$line, $allocated]) {
                $attributes = ['quantity_allocated' => $allocated];
                if ((bool) ($line->product?->track_inventory ?? false)) {
                    $attributes['backorder_quantity'] = max(0.0, (float) $line->quantity - $allocated);
                }
                $line->update($attributes);
            }

            // #377 (O8): log before returning (see confirmOrder).
            $this->logChatter($order, 'status_changed', "Allocated stock for sales order {$order->sales_order_number}", ['to_state' => 'allocated']);

            return $order->fresh(['customer', 'lines.product', 'statusHistory']);

        });
    }

    /**
     * #373 (O4): no production HTTP route or automated flow may call this —
     * fulfilment is derived from delivery-order completion (see
     * DeliveryOrderService::completeDeliveryOrder). The method remains as the
     * state-transition primitive used by lifecycle tests.
     */
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

            // #377 (O8): log before returning (see confirmOrder).
            $this->logChatter($order, 'status_changed', "Fulfilled sales order {$order->sales_order_number}", ['to_state' => 'fulfilled']);

            return $order->fresh(['customer', 'lines.product', 'statusHistory']);

        });
    }

    /**
     * Issue #380: the quantity a stock-tracked line can really be allocated —
     * its active reservation, or (when confirmation could not reserve it, a
     * #379 backorder) a fresh reservation of whatever is available now, up
     * to the ordered quantity. Returns 0 when nothing is available.
     */
    private function allocateStockForLine(SalesOrder $order, SalesOrderLine $line): float
    {
        $reservations = \Modules\Inventory\Models\StockReservation::where([
            'source_document_type' => 'SalesOrder',
            'source_document_id' => $order->id,
            'source_document_line_id' => $line->id,
        ])->whereIn('status', ['open', 'allocated'])->get();

        if ($reservations->isNotEmpty()) {
            return min((float) $line->quantity, (float) $reservations->sum('quantity'));
        }

        $warehouseId = (int) $order->warehouse_id;
        $stockLevel = \Modules\Inventory\Models\StockLevel::where('product_id', $line->product_id)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        $available = $stockLevel
            ? max(0.0, (float) $stockLevel->quantity - (float) $stockLevel->reserved_quantity)
            : 0.0;
        $quantity = round(min((float) $line->quantity, $available), 4);

        if ($quantity <= 0.0) {
            return 0.0;
        }

        $reservation = $this->reservationService->reserve(
            productId: (int) $line->product_id,
            warehouseId: $warehouseId,
            quantity: $quantity,
            sourceDocumentType: 'SalesOrder',
            sourceDocumentId: $order->id,
            sourceDocumentLineId: $line->id,
            notes: "Allocated for sales order {$order->sales_order_number}",
        );

        return (float) $reservation->quantity;
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

            // Issue #321: the order's master payment term (carried from the
            // quotation) sets the due date; without one the customer's day
            // count applies as before.
            $invoiceDate = now()->toDateString();
            $paymentTerm = $order->payment_term_id
                ? \App\Models\PaymentTerm::query()->find($order->payment_term_id)
                : null;
            $dueDate = $paymentTerm
                ? $paymentTerm->calculateDueDate($invoiceDate)->toDateString()
                // #539: the tenant's default terms only when the customer has none.
                : now()->addDays(
                    $order->customer->payment_terms ?? app(\App\Services\SalesSettings::class)->defaultPaymentTermsDays()
                )->toDateString();

            $invoiceData = [
                'customer_id' => $order->customer_id,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'payment_term_id' => $paymentTerm?->id,
                // #539: the tenant's default invoice notes lead, the order
                // reference stays for traceability.
                'notes' => implode("\n", array_filter([
                    app(\App\Services\SalesSettings::class)->defaultNotes(),
                    "Sales Order #{$order->sales_order_number}",
                ])),
                'sales_order_id' => $order->id,
                // Carry the order's references into the invoice for end-to-end
                // tracing (Customer Ref. / PO / Internal Ref.).
                'customer_reference' => $order->customer_reference,
                'internal_reference' => $order->internal_reference,
                'po_number' => $order->po_number,
                // Issue #333: the order's Incoterm (code + place) is part of
                // the agreed trade term — it travels onto every invoice.
                'incoterm_code' => $order->incoterm_code,
                'incoterm_place' => $order->incoterm_place,
                // MRD FR-5.5: currency + rate snapshot propagate unchanged.
                'currency_code' => $order->currency_code,
                'exchange_rate' => $order->exchange_rate,
                'base_currency_code' => $order->base_currency_code,
            ];

            // ── Build invoice lines using per-line invoicing_policy ─────────
            // invoiceable_qty =
            //   ORDERED_QTY:  max(ordered - invoiced, 0)
            //   DELIVERED_QTY: max(delivered - invoiced, 0)
            $lines = [];
            $totalInvoiceableQty = 0;
            // #291: pro-rate the order's header discount by the invoiced share
            // (net, before tax) so a partial invoice carries its fair slice and
            // a full invoice carries all of it.
            $orderNet = 0.0;
            $invoicedNet = 0.0;

            foreach ($order->lines as $line) {
                if (in_array($line->line_type ?? 'product', ['note', 'section'], true)) {
                    $lines[] = [
                        'line_type' => $line->line_type,
                        'sequence' => $line->sequence ?? 0,
                        'sales_order_line_id' => $line->id,
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

                // Net of the line's own discount, before tax and before the
                // header discount — the base the header discount is shared over.
                $orderNet += $ordered * (float) $line->unit_price - (float) $line->discount_amount;

                $invoiceableQty = $policy === 'delivered_qty'
                    ? max($delivered - $invoiced, 0)
                    : max($ordered - $invoiced, 0);

                if ($invoiceableQty <= 0) {
                    continue;
                }

                $totalInvoiceableQty += $invoiceableQty;
                $lineDiscountShare = $ordered > 0 ? (float) $line->discount_amount * ($invoiceableQty / $ordered) : 0.0;
                $invoicedNet += $invoiceableQty * (float) $line->unit_price - $lineDiscountShare;

                [$invoiceQuantity, $invoiceUnitPrice, $description, $invoiceBaseUnitPrice] = $this->normalizeInvoiceLineWithQty(
                    $line,
                    $invoiceableQty,
                );

                // Issue #685: the order line stores base quantities, but the
                // invoice is a customer-facing sales document — when the line
                // was entered in a sales UoM, bill it in that UoM (2 Box) with
                // a per-sales-UoM price. The conversion is the shared
                // ProductUomConverter, so the totals are unchanged:
                // (qty / factor) × (price × factor) = qty × price.
                $lineUom = ($line->uom_id && $line->product_id)
                    ? $this->uomConverter->resolveOrNull((int) $line->product_id, (int) $line->uom_id)
                    : null;

                if ($lineUom && ! $lineUom->isStockUnit()) {
                    $invoiceQuantity = $this->uomConverter->fromStock($lineUom, (float) $invoiceQuantity);
                    $invoiceUnitPrice = $this->uomConverter->basePriceToSales($lineUom, (float) $invoiceUnitPrice);
                }

                $lines[] = [
                    'line_type' => 'product',
                    'sequence' => $line->sequence ?? 0,
                    // Issue #138: stable order-line reference for bookkeeping.
                    'sales_order_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'model_number' => $line->model_number,
                    'product_notes' => $line->product_notes,
                    'description' => $description,
                    'quantity' => $invoiceQuantity,
                    // Issue #685: the sales UoM travels onto the invoice so the
                    // document says "2 BX" and the e-invoice mapper can read
                    // the UoM's UN/ECE code (#665).
                    'uom_id' => $lineUom?->id,
                    'unit' => $lineUom?->abbreviation,
                    'unit_price' => $invoiceUnitPrice,
                    // FR-5.3/5.5: provenance travels with the price.
                    'base_unit_price' => $invoiceBaseUnitPrice,
                    'pricing_trace' => $line->pricing_trace,
                    'tax_rate' => $line->tax_rate,
                    // #303 (X3): the order line's inclusive mode is part of the
                    // carried price — the invoice must book the same gross.
                    'tax_inclusive' => (bool) $line->tax_inclusive,
                    'discount_amount' => $line->discount_amount,
                    // Issue #884 (UX2): the order line's income account and
                    // analytic dimensions land on the invoice line, so the
                    // invoice records the override it will post with.
                    'gl_account_id' => $line->income_account_id,
                    'project_id' => $line->project_id,
                    'department_id' => $line->department_id,
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

            // #291: attach the header discount for this invoice — the full
            // amount for a full invoice, otherwise its share of the invoiced
            // net. Invoice::calculateTotals then applies it before tax.
            $orderDiscount = (float) ($order->discount_amount ?? 0);
            if ($orderDiscount > 0 && $orderNet > 0) {
                $ratio = min(max($invoicedNet / $orderNet, 0), 1);
                $invoiceData['discount_amount'] = round($orderDiscount * $ratio, 2);
            }

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
                // #371 (O2): the link records THIS invoice's actual value —
                // the order total overstated every partial invoice.
                'invoiced_value' => (float) $invoice->total,
                'created_by' => TenantUserHelper::requireTenantUserId($order->created_by),
            ]);

            if ($invoice->sales_order_id !== $order->id) {
                $invoice->update(['sales_order_id' => $order->id]);
            }

            $this->synchronizeInvoiceBookkeeping($order->fresh('lines'), $invoice->fresh('lines'));

            // #455 (DO3): the same goods must not stay billable on the linked
            // delivery orders after the order itself invoiced them.
            $this->synchronizeDeliveryOrderInvoicedQuantities($order, $invoice->fresh('lines'));

            // #371 (O2): only a fully invoiced order locks as `invoiced`.
            // A partial invoice leaves the lifecycle state alone so the
            // remainder can still be delivered, invoiced, cancelled or closed.
            $order = $order->fresh('lines');
            if ($order->isFullyInvoiced()) {
                $this->lifecycleService->markInvoiced($order, 'Invoice created');
            }

            $this->logChatter($order, 'status_changed', "Raised invoice {$invoice->invoice_number} from sales order {$order->sales_order_number}", [
                'to_state' => $order->fresh()->status,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);

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
            // Issue #154: point at the real, reachable path
            // (POST /sales/orders/{id}/credit-note).
            throw new \Exception('Invoiced orders cannot be cancelled. Issue a credit note from the order instead.');
        }

        return DB::transaction(function () use ($order, $reason) {
            // Release all stock reservations for this order
            $this->releaseStockReservations($order);

            $order = $this->lifecycleService->cancel($order, $reason);

            foreach ($order->lines as $line) {
                // #371 (O2): cancel the remainder only — quantity already
                // delivered OR invoiced stays uncancelled (a cancelled
                // invoice quantity would corrupt the credit-note basis).
                $line->update([
                    'quantity_cancelled' => max(
                        0,
                        (float) $line->quantity - max((float) $line->quantity_fulfilled, (float) $line->quantity_invoiced)
                    ),
                ]);
            }

            // Issue #152 (hostile re-review): a release order from a blanket
            // agreement consumed committed quantity. Cancelling it must give
            // the commitment back, otherwise cancelled releases permanently
            // burn the agreement's remaining quantity.
            $this->restoreBlanketCommitments($order);

            $this->logChatter($order, 'cancelled', "Cancelled sales order {$order->sales_order_number}", [
                'reason' => $reason,
            ]);

            return $order->fresh(['customer', 'lines.product', 'statusHistory']);
        });
    }

    /**
     * Issue #152: decrement the blanket lines a cancelled release order
     * consumed, then clear the consumption ledger so a reset-and-release of
     * the same order can record again.
     */
    private function restoreBlanketCommitments(SalesOrder $order): void
    {
        // Some tenants have not installed the optional blanket-order release
        // ledger yet. The model class existing does not guarantee its tenant
        // table exists, and a normal Sales Order cancellation must not fail
        // merely because this optional feature is unavailable.
        if (
            ! class_exists(\Modules\Sales\Models\SalesBlanketOrderRelease::class)
            || ! Schema::hasTable('sales_blanket_order_releases')
        ) {
            return;
        }

        $releases = \Modules\Sales\Models\SalesBlanketOrderRelease::where('sales_order_id', $order->id)
            ->lockForUpdate()
            ->get();

        foreach ($releases as $release) {
            $line = \Modules\Sales\Models\SalesBlanketOrderLine::whereKey($release->blanket_order_line_id)
                ->lockForUpdate()
                ->first();

            if ($line !== null) {
                $line->update([
                    'released_qty' => max(0, round((float) $line->released_qty - (float) $release->quantity, 4)),
                ]);
            }
        }

        \Modules\Sales\Models\SalesBlanketOrderRelease::where('sales_order_id', $order->id)->delete();
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
                'price_mode' => 'carried',
                'tax_rate' => $item->tax_rate ?? 0,
                'tax_amount' => $item->tax_amount ?? 0,
                // #303 (X3): pos_order_items does not store the flag; resolve
                // it with the same engine call PosOrderController used at sale
                // time so the mirrored line keeps the inclusive price.
                'tax_inclusive' => $item->product !== null
                    && app(\App\Services\TaxResolutionService::class)->isInclusiveForProduct(
                        $item->product,
                        $item->variant,
                        $posOrder->created_at?->toDateString(),
                    ),
                'discount_amount' => 0,
                'source_line_type' => 'PosOrderItem',
                'source_line_id' => $item->id,
            ];
        }

        $order = $this->createOrder([
            'customer_id' => $posOrder->customer_id,
            'channel' => 'pos',
            // FR-5.5/FR-9.1: conversions copy the POS order's currency and
            // snapshot (captured at sale time) and NEVER re-price.
            'currency_code' => $posOrder->currency ?: \App\Services\DocumentCurrencyResolver::baseFor(null, $posOrder->customer_id),
            'exchange_rate' => (float) ($posOrder->exchange_rate ?? 1.0),
            'base_currency_code' => $posOrder->base_currency_code ?: null,
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

    /**
     * Canonical SalesOrder creation for paid Storefront module orders
     * (FR-ORD-03). Idempotent via SalesChannelReference(channel=storefront);
     * same shape as the web-order sibling — Storefront never builds parallel
     * sales-order plumbing (SOP §4).
     */
    public function createFromStorefrontOrder(\Modules\Storefront\Models\StorefrontOrder $sfOrder): SalesOrder
    {
        $existing = SalesChannelReference::where('channel', 'storefront')
            ->where('external_id', (string) $sfOrder->id)
            ->first();

        if ($existing) {
            return $existing->salesOrder;
        }

        $lines = [];
        foreach ($sfOrder->items as $item) {
            $lines[] = [
                'product_id' => $item->product_id,
                'description' => $item->name,
                'sku' => $item->sku,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'price_mode' => 'carried',
                'tax_rate' => (float) $item->tax_rate,
                'tax_amount' => (float) $item->tax_amount,
                'discount_amount' => (float) $item->discount_amount,
                'income_account_id' => $item->income_account_id,
                'source_line_type' => 'StorefrontOrderItem',
                'source_line_id' => $item->id,
            ];
        }

        // MRD FR-9.2/FR-5.2: the storefront order currency is the visitor
        // currency; checkout stores the REAL snapshot rate at the order date
        // so the sales order (and any invoice) carries a ledger-consistent
        // base equivalent. Missing rate → fail closed.
        $storefrontCurrency = strtoupper((string) ($sfOrder->currency
            ?: \App\Services\DocumentCurrencyResolver::baseFor($sfOrder->legal_entity_id, $sfOrder->customer_id)));
        $storefrontSnapshot = \App\Services\DocumentCurrencyResolver::snapshot(
            $storefrontCurrency,
            $sfOrder->customer_id,
            $sfOrder->legal_entity_id,
            ($sfOrder->placed_at ?? now())->toDateString(),
        );

        return $this->createOrder([
            'customer_id' => $sfOrder->customer_id,
            'channel' => 'storefront',
            // FR-5.5/FR-9.2: carried conversion with the checkout snapshot.
            'currency_code' => $storefrontSnapshot['currency_code'],
            'exchange_rate' => $storefrontSnapshot['exchange_rate'],
            'base_currency_code' => $storefrontSnapshot['base_currency_code'],
            'warehouse_id' => $sfOrder->fulfillment_warehouse_id,
            'accounting_status' => $this->resolveSourceAccountingStatus('StorefrontOrder', $sfOrder->id),
            'source_document_type' => 'StorefrontOrder',
            'source_document_id' => $sfOrder->id,
            'legal_entity_id' => $sfOrder->legal_entity_id,
            'currency' => $sfOrder->currency,
            'order_date' => ($sfOrder->placed_at ?? now())->toDateString(),
            'notes' => "Storefront Order #{$sfOrder->order_number}",
            'channel_reference' => [
                'channel' => 'storefront',
                'external_id' => (string) $sfOrder->id,
                'external_number' => $sfOrder->order_number,
                'raw_document_ids' => ['storefront_order_id' => $sfOrder->id],
            ],
            'lines' => $lines,
        ]);
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
                'price_mode' => 'carried',
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
            // FR-5.5: carried conversion at rate 1 (base-denominated today).
            'currency_code' => \App\Services\DocumentCurrencyResolver::baseFor(null, $webOrder->customer_id),
            'exchange_rate' => 1.000000,
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
        $orderDate = now()->toDateString();

        // #306 (X6): these lines used to be booked at a hard-coded 0%, so
        // service tax / GST was never charged on the resulting invoice.
        // Labour is a service and resolves through the shared engine as the
        // pack's service tax type (MY SST service rules; single-type packs
        // like SG stay unfiltered); the fallback job line is a service too.
        $labourTax = $this->resolveWorkOrderLineTax(true, null, $orderDate);

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
                'tax_rate' => $labourTax['rate'],
                'tax_inclusive' => $labourTax['tax_inclusive'],
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
            $partTax = $this->resolveWorkOrderLineTax(false, $part->product, $orderDate);

            $lines[] = [
                'product_id' => $part->product_id,
                'description' => $description,
                'quantity' => (float) $part->quantity,
                'unit' => 'pcs',
                'unit_price' => (float) $part->unit_price,
                'tax_rate' => $partTax['rate'],
                'tax_inclusive' => $partTax['tax_inclusive'],
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
                'tax_rate' => $labourTax['rate'],
                'tax_inclusive' => $labourTax['tax_inclusive'],
                'discount_amount' => 0,
            ];
        }

        $order = $this->createOrder([
            'customer_id' => $workOrder->customer_id,
            'channel' => 'admin',
            'created_by' => $workOrder->created_by,
            'order_date' => $orderDate,
            'source_document_type' => 'WorkOrder',
            'source_document_id' => $workOrder->id,
            'notes' => "Generated from Work Order {$workOrder->reference}: {$workOrder->title}",
            'lines' => $lines,
        ]);

        $workOrder->forceFill(['sales_order_id' => $order->id])->saveQuietly();

        return $order;
    }

    /**
     * #306 (X6): resolve one Field Service line's tax on the shared engine.
     * A part resolves from its product through TaxResolutionService (the
     * canonical product resolver); labour and the fallback job line have no
     * product and resolve as the country pack's declared service tax type —
     * MY maps 'service' to its SST service rules, packs without a line-type
     * map (SG, single GST type) stay unfiltered. No rate is ever hard-coded.
     *
     * @return array{rate: float, tax_inclusive: bool}
     */
    private function resolveWorkOrderLineTax(bool $isService, ?\App\Models\Product $product, string $orderDate): array
    {
        $engine = app(\App\Services\TaxEngineService::class);
        $jurisdiction = $engine->tenantJurisdiction();

        if (! $isService && $product !== null) {
            $resolved = app(\App\Services\TaxResolutionService::class)->resolveTaxForProduct(
                $product,
                null,
                $jurisdiction,
                'output',
                $orderDate,
            );
        } else {
            $serviceTaxType = $isService
                ? app(\App\Services\TaxCountryPackService::class)->lineTaxType($jurisdiction, 'service')
                : null;

            $resolved = $engine->resolve(null, $jurisdiction, 'output', $serviceTaxType, $orderDate);
        }

        return [
            'rate' => (float) ($resolved['rate'] ?? 0),
            'tax_inclusive' => (bool) ($resolved['is_inclusive'] ?? false),
        ];
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
            // Issue #138: prefer the stable persisted link; only fall back to
            // the legacy product+description heuristic for pre-link rows.
            $matchedInvoiceLine = $invoiceLines->firstWhere('sales_order_line_id', $orderLine->id);

            $matchedInvoiceLine = $matchedInvoiceLine ?? $invoiceLines->first(function ($invoiceLine) use ($orderLine) {
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

            // Lines that are not part of this invoice (already billed earlier,
            // or billed later) keep their running invoiced quantity.
            if (! $matchedInvoiceLine) {
                continue;
            }

            // Accumulate what this invoice actually billed. Stamping the full
            // ordered/delivered quantity here blocked staged invoicing: the
            // second invoice saw the line as fully invoiced and refused to bill
            // the remainder.
            //
            // Issue #685: the order line's quantity is in stock units, but the
            // invoice line may be denominated in a sales UoM (2 Box). Convert
            // before accumulating so bookkeeping never compares Box with pcs.
            $invoicedStockQty = (float) $matchedInvoiceLine->quantity;
            if ($matchedInvoiceLine->uom_id && $orderLine->product_id) {
                $invoiceLineUom = $this->uomConverter->resolveOrNull(
                    (int) $orderLine->product_id,
                    (int) $matchedInvoiceLine->uom_id,
                );
                if ($invoiceLineUom && ! $invoiceLineUom->isStockUnit()) {
                    $invoicedStockQty = $this->uomConverter->toStock($invoiceLineUom, $invoicedStockQty);
                }
            }

            $orderLine->update([
                'quantity_invoiced' => (float) $orderLine->quantity_invoiced + $invoicedStockQty,
            ]);
        }
    }

    /**
     * #455 (DO3): consume the linked delivery orders' billable quantities for
     * every order line this invoice billed, so a later delivery-order invoice
     * cannot bill the same goods a second time. Multiple DOs are served in
     * order, each capped at its own remaining billable quantity.
     */
    private function synchronizeDeliveryOrderInvoicedQuantities(SalesOrder $order, \App\Models\Invoice $invoice): void
    {
        foreach ($invoice->lines as $invoiceLine) {
            if (! $invoiceLine->sales_order_line_id) {
                continue;
            }

            $remaining = (float) $invoiceLine->quantity;

            if ($remaining <= 0.0001) {
                continue;
            }

            $deliveryOrderLines = DeliveryOrderLine::query()
                ->where('sales_order_line_id', $invoiceLine->sales_order_line_id)
                ->whereHas('deliveryOrder', fn ($query) => $query->whereNotIn('status', ['cancelled']))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($deliveryOrderLines as $deliveryOrderLine) {
                if ($remaining <= 0.0001) {
                    break;
                }

                $billable = (float) ($deliveryOrderLine->quantity_delivered ?: $deliveryOrderLine->quantity_ordered)
                    - (float) $deliveryOrderLine->quantity_invoiced;

                $allocate = min(max($billable, 0), $remaining);

                if ($allocate <= 0.0001) {
                    continue;
                }

                $deliveryOrderLine->update([
                    'quantity_invoiced' => (float) $deliveryOrderLine->quantity_invoiced + $allocate,
                ]);

                $remaining -= $allocate;
            }
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
     *
     * @return array{0:int|float,1:float,2:string,3:float|null} [qty, unit_price, description, base_unit_price]
     */
    private function normalizeInvoiceLineWithQty(SalesOrderLine $line, float $quantity): array
    {
        if ($quantity <= 0) {
            return [0, 0, $line->description, null];
        }

        $baseUnitPrice = $line->base_unit_price !== null ? (float) $line->base_unit_price : null;

        $roundedQuantity = round($quantity);

        if (abs($quantity - $roundedQuantity) < 0.0001 && $roundedQuantity >= 1) {
            return [(int) $roundedQuantity, (float) $line->unit_price, $line->description, $baseUnitPrice];
        }

        // #295 (Q7): invoice_lines.quantity is numeric(15,4), so a fractional
        // quantity is stored as the real quantity/unit price — the old
        // "fractional fold" (one folded unit + note in the description) is no
        // longer needed and hid the true quantity from the invoice/reports.
        return [$quantity, (float) $line->unit_price, $line->description, $baseUnitPrice];
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

        $this->reserveStockForLines($order, $order->lines);
    }

    /**
     * Issue #407: reserve stock for a set of lines (all lines at confirmation,
     * only the newly added ones for an amendment). Insufficient stock does NOT
     * abort the caller — the line is left unreserved and flagged as a
     * backorder, exactly like confirmation (#379).
     *
     * @param  iterable<\Modules\Sales\Models\SalesOrderLine>  $lines
     */
    private function reserveStockForLines(SalesOrder $order, iterable $lines): void
    {
        if (! $order->warehouse_id) {
            return;
        }

        $warehouseId = (int) $order->warehouse_id;

        foreach ($lines as $line) {
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
                    // #379 (O10): base-unit quantities are decimal — (int)
                    // round() reserved 3 for 2.5 and nothing for 0.4.
                    quantity: (float) $line->quantity,
                    sourceDocumentType: 'SalesOrder',
                    sourceDocumentId: $order->id,
                    sourceDocumentLineId: $line->id,
                    notes: "Reserved for sales order {$order->sales_order_number}",
                );

                if ((float) ($line->backorder_quantity ?? 0) !== 0.0) {
                    $line->update(['backorder_quantity' => 0]);
                }
            } catch (\Throwable $e) {
                // Out-of-stock (or other reservation error): the line becomes
                // a VISIBLE backorder instead of a silent log line (#379).
                $line->update(['backorder_quantity' => (float) $line->quantity]);

                Log::warning('Stock reservation skipped — line flagged as backorder', [
                    'sales_order_id' => $order->id,
                    'sales_order_number' => $order->sales_order_number,
                    'product_id' => $line->product_id,
                    'quantity' => $line->quantity,
                    'backorder_quantity' => (float) $line->quantity,
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
