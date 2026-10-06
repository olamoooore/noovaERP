<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\DomainPostingLine;
use App\Accounting\DTOs\DomainPostingRequest;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceWriteOff;
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
     * Issue #882/#888: resolve the deferred-revenue snapshot for ONE invoice
     * line. This is the single rule shared by invoice creation and draft
     * edit — an explicit payload flag wins, otherwise the product's deferred
     * setting. A deferred line defaults its start to the invoice date and,
     * when the product declares a deferred period, its end to start + that
     * period. A missing period is not invented here: posting fails closed
     * instead of recognising the revenue up front.
     *
     * @param  array<string, mixed>  $lineData
     * @return array{is_deferred: bool, service_start_date: ?string, service_end_date: ?string}
     */
    public function resolveDeferredLineFields(?\App\Models\Product $product, array $lineData, string $invoiceDate): array
    {
        $isDeferred = array_key_exists('is_deferred', $lineData) && $lineData['is_deferred'] !== null
            ? (bool) $lineData['is_deferred']
            : (bool) ($product?->is_deferred ?? false);

        if (! $isDeferred) {
            return ['is_deferred' => false, 'service_start_date' => null, 'service_end_date' => null];
        }

        $serviceStart = $lineData['service_start_date'] ?? null;
        $serviceStart = $serviceStart ?: \Illuminate\Support\Carbon::parse($invoiceDate)->toDateString();

        $serviceEnd = $lineData['service_end_date'] ?? null;
        if (! $serviceEnd && $product?->deferred_months) {
            $serviceEnd = \Illuminate\Support\Carbon::parse($serviceStart)
                ->addMonthsNoOverflow((int) $product->deferred_months)
                ->toDateString();
        }

        return [
            'is_deferred' => true,
            'service_start_date' => $serviceStart,
            'service_end_date' => $serviceEnd,
        ];
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

            // #309 (X9): tax resolution consults the customer's tax profile —
            // an exemption valid on the document date suppresses the line tax
            // and records its reason for the e-invoice.
            $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
            $customerExempt = \App\Support\CustomerTaxProfileResolver::isExempt($customerId, $data['invoice_date']);
            $customerExemptionReason = $customerExempt
                ? \App\Support\CustomerTaxProfileResolver::exemptionReason($customerId, $data['invoice_date'])
                : null;

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

            // #900 (PC6): the DB default owns the starting status; record it on
            // the workflow transition log so creation is not a back-door write.
            app(SalesWorkflowService::class)->initialize($invoice, ['source' => 'create']);

            foreach ($lines as $lineIndex => $lineData) {
                $lineTaxInclusive = array_key_exists('tax_inclusive', $lineData)
                    ? (bool) $lineData['tax_inclusive']
                    : $this->resolveLineTaxInclusive($lineData['product_id'] ?? null, $data['invoice_date'] ?? null);

                // Issue #882 (SA9): a line is deferred when the payload says so,
                // else when its product carries the deferred setting. The
                // defaulted service period comes from the shared #888 resolver
                // so creation and draft edit can never drift.
                $lineProduct = ! empty($lineData['product_id'])
                    ? \App\Models\Product::find($lineData['product_id'])
                    : null;
                $deferredFields = $this->resolveDeferredLineFields(
                    $lineProduct,
                    $lineData,
                    \Illuminate\Support\Carbon::parse($data['invoice_date'])->toDateString()
                );

                $invoice->lines()->create([
                    'line_type' => $lineData['line_type'] ?? 'product',
                    // Issue #138: stable link to the originating order line.
                    'sales_order_line_id' => $lineData['sales_order_line_id'] ?? null,
                    'sequence' => $lineData['sequence'] ?? $lineIndex,
                    'product_id' => $lineData['product_id'] ?? null,
                    // Issue #884 (UX2): persist the line's income-account
                    // override and analytic dimensions instead of dropping
                    // them — accounting-origin creation and the direct API
                    // have always supplied these fields.
                    'gl_account_id' => $lineData['gl_account_id'] ?? null,
                    'cost_center_id' => $lineData['cost_center_id'] ?? null,
                    'project_id' => $lineData['project_id'] ?? null,
                    'department_id' => $lineData['department_id'] ?? null,
                    'branch_id' => $lineData['branch_id'] ?? null,
                    'model_number' => $lineData['model_number'] ?? null,
                    'description' => $lineData['description'],
                    'product_notes' => $lineData['product_notes'] ?? null,
                    'lot_number' => $lineData['lot_number'] ?? null,
                    'serial_number' => $lineData['serial_number'] ?? null,
                    'product_batch_id' => $lineData['product_batch_id'] ?? null,
                    'product_serial_id' => $lineData['product_serial_id'] ?? null,
                    'expiry_date' => $lineData['expiry_date'] ?? null,
                    'quantity' => $lineData['quantity'] ?? 0,
                    // Issue #685: the sales UoM (and its display abbreviation)
                    // travel onto the invoice line; null keeps stock units.
                    'uom_id' => $lineData['uom_id'] ?? null,
                    'unit' => $lineData['unit'] ?? null,
                    'unit_price' => $lineData['unit_price'] ?? 0,
                    // MRD pricelist-multicurrency FR-5.3: provenance columns
                    // propagate from the SO line through the creation token.
                    'base_unit_price' => $lineData['base_unit_price'] ?? null,
                    'pricing_trace' => $lineData['pricing_trace'] ?? null,
                    'tax_rate' => $customerExempt ? 0 : ($lineData['tax_rate'] ?? 0),
                    'tax_inclusive' => $lineTaxInclusive,
                    'discount_amount' => $lineData['discount_amount'] ?? 0,
                    // #309 (X9): the line records its exemption reason so the
                    // MyInvois E line carries it; an explicit line reason wins.
                    'tax_exemption_reason' => $lineData['tax_exemption_reason'] ?? $customerExemptionReason,
                    // Issue #882 (SA9): deferred revenue period snapshot.
                    'is_deferred' => $deferredFields['is_deferred'],
                    'service_start_date' => $deferredFields['service_start_date'],
                    'service_end_date' => $deferredFields['service_end_date'],
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
        // #649: the invoice is passed so its per-type threshold applies and the
        // comparison runs on the base-currency total via its FX snapshot.
        if (! app(SalesApprovalGate::class)->requiresApproval(Invoice::class, (float) $invoice->total, $invoice)) {
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
        // Issue #902: capture the tenant before the posting work runs. A sync
        // BI job dispatched from JournalEntryObserver tears the tenancy
        // context down mid-posting, so the post-commit InvoicePosted dispatch
        // below would otherwise hand listeners a null tenant — silently
        // skipping the e-invoice auto-submit (and its failure reporting).
        $tenantId = function_exists('tenant') && tenant() ? tenant()->id : null;

        DB::beginTransaction();

        try {
            // Re-fetch with lock to prevent concurrent posting
            $invoice = Invoice::with(['customer', 'lines.product', 'lines.uom', 'lines.salesOrderLine'])
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

            // Issue #882 (SA9): a deferred line with no service end date has
            // no period to release over. Fail the posting before any ledger
            // or schedule row is written rather than silently recognising the
            // revenue up front (the exact defect this issue fixes).
            foreach ($invoice->lines as $deferredLine) {
                if ($deferredLine->is_deferred && ! $deferredLine->service_end_date) {
                    throw new \RuntimeException(
                        "Deferred invoice line #{$deferredLine->id} has no service end date; ".
                        'set a service period or a deferred period on the product before posting.'
                    );
                }
            }

            // #900 (PC6): pre-flight the registered transition guards
            // (approval, period lock) through the engine BEFORE any posting
            // work so a denied transition cannot leave a posted journal entry
            // behind. The status itself is applied through the engine below.
            $workflow = app(SalesWorkflowService::class);
            $canPost = $workflow->can($invoice, 'post');

            if (! $canPost['allowed']) {
                throw new \RuntimeException($canPost['reason'] ?? 'Invoice posting is not allowed.');
            }

            // Issue #883 (SAU1): the business-value lines, entity binding and
            // posting request come from the ONE builder the draft Journal
            // preview also calls (with persistence off), so the tab and the
            // posting can never drift.
            $draft = $this->buildPostingDraft($invoice, persist: true);

            $result = $this->postingEngine->domainPosting($draft['domain_request']);
            if (! $result->success) {
                throw new \Exception($result->error);
            }

            // Issue #883 (SAU1): the write-side context the shared builder
            // resolved — deferred shares, entity/book and the stock
            // quantities, lots and serials COGS was costed from.
            $deferredShares = $draft['deferred_shares'];
            $legalEntityId = $draft['legal_entity_id'];
            $unissuedQuantities = $draft['unissued_quantities'];
            $lineBatches = $draft['line_batches'];
            $lineSerials = $draft['line_serials'];

            // Issue #882 (SA9): the deferred subledger rows are written in the
            // same transaction as the journal entry, so a posting can never
            // leave the ledger with an unearned balance no schedule tracks.
            // A reset-to-draft releases the post link and leaves the previous
            // schedules behind (its reversal closed them); the repost replaces
            // them so the unique (invoice_line_id) row belongs to this posting.
            if ($deferredShares !== []) {
                \App\Models\DeferredRevenueSchedule::where('invoice_id', $invoice->id)->delete();
            }

            foreach ($deferredShares as $deferredShare) {
                /** @var \App\Models\InvoiceLine|null $deferredLine */
                $deferredLine = $deferredShare['line'];

                if (! $deferredLine) {
                    continue;
                }

                \App\Models\DeferredRevenueSchedule::create([
                    'invoice_id' => $invoice->id,
                    'invoice_line_id' => $deferredLine->id,
                    'customer_id' => $invoice->customer_id,
                    'legal_entity_id' => $legalEntityId,
                    'deferred_account_id' => $deferredShare['deferred_account_id'],
                    'income_account_id' => $deferredShare['income_account_id'],
                    'amount' => $deferredShare['amount'],
                    'recognized_amount' => 0,
                    'reversed_amount' => 0,
                    'start_date' => $deferredLine->service_start_date->toDateString(),
                    'end_date' => $deferredLine->service_end_date->toDateString(),
                    'department_id' => $deferredLine->department_id,
                    'project_id' => $deferredLine->project_id,
                    'status' => \App\Models\DeferredRevenueSchedule::STATUS_ACTIVE,
                ]);
            }

            // Status transitions to 'posted' (accounting released to GL) through
            // the shared workflow engine (#900, PC6). The engine writes the
            // operational status and derives lifecycle_status ('posted') in the
            // same single write — Invoice::scopePosted() (used by any invoice
            // list / report) filters by lifecycle_status, not status.
            $workflow->transition($invoice, 'post', [
                'journal_entry_id' => $result->journalEntryId,
                'reason' => 'Invoice posted',
            ]);

            $invoice->update(['journal_entry_id' => $result->journalEntryId]);

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
                // Issue #686: an invoice linked to a sales order issues goods
                // against that order's reservation; stock held by other
                // documents' reservations is unavailable to it.
                $reservationSourceType = $invoice->sales_order_id ? 'SalesOrder' : 'Invoice';
                $reservationSourceId = (int) ($invoice->sales_order_id ?: $invoice->id);

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
                        reservationSourceType: $reservationSourceType,
                        reservationSourceId: $reservationSourceId,
                    );
                }
            }

            // Issue #683: stock has left for the invoice's serialised lines —
            // walk the same mark-sold mechanism the delivery-order path uses
            // (#131) so the serial belongs to this customer and cannot be sold
            // again.
            $batchConsumption = app(\App\Services\BatchSerialConsumptionService::class);

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

            \App\Accounting\Events\InvoicePosted::dispatch($invoice->fresh(), $tenantId);

            return $invoice->fresh(['journalEntry', 'lines']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Issue #879 (SA6): write off (part of) an open invoice as bad debt.
     * Posts DR bad-debt expense / CR AR through the PostingEngine (domain
     * path), records the event for recovery/audit, and reduces the balance —
     * writing off the whole balance sets status `written_off` and balance
     * zero. The expense account is caller-supplied (no bad-debt role
     * mapping exists yet, so no default account is invented).
     */
    public function writeOff(Invoice $invoice, float $amount, string $reason, int $accountId, ?int $userId = null): InvoiceWriteOff
    {
        return DB::transaction(function () use ($invoice, $amount, $reason, $accountId, $userId) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $amount = round($amount, 2);
            $account = $this->assertWriteOffAllowed($invoice, $amount, $accountId);

            $userId = TenantUserHelper::requireTenantUserId($userId ?? Auth::id() ?? $invoice->created_by);
            [$legalEntityId, $entityBookId] = $this->resolvePostingEntityBinding($invoice);

            $writeOff = InvoiceWriteOff::create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'recovered_amount' => 0,
                'reason' => $reason,
                'account_id' => $account->id,
                'created_by' => $userId,
            ]);

            [$lines, $foreign, $docCurrency, $baseCurrency, $rate] = $this->buildWriteOffLines(
                invoice: $invoice,
                documentAmountByAccount: [(int) $account->id => $amount],
                description: "Bad debt write-off - Invoice {$invoice->invoice_number}",
            );

            $result = $this->postingEngine->domainPosting(new DomainPostingRequest(
                postingRequestId: DomainPostingRequest::generateRequestId(),
                idempotencyKey: "invoice:{$invoice->id}:write-off:{$writeOff->id}",
                tenantId: (string) (function_exists('tenant') && tenant() ? tenant('id') : ''),
                entityId: $legalEntityId !== null ? (string) $legalEntityId : '',
                accountingBookId: $entityBookId !== null ? (string) $entityBookId : '',
                sourceCapability: 'ACC-003',
                sourceRecordType: 'invoice_write_off',
                sourceRecordId: (string) $writeOff->id,
                sourceRecordVersion: 1,
                postingProfileCode: 'AR_WRITE_OFF',
                transactionDate: now()->toDateString(),
                postingDate: now()->toDateString(),
                lines: $lines,
                currencyCode: $foreign ? $docCurrency : null,
                exchangeRate: $foreign ? $rate : null,
                userId: $userId,
                ledgerDescription: "Bad debt write-off - Invoice {$invoice->invoice_number}",
                reference: $invoice->invoice_number,
            ));

            if (! $result->success) {
                throw new \RuntimeException($result->error);
            }

            $writeOff->update(['journal_entry_id' => $result->journalEntryId]);

            $newBalance = max(0.0, round((float) $invoice->balance - $amount, 2));

            $invoice->update([
                'written_off_amount' => round((float) $invoice->written_off_amount + $amount, 2),
                'balance' => $newBalance,
            ]);

            // #900 (PC6): full write-off is a settlement state — through the
            // engine like every other status change. A partial write-off
            // leaves the status untouched (no transition to record).
            $writeOffStatus = $newBalance <= 0 ? 'written_off' : $invoice->status;

            if ($writeOffStatus !== $invoice->status) {
                $this->syncInvoiceWorkflowStatus(
                    $invoice,
                    $writeOffStatus,
                    ['reason' => 'Invoice written off', 'write_off_id' => $writeOff->id],
                );
            }

            if ($invoice->customer) {
                $invoice->customer->updateOutstanding();
            }

            return $writeOff->fresh();
        });
    }

    /**
     * Issue #886 (UX4): the write-off journal preview. The lines come from
     * buildWriteOffLines() — the same builder writeOff() posts — and resolve
     * through the same PostingPolicyMapper, so the dialog can never show an
     * entry different from the one Confirm creates. Nothing is persisted.
     */
    public function previewWriteOff(Invoice $invoice, ?float $amount, int $accountId): array
    {
        $documentAmount = round((float) ($amount ?? $invoice->balance), 2);
        $description = "Bad debt write-off - Invoice {$invoice->invoice_number}";
        $lines = [];
        $error = null;
        $currency = $this->journalCurrency($invoice, null);
        $legalEntityId = $invoice->legal_entity_id !== null ? (int) $invoice->legal_entity_id : null;
        $entityBookId = $invoice->entity_book_id !== null ? (int) $invoice->entity_book_id : null;

        try {
            $account = $this->assertWriteOffAllowed($invoice, $documentAmount, $accountId);

            [$postingLines, $foreign, $docCurrency, $baseCurrency, $rate] = $this->buildWriteOffLines(
                invoice: $invoice,
                documentAmountByAccount: [(int) $account->id => $documentAmount],
                description: $description,
            );

            [$legalEntityId, $entityBookId] = $this->resolvePostingEntityBinding($invoice);
            $currency = [
                'document' => $docCurrency,
                'base' => $baseCurrency,
                'exchange_rate' => $foreign ? $rate : 1.0,
            ];

            $resolution = app(\App\Accounting\Services\PostingPolicyMapper::class)->resolve(new DomainPostingRequest(
                postingRequestId: DomainPostingRequest::generateRequestId(),
                idempotencyKey: "invoice:{$invoice->id}:write-off:preview",
                tenantId: (string) (function_exists('tenant') && tenant() ? tenant('id') : ''),
                entityId: $legalEntityId !== null ? (string) $legalEntityId : '',
                accountingBookId: $entityBookId !== null ? (string) $entityBookId : '',
                sourceCapability: 'ACC-003',
                sourceRecordType: 'invoice_write_off',
                sourceRecordId: 'preview',
                sourceRecordVersion: 1,
                postingProfileCode: 'AR_WRITE_OFF',
                transactionDate: now()->toDateString(),
                postingDate: now()->toDateString(),
                lines: $postingLines,
                currencyCode: $foreign ? $docCurrency : null,
                exchangeRate: $foreign ? $rate : null,
                ledgerDescription: $description,
                reference: $invoice->invoice_number,
            ));

            if (! $resolution->success) {
                $error = $resolution->error;
            } else {
                $unresolved = [];
                foreach ($resolution->ledgerLines as $line) {
                    if (empty($line['account_id'])) {
                        $unresolved[] = $line['account_role'] ?? 'unknown';
                    }
                }

                if ($unresolved !== []) {
                    $error = 'Account role(s) could not be resolved to a chart_of_accounts.id: '.
                        implode(', ', array_unique($unresolved)).
                        '. Configure via Entity Account Roles or update the posting profile.';
                } else {
                    $lines = $this->decorateJournalLines($resolution->ledgerLines);
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return $this->journalPayload(
            status: 'draft',
            source: 'preview',
            entry: null,
            postingDate: now()->toDateString(),
            description: $description,
            reference: $invoice->invoice_number,
            legalEntity: $this->journalLegalEntity($legalEntityId),
            accountingBookId: $entityBookId,
            currency: $currency,
            lines: $lines,
            error: $error,
        );
    }

    /**
     * Issue #879 (SA6) / #886 (UX4): the shared write-off pre-checks — open
     * posted receivable, non-zero amount within the balance, an active expense
     * account. writeOff() runs them under its row lock; the journal preview
     * enforces the same rules without writing.
     */
    private function assertWriteOffAllowed(Invoice $invoice, float $amount, int $accountId): ChartOfAccount
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('The write-off amount must be greater than zero.');
        }

        if ($invoice->journal_entry_id === null
            || (string) $invoice->lifecycle_status !== 'posted'
            || ! in_array((string) $invoice->status, ['posted', 'sent', 'partial', 'overdue'], true)) {
            throw new \RuntimeException(
                "Invoice {$invoice->invoice_number} is not an open posted receivable and cannot be written off."
            );
        }

        if ($amount > (float) $invoice->balance + 0.01) {
            throw new \RuntimeException(
                "The write-off amount ({$amount}) exceeds the outstanding balance (".
                round((float) $invoice->balance, 2).") of invoice {$invoice->invoice_number}."
            );
        }

        $account = ChartOfAccount::query()
            ->whereKey($accountId)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new \RuntimeException('The write-off account is not an active chart-of-accounts account.');
        }

        if ($account->type !== 'expense') {
            throw new \RuntimeException('The write-off account must be an expense account (bad-debt expense).');
        }

        return $account;
    }

    /**
     * Issue #879 (SA6): write-off / recovery lines: AR plus one bad-debt
     * expense line per account, at the invoice's FX snapshot rate.
     *
     * @param  array<int, float>  $documentAmountByAccount  account id => document-currency amount
     * @return array{0: array<int, DomainPostingLine>, 1: bool, 2: string, 3: string, 4: ?float}
     */
    private function buildWriteOffLines(Invoice $invoice, array $documentAmountByAccount, string $description): array
    {
        $docCurrency = strtoupper((string) ($invoice->currency_code ?: \App\Services\DocumentCurrencyResolver::resolve()));
        $baseCurrency = strtoupper((string) ($invoice->base_currency_code
            ?: \App\Services\DocumentCurrencyResolver::baseFor(
                $invoice->salesOrder?->legal_entity_id,
                $invoice->customer_id
            )));
        $foreign = $docCurrency !== $baseCurrency;
        $rate = $invoice->exchange_rate !== null ? (float) $invoice->exchange_rate : null;

        if ($foreign && ($rate === null || $rate <= 0)) {
            throw new \RuntimeException(
                "Invoice {$invoice->invoice_number} has no FX snapshot rate; cannot post the bad-debt entry."
            );
        }

        $toBase = static fn (float $documentAmount): float => $foreign
            ? round($documentAmount * $rate, 2)
            : round($documentAmount, 2);

        $totalDocument = round(array_sum($documentAmountByAccount), 2);

        $lines = [
            new DomainPostingLine(
                accountRole: 'accounts_receivable',
                amount: $toBase($totalDocument),
                currencyCode: $foreign ? $baseCurrency : null,
                originalAmount: $foreign ? $totalDocument : null,
                exchangeRate: $foreign ? $rate : null,
                description: $description,
            ),
        ];

        foreach ($documentAmountByAccount as $accountId => $documentAmount) {
            $lines[] = new DomainPostingLine(
                accountRole: 'bad_debt_expense',
                amount: $toBase((float) $documentAmount),
                currencyCode: $foreign ? $baseCurrency : null,
                originalAmount: $foreign ? (float) $documentAmount : null,
                exchangeRate: $foreign ? $rate : null,
                description: $description,
                accountId: (int) $accountId,
            );
        }

        return [$lines, $foreign, $docCurrency, $baseCurrency, $rate];
    }

    /**
     * Issue #879 (SA6): the (entity, book) a write-off/recovery posting binds
     * to — the same binding the invoice posting resolves.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function resolvePostingEntityBinding(Invoice $invoice): array
    {
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

        return [$legalEntityId, $entityBookId];
    }

    /**
     * Issue #883 (SAU1): the ONE builder behind an invoice journal.
     *
     * postInvoice() calls it with $persist = true and hands the returned
     * DomainPostingRequest to PostingEngine; the draft Journal preview calls
     * it with $persist = false and only resolves the request. Business lines,
     * account resolution, tax determination, entity binding and the currency
     * snapshot therefore come from one place — a preview cannot drift from
     * posting, and persist=false suppresses every write in the build path
     * (tax determination rows, override audits, FX/entity stamps, exception
     * reports).
     *
     * @return array{domain_request: DomainPostingRequest, deferred_shares: array, legal_entity_id: ?int, entity_book_id: ?int, unissued_quantities: array, line_batches: array, line_serials: array, doc_currency: string, base_currency: string, fx_rate: ?float, foreign: bool}
     */
    private function buildPostingDraft(Invoice $invoice, bool $persist): array
    {
        // ── Tax determination (Issue #42) ─────────────────
        $taxOverrides = array_filter([
            'jurisdiction' => $invoice->tax_jurisdiction,
            'tax_regime' => $invoice->tax_regime,
            'treatment' => $invoice->tax_treatment,
        ], fn ($v) => $v !== null);

        $taxDetermination = $this->taxDeterminationService->determineForInvoice(
            $invoice,
            $taxOverrides ?: null,
            $persist,
        );
        $primaryTaxCode = $taxDetermination['tax_lines'][0]['tax_code'] ?? null;

        // MRD R3.3: named variance between the booked document tax and the
        // determination. The GL stays booked-first (balance-safe), but the
        // divergence is loudly recorded for the accountant to resolve —
        // it is exactly the class of drift the tax report reconciliation
        // is built to surface.
        $determinationTaxTotal = round((float) $taxDetermination['tax_amount'], 2);
        $bookedTaxTotal = round((float) $invoice->tax_amount, 2);

        if ($persist && abs($determinationTaxTotal - $bookedTaxTotal) > 0.02) {
            \Illuminate\Support\Facades\Log::warning('TAX_VARIANCE: booked invoice tax differs from determination', [
                'invoice_id' => $invoice->id,
                'booked_tax' => $bookedTaxTotal,
                'determination_tax' => $determinationTaxTotal,
                'variance' => round($bookedTaxTotal - $determinationTaxTotal, 2),
            ]);

            // Issue #902: the divergence is exactly the class of drift the
            // tax reconciliation exists to surface — queue it so an
            // accountant can see, own and resolve it instead of it dying
            // in the log.
            app(\App\Platform\Exceptions\ExceptionService::class)->report(
                new \App\Platform\Exceptions\ExceptionReport(
                    type: \App\Platform\Exceptions\ExceptionType::SALES_INVOICE_TAX_VARIANCE,
                    message: "Invoice {$invoice->invoice_number}: booked tax {$bookedTaxTotal} differs from determination {$determinationTaxTotal}",
                    documentId: $invoice->id,
                    documentType: 'invoice',
                    legalEntityId: $this->invoiceLegalEntityId($invoice),
                    payload: [
                        'booked_tax' => $bookedTaxTotal,
                        'determination_tax' => $determinationTaxTotal,
                        'variance' => round($bookedTaxTotal - $determinationTaxTotal, 2),
                        'result_status' => $taxDetermination['result_status'] ?? null,
                    ],
                ),
            );
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
            if ($persist) {
                $invoice->update([
                    'exchange_rate' => $fxRate,
                    'base_currency_code' => $baseCurrency,
                ]);
            }
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
        // a cent from the AR base. Issue #880 (SA7): the revenue leg keeps
        // its EXACT converted amount and the residual is posted to the
        // existing FX gain/loss role account (MRD pricelist-multicurrency
        // FR-7.4 — "posted to the same FX account (or a rounding account
        // if configured)"). It is never absorbed at revenue.
        //
        // Issue #333: the cash-rounding delta persisted by
        // Invoice::calculateTotals() is the ONE source of truth for the
        // rounding leg — posting never re-derives it, so the document
        // total and the ledger cannot disagree. Cash rounding posts as its
        // own line, so the FX residual excludes it.
        $roundingAmount = round((float) ($invoice->rounding_amount ?? 0), 2);
        $roundingBase = abs($roundingAmount) > 0.0 ? $toBase($roundingAmount) : 0.0;

        $arBase = $toBase((float) $invoice->total);
        $revenueExactBase = $toBase($salesRevenue);

        if ($foreign) {
            $residual = round($arBase - $revenueExactBase - $taxBaseTotal - $shippingBase - $roundingBase, 2);
            $revenueBase = $revenueExactBase;
        } else {
            // Same-currency documents (rate 1.0) have no FX residual to
            // classify; a tampered-inconsistent header keeps the legacy
            // balance-through-revenue arithmetic.
            $residual = 0.0;
            $revenueBase = round($arBase - $taxBaseTotal - $shippingBase - $roundingBase, 2);
        }

        if ($persist && $foreign && abs($residual) > 0.02) {
            \Illuminate\Support\Facades\Log::warning('FX posting: rounding residual beyond 0.02 posted to the FX account', [
                'invoice_id' => $invoice->id,
                'expected_revenue_base' => $revenueExactBase,
                'posted_revenue_base' => $revenueBase,
                'residual' => $residual,
            ]);

            // Issue #902: the entry balances, but the residual no longer
            // matches a cent-level rounding drift — an accountant must
            // review the rate/document.
            app(\App\Platform\Exceptions\ExceptionService::class)->report(
                new \App\Platform\Exceptions\ExceptionReport(
                    type: \App\Platform\Exceptions\ExceptionType::SALES_INVOICE_FX_RESIDUAL,
                    message: "Invoice {$invoice->invoice_number}: FX rounding residual ".number_format($residual, 2).' posted to the FX rounding account',
                    documentId: $invoice->id,
                    documentType: 'invoice',
                    legalEntityId: $this->invoiceLegalEntityId($invoice),
                    payload: [
                        'currency_code' => $docCurrency,
                        'base_currency_code' => $baseCurrency,
                        'exchange_rate' => $fxRate,
                        'expected_revenue_base' => $revenueExactBase,
                        'posted_revenue_base' => $revenueBase,
                        'residual' => $residual,
                    ],
                ),
            );
        }

        // Issue #882 (SA9): deferred lines credit the deferred-revenue
        // role account and their allocated revenue share is recorded so a
        // schedule row can be created once the posting succeeds.
        $deferredShares = [];

        if ($salesRevenue > 0 || $revenueBase != 0) {
            // Issue #876 (SA2): revenue is recognised per resolved income
            // account (line GL override → sales-order line → product →
            // the account_mappings sales_revenue default) and carries the
            // line's department/project dimensions.
            foreach ($this->buildRevenueLines(
                $invoice,
                baseTotal: $revenueBase,
                documentTotal: $salesRevenue,
                foreign: $foreign,
                exchangeRate: $fxRate,
                baseCurrency: $baseCurrency,
                deferredShares: $deferredShares,
            ) as $revenueLine) {
                $lines[] = $revenueLine;
            }
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

        if (abs($roundingAmount) > 0.0) {
            // Issue #333: the cash-rounding adjustment is its own line on
            // the tenant's configured rounding account. Rounding UP is a
            // gain (credit); rounding DOWN is a loss (debit). The account
            // resolves from the tenant setting — when it is missing the
            // posting fails loudly (accountIdOrFail) rather than silently
            // dropping the adjustment.
            $lines[] = new DomainPostingLine(
                accountRole: 'rounding',
                amount: abs($roundingBase),
                currencyCode: $foreign ? $baseCurrency : null,
                originalAmount: $foreign ? abs($roundingAmount) : null,
                exchangeRate: $foreign ? $fxRate : null,
                description: "Cash rounding - Invoice {$invoice->invoice_number}",
                accountId: app(CashRoundingService::class)->accountIdOrFail(),
                direction: $roundingBase >= 0 ? 'credit' : 'debit',
            );
        }

        // Issue #880 (SA7): the independently rounded legs rarely drift
        // from the AR base by more than a cent. That residual belongs to
        // the FX gain/loss role (FR-7.4), split by direction so a gain is
        // a credit and a loss is a debit.
        if ($foreign && abs($residual) >= 0.01) {
            $lines[] = new DomainPostingLine(
                accountRole: $residual > 0 ? 'realized_fx_gain' : 'realized_fx_loss',
                amount: abs($residual),
                currencyCode: $baseCurrency,
                description: "FX rounding difference - Invoice {$invoice->invoice_number}",
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

            // Issue #685: a line entered in a sales UoM (2 Box) keeps that
            // quantity and per-sales-UoM price on the document, but every
            // stock/COGS number below is in the product's STOCK UoM. The
            // conversion goes through the shared ProductUomConverter.
            $lineStockQty = (float) $line->quantity;
            if ($line->uom_id && $line->uom && ! $line->uom->isStockUnit()) {
                $lineStockQty = app(ProductUomConverter::class)->toStock($line->uom, $lineStockQty);
            }

            $qty = $goodsIssueLedger->remainingForInvoiceLine($line, $lineStockQty);

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

        if ($persist && $legalEntityId && ((int) $invoice->legal_entity_id !== $legalEntityId || (int) $invoice->entity_book_id !== (int) $entityBookId)) {
            $invoice->forceFill([
                'legal_entity_id' => $legalEntityId,
                'entity_book_id' => $entityBookId,
            ])->save();
        }

        return [
            'domain_request' => new DomainPostingRequest(
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
            ),
            // Issue #882 (SA9): deferred subledger shares the posting
            // transaction turns into DeferredRevenueSchedule rows.
            'deferred_shares' => $deferredShares,

            // Issue #875: the entity/book the posting is bound to.
            'legal_entity_id' => $legalEntityId,
            'entity_book_id' => $entityBookId,

            // Issue #682/#683: stock quantities, lots and serials resolved
            // against the same ledger read the COGS used.
            'unissued_quantities' => $unissuedQuantities,
            'line_batches' => $lineBatches,
            'line_serials' => $lineSerials,

            // FR-5.7/FR-7.1: the document snapshot the header/lines display.
            'doc_currency' => $docCurrency,
            'base_currency' => $baseCurrency,
            'fx_rate' => $fxRate,
            'foreign' => $foreign,
        ];
    }

    /**
     * Issue #883 (SAU1): the Journal tab payload.
     *
     * A draft is served by buildPostingDraft() with persistence OFF, then run
     * through the same PostingPolicyMapper domainPosting() uses — the tab
     * shows the lines Post will write, and the read never writes. A posted
     * invoice is served from its actual JournalEntry.
     */
    public function previewJournal(Invoice $invoice): array
    {
        if ($invoice->journal_entry_id) {
            return $this->postedJournal($invoice);
        }

        try {
            $draft = $this->buildPostingDraft($invoice, persist: false);

            $resolution = app(\App\Accounting\Services\PostingPolicyMapper::class)
                ->resolve($draft['domain_request']);

            if (! $resolution->success) {
                return $this->draftJournal($invoice, $draft, [], $resolution->error);
            }

            $unresolved = [];
            foreach ($resolution->ledgerLines as $line) {
                if (empty($line['account_id'])) {
                    $unresolved[] = $line['account_role'] ?? 'unknown';
                }
            }

            if ($unresolved !== []) {
                return $this->draftJournal($invoice, $draft, [], 'Account role(s) could not be resolved to a chart_of_accounts.id: '.
                    implode(', ', array_unique($unresolved)).
                    '. Configure via Entity Account Roles or update the posting profile.');
            }

            return $this->draftJournal($invoice, $draft, $this->decorateJournalLines($resolution->ledgerLines));
        } catch (\Throwable $e) {
            return $this->draftJournal($invoice, null, [], $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>|null  $draft
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function draftJournal(Invoice $invoice, ?array $draft, array $lines, ?string $error = null): array
    {
        return $this->journalPayload(
            status: 'draft',
            source: 'preview',
            entry: null,
            postingDate: $invoice->invoice_date?->toDateString(),
            description: $draft['domain_request']->ledgerDescription
                ?? "Invoice #{$invoice->invoice_number} - ".($invoice->customer?->name ?? ''),
            reference: $invoice->invoice_number,
            legalEntity: $this->journalLegalEntity(
                $draft['legal_entity_id'] ?? ($invoice->legal_entity_id !== null ? (int) $invoice->legal_entity_id : null)
            ),
            accountingBookId: $draft['entity_book_id'] ?? ($invoice->entity_book_id !== null ? (int) $invoice->entity_book_id : null),
            currency: $this->journalCurrency($invoice, $draft),
            lines: $lines,
            error: $error,
        );
    }

    private function postedJournal(Invoice $invoice): array
    {
        $invoice->loadMissing([
            'journalEntry.lines.account',
            'journalEntry.lines.department',
            'journalEntry.lines.project',
            'journalEntry.lines.location',
        ]);

        $entry = $invoice->journalEntry ?? throw new \RuntimeException('Invoice has no journal entry.');

        $lines = $this->decorateJournalLines($entry->lines->map(fn ($line) => [
            'account_id' => $line->account_id,
            'debit' => $line->debit,
            'credit' => $line->credit,
            'description' => $line->description,
            'tax_code' => $line->tax_code,
            'currency_code' => $line->currency_code,
            'original_amount' => $line->original_amount,
            'exchange_rate' => $line->exchange_rate,
            'department_id' => $line->department_id,
            'project_id' => $line->project_id,
            'location_id' => $line->location_id,
        ])->all());

        $document = strtoupper((string) ($entry->original_currency_code ?: $invoice->currency_code ?: \App\Services\DocumentCurrencyResolver::resolve()));
        $base = strtoupper((string) ($invoice->base_currency_code
            ?: \App\Services\DocumentCurrencyResolver::baseFor($invoice->salesOrder?->legal_entity_id, $invoice->customer_id)));

        return $this->journalPayload(
            status: 'posted',
            source: 'actual',
            entry: [
                'id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date?->toDateString(),
                'status' => $entry->status,
                'description' => $entry->description,
                'reference' => $entry->reference,
            ],
            postingDate: $entry->entry_date?->toDateString(),
            description: (string) $entry->description,
            reference: $entry->reference,
            legalEntity: $this->journalLegalEntity($invoice->legal_entity_id !== null ? (int) $invoice->legal_entity_id : null),
            accountingBookId: $invoice->entity_book_id !== null ? (int) $invoice->entity_book_id : null,
            currency: [
                'document' => $document,
                'base' => $base,
                'exchange_rate' => $entry->exchange_rate !== null ? (float) $entry->exchange_rate : ($document === $base ? 1.0 : null),
            ],
            lines: $lines,
            error: null,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function journalPayload(
        string $status,
        string $source,
        ?array $entry,
        ?string $postingDate,
        string $description,
        ?string $reference,
        ?array $legalEntity,
        ?int $accountingBookId,
        array $currency,
        array $lines,
        ?string $error,
    ): array {
        $totalDebit = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($lines, 'credit')), 2);

        return [
            'status' => $status,
            'source' => $source,
            'preview' => $source === 'preview',
            'error' => $error,
            'journal_entry' => $entry,
            'posting_date' => $postingDate,
            'description' => $description,
            'reference' => $reference,
            'legal_entity' => $legalEntity,
            'accounting_book_id' => $accountingBookId,
            'currency' => $currency,
            'lines' => $lines,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => $lines !== [] && abs($totalDebit - $totalCredit) < 0.01,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $draft
     * @return array{document: string, base: string, exchange_rate: ?float}
     */
    private function journalCurrency(Invoice $invoice, ?array $draft): array
    {
        if ($draft !== null) {
            return [
                'document' => $draft['doc_currency'],
                'base' => $draft['base_currency'],
                'exchange_rate' => $draft['fx_rate'] !== null ? (float) $draft['fx_rate'] : null,
            ];
        }

        $document = strtoupper((string) ($invoice->currency_code ?: \App\Services\DocumentCurrencyResolver::resolve()));
        $base = strtoupper((string) ($invoice->base_currency_code
            ?: \App\Services\DocumentCurrencyResolver::baseFor($invoice->salesOrder?->legal_entity_id, $invoice->customer_id)));

        return [
            'document' => $document,
            'base' => $base,
            'exchange_rate' => $document === $base ? 1.0 : ($invoice->exchange_rate !== null ? (float) $invoice->exchange_rate : null),
        ];
    }

    /**
     * @return array{id: int, name: ?string, code: ?string}|null
     */
    private function journalLegalEntity(?int $legalEntityId): ?array
    {
        if (! $legalEntityId) {
            return null;
        }

        $entity = DB::table('legal_entities')->where('id', $legalEntityId)->first(['id', 'name', 'code']);

        return [
            'id' => $legalEntityId,
            'name' => $entity?->name,
            'code' => $entity?->code,
        ];
    }

    /**
     * Attach the account identity the ledger drill-down needs (plus the
     * analytic dimension names) to raw resolved posting lines.
     *
     * @param  array<int, array<string, mixed>>  $rawLines
     * @return array<int, array<string, mixed>>
     */
    private function decorateJournalLines(array $rawLines): array
    {
        $lines = array_map(function (array $line): array {
            $dimensions = is_array($line['dimensions'] ?? null) ? $line['dimensions'] : [];

            return [
                'account_id' => isset($line['account_id']) && $line['account_id'] !== null ? (int) $line['account_id'] : null,
                'account_code' => null,
                'account_name' => null,
                'debit' => round((float) ($line['debit'] ?? 0), 2),
                'credit' => round((float) ($line['credit'] ?? 0), 2),
                'description' => $line['description'] ?? null,
                'tax_code' => $line['tax_code'] ?? null,
                'currency_code' => $line['currency_code'] ?? null,
                'original_amount' => isset($line['original_amount']) && $line['original_amount'] !== null
                    ? round((float) $line['original_amount'], 2)
                    : null,
                'exchange_rate' => isset($line['exchange_rate']) && $line['exchange_rate'] !== null
                    ? (float) $line['exchange_rate']
                    : null,
                'department_id' => isset($line['department_id']) && $line['department_id'] !== null
                    ? (int) $line['department_id']
                    : (isset($dimensions['department_id']) ? (int) $dimensions['department_id'] : null),
                'department_name' => null,
                'project_id' => isset($line['project_id']) && $line['project_id'] !== null
                    ? (int) $line['project_id']
                    : (isset($dimensions['project_id']) ? (int) $dimensions['project_id'] : null),
                'project_name' => null,
                'location_id' => isset($line['location_id']) && $line['location_id'] !== null
                    ? (int) $line['location_id']
                    : (isset($dimensions['location_id']) ? (int) $dimensions['location_id'] : null),
                'location_name' => null,
            ];
        }, $rawLines);

        $accountIds = array_values(array_unique(array_filter(array_column($lines, 'account_id'))));
        $accounts = $accountIds === []
            ? collect()
            : \App\Models\ChartOfAccount::query()->whereIn('id', $accountIds)->get(['id', 'code', 'name'])->keyBy('id');

        $analyticIds = array_values(array_unique(array_filter(array_merge(
            array_column($lines, 'department_id'),
            array_column($lines, 'project_id'),
            array_column($lines, 'location_id'),
        ))));
        $analytics = $analyticIds === []
            ? collect()
            : DB::table('analytic_accounts')->whereIn('id', $analyticIds)->pluck('name', 'id');

        foreach ($lines as &$line) {
            $line['account_code'] = $line['account_id'] !== null ? $accounts->get($line['account_id'])?->code : null;
            $line['account_name'] = $line['account_id'] !== null ? $accounts->get($line['account_id'])?->name : null;
            $line['department_name'] = $line['department_id'] !== null ? $analytics->get($line['department_id']) : null;
            $line['project_name'] = $line['project_id'] !== null ? $analytics->get($line['project_id']) : null;
            $line['location_name'] = $line['location_id'] !== null ? $analytics->get($line['location_id']) : null;
        }
        unset($line);

        return $lines;
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

    /**
     * Issue #876 (SA2): the concrete account a line's revenue recognises to.
     *
     * Precedence — line GL override → sales-order line income account →
     * product income account → product category income account → null (the
     * profile's `sales_revenue` default resolved from account_mappings /
     * entity_account_roles).
     */
    private function resolveLineIncomeAccountId(InvoiceLine $line): ?int
    {
        if ($line->gl_account_id) {
            return (int) $line->gl_account_id;
        }

        $orderLineIncome = $line->sales_order_line_id
            ? $line->salesOrderLine?->income_account_id
            : null;

        if ($orderLineIncome) {
            return (int) $orderLineIncome;
        }

        if ($line->product?->income_account_id) {
            return (int) $line->product->income_account_id;
        }

        if ($line->product?->category?->income_account_id) {
            return (int) $line->product->category->income_account_id;
        }

        return null;
    }

    /**
     * The analytic dimensions a revenue line carries onto its journal line —
     * only the ids journal_entry_lines actually has columns for.
     */
    private function revenueLineDimensions(InvoiceLine $line): array
    {
        return array_filter([
            'department_id' => $line->department_id !== null ? (int) $line->department_id : null,
            'project_id' => $line->project_id !== null ? (int) $line->project_id : null,
        ]);
    }

    /**
     * Group the invoice's revenue-carrying lines by (income account,
     * dimensions) — or per deferred line (#882) — and keep each line's
     * tax-exclusive net as the weight the document's revenue is shared over.
     *
     * @return array{lines: array<int, array{key: string, weight: float}>, groups: array<string, array{account_id: ?int, dimensions: array, deferred: bool, line_id: ?int}>}
     */
    private function revenueAllocationPlan(Invoice $invoice): array
    {
        $lines = [];
        $groups = [];

        foreach ($invoice->lines as $line) {
            if (in_array($line->line_type ?? 'product', ['note', 'section'], true)) {
                continue;
            }

            $weight = round((float) $line->line_total - (float) $line->tax_amount, 2);

            // A credit-bearing line would need signed shares — fall back to
            // the single legacy revenue line instead of inventing one.
            if ($weight < 0) {
                return ['lines' => [], 'groups' => []];
            }

            if ($weight == 0) {
                continue;
            }

            $accountId = $this->resolveLineIncomeAccountId($line);
            $dimensions = $this->revenueLineDimensions($line);

            if ($line->is_deferred) {
                // Issue #882 (SA9): every deferred line owns its own schedule,
                // so it must never merge with another line's revenue group.
                $key = 'deferred:'.$line->id;
                $groups[$key] = [
                    'account_id' => $accountId,
                    'dimensions' => $dimensions,
                    'deferred' => true,
                    'line_id' => (int) $line->id,
                ];
            } else {
                $key = ($accountId ?? 'default').':'.json_encode($dimensions);
                $groups[$key] ??= [
                    'account_id' => $accountId,
                    'dimensions' => $dimensions,
                    'deferred' => false,
                    'line_id' => null,
                ];
            }

            $lines[] = ['key' => $key, 'weight' => $weight];
        }

        return ['lines' => $lines, 'groups' => $groups];
    }

    /**
     * Build the `sales_revenue` credit lines for one invoice posting.
     *
     * Issue #876 (SA2): revenue is split per resolved income account (and
     * per department/project group), the header discount included, because
     * the shares are taken over each line's net. The document totals are
     * never re-derived — the shares add up to EXACTLY $baseTotal and
     * $documentTotal in whole cents, so the AR leg, the tax/shipping legs
     * and the FX residual arithmetic (#880/SA7) stay identical.
     *
     * @return array<int, DomainPostingLine>
     */
    private function buildRevenueLines(
        Invoice $invoice,
        float $baseTotal,
        float $documentTotal,
        bool $foreign,
        ?float $exchangeRate,
        string $baseCurrency,
        array &$deferredShares,
    ): array {
        $description = "Sales - Invoice {$invoice->invoice_number}";
        $plan = $this->revenueAllocationPlan($invoice);

        // Nothing to split (no revenue lines, or a credit-bearing one): post
        // the whole amount to the role default, exactly as before.
        if ($plan['lines'] === []) {
            return [
                $this->revenuePostingLine(
                    amount: $baseTotal,
                    documentAmount: $documentTotal,
                    foreign: $foreign,
                    exchangeRate: $exchangeRate,
                    baseCurrency: $baseCurrency,
                    description: $description,
                ),
            ];
        }

        // ONE group owns the entire document — post it whole, no allocation.
        if (count($plan['groups']) === 1) {
            $group = reset($plan['groups']);

            if ($group['deferred']) {
                return [
                    $this->deferredRevenuePostingLine(
                        line: $invoice->lines->firstWhere('id', $group['line_id']),
                        amount: $baseTotal,
                        documentAmount: $documentTotal,
                        foreign: $foreign,
                        exchangeRate: $exchangeRate,
                        baseCurrency: $baseCurrency,
                        description: $description,
                        deferredShares: $deferredShares,
                    ),
                ];
            }

            return [
                $this->revenuePostingLine(
                    amount: $baseTotal,
                    documentAmount: $documentTotal,
                    foreign: $foreign,
                    exchangeRate: $exchangeRate,
                    baseCurrency: $baseCurrency,
                    description: $description,
                    accountId: $group['account_id'],
                    dimensions: $group['dimensions'],
                ),
            ];
        }

        // Several groups: share both totals over the lines by net weight.
        $weights = array_column($plan['lines'], 'weight');
        $baseShares = $this->allocateCents((int) round($baseTotal * 100), $weights);
        $documentShares = $foreign
            ? $this->allocateCents((int) round($documentTotal * 100), $weights)
            : [];

        $totals = [];
        foreach ($plan['lines'] as $index => $entry) {
            $key = $entry['key'];
            $totals[$key]['base'] = ($totals[$key]['base'] ?? 0.0) + $baseShares[$index] / 100;
            $totals[$key]['document'] = ($totals[$key]['document'] ?? 0.0) + ($documentShares[$index] ?? 0) / 100;
        }

        $result = [];
        foreach ($plan['groups'] as $key => $group) {
            if (! isset($totals[$key]) || round($totals[$key]['base'], 2) == 0.0) {
                continue;
            }

            if ($group['deferred']) {
                $result[] = $this->deferredRevenuePostingLine(
                    line: $invoice->lines->firstWhere('id', $group['line_id']),
                    amount: round($totals[$key]['base'], 2),
                    documentAmount: round($totals[$key]['document'], 2),
                    foreign: $foreign,
                    exchangeRate: $exchangeRate,
                    baseCurrency: $baseCurrency,
                    description: $description,
                    deferredShares: $deferredShares,
                );

                continue;
            }

            $result[] = $this->revenuePostingLine(
                amount: round($totals[$key]['base'], 2),
                documentAmount: round($totals[$key]['document'], 2),
                foreign: $foreign,
                exchangeRate: $exchangeRate,
                baseCurrency: $baseCurrency,
                description: $description,
                accountId: $group['account_id'],
                dimensions: $group['dimensions'],
            );
        }

        return $result;
    }

    /**
     * Issue #882 (SA9): the credit line for a deferred service line — it
     * credits the deferred-revenue role account and records the share the
     * posting path turns into a DeferredRevenueSchedule row. The line's
     * eventual income account is resolved now (line override → order line →
     * product → sales_revenue default) so the recognition job credits the
     * same account the invoice would have used.
     */
    private function deferredRevenuePostingLine(
        ?\App\Models\InvoiceLine $line,
        float $amount,
        float $documentAmount,
        bool $foreign,
        ?float $exchangeRate,
        string $baseCurrency,
        string $description,
        array &$deferredShares,
    ): DomainPostingLine {
        if (! $line) {
            throw new \RuntimeException('Deferred revenue plan references an invoice line that no longer exists.');
        }

        $roleResolver = app(\App\Accounting\Services\AccountRoleResolver::class);
        $deferredAccountId = $roleResolver->resolveId('deferred_revenue', 'invoice');
        $incomeAccountId = $this->resolveLineIncomeAccountId($line)
            ?? $roleResolver->resolveId('sales_revenue', 'invoice');

        $deferredShares[] = [
            'line' => $line,
            'amount' => round($amount, 2),
            'deferred_account_id' => $deferredAccountId,
            'income_account_id' => $incomeAccountId,
        ];

        return new DomainPostingLine(
            accountRole: 'deferred_revenue',
            amount: $amount,
            currencyCode: $foreign ? $baseCurrency : null,
            originalAmount: $foreign ? round($documentAmount, 2) : null,
            exchangeRate: $foreign ? $exchangeRate : null,
            description: $description,
            dimensions: $this->revenueLineDimensions($line),
            accountId: $deferredAccountId,
        );
    }

    private function revenuePostingLine(
        float $amount,
        float $documentAmount,
        bool $foreign,
        ?float $exchangeRate,
        string $baseCurrency,
        string $description,
        ?int $accountId = null,
        array $dimensions = [],
    ): DomainPostingLine {
        return new DomainPostingLine(
            accountRole: 'sales_revenue',
            amount: $amount,
            currencyCode: $foreign ? $baseCurrency : null,
            originalAmount: $foreign ? round($documentAmount, 2) : null,
            exchangeRate: $foreign ? $exchangeRate : null,
            description: $description,
            dimensions: $dimensions,
            accountId: $accountId,
        );
    }

    /**
     * Split $totalCents over the weights in WHOLE cents: every share is an
     * integer and the shares add up to $totalCents exactly. The arithmetic
     * lives in App\Support\CentsAllocation so the credit-note revenue split
     * (#882, SA9) uses the identical rule.
     *
     * @param  array<int, float>  $weights
     * @return array<int, int>
     */
    private function allocateCents(int $totalCents, array $weights): array
    {
        return \App\Support\CentsAllocation::allocate($totalCents, $weights);
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
     * #303 (X3): engine-first inclusive/exclusive resolution for callers that
     * do not send the flag (legacy screens, OCR/import, direct service use).
     */
    private function resolveLineTaxInclusive(?int $productId, mixed $invoiceDate): bool
    {
        if ($productId === null) {
            return false;
        }

        $product = \App\Models\Product::find($productId);

        if ($product === null) {
            return false;
        }

        $date = $invoiceDate !== null
            ? \Illuminate\Support\Carbon::parse($invoiceDate)->toDateString()
            : null;

        return app(TaxResolutionService::class)->isInclusiveForProduct($product, null, $date);
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

    /**
     * Issue #902: the legal entity for a queued invoice exception, resolved
     * best-effort (the posting itself stamps it later — reporting must not
     * depend on that ordering).
     */
    private function invoiceLegalEntityId(Invoice $invoice): ?int
    {
        try {
            return app(\App\Services\LegalEntityContextResolver::class)->resolveEntity(
                explicit: $invoice->legal_entity_id ? (int) $invoice->legal_entity_id : null,
                salesOrderId: $invoice->sales_order_id ? (int) $invoice->sales_order_id : null,
                customerId: $invoice->customer_id ? (int) $invoice->customer_id : null,
                required: false,
            );
        } catch (\Throwable) {
            return null;
        }
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

        // ACC-005B: channel receipts (POS / ecommerce / gateway) are
        // cash-bearing when recorded, so they must appear in the settlement
        // overlay like any other posted receipt.
        app(SettlementRecognitionService::class)->recognisePayment($payment->fresh());

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
        ]);

        $this->syncInvoiceWorkflowStatus($invoice, $status, ['reason' => 'Refund applied']);

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
        ]);

        $this->syncInvoiceWorkflowStatus($invoice, $status, ['reason' => 'Refund restored']);

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
        // Issue #879 (SA6): a written-off portion is settled and never comes
        // back as open balance when a credit note is applied.
        $newBalance = max(0.0, (float) $invoice->total - (float) $invoice->paid_amount - $credited - (float) $invoice->written_off_amount);

        $lifecycleFinal = in_array($invoice->status, ['draft', 'void', 'cancelled'], true);

        $status = $lifecycleFinal ? $invoice->status : match (true) {
            $newBalance > 0 && (float) $invoice->paid_amount > 0 => 'partial',
            $newBalance > 0 => $invoice->status === 'overdue' ? 'overdue' : 'sent',
            default => 'paid',
        };

        Invoice::where('id', $invoice->id)->update([
            'credited_amount' => $credited,
            'balance' => $newBalance,
        ]);

        $this->syncInvoiceWorkflowStatus($invoice, $status, [
            'reason' => $delta >= 0 ? 'Credit note applied' : 'Credit note reversed',
            'credited_delta' => $delta,
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
            ->get(['id', 'total', 'paid_amount', 'credited_amount', 'written_off_amount']);

        $credit = 0.0;

        foreach ($rows as $row) {
            // Issue #879 (SA6): a written-off portion is settled, so it cannot
            // absorb credit — only the truly open remainder can.
            $remaining = max(0.0, (float) $row->total - (float) $row->paid_amount - (float) $row->written_off_amount);
            $credit += max(0.0, (float) $row->credited_amount - $remaining);
        }

        Customer::where('id', $customer->id)->update(['credit_balance' => round($credit, 2)]);
    }

    /**
     * Issue #879 (SA6): release written-off amounts as a payment allocation
     * recovers them — FIFO over the write-off records, posting DR AR /
     * CR bad-debt expense through the PostingEngine.
     */
    private function recoverWrittenOffAmount(Invoice $invoice, float $settledAmount, Payment $payment): void
    {
        $amount = round(min($settledAmount, (float) $invoice->written_off_amount), 2);
        if ($amount <= 0) {
            return;
        }

        // The allocation event is the recovery posting's source document: its
        // id keeps the posting idempotent for one (payment, invoice) settlement.
        // applied_amount is the FULL settled amount (not just the recovered
        // part) so a payment that also clears the open balance cannot be
        // allocated again.
        $application = \App\Models\PaymentApplication::firstOrCreate(
            [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
            ],
            [
                'tenant_id' => $invoice->tenant_id
                    ?? (function_exists('tenant') && tenant() ? tenant()->id : null),
                'applied_amount' => round($settledAmount, 2),
                'currency_code' => $invoice->currency_code ?? null,
                'created_by' => TenantUserHelper::resolveTenantUserId(),
            ]
        );

        if (\App\Models\JournalEntryLink::where('source_document_type', 'write_off_recovery')
            ->where('source_document_id', $application->id)
            ->whereNull('superseded_at')
            ->exists()) {
            return; // already recovered for this allocation
        }

        $byAccount = [];
        $remaining = $amount;

        foreach (InvoiceWriteOff::where('invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get() as $record) {
            if ($remaining <= 0) {
                break;
            }

            $open = $record->openAmount();
            if ($open <= 0) {
                continue;
            }

            $take = min($open, $remaining);
            $record->update(['recovered_amount' => round((float) $record->recovered_amount + $take, 2)]);
            $byAccount[(int) $record->account_id] = round(($byAccount[(int) $record->account_id] ?? 0) + $take, 2);
            $remaining = round($remaining - $take, 2);
        }

        $recovered = round($amount - $remaining, 2);
        if ($recovered <= 0 || $byAccount === []) {
            return;
        }

        [$lines, $foreign, $docCurrency, $baseCurrency, $rate] = $this->buildWriteOffLines(
            invoice: $invoice,
            documentAmountByAccount: $byAccount,
            description: "Bad debt recovered - Invoice {$invoice->invoice_number}",
        );

        [$legalEntityId, $entityBookId] = $this->resolvePostingEntityBinding($invoice);

        $result = $this->postingEngine->domainPosting(new DomainPostingRequest(
            postingRequestId: DomainPostingRequest::generateRequestId(),
            idempotencyKey: "payment-application:{$application->id}:write-off-recovery",
            tenantId: (string) (function_exists('tenant') && tenant() ? tenant('id') : ''),
            entityId: $legalEntityId !== null ? (string) $legalEntityId : '',
            accountingBookId: $entityBookId !== null ? (string) $entityBookId : '',
            sourceCapability: 'ACC-003',
            sourceRecordType: 'write_off_recovery',
            sourceRecordId: (string) $application->id,
            sourceRecordVersion: 1,
            postingProfileCode: 'AR_WRITE_OFF_RECOVERY',
            transactionDate: now()->toDateString(),
            postingDate: now()->toDateString(),
            lines: $lines,
            currencyCode: $foreign ? $docCurrency : null,
            exchangeRate: $foreign ? $rate : null,
            userId: TenantUserHelper::requireTenantUserId($payment->received_by),
            ledgerDescription: "Bad debt recovered - Invoice {$invoice->invoice_number}",
            reference: $invoice->invoice_number,
        ));

        if (! $result->success) {
            throw new \RuntimeException($result->error);
        }

        $invoice->update([
            'written_off_amount' => round((float) $invoice->written_off_amount - $recovered, 2),
        ]);
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
        // Issue #879 (SA6): the written-off portion stays settled — reversing
        // an allocation must not resurrect it as an open balance.
        $newBalance = max(0.0, round(
            (float) $invoice->total - $newPaid - (float) $invoice->written_off_amount,
            2
        ));
        $newStatus = match (true) {
            $newBalance <= 0 && (float) $invoice->written_off_amount > 0 => 'written_off',
            $newBalance <= 0 => 'paid',
            $newPaid > 0 => 'partial',
            default => 'sent',
        };

        Invoice::where('id', $invoice->id)->update([
            'paid_amount' => $newPaid,
            'balance' => $newBalance,
        ]);

        $this->syncInvoiceWorkflowStatus($invoice, $newStatus, [
            'reason' => 'Payment allocation reversed',
            'reversed_amount' => $amount,
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

    /**
     * #900 (PC6): settlement statuses (sent / partial / paid / overdue) are
     * derived from the paid/credited amounts. The status write goes through the
     * workflow engine's wildcard sync transitions so every status change is
     * logged and audited; the amount columns keep their existing writes.
     */
    private function syncInvoiceWorkflowStatus(Invoice $invoice, string $status, array $context = []): void
    {
        $action = match ($status) {
            'sent' => 'sync_sent',
            'partial' => 'sync_partial',
            'paid' => 'sync_paid',
            'overdue' => 'sync_overdue',
            // #879 (SA6): a fully written-off invoice's settlement state is
            // 'written_off' — derived from the written_off_amount dimension.
            'written_off' => 'sync_written_off',
            default => throw new \InvalidArgumentException("No workflow sync action for invoice status '{$status}'."),
        };

        app(SalesWorkflowService::class)->transition($invoice, $action, $context);
    }

    private function syncInvoicePaymentState(Invoice $invoice, float $paymentAmount, ?Payment $payment = null): void
    {
        $invoice = $invoice->fresh() ?? $invoice;

        $beforeState = [
            'paid_amount' => $invoice->paid_amount,
            'balance' => $invoice->balance,
            'status' => $invoice->status,
        ];

        // Issue #879 (SA6): a payment settling a written-off invoice first
        // reinstates the receivable and reverses the bad-debt expense (DR AR /
        // CR bad-debt) for the recovered portion — posted through the
        // PostingEngine, never written directly.
        if ($payment && (float) $invoice->written_off_amount > 0) {
            $this->recoverWrittenOffAmount($invoice, $paymentAmount, $payment);
            $invoice->refresh();
        }

        Invoice::where('id', $invoice->id)
            ->increment('paid_amount', $paymentAmount);

        $invoice->refresh();

        // Issue #879 (SA6): a written-off amount settles the invoice exactly
        // like cash does; excluding it here would resurrect a written-off
        // balance the next time a payment is applied.
        $newBalance = max(0.0, round(
            (float) $invoice->total
            - (float) $invoice->paid_amount
            - (float) $invoice->written_off_amount,
            2
        ));

        $stillWrittenOff = (float) $invoice->written_off_amount > 0;

        Invoice::where('id', $invoice->id)->update([
            'balance' => $newBalance,
        ]);

        $this->syncInvoiceWorkflowStatus(
            $invoice,
            $newBalance <= 0
                ? ($stillWrittenOff ? 'written_off' : 'paid')
                : ((float) $invoice->paid_amount > 0 ? 'partial' : $invoice->status),
            ['reason' => 'Payment settled', 'payment_id' => $payment?->id],
        );

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
