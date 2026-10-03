<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\DomainPostingLine;
use App\Accounting\DTOs\DomainPostingRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\SettlementAuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\InventoryService;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

/**
 * Invoice Service - Handles invoice workflow (posting, payments, balance sync).
 *
 * Invoice creation is NOT public. Only authorised internal collaborators
 * (SalesOrderService, AccountingOriginInvoiceService) may create invoices
 * by calling the protected createInvoiceInternal() method via the
 * InvoiceCreationToken pattern — no debug_backtrace heuristics.
 */
class InvoiceService
{
    public function __construct(
        private PostingEngineInterface $postingEngine,
        private TaxDeterminationService $taxDeterminationService,
        private InventoryService $inventoryService,
        private \App\Services\BundleDecompositionService $bundleDecomposition,
    ) {}

    /**
     * Create an InvoiceCreationToken that grants one-shot access to
     * createInvoiceInternal(). Only trusted orchestrators should call this.
     *
     * @internal For use by SalesOrderService and AccountingOriginInvoiceService only.
     *           Calling this method directly from controllers, commands, or other
     *           non-trusted contexts is a code quality violation. The token pattern
     *           provides reuse protection but does NOT authorize arbitrary callers.
     *           Contact the accounting team before using this in new code paths.
     */
    public function issueCreationToken(): InvoiceCreationToken
    {
        return new InvoiceCreationToken($this);
    }

    /**
     * Create invoice with lines — structurally non-public.
     *
     * Callers must hold a valid InvoiceCreationToken obtained from issueCreationToken().
     * This replaces the old debug_backtrace guard with a structural enforcement pattern.
     *
     * @internal Use SalesOrderService::createInvoice() or AccountingOriginInvoiceService for external flows.
     */
    public function createInvoiceInternal(InvoiceCreationToken $token, array $data): Invoice
    {
        $token->consume(); // throws if already used

        return DB::transaction(function () use ($data) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['created_by'] = TenantUserHelper::requireTenantUserId($data['created_by'] ?? Auth::id());
            $data['invoice_date'] = $data['invoice_date'] ?? now();
            $data['due_date'] = $data['due_date'] ?? now()->addDays(30);
            $data['status'] = 'draft';

            // Apply the tenant's approval default when the caller did not
            // decide explicitly, so every creation path honours the setting.
            if (! array_key_exists('requires_approval', $data)) {
                $data['requires_approval'] = (bool) \App\Models\Setting::getValue(
                    'sales.invoice.default_requires_approval',
                    false
                );
            }

            // FR-5.1: when no snapshot reached the creator (legacy callers,
            // API-origin paths), stamp one now from the document's currency +
            // date. A same-currency stamp is rate 1 — byte-identical to the
            // pre-feature behaviour.
            if (empty($data['currency_code'])) {
                $data['currency_code'] = \App\Services\DocumentCurrencyResolver::defaultCurrencyFor(
                    $data['customer_id'] ?? null,
                    null
                );
            }
            if (empty($data['base_currency_code'])) {
                $data['base_currency_code'] = \App\Services\DocumentCurrencyResolver::baseFor(null, $data['customer_id'] ?? null);
            }
            if (($data['exchange_rate'] ?? null) === null) {
                $data['exchange_rate'] = $data['currency_code'] === $data['base_currency_code']
                    ? 1.000000
                    : (function () use ($data) {
                        try {
                            return \App\Services\DocumentCurrencyResolver::snapshot(
                                $data['currency_code'],
                                $data['customer_id'] ?? null,
                                null,
                                isset($data['invoice_date']) ? \Illuminate\Support\Carbon::parse($data['invoice_date'])->toDateString() : now()->toDateString()
                            )['exchange_rate'];
                        } catch (\App\Exceptions\PricingException) {
                            // Drafts may be created without a rate; posting
                            // blocks separately (FR-1.5 / AC-3).
                            return null;
                        }
                    })();
            }

            $invoice = Invoice::create($data);

            foreach ($lines as $lineIndex => $lineData) {
                $invoice->lines()->create([
                    'line_type' => $lineData['line_type'] ?? 'product',
                    // Issue #138: stable link to the originating order line.
                    'sales_order_line_id' => $lineData['sales_order_line_id'] ?? null,
                    'sequence' => $lineData['sequence'] ?? $lineIndex,
                    'product_id' => $lineData['product_id'] ?? null,
                    'model_number' => $lineData['model_number'] ?? null,
                    'description' => $lineData['description'],
                    'product_notes' => $lineData['product_notes'] ?? null,
                    'lot_number' => $lineData['lot_number'] ?? null,
                    'serial_number' => $lineData['serial_number'] ?? null,
                    'product_batch_id' => $lineData['product_batch_id'] ?? null,
                    'product_serial_id' => $lineData['product_serial_id'] ?? null,
                    'expiry_date' => $lineData['expiry_date'] ?? null,
                    'quantity' => $lineData['quantity'] ?? 0,
                    'unit_price' => $lineData['unit_price'] ?? 0,
                    // MRD pricelist-multicurrency FR-5.3: provenance columns
                    // propagate from the SO line through the creation token.
                    'base_unit_price' => $lineData['base_unit_price'] ?? null,
                    'pricing_trace' => $lineData['pricing_trace'] ?? null,
                    'tax_rate' => $lineData['tax_rate'] ?? 0,
                    'discount_amount' => $lineData['discount_amount'] ?? 0,
                ]);
            }

            $invoice->refresh();
            $invoice->calculateTotals();

            // The tenant policy / explicit caller decision is captured BEFORE
            // the threshold rule runs: a policy-required invoice keeps its
            // long-standing create-time submission below, while a
            // threshold-only promotion is submitted by the interactive create
            // endpoints (InvoiceController::store / SalesOrderController) —
            // never twice.
            $approvalRequestedByPolicy = (bool) $invoice->requires_approval;

            // Issue #647: the tenant approval threshold is applied HERE, in the
            // one service every creation path goes through (direct create,
            // SO→invoice, quotation/DO conversion, imports, POS/channel), not
            // only on the edit form.
            $this->applyApprovalThreshold($invoice);

            // ADR-003A: Populate snapshot fields from authoritative customer/contact records
            app(DocumentSnapshotService::class)->populateInvoiceSnapshot($invoice);

            // Tenant policy (sales.invoice.default_requires_approval=true) means
            // every created invoice must go through the approval channel before
            // posting — submit it right away so it never sits in draft with no
            // pending request (which left quote→invoices unapprovable).
            if ($approvalRequestedByPolicy && $invoice->requires_approval) {
                try {
                    $actor = \Illuminate\Support\Facades\Auth::user();
                    if ($actor && $invoice->lines()->exists()) {
                        app(\App\Services\InvoiceApprovalService::class)->submit($invoice, $actor);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Invoice auto-submit for approval failed', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $invoice->load(['customer', 'lines.product']);
        });
    }

    /**
     * Issue #647: the ONE place the tenant invoice approval threshold is
     * applied. Any invoice whose total meets or exceeds
     * `sales.approval_threshold_amount` (SalesApprovalGate::SETTING_KEY) is
     * promoted to `requires_approval = true`, which the creation path then
     * submits for approval and which `InvoiceApprovalService::checkGuard()`
     * enforces before posting.
     *
     * Callers: every invoice creation runs through createInvoiceInternal(),
     * the edit form calls this after recalculating totals, and the draft
     * re-import path calls it after its own recalculation — so a document can
     * never skip the gate by being created (or grown) on a path that is not
     * the edit form.
     *
     * @return bool whether the invoice requires approval after this call
     */
    public function applyApprovalThreshold(Invoice $invoice): bool
    {
        if (! app(SalesApprovalGate::class)->requiresApproval(Invoice::class, (float) $invoice->total)) {
            return (bool) $invoice->requires_approval;
        }

        if (! $invoice->requires_approval) {
            $invoice->requires_approval = true;
            $invoice->save();
        }

        return true;
    }

    /**
     * Post invoice (creates journal entry with COGS, reduces inventory)
     */
    public function postInvoice(Invoice $invoice): Invoice
    {
        DB::beginTransaction();

        try {
            // Re-fetch with lock to prevent concurrent posting
            $invoice = Invoice::with(['customer', 'lines.product'])
                ->where('id', $invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->status === 'posted' && $invoice->journal_entry_id !== null) {
                // Idempotent: already posted — return the invoice without duplicate posting
                return $invoice->fresh(['journalEntry', 'lines']);
            }

            if ($invoice->status !== 'draft') {
                throw new \RuntimeException('Only draft invoices can be posted');
            }

            // ── Tax determination (Issue #42) ─────────────────
            $taxOverrides = array_filter([
                'jurisdiction' => $invoice->tax_jurisdiction,
                'tax_regime' => $invoice->tax_regime,
                'treatment' => $invoice->tax_treatment,
            ], fn ($v) => $v !== null);

            $taxDetermination = $this->taxDeterminationService->determineForInvoice(
                $invoice,
                $taxOverrides ?: null,
            );
            $primaryTaxCode = $taxDetermination['tax_lines'][0]['tax_code'] ?? null;

            // MRD R3.3: named variance between the booked document tax and the
            // determination. The GL stays booked-first (balance-safe), but the
            // divergence is loudly recorded for the accountant to resolve —
            // it is exactly the class of drift the tax report reconciliation
            // is built to surface.
            $determinationTaxTotal = round((float) $taxDetermination['tax_amount'], 2);
            $bookedTaxTotal = round((float) $invoice->tax_amount, 2);

            if (abs($determinationTaxTotal - $bookedTaxTotal) > 0.02) {
                \Illuminate\Support\Facades\Log::warning('TAX_VARIANCE: booked invoice tax differs from determination', [
                    'invoice_id' => $invoice->id,
                    'booked_tax' => $bookedTaxTotal,
                    'determination_tax' => $determinationTaxTotal,
                    'variance' => round($bookedTaxTotal - $determinationTaxTotal, 2),
                ]);
            }

            // ── MRD pricelist-multicurrency FR-5.7/FR-7.1/AC-3 ───────────
            // Posting converts the DOCUMENT amounts at the invoice's SNAPSHOT
            // rate — never today's rate (FR-7.1). Missing snapshot → resolve
            // at the invoice date and persist; still missing → BLOCK the
            // posting with a distinct error so the invoice stays draft and no
            // journal rows are written (AC-3).
            $docCurrency = strtoupper((string) ($invoice->currency_code ?: \App\Services\DocumentCurrencyResolver::resolve()));
            $baseCurrency = strtoupper((string) ($invoice->base_currency_code
                ?: \App\Services\DocumentCurrencyResolver::baseFor(
                    $invoice->salesOrder?->legal_entity_id,
                    $invoice->customer_id
                )));
            $foreign = $docCurrency !== $baseCurrency;

            $fxRate = $invoice->exchange_rate !== null ? (float) $invoice->exchange_rate : null;

            if (! $foreign) {
                $fxRate = 1.0;
            } elseif ($fxRate === null || $fxRate <= 0) {
                $snapshot = \App\Services\DocumentCurrencyResolver::snapshot(
                    $docCurrency,
                    $invoice->customer_id,
                    $invoice->salesOrder?->legal_entity_id,
                    $invoice->invoice_date->toDateString()
                );
                $fxRate = (float) $snapshot['exchange_rate'];
                $baseCurrency = strtoupper((string) $snapshot['base_currency_code']);
                $invoice->update([
                    'exchange_rate' => $fxRate,
                    'base_currency_code' => $baseCurrency,
                ]);
            }

            // §9.3: base = round(document amount × snapshot rate, 2).
            $toBase = static fn (float $docAmount): float => $foreign
                ? round($docAmount * $fxRate, 2)
                : round($docAmount, 2);

            // ── Build business-value lines (ACC-RM-001: profile-resolved) ──
            // Operational domains may not submit unrestricted DR/CR lines
            // (ADR-005 §12). Invoice submits account-role + amount business
            // values in BASE currency; currency_code/original_amount/
            // exchange_rate carry the foreign side for dual display (FR-5.7).
            $lines = [];

            // Accounts Receivable (total) → profile direction: debit
            $lines[] = new DomainPostingLine(
                accountRole: 'accounts_receivable',
                amount: $toBase((float) $invoice->total),
                currencyCode: $foreign ? $baseCurrency : null,
                originalAmount: $foreign ? round((float) $invoice->total, 2) : null,
                exchangeRate: $foreign ? $fxRate : null,
                description: "Invoice {$invoice->invoice_number}".($foreign ? " ({$invoice->total} {$docCurrency} @ {$fxRate})" : ''),
            );

            // Sales Revenue (subtotal minus discount) → profile direction: credit
            $salesRevenue = (float) $invoice->subtotal - (float) $invoice->discount_amount;

            // Shipping Revenue (if shipping cost > 0) → profile direction: credit
            $shippingBase = (float) $invoice->shipping_cost > 0 ? $toBase((float) $invoice->shipping_cost) : 0.0;

            // Sales Tax Payable → profile direction: credit; keeps tax-code evidence.
            // MRD TAX-REQ-07: one credit line per determined tax_code (multi-rate
            // documents get one tax_transaction_details row per code with the
            // correct taxable base), while the invoice's booked tax total is
            // preserved exactly so the journal stays balanced.
            $bookedTax = round((float) $invoice->tax_amount, 2);
            $taxBaseTotal = 0.0;
            $taxLines = [];

            if ($bookedTax > 0) {
                foreach (TaxDeterminationService::allocateBookedTax($taxDetermination['tax_lines'] ?? [], $bookedTax) as $allocation) {
                    if ($allocation['amount'] <= 0) {
                        continue;
                    }
                    $taxBase = $toBase((float) $allocation['amount']);
                    $taxBaseTotal += $taxBase;
                    $taxLines[] = new DomainPostingLine(
                        accountRole: 'sales_tax_payable',
                        amount: $taxBase,
                        currencyCode: $foreign ? $baseCurrency : null,
                        originalAmount: $foreign ? round((float) $allocation['amount'], 2) : null,
                        exchangeRate: $foreign ? $fxRate : null,
                        description: "Tax - Invoice {$invoice->invoice_number}".($allocation['tax_code'] ? " [{$allocation['tax_code']}]" : ''),
                        taxCode: $allocation['tax_code'] ?? $primaryTaxCode,
                        taxId: $allocation['tax_id'],
                    );
                }
            }

            // §9.4 / FR-7.4: the sum of independently rounded legs can drift
            // a cent from the AR base. The residual is absorbed at REVENUE so
            // the entry balances exactly and no hidden gap survives to the
            // ledger.
            $arBase = $toBase((float) $invoice->total);
            $revenueBase = round($arBase - $taxBaseTotal - $shippingBase, 2);

            if ($foreign && abs($revenueBase - $toBase($salesRevenue)) > 0.02) {
                \Illuminate\Support\Facades\Log::warning('FX posting: revenue absorbed rounding residual beyond 0.02', [
                    'invoice_id' => $invoice->id,
                    'expected_revenue_base' => $toBase($salesRevenue),
                    'posted_revenue_base' => $revenueBase,
                    'residual' => round($revenueBase - $toBase($salesRevenue), 2),
                ]);
            }

            if ($salesRevenue > 0 || $revenueBase != 0) {
                $lines[] = new DomainPostingLine(
                    accountRole: 'sales_revenue',
                    amount: $revenueBase,
                    currencyCode: $foreign ? $baseCurrency : null,
                    originalAmount: $foreign ? round($salesRevenue, 2) : null,
                    exchangeRate: $foreign ? $fxRate : null,
                    description: "Sales - Invoice {$invoice->invoice_number}",
                );
            }

            if ((float) $invoice->shipping_cost > 0) {
                $lines[] = new DomainPostingLine(
                    accountRole: 'shipping_revenue',
                    amount: $shippingBase,
                    currencyCode: $foreign ? $baseCurrency : null,
                    originalAmount: $foreign ? round((float) $invoice->shipping_cost, 2) : null,
                    exchangeRate: $foreign ? $fxRate : null,
                    description: "Shipping - Invoice {$invoice->invoice_number}",
                );
            }

            foreach ($taxLines as $taxLine) {
                $lines[] = $taxLine;
            }

            // ── COGS lines for inventory items ───────────────
            // Issue #176/#682: COGS and the stock movement below are derived from
            // ONE number per line — the quantity no document has issued yet
            // (GoodsIssueLedger) — so they can no longer disagree about whether
            // a delivery order already shipped these goods. A delivery order
            // that issued them first leaves nothing to recognise here: the
            // invoice only books revenue/AR.
            $bundleDecomposition = app(\App\Services\BundleDecompositionService::class);
            $batchConsumption = app(\App\Services\BatchSerialConsumptionService::class);
            $goodsIssueLedger = app(\App\Services\GoodsIssueLedger::class);

            // Issue #131: batch-tracked lines cost their FEFO layers (previewed
            // here because GL posts before stock is deducted).
            try {
                $cogsWarehouseId = $this->resolveInventoryWarehouseId($invoice);
            } catch (\Throwable) {
                $cogsWarehouseId = null;
            }

            $unissuedQuantities = [];
            $lineBatches = [];
            $lineSerials = [];
            $totalCogs = 0.0;

            foreach ($invoice->lines as $line) {
                if (($line->line_type ?? 'product') !== 'product' || ! $line->product) {
                    continue;
                }

                $qty = $goodsIssueLedger->remainingForInvoiceLine($line, (float) $line->quantity);

                if ($qty <= 0) {
                    continue;
                }

                $unissuedQuantities[$line->id] = $qty;

                // Issue #683: the line's lot and serial are not decoration —
                // the lot is the layer that leaves the warehouse (and the one
                // COGS is costed from), and the serial must be available now or
                // the whole posting is refused before anything is written.
                $lineBatch = $this->resolveInvoiceLineBatch($line);
                if ($lineBatch) {
                    $lineBatches[$line->id] = $lineBatch;
                }

                $lineSerial = $this->resolveInvoiceLineSerial($line);
                if ($lineSerial) {
                    $lineSerials[$line->id] = $lineSerial;
                }

                $totalCogs += $batchConsumption->costForLine($line->product, $qty, $cogsWarehouseId, $lineBatch?->id)
                    ?? $bundleDecomposition->cost($line->product, $qty);
            }

            if ($totalCogs > 0) {
                // Cost of Goods Sold → profile direction: debit.
                // Valuation comes from stock layers, which are stored in
                // the BASE currency — never converted (FR-12 §12).
                $lines[] = new DomainPostingLine(
                    accountRole: 'cogs',
                    amount: round($totalCogs, 2),
                    currencyCode: $foreign ? $baseCurrency : null,
                    description: "COGS - Invoice {$invoice->invoice_number}",
                );

                // Inventory → profile direction: credit
                $lines[] = new DomainPostingLine(
                    accountRole: 'inventory',
                    amount: round($totalCogs, 2),
                    currencyCode: $foreign ? $baseCurrency : null,
                    description: "Inventory - Invoice {$invoice->invoice_number}",
                );
            }

            // ── Post via PostingEngine (domain contract) ─────
            // Issue #875: bind the posting to the invoice's legal entity (and
            // entity book). Legacy rows that predate the entity columns are
            // resolved here from the sales order / customer / request context
            // and stamped on the invoice so document and ledger agree.
            $entityResolver = app(\App\Services\LegalEntityContextResolver::class);
            $legalEntityId = $entityResolver->resolveEntity(
                explicit: $invoice->legal_entity_id ? (int) $invoice->legal_entity_id : null,
                salesOrderId: $invoice->sales_order_id ? (int) $invoice->sales_order_id : null,
                customerId: $invoice->customer_id ? (int) $invoice->customer_id : null,
                required: true,
            );
            $entityBookId = $invoice->entity_book_id
                ? (int) $invoice->entity_book_id
                : $entityResolver->resolveBookId($legalEntityId);

            if ($legalEntityId && ((int) $invoice->legal_entity_id !== $legalEntityId || (int) $invoice->entity_book_id !== (int) $entityBookId)) {
                $invoice->forceFill([
                    'legal_entity_id' => $legalEntityId,
                    'entity_book_id' => $entityBookId,
                ])->save();
            }

            $result = $this->postingEngine->domainPosting(new DomainPostingRequest(
                postingRequestId: DomainPostingRequest::generateRequestId(),
                idempotencyKey: "invoice:{$invoice->id}:post",
                tenantId: (string) (function_exists('tenant') && tenant() ? tenant('id') : ''),
                entityId: $legalEntityId !== null ? (string) $legalEntityId : '',
                accountingBookId: $entityBookId !== null ? (string) $entityBookId : '',
                sourceCapability: 'ACC-003',
                sourceRecordType: 'invoice',
                sourceRecordId: (string) $invoice->id,
                sourceRecordVersion: (int) ($invoice->version_number ?? 1),
                postingProfileCode: 'AR_INVOICE_POST',
                transactionDate: $invoice->invoice_date->toDateString(),
                postingDate: $invoice->invoice_date->toDateString(),
                // FR-5.7/§12: the header FX stamp on the journal entry comes
                // from the document snapshot (PostingEngine writes the header
                // columns when this is set).
                currencyCode: $foreign ? $docCurrency : null,
                exchangeRate: $foreign ? $fxRate : null,
                lines: $lines,
                userId: TenantUserHelper::requireTenantUserId($invoice->created_by),
                taxDeterminationResult: $taxDetermination,
                ledgerDescription: "Invoice #{$invoice->invoice_number} - {$invoice->customer->name}",
                reference: $invoice->invoice_number,
            ));

            if (! $result->success) {
                throw new \Exception($result->error);
            }

            // Status transitions to 'posted' (accounting released to GL).
            // The operational 'sent' status is set separately via InvoiceController::send().
            // lifecycle_status must also be set to 'posted' — resetToDraft leaves
            // it as 'draft' on the invoice, and Invoice::scopePosted() (used by
            // any invoice list / report) filters by lifecycle_status, not status.
            $invoice->update([
                'status' => 'posted',
                'lifecycle_status' => 'posted',
                'journal_entry_id' => $result->journalEntryId,
            ]);

            $this->synchronizeSalesOrderPosting($invoice->fresh(['salesOrder', 'lines']));

            // Reduce inventory for product lines via canonical InventoryService path.
            // Source-linked invoices must carry explicit warehouse context; do not inject defaults.
            //
            // Issue #241: bundle lines must decompose into their components —
            // invoice-only sales previously skipped stock entirely for bundles
            // (the bundle row usually has track_inventory=false) while the GL
            // still posted component COGS. Demand is aggregated per component
            // product because stock_movements_ref_product_type_unique allows a
            // single movement per (reference, product, type).
            //
            // Issue #682: only the quantity no document has issued yet
            // ($unissuedQuantities, computed above from the SAME ledger read the
            // COGS used) leaves the warehouse here, so the stock movement and
            // the COGS entry can never disagree.
            $componentDemand = [];
            foreach ($unissuedQuantities as $lineId => $unissuedQty) {
                $line = $invoice->lines->firstWhere('id', $lineId);

                if (! $line?->product) {
                    continue;
                }

                // Issue #683: a bundle has no lot of its own — only a directly
                // sold product carries the selected lot onto its stock movement.
                $lineBatch = $line->product->is_bundle ? null : ($lineBatches[$lineId] ?? null);

                foreach ($this->bundleDecomposition->stockConsumingComponents($line->product, (float) $unissuedQty) as $component) {
                    $productId = (int) $component['product']->id;
                    $qty = (float) $component['quantity'];
                    if ($qty <= 0) {
                        continue;
                    }

                    $componentDemand[$productId] ??= [
                        'quantity' => 0.0,
                        'batches' => [],
                        'batch_number' => null,
                        'expiry_date' => null,
                    ];

                    $componentDemand[$productId]['quantity'] += $qty;

                    if ($lineBatch && (int) $lineBatch->product_id === $productId) {
                        $componentDemand[$productId]['batches'][$lineBatch->id] =
                            ($componentDemand[$productId]['batches'][$lineBatch->id] ?? 0.0) + $qty;
                        $componentDemand[$productId]['batch_number'] ??= $line->lot_number;
                        $componentDemand[$productId]['expiry_date'] ??=
                            $line->expiry_date?->toDateString()
                            ?? $lineBatch->expiry_date?->toDateString();
                    }
                }
            }

            $warehouseId = $componentDemand === [] ? null : $this->resolveInventoryWarehouseId($invoice);
            if ($componentDemand !== [] && $warehouseId === null) {
                throw new \RuntimeException(
                    "Cannot post invoice {$invoice->invoice_number}: no inventory warehouse context available."
                );
            }

            if ($warehouseId !== null) {
                foreach ($componentDemand as $productId => $demand) {
                    $remainingToDeduct = (float) $demand['quantity'];
                    if ($remainingToDeduct <= 0) {
                        continue;
                    }

                    $this->inventoryService->adjustStock(
                        productId: $productId,
                        warehouseId: $warehouseId,
                        quantity: $remainingToDeduct,
                        type: 'sale',
                        reference: $invoice->invoice_number,
                        referenceType: 'Invoice',
                        referenceId: $invoice->id,
                        batchNumber: $demand['batch_number'],
                        expiryDate: $demand['expiry_date'] ? new \DateTime($demand['expiry_date']) : null,
                        batchAllocations: $demand['batches'],
                    );
                }
            }

            // Issue #683: stock has left for the invoice's serialised lines —
            // walk the same mark-sold mechanism the delivery-order path uses
            // (#131) so the serial belongs to this customer and cannot be sold
            // again.
            foreach ($lineSerials as $lineId => $serial) {
                $line = $invoice->lines->firstWhere('id', $lineId);

                if (! $line?->product) {
                    continue;
                }

                $batchConsumption->markSerialsSold(
                    product: $line->product,
                    serialNumbers: [(string) $serial->serial_number],
                    customerId: (int) $invoice->customer_id,
                    saleReference: $invoice->invoice_number,
                    soldAt: now(),
                );
            }

            // Update customer outstanding + purchase stats so the customer
            // detail figures reflect the posted invoice immediately.
            $invoice->customer->updateOutstanding();
            $invoice->customer->updatePurchaseStats();

            DB::commit();

            \App\Accounting\Events\InvoicePosted::dispatch($invoice->fresh());

            return $invoice->fresh(['journalEntry', 'lines']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Record payment against invoice
     */
    /**
     * Record a payment against an invoice.
     *
     * $postImmediately=true keeps the historical behaviour (used by channel
     * flows where the money has already settled and the ledger must move with
     * the record). The manual "Record Payment" UI passes false so the payment
     * lands as a DRAFT (`recorded`, no journal entry) and the operator posts it
     * deliberately from the Payments page.
     */
    public function recordPayment(Invoice $invoice, array $paymentData, bool $postImmediately = true): Payment
    {
        DB::beginTransaction();

        try {
            $paymentData['customer_id'] = $invoice->customer_id;
            $paymentData['invoice_id'] = $invoice->id;
            $paymentData['payment_date'] = $paymentData['payment_date'] ?? now();
            $paymentData['received_by'] = TenantUserHelper::requireTenantUserId($paymentData['received_by'] ?? Auth::id() ?? $invoice->created_by);
            // bank_account_id is passed through from the caller if provided

            // Generate payment_number explicitly so the boot hook does not query the
            // payments table inside an already-aborted outer transaction
            // (which surfaces as SQLSTATE 25P02 from generatePaymentNumber()).
            $paymentData['payment_number'] = $paymentData['payment_number'] ?? Payment::generatePaymentNumber();

            // MRD pricelist-multicurrency FR-7.3: payments inherit the
            // document currency, and the settlement-date rate is snapshotted
            // at recording time. A draft payment may exist without a rate
            // (missing rate blocks POSTING, not recording — FR-1.5/AC-3).
            $paymentData['currency'] = $paymentData['currency']
                ?? $paymentData['currency_code']
                ?? $invoice->currency_code
                ?? \App\Services\DocumentCurrencyResolver::resolve();
            $paymentData['base_currency_code'] = $paymentData['base_currency_code']
                ?? $invoice->base_currency_code
                ?? \App\Services\DocumentCurrencyResolver::baseFor($invoice->salesOrder?->legal_entity_id, $invoice->customer_id);

            if (! array_key_exists('exchange_rate', $paymentData) || $paymentData['exchange_rate'] === null) {
                $paymentCurrency = strtoupper((string) $paymentData['currency']);
                $paymentBase = strtoupper((string) $paymentData['base_currency_code']);

                if ($paymentCurrency === $paymentBase) {
                    $paymentData['exchange_rate'] = 1.000000;
                } else {
                    try {
                        $paymentData['exchange_rate'] = \App\Services\DocumentCurrencyResolver::snapshot(
                            $paymentCurrency,
                            $invoice->customer_id,
                            $invoice->salesOrder?->legal_entity_id,
                            \Illuminate\Support\Carbon::parse($paymentData['payment_date'])->toDateString()
                        )['exchange_rate'];
                    } catch (\App\Exceptions\PricingException) {
                        $paymentData['exchange_rate'] = null;
                    }
                }
            }

            $payment = new Payment($paymentData);
            $payment->save();

            if (! $postImmediately) {
                // #477 (Decision 2026-10-02): a DRAFT payment must not touch the
                // invoice. Paid amount, balance, status and AR move only when the
                // payment is posted (which creates the journal entry). Posting
                // later runs the settlement sync below via PaymentPostingService.
                DB::commit();

                return $payment->fresh();
            }

            // Post via the canonical PaymentPostingService — ONE realized-FX
            // aware posting path for UI, channel and API flows (SOP §4). Posting
            // now also runs the invoice settlement sync (#477), so the invoice
            // and the ledger always move together.
            app(\App\Services\PaymentPostingService::class)->post($payment, $payment->received_by);

            DB::commit();

            return $payment->fresh('journalEntry');

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function resolveInventoryWarehouseId(Invoice $invoice): ?int
    {
        $posOrder = $invoice->posOrder()->with('terminal')->first();
        if ($posOrder?->terminal?->warehouse_id) {
            return (int) $posOrder->terminal->warehouse_id;
        }

        $webOrder = $invoice->webOrder()->first();
        $webWarehouseId = $webOrder?->resolveInventoryWarehouseId();
        if ($webWarehouseId) {
            return (int) $webWarehouseId;
        }

        $salesOrder = $invoice->salesOrder()->first();

        if ($salesOrder) {
            return $this->resolveSalesOrderWarehouseId($salesOrder);
        }

        // Issue #241: a direct invoice-only sale has no linked order to resolve
        // warehouse context from; fall back to the tenant default warehouse so
        // bundle components can actually move (previously the whole stock loop
        // was skipped for bundles and GL/inventory silently diverged).
        return \App\Models\Warehouse::getDefault()?->id;
    }

    /**
     * Issue #683: the ProductBatch a document line names — by explicit id, or
     * by the lot number it carries. Returns null (FEFO still applies) when the
     * line names no batch or the reference does not belong to its product.
     */
    private function resolveInvoiceLineBatch(InvoiceLine $line): ?ProductBatch
    {
        $product = $line->product;

        if (! $product) {
            return null;
        }

        $batch = null;

        if ($line->product_batch_id) {
            $batch = $line->relationLoaded('batch')
                ? $line->batch
                : ProductBatch::find($line->product_batch_id);
        } elseif (filled($line->lot_number)) {
            $batch = ProductBatch::where('product_id', $product->id)
                ->where(function ($query) use ($line) {
                    $query->where('batch_number', $line->lot_number)
                        ->orWhere('lot_number', $line->lot_number);
                })
                ->orderByRaw('expiry_date ASC NULLS LAST')
                ->orderBy('id')
                ->first();
        }

        if (! $batch || (int) $batch->product_id !== (int) $product->id) {
            return null;
        }

        return $batch;
    }

    /**
     * Issue #683: validate the serial a document line names (only for
     * serial-tracked products) and return it so posting can mark it sold.
     * Throws when the serial is unknown or no longer available — a second sale
     * of the same serial must be refused before any stock or GL is written.
     */
    private function resolveInvoiceLineSerial(InvoiceLine $line): ?ProductSerial
    {
        $product = $line->product;

        if (! $product || ($product->tracking_method ?? null) !== \App\Models\Product::TRACKING_SERIAL) {
            return null;
        }

        return app(BatchSerialConsumptionService::class)->requireAvailableSerial(
            $product,
            $line->product_serial_id ? (int) $line->product_serial_id : null,
            $line->serial_number ?: null,
        );
    }

    private function resolveSalesOrderWarehouseId(?SalesOrder $salesOrder): ?int
    {
        if (! $salesOrder) {
            return null;
        }

        return match ($salesOrder->source_document_type) {
            'PosOrder' => (function () use ($salesOrder): ?int {
                $posOrder = \Modules\POS\Models\PosOrder::with('terminal')
                    ->find($salesOrder->source_document_id);

                return $posOrder?->terminal?->warehouse_id
                    ? (int) $posOrder->terminal?->warehouse_id
                    : (int) ($salesOrder->warehouse_id ?: null);
            })(),
            'WebOrder' => (function () use ($salesOrder): ?int {
                $webOrder = \Modules\Ecommerce\Models\WebOrder::find($salesOrder->source_document_id);

                return $webOrder?->resolveInventoryWarehouseId()
                    ?? ($salesOrder->warehouse_id ? (int) $salesOrder->warehouse_id : null);
            })(),
            default => $salesOrder->warehouse_id ? (int) $salesOrder->warehouse_id : null,
        };
    }

    private function synchronizeSalesOrderPosting(Invoice $invoice): void
    {
        $salesOrder = $invoice->salesOrder;
        if (! $salesOrder) {
            return;
        }

        $activeLinks = $salesOrder->invoiceLinks()->where('link_state', 'active')->pluck('invoice_id');

        $postedCount = Invoice::query()
            ->whereIn('id', $activeLinks)
            ->whereNotNull('journal_entry_id')
            ->count();

        $activeCount = $activeLinks->count();

        $accountingStatus = match (true) {
            $activeCount === 0, $postedCount === 0 => 'not_posted',
            $postedCount < $activeCount => 'partially_posted',
            default => 'fully_posted',
        };

        $salesOrder->forceFill([
            'accounting_status' => $accountingStatus,
        ])->saveQuietly();
    }

    /**
     * Record a channel-originated payment against an invoice WITHOUT posting
     * a separate payment-side GL entry.
     *
     * Use this when the channel (POS / ecommerce webhook / FixStuckWebOrders)
     * has ALREADY posted GL via PosPostingService or WebOrderPostingService.
     * This creates the Payment row and atomically syncs the invoice balances.
     */
    public function recordChannelPayment(Invoice $invoice, array $paymentData): Payment
    {
        $paymentData['customer_id'] = $paymentData['customer_id'] ?? $invoice->customer_id;
        $paymentData['invoice_id'] = $invoice->id;
        $paymentData['payment_date'] = $paymentData['payment_date'] ?? now();
        $paymentData['received_by'] = TenantUserHelper::resolveTenantUserId($paymentData['received_by'] ?? $invoice->created_by);
        // The channel (POS / e-commerce / gateway) already posted the ledger
        // entry. The payment record is therefore settled on creation — a
        // `recorded` row here showed as Draft and offered to post again.
        $paymentData['payment_status'] = $paymentData['payment_status'] ?? 'applied';

        $payment = Payment::create($paymentData);

        $this->syncInvoicePaymentState($invoice->fresh(), (float) $payment->amount, $payment);

        return $payment;
    }

    /**
     * Apply a refund amount to an invoice using atomic operations.
     *
     * Reverses paid_amount, recalculates balance, and transitions status.
     * Does NOT create a separate GL reversal entry — callers that need one
     * should invoke PostingEngine::reverse() with a credit-memo document.
     */
    public function applyRefundToInvoice(Invoice $invoice, float $refundAmount): Invoice
    {
        // A refund returns money to the customer: paid_amount goes DOWN and
        // balance (= total − paid) goes UP. Balance must always be derived
        // from the canonical total/paid pair — mutating it independently
        // produced negative balances (e.g. −500 instead of +500).
        Invoice::where('id', $invoice->id)
            ->decrement('paid_amount', $refundAmount);

        $invoice->refresh();

        $newBalance = max(0, (float) $invoice->total - (float) $invoice->paid_amount);

        $status = match (true) {
            (float) $invoice->paid_amount <= 0 => 'sent',
            $newBalance > 0 => 'partial',
            default => 'paid',
        };

        Invoice::where('id', $invoice->id)->update([
            'balance' => $newBalance,
            'status' => $status,
        ]);

        $invoice->refresh();

        if ($invoice->customer) {
            $invoice->customer->updateOutstanding();
        }

        return $invoice;
    }

    /**
     * Issue #228: inverse of applyRefundToInvoice — used when a posted credit
     * note is voided. Re-adds the credited amount to paid_amount and rebuilds
     * balance/status from the canonical total/paid pair.
     */
    public function restoreRefundToInvoice(Invoice $invoice, float $refundAmount): Invoice
    {
        Invoice::where('id', $invoice->id)
            ->increment('paid_amount', $refundAmount);

        $invoice->refresh();

        $newBalance = max(0, (float) $invoice->total - (float) $invoice->paid_amount);

        $status = match (true) {
            (float) $invoice->paid_amount <= 0 => 'sent',
            $newBalance > 0 => 'partial',
            default => 'paid',
        };

        Invoice::where('id', $invoice->id)->update([
            'balance' => $newBalance,
            'status' => $status,
        ]);

        $invoice->refresh();

        if ($invoice->customer) {
            $invoice->customer->updateOutstanding();
        }

        return $invoice;
    }

    /**
     * Issue #510 (CN1): a credit note reduces what the customer OWES — it is
     * not a cash refund. Track it in credited_amount and derive
     * balance = max(0, total − paid − credited); any excess beyond the open
     * balance is parked as customer credit_balance (unapplied customer
     * credit), never a negative balance.
     */
    public function applyCreditNoteToInvoice(Invoice $invoice, float $creditAmount): Invoice
    {
        return $this->adjustCreditNoteOnInvoice($invoice, $creditAmount);
    }

    /**
     * Issue #510 (CN1): inverse of applyCreditNoteToInvoice — used when a
     * posted credit note is voided. Releases the credited amount (and any
     * customer credit it created) and rebuilds balance/status.
     */
    public function reverseCreditNoteFromInvoice(Invoice $invoice, float $creditAmount): Invoice
    {
        return $this->adjustCreditNoteOnInvoice($invoice, -$creditAmount);
    }

    private function adjustCreditNoteOnInvoice(Invoice $invoice, float $delta): Invoice
    {
        $invoice->refresh();

        $credited = max(0.0, (float) $invoice->credited_amount + $delta);
        $newBalance = max(0.0, (float) $invoice->total - (float) $invoice->paid_amount - $credited);

        $lifecycleFinal = in_array($invoice->status, ['draft', 'void', 'cancelled'], true);

        $status = $lifecycleFinal ? $invoice->status : match (true) {
            $newBalance > 0 && (float) $invoice->paid_amount > 0 => 'partial',
            $newBalance > 0 => $invoice->status === 'overdue' ? 'overdue' : 'sent',
            default => 'paid',
        };

        Invoice::where('id', $invoice->id)->update([
            'credited_amount' => $credited,
            'balance' => $newBalance,
            'status' => $status,
        ]);

        $invoice->refresh();

        $this->refreshCustomerCreditBalance($invoice->customer);

        if ($invoice->customer) {
            $invoice->customer->updateOutstanding();
        }

        return $invoice;
    }

    /**
     * Derive customers.credit_balance from live invoices: credited_amount the
     * invoice's own remaining balance cannot absorb is unapplied customer
     * credit. Always recomputed from scratch so post/void can never drift.
     */
    public function refreshCustomerCreditBalance(?Customer $customer): void
    {
        if (! $customer) {
            return;
        }

        $rows = Invoice::where('customer_id', $customer->id)
            ->notSuperseded()
            ->whereNotIn('status', ['draft', 'void', 'cancelled'])
            ->get(['id', 'total', 'paid_amount', 'credited_amount']);

        $credit = 0.0;

        foreach ($rows as $row) {
            $remaining = max(0.0, (float) $row->total - (float) $row->paid_amount);
            $credit += max(0.0, (float) $row->credited_amount - $remaining);
        }

        Customer::where('id', $customer->id)->update(['credit_balance' => round($credit, 2)]);
    }

    /**
     * Atomically sync invoice paid_amount / balance / status after a payment.
     *
     * This is the single canonical path for invoice balance reconciliation
     * — used by both recordPayment (which also posts GL) and
     * recordChannelPayment (which skips GL because the channel already posted).
     */
    /**
     * #477: settle a payment against its invoice at POST time. This is the
     * single place a posted payment updates the invoice's paid_amount, balance,
     * status and AR exposure — invoked by PaymentPostingService::post() so the
     * subledger and the GL always move together. Standalone payments (no linked
     * invoice) are a no-op (they leave a customer credit on the ledger).
     */
    public function settlePostedPayment(Payment $payment): void
    {
        if (! $payment->invoice_id) {
            return;
        }

        $invoice = $payment->invoice()->first();
        if (! $invoice) {
            return;
        }

        // #478: a cash discount is part of what settles the invoice — the
        // customer pays `amount` and the discount clears the rest.
        $settled = round((float) $payment->amount + (float) ($payment->discount_amount ?? 0), 2);

        $this->syncInvoicePaymentState($invoice, $settled, $payment);
    }

    /**
     * Reverse an applied payment portion from its invoice (the mirror of
     * syncInvoicePaymentState). Used by PaymentAllocationService when a posted
     * payment's allocations are undone by a correction (reversal / refund /
     * unapply). Decrements paid_amount, restores the balance, recomputes the
     * status and writes the settlement audit log so the un-settlement is
     * traceable.
     */
    public function reverseAllocatedPayment(Invoice $invoice, float $amount, ?Payment $payment = null): void
    {
        $amount = round($amount, 2);
        if ($amount === 0.0) {
            return;
        }

        $invoice = $invoice->fresh();

        $beforeState = [
            'paid_amount' => $invoice->paid_amount,
            'balance' => $invoice->balance,
            'status' => $invoice->status,
        ];

        $newPaid = max(0.0, round((float) $invoice->paid_amount - $amount, 2));
        $newBalance = max(0.0, round((float) $invoice->total - $newPaid, 2));
        $newStatus = match (true) {
            $newBalance <= 0 => 'paid',
            $newPaid > 0 => 'partial',
            default => 'sent',
        };

        Invoice::where('id', $invoice->id)->update([
            'paid_amount' => $newPaid,
            'balance' => $newBalance,
            'status' => $newStatus,
        ]);

        $invoice->refresh();

        SettlementAuditLog::create([
            'invoice_id' => $invoice->id,
            'payment_id' => $payment?->id,
            'payment_amount' => -$amount,
            'before_paid_amount' => $beforeState['paid_amount'],
            'after_paid_amount' => $invoice->paid_amount,
            'before_balance' => $beforeState['balance'],
            'after_balance' => $invoice->balance,
            'before_status' => $beforeState['status'],
            'after_status' => $invoice->status,
            'actor_user_id' => TenantUserHelper::resolveTenantUserId(),
            'settlement_method' => $payment?->payment_method ?? 'unknown',
            'notes' => 'Payment allocation reversed',
        ]);

        if ($invoice->customer) {
            $invoice->customer->updateOutstanding();
        }
    }

    private function syncInvoicePaymentState(Invoice $invoice, float $paymentAmount, ?Payment $payment = null): void
    {
        $beforeState = [
            'paid_amount' => $invoice->paid_amount,
            'balance' => $invoice->balance,
            'status' => $invoice->status,
        ];

        Invoice::where('id', $invoice->id)
            ->increment('paid_amount', $paymentAmount);

        $invoice->refresh();

        $newBalance = $invoice->total - $invoice->paid_amount;

        Invoice::where('id', $invoice->id)->update([
            'balance' => $newBalance,
            'status' => $newBalance <= 0 ? 'paid' : ($invoice->paid_amount > 0 ? 'partial' : $invoice->status),
        ]);

        $invoice->refresh();

        // Create settlement audit log
        SettlementAuditLog::create([
            'invoice_id' => $invoice->id,
            'payment_id' => $payment?->id,
            'payment_amount' => $paymentAmount,
            'before_paid_amount' => $beforeState['paid_amount'],
            'after_paid_amount' => $invoice->paid_amount,
            'before_balance' => $beforeState['balance'],
            'after_balance' => $invoice->balance,
            'before_status' => $beforeState['status'],
            'after_status' => $invoice->status,
            'actor_user_id' => TenantUserHelper::resolveTenantUserId(),
            'settlement_method' => $payment?->payment_method ?? 'unknown',
        ]);

        // Issue #155: record the explicit payment→invoice application so the
        // allocation ledger is authoritative for split/unapplied payments.
        if ($payment) {
            \App\Models\PaymentApplication::firstOrCreate(
                [
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                ],
                [
                    'tenant_id' => $invoice->tenant_id
                        ?? (function_exists('tenant') && tenant() ? tenant()->id : null),
                    'applied_amount' => $paymentAmount,
                    'currency_code' => $invoice->currency_code ?? null,
                    'created_by' => TenantUserHelper::resolveTenantUserId(),
                ]
            );
        }

        if ($invoice->customer) {
            $invoice->customer->updateOutstanding();
        }
    }

    /**
     * Issue #155: apply an allocated portion of a payment to an invoice through
     * the canonical settlement path (used by PaymentAllocationService for split
     * payments / statement-level allocations).
     */
    public function applyAllocatedPayment(Invoice $invoice, float $amount, ?Payment $payment = null): void
    {
        DB::transaction(function () use ($invoice, $amount, $payment) {
            $this->syncInvoicePaymentState($invoice, $amount, $payment);
        });
    }

    public function createPaymentLink(
        Invoice $invoice,
        float $amount,
        \DateTimeInterface $expiresAt,
        ?string $successUrl = null,
        ?string $cancelUrl = null,
    ): array {
        // P-04c: real success/cancel landings. Caller-provided absolute
        // http(s) URLs win; otherwise the platform's own confirmation
        // endpoints answer (the old hardcoded stubs were dead routes).
        $base = rtrim((string) config('app.url'), '/');
        $success = $this->absoluteHttpUrl($successUrl)
            ?? $base.'/api/v1/payments/invoice/'.$invoice->id.'/success';
        $cancel = $this->absoluteHttpUrl($cancelUrl)
            ?? $base.'/api/v1/payments/invoice/'.$invoice->id.'/cancelled';

        $existingLink = $invoice->payment_link_url;
        $existingExpiry = $invoice->payment_link_expires_at;

        if ($existingLink && $existingExpiry && $existingExpiry->isFuture()) {
            return [
                'url' => $existingLink,
                'expires_at' => $existingExpiry->toIso8601String(),
                'amount' => $invoice->balance,
                'idempotent' => true,
            ];
        }

        $gateway = app(\Modules\PaymentGateway\Services\PaymentService::class);
        $result = $gateway->createIntent([
            'amount' => $amount,
            'currency' => $invoice->currency_code,
            'description' => "Invoice {$invoice->invoice_number}",
            'metadata' => [
                'type' => 'invoice',
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ],
            'email' => $invoice->contact_email ?? $invoice->customer?->email,
            'customer_id' => $invoice->customer_id,
            'redirect_url' => $success,
            'cancel_url' => $cancel,
        ]);

        // PaymentService::createIntent returns `payment_url` (the hosted
        // checkout link) — reading only checkout_url/url/client_secret left
        // the stored payment link NULL on HitPay/PayPal (P-04c defect).
        $checkoutUrl = $result['payment_url'] ?? $result['checkout_url'] ?? $result['url'] ?? null;

        $invoice->updateQuietly([
            'payment_link_url' => $checkoutUrl,
            'payment_link_expires_at' => $expiresAt,
        ]);

        return [
            'url' => $checkoutUrl,
            'expires_at' => $expiresAt->format('c'),
            'amount' => $amount,
            'currency' => $invoice->currency_code,
            'transaction_id' => $result['transaction_id'] ?? null,
            'success_url' => $success,
            'cancel_url' => $cancel,
            'idempotent' => false,
        ];
    }

    private function absoluteHttpUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $url;
    }
}
