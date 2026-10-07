<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Exceptions\QuotationHasLiveSalesOrderException;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JournalEntry;
use App\Models\Quotation;
use App\Models\QuotationLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;

class ReplacementDraftService
{
    public function __construct(
        private DocumentVersionLinkService $linkService,
        private DocumentLifecycleService $lifecycleService,
        private PostingEngineInterface $postingEngine,
    ) {}

    public function createInvoiceReplacementDraft(Invoice $original, CorrectionRequest $request): Invoice
    {
        // Hostile re-review (#178/#179): the void of the original and the
        // creation of the replacement must be one atomic unit — a failure
        // while cloning must not leave a cancelled original behind.
        return DB::transaction(fn () => $this->doCreateInvoiceReplacementDraft($original, $request));
    }

    /**
     * #527 (SALES-ODOO): "full refund and new draft invoice" — the original
     * has been fully credited by $creditNote; the replacement draft is the
     * corrected re-issue. It reuses the replacement clone below and adds what
     * a re-issue needs to be the same invoice again: the credit-note link,
     * the original's currency / rate snapshot, legal entity and payment
     * term, and each line's own fields (tax mode, GL account, analytics, UoM)
     * rather than only the amount columns. The existing replacement flow is
     * unchanged.
     */
    public function createInvoiceReissueDraft(Invoice $original, CustomerCreditNote $creditNote, CorrectionRequest $request): Invoice
    {
        return DB::transaction(fn () => $this->doCreateInvoiceReplacementDraft($original, $request, [
            'credit_note_id' => $creditNote->id,
            'currency_code' => $original->currency_code,
            'exchange_rate' => $original->exchange_rate,
            'base_currency_code' => $original->base_currency_code,
            'legal_entity_id' => $original->legal_entity_id,
            'entity_book_id' => $original->entity_book_id,
            'payment_term_id' => $original->payment_term_id,
            'payment_terms_snapshot' => $original->payment_terms_snapshot,
        ], copyLineFields: true));
    }

    private function doCreateInvoiceReplacementDraft(
        Invoice $original,
        CorrectionRequest $request,
        array $extraAttributes = [],
        bool $copyLineFields = false,
    ): Invoice {
        $correctionGroupId = $original->correction_group_id ?? Str::uuid()->toString();

        $original->update([
            'correction_group_id' => $correctionGroupId,
            'is_active_version' => false,
            'lifecycle_status' => 'superseded',
            'superseded_at' => now(),
        ]);

        $nextVersion = ($original->version_number ?? 1) + 1;

        $replacement = Invoice::create(array_merge([
            'invoice_number' => $original->invoice_number.'-R'.str_pad($nextVersion, 2, '0', STR_PAD_LEFT),
            'customer_id' => $original->customer_id,
            'invoice_date' => $original->invoice_date,
            'due_date' => $original->due_date,
            'customer_reference' => $original->customer_reference,
            'internal_reference' => $original->internal_reference,
            'attention_to' => $original->attention_to,
            'contact_email' => $original->contact_email,
            'contact_phone' => $original->contact_phone,
            'billing_address_snapshot' => $original->billing_address_snapshot,
            'shipping_address_snapshot' => $original->shipping_address_snapshot,
            'company_registration_number' => $original->company_registration_number,
            'tax_registration_number' => $original->tax_registration_number,
            'status' => 'draft',
            'subtotal' => $original->subtotal,
            'tax_amount' => $original->tax_amount,
            'discount_amount' => $original->discount_amount,
            'total' => $original->total,
            'paid_amount' => 0,
            'balance' => $original->total,
            'notes' => $original->notes,
            'terms' => $original->terms,
            'sent_at' => null,
            'created_by' => $request->userId ?? Auth::id(),
            'origin_type' => 'replacement',
            'origin_reference_type' => 'invoice',
            'origin_reference_id' => $original->id,
            'lifecycle_status' => 'draft',
            'settlement_status' => 'unpaid',
            'correction_group_id' => $correctionGroupId,
            'original_document_id' => $original->id,
            'correction_type' => 'replacement',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'version_number' => $nextVersion,
            'is_active_version' => true,
        ], $extraAttributes));

        foreach ($original->lines as $originalLine) {
            $lineFields = $copyLineFields
                ? collect($originalLine->only($originalLine->getFillable()))
                    ->except(['invoice_id', 'sales_order_line_id'])
                    ->all()
                : [];

            InvoiceLine::create([
                ...$lineFields,
                'invoice_id' => $replacement->id,
                'product_id' => $originalLine->product_id,
                'description' => $originalLine->description,
                'quantity' => $originalLine->quantity,
                'unit_price' => $originalLine->unit_price,
                'tax_rate' => $originalLine->tax_rate,
                'tax_amount' => $originalLine->tax_amount,
                'discount_amount' => $originalLine->discount_amount,
                'line_total' => $originalLine->line_total,
            ]);
        }

        $original->update([
            'replacement_document_id' => $replacement->id,
        ]);

        $this->linkService->linkReplacement($original, $replacement, $request);

        return $replacement;
    }

    public function createQuotationReplacementDraft(Quotation $original, CorrectionRequest $request): Quotation
    {
        // Hostile re-review (#178/#179): atomic AND serialized — the original
        // is row-locked so concurrent rejects cannot both create a v2 draft.
        return DB::transaction(function () use ($original, $request) {
            $locked = Quotation::whereKey($original->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled' || $locked->is_active_version === false) {
                throw new \RuntimeException('This quotation has already been rejected or superseded.');
            }

            // Issue #336 (Decision 7 Oct 2026): a quotation with a live
            // (non-cancelled) sales order cannot be rejected/revised — the
            // sales order must be cancelled first.
            if ($locked->sales_order_id !== null) {
                $salesOrder = SalesOrder::whereKey($locked->sales_order_id)->lockForUpdate()->first();
                if ($salesOrder && ! $salesOrder->isCancelled()) {
                    throw new QuotationHasLiveSalesOrderException;
                }
            }

            return $this->doCreateQuotationReplacementDraft($locked, $request);
        });
    }

    /**
     * Issue #178/#179: quotation rejection → auto-versioned replacement draft.
     * The original is voided (cancelled + superseded) and the replacement keeps
     * the same correction group with version_number+1, cloned header + lines.
     */
    private function doCreateQuotationReplacementDraft(Quotation $original, CorrectionRequest $request): Quotation
    {
        $correctionGroupId = $original->correction_group_id ?? Str::uuid()->toString();

        $original->update([
            'correction_group_id' => $correctionGroupId,
            'is_active_version' => false,
            'superseded_at' => now(),
            'status' => 'cancelled',
        ]);

        $nextVersion = $this->nextQuotationVersion($original, $correctionGroupId);

        $replacement = Quotation::create([
            'quotation_number' => $original->quotation_number.'-R'.str_pad((string) $nextVersion, 2, '0', STR_PAD_LEFT),
            'customer_id' => $original->customer_id,
            'quotation_date' => $original->quotation_date,
            'valid_until' => $original->valid_until,
            'status' => 'draft',
            'subtotal' => $original->subtotal,
            'tax_amount' => $original->tax_amount,
            'discount_amount' => $original->discount_amount,
            'discount_type' => $original->discount_type,
            'discount_value' => $original->discount_value,
            'total' => $original->total,
            'notes' => $original->notes,
            'terms' => $original->terms,
            'customer_reference' => $original->customer_reference,
            'internal_reference' => $original->internal_reference,
            'credit_terms' => $original->credit_terms,
            'attention_to' => $original->attention_to,
            'billing_contact_id' => $original->billing_contact_id,
            'shipping_contact_id' => $original->shipping_contact_id,
            'contact_email' => $original->contact_email,
            'contact_phone' => $original->contact_phone,
            'salesperson' => $original->salesperson,
            'delivery_lead_time' => $original->delivery_lead_time,
            'shipping_method' => $original->shipping_method,
            'shipping_terms' => $original->shipping_terms,
            'validity' => $original->validity,
            'case_number' => $original->case_number,
            'date_of_case' => $original->date_of_case,
            'case_location' => $original->case_location,
            'patient_name' => $original->patient_name,
            'cost_center_id' => $original->cost_center_id,
            'delivery_date' => $original->delivery_date,
            'pr_no' => $original->pr_no,
            'contract_no' => $original->contract_no,
            'plant_code' => $original->plant_code,
            'company_code' => $original->company_code,
            'billing_address_snapshot' => $original->billing_address_snapshot,
            'shipping_address_snapshot' => $original->shipping_address_snapshot,
            // Issue #336: the replacement draft never inherits the original's sales order.
            'sales_order_id' => null,
            'created_by' => $request->userId ?? Auth::id(),
            'correction_group_id' => $correctionGroupId,
            'original_document_id' => $original->id,
            'correction_type' => 'replacement',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'version_number' => $nextVersion,
            'is_active_version' => true,
        ]);

        foreach ($original->lines as $originalLine) {
            QuotationLine::create([
                'quotation_id' => $replacement->id,
                'line_type' => $originalLine->line_type,
                'sequence' => $originalLine->sequence,
                'product_id' => $originalLine->product_id,
                'model_number' => $originalLine->model_number,
                'description' => $originalLine->description,
                'product_notes' => $originalLine->product_notes,
                'quantity' => $originalLine->quantity,
                'unit_price' => $originalLine->unit_price,
                'tax_rate' => $originalLine->tax_rate,
                'tax_amount' => $originalLine->tax_amount,
                'discount_amount' => $originalLine->discount_amount,
                'line_total' => $originalLine->line_total,
                // Issue #338: a revision keeps which lines are optional, so
                // its copied totals still exclude them.
                'is_optional' => (bool) $originalLine->is_optional,
            ]);
        }

        $original->update(['replacement_document_id' => $replacement->id]);

        $this->linkService->linkReplacement($original, $replacement, $request);

        return $replacement;
    }

    /**
     * Issue #414 (DX4): partial acceptance. The customer accepted only some of
     * the quotation's lines; the lines they did NOT accept move to a new open
     * revision (same correction group, next version) so the salesperson can
     * follow them up. Unlike a rejection the original is neither cancelled nor
     * superseded — it goes on to be confirmed for the accepted lines.
     *
     * The caller owns the transaction and recomputes both documents' totals.
     *
     * @param  array<int, int>  $lineIds  lines of $original that move to the remainder
     */
    public function createQuotationRemainderRevision(Quotation $original, array $lineIds, CorrectionRequest $request): Quotation
    {
        if ($lineIds === []) {
            throw new \InvalidArgumentException('A remainder revision needs at least one line.');
        }

        $correctionGroupId = $original->correction_group_id ?? Str::uuid()->toString();

        if ($original->correction_group_id === null) {
            $original->update(['correction_group_id' => $correctionGroupId]);
        }

        $nextVersion = $this->nextQuotationVersion($original, $correctionGroupId);

        // The whole header (currency + FX snapshot, terms, Incoterm, payment
        // term, #550 requirements …) carries over; acceptance, order link and
        // version bookkeeping start afresh.
        $remainder = $original->replicate([
            'acceptance_token', 'acceptance_expires_at', 'accepted_at', 'signer_name', 'signer_email',
            'signature_data', 'acceptance_source', 'sales_order_id', 'replacement_document_id',
            'superseded_at', 'lock_version',
        ]);
        $remainder->fill([
            'quotation_number' => $original->quotation_number.'-R'.str_pad((string) $nextVersion, 2, '0', STR_PAD_LEFT),
            'status' => 'draft',
            'created_by' => $request->userId ?? $original->created_by,
            'correction_group_id' => $correctionGroupId,
            'original_document_id' => $original->id,
            'correction_type' => 'partial_remainder',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'version_number' => $nextVersion,
            'is_active_version' => true,
        ]);
        $remainder->save();

        // The unaccepted lines themselves move (every column — UoM, tax rule,
        // pricing trace, analytics — stays exactly as quoted).
        QuotationLine::query()
            ->where('quotation_id', $original->id)
            ->whereIn('id', $lineIds)
            ->update(['quotation_id' => $remainder->id]);

        $this->linkService->linkReplacement($original, $remainder, $request);

        return $remainder;
    }

    /**
     * The next free version number in the quotation's correction group (a
     * group can hold a partial-acceptance remainder next to the original).
     */
    private function nextQuotationVersion(Quotation $original, string $correctionGroupId): int
    {
        $max = (int) Quotation::withTrashed()
            ->where('correction_group_id', $correctionGroupId)
            ->max('version_number');

        return max($max, (int) ($original->version_number ?? 1)) + 1;
    }

    public function createBillReplacementDraft(Bill $original, CorrectionRequest $request): Bill
    {
        // Hostile re-review (#178/#179): the void of the original and the
        // creation of the replacement must be one atomic unit — a failure
        // while cloning must not leave a cancelled original behind.
        return DB::transaction(fn () => $this->doCreateBillReplacementDraft($original, $request));
    }

    public function createJournalReplacementDraft(JournalEntry $original, CorrectionRequest $request): JournalEntry
    {
        // Hostile re-review (#178/#179): the void of the original and the
        // creation of the replacement must be one atomic unit — a failure
        // while cloning must not leave a cancelled original behind.
        return DB::transaction(fn () => $this->doCreateJournalReplacementDraft($original, $request));
    }

    public function createDeliveryOrderReplacementDraft(DeliveryOrder $original, CorrectionRequest $request): DeliveryOrder
    {
        // Hostile re-review (#178/#179): atomic AND serialized — the original
        // is row-locked so concurrent rejects cannot both create a v2 draft.
        return DB::transaction(function () use ($original, $request) {
            $locked = DeliveryOrder::whereKey($original->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled' || $locked->is_active_version === false) {
                throw new \RuntimeException('This delivery order has already been rejected or superseded.');
            }

            return $this->doCreateDeliveryOrderReplacementDraft($locked, $request);
        });
    }

    /**
     * Issue #178/#179: delivery-order rejection → auto-versioned replacement.
     */
    private function doCreateDeliveryOrderReplacementDraft(DeliveryOrder $original, CorrectionRequest $request): DeliveryOrder
    {
        $correctionGroupId = $original->correction_group_id ?? Str::uuid()->toString();

        $original->update([
            'correction_group_id' => $correctionGroupId,
            'is_active_version' => false,
            'superseded_at' => now(),
            'status' => 'cancelled',
        ]);

        $nextVersion = ($original->version_number ?? 1) + 1;

        $replacement = DeliveryOrder::create([
            'delivery_order_number' => $original->delivery_order_number.'-R'.str_pad((string) $nextVersion, 2, '0', STR_PAD_LEFT),
            'sales_order_id' => $original->sales_order_id,
            'customer_id' => $original->customer_id,
            'warehouse_id' => $original->warehouse_id,
            'status' => 'draft',
            'delivery_date' => $original->delivery_date,
            'expected_date' => $original->expected_date,
            'payment_terms' => $original->payment_terms,
            'source' => $original->source,
            'external_reference' => $original->external_reference,
            'po_number' => $original->po_number,
            'salesperson' => $original->salesperson,
            'attention_to' => $original->attention_to,
            'quotation_id' => $original->quotation_id,
            'carrier' => $original->carrier,
            'tracking_number' => $original->tracking_number,
            'shipping_address' => $original->shipping_address,
            'shipping_address_overridden' => $original->shipping_address_overridden,
            'delivery_address_snapshot' => $original->delivery_address_snapshot,
            'notes' => $original->notes,
            'total' => $original->total,
            'created_by' => $request->userId ?? Auth::id(),
            'correction_group_id' => $correctionGroupId,
            'original_document_id' => $original->id,
            'correction_type' => 'replacement',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'version_number' => $nextVersion,
            'is_active_version' => true,
        ]);

        foreach ($original->lines as $originalLine) {
            DeliveryOrderLine::create([
                'delivery_order_id' => $replacement->id,
                'line_type' => $originalLine->line_type,
                'sequence' => $originalLine->sequence,
                'sales_order_line_id' => $originalLine->sales_order_line_id,
                'product_id' => $originalLine->product_id,
                'model_number' => $originalLine->model_number,
                'description' => $originalLine->description,
                'product_notes' => $originalLine->product_notes,
                'patient_name' => $originalLine->patient_name,
                'quantity_ordered' => $originalLine->quantity_ordered,
                'quantity_delivered' => 0,
                'unit_price' => $originalLine->unit_price,
                'line_total' => $originalLine->line_total,
                'unit' => $originalLine->unit,
                'lot_number' => $originalLine->lot_number,
                'batch_number' => $originalLine->batch_number,
                'expiry_date' => $originalLine->expiry_date,
            ]);
        }

        $original->update(['replacement_document_id' => $replacement->id]);

        $this->linkService->linkReplacement($original, $replacement, $request);

        return $replacement;
    }

    private function doCreateBillReplacementDraft(Bill $original, CorrectionRequest $request): Bill
    {
        $correctionGroupId = $original->correction_group_id ?? Str::uuid()->toString();

        $original->update([
            'correction_group_id' => $correctionGroupId,
            'is_active_version' => false,
            'lifecycle_status' => 'superseded',
            'superseded_at' => now(),
        ]);

        $nextVersion = ($original->version_number ?? 1) + 1;

        $replacement = Bill::create([
            'bill_number' => $original->bill_number.'-R'.str_pad($nextVersion, 2, '0', STR_PAD_LEFT),
            'vendor_id' => $original->vendor_id,
            'purchase_order_id' => $original->purchase_order_id,
            'goods_receipt_id' => $original->goods_receipt_id,
            'bill_date' => $original->bill_date,
            'due_date' => $original->due_date,
            'vendor_reference' => $original->vendor_reference,
            'internal_reference' => $original->internal_reference,
            'attention_to' => $original->attention_to,
            'contact_email' => $original->contact_email,
            'contact_phone' => $original->contact_phone,
            'vendor_address_snapshot' => $original->vendor_address_snapshot,
            'remittance_address_snapshot' => $original->remittance_address_snapshot,
            'company_registration_number' => $original->company_registration_number,
            'tax_registration_number' => $original->tax_registration_number,
            'status' => 'draft',
            'bill_type' => $original->bill_type,
            'subtotal' => $original->subtotal,
            'tax_amount' => $original->tax_amount,
            'discount_amount' => $original->discount_amount,
            'total' => $original->total,
            'paid_amount' => 0,
            'balance' => $original->total,
            'notes' => $original->notes,
            'terms' => $original->terms,
            'received_at' => null,
            'created_by' => $request->userId ?? Auth::id(),
            'origin_type' => 'replacement',
            'origin_reference_type' => 'bill',
            'origin_reference_id' => $original->id,
            'lifecycle_status' => 'draft',
            'settlement_status' => 'unpaid',
            'correction_group_id' => $correctionGroupId,
            'original_document_id' => $original->id,
            'correction_type' => 'replacement',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'version_number' => $nextVersion,
            'is_active_version' => true,
        ]);

        foreach ($original->lines as $originalLine) {
            BillLine::create([
                'bill_id' => $replacement->id,
                'product_id' => $originalLine->product_id,
                'description' => $originalLine->description,
                'quantity' => $originalLine->quantity,
                'unit_price' => $originalLine->unit_price,
                'tax_rate' => $originalLine->tax_rate,
                'tax_amount' => $originalLine->tax_amount,
                'discount_amount' => $originalLine->discount_amount,
                'line_total' => $originalLine->line_total,
            ]);
        }

        $original->update([
            'replacement_document_id' => $replacement->id,
        ]);

        $this->linkService->linkReplacement($original, $replacement, $request);

        return $replacement;
    }

    private function doCreateJournalReplacementDraft(JournalEntry $original, CorrectionRequest $request): JournalEntry
    {
        $result = $this->postingEngine->createReplacementDraft($original, $request);

        if (! $result->success || ! $result->journalEntry) {
            throw new \RuntimeException('Failed to create journal replacement draft: '.($result->error ?? 'Unknown error'));
        }

        $this->linkService->linkReplacement($original, $result->journalEntry, $request);

        return $result->journalEntry;
    }
}
