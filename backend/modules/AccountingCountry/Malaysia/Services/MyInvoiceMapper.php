<?php

namespace Modules\AccountingCountry\Malaysia\Services;

use App\Accounting\Contracts\InvoiceSchemaMapperContract;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Malaysia MyInvoice Mapper
 * Maps GL invoices to MyInvoice portal format (JSON-based)
 */
class MyInvoiceMapper implements InvoiceSchemaMapperContract
{
    /**
     * LHDN standard general-public TIN for consolidated/end-consumer invoices.
     * This is a fixed LHDN constant, not an identity credential — safe to hardcode.
     */
    private const LHDN_GENERAL_PUBLIC_TIN = 'EI00000000010';

    /** Buyer types the Malaysia pack recognises (stored on customers.buyer_type). */
    private const BUYER_TYPE_BUSINESS = 'business';

    private const BUYER_TYPE_INDIVIDUAL = 'individual';

    private const BUYER_TYPE_GOVERNMENT = 'government';

    private const BUYER_TYPE_FOREIGN = 'foreign';

    private const BUYER_TYPES = [
        self::BUYER_TYPE_BUSINESS,
        self::BUYER_TYPE_INDIVIDUAL,
        self::BUYER_TYPE_GOVERNMENT,
        self::BUYER_TYPE_FOREIGN,
    ];

    /**
     * Buyer types that must carry a real TIN — the general-public TIN
     * (EI00000000010) is only valid for individual buyers / consolidated B2C.
     */
    private const NON_INDIVIDUAL_BUYER_TYPES = [
        self::BUYER_TYPE_BUSINESS,
        self::BUYER_TYPE_GOVERNMENT,
        self::BUYER_TYPE_FOREIGN,
    ];

    /**
     * Map internal-expense JournalEntry as a MyInvois document.
     *
     * @param  bool  $selfBilled  When true (default): LHDN self-billed invoice (type 11 per the
     *                            e-Invoice API v1.0 spec). When false: regular invoice (type 01).
     *                            LHDN portal account does not have self-billing activated.
     */
    public function mapJournalEntry(\App\Models\JournalEntry $journalEntry, bool $selfBilled = true): array
    {
        $issuedDate = $journalEntry->entry_date;

        // Debit lines = expense side → invoice line items
        $debitLines = $journalEntry->lines()
            ->with('account')
            ->where('debit', '>', 0)
            ->get();

        $lineItems = $debitLines->values()->map(function ($line, int $index) {
            $amount = round((float) $line->debit, 2);

            return [
                'line_number' => $index + 1,
                'item_id' => (string) ($line->id ?? ($index + 1)),
                'description' => $line->description ?? ($line->account->name ?? 'Internal Expense'),
                'quantity' => 1.0,
                'unit_code' => 'C62',
                'unit_price' => $amount,
                'line_amount' => $amount,
                'discount_amount' => 0.0,
                'discount_percent' => 0.0,
                'tax_type' => 'E',
                'sst_rate' => 0.0,
                'sst_amount' => 0.0,
                'tax_exemption_reason' => null,
            ];
        })->toArray();

        $totalDebit = round((float) $journalEntry->getTotalDebit(), 2);

        if (empty($lineItems)) {
            $lineItems[] = [
                'line_number' => 1,
                'item_id' => (string) $journalEntry->id,
                'description' => $journalEntry->description ?? 'Internal Expense',
                'quantity' => 1.0,
                'unit_code' => 'C62',
                'unit_price' => $totalDebit,
                'line_amount' => $totalDebit,
                'discount_amount' => 0.0,
                'discount_percent' => 0.0,
                'tax_type' => 'E',
                'sst_rate' => 0.0,
                'sst_amount' => 0.0,
                'tax_exemption_reason' => null,
            ];
        }

        // For self-billed internal expenses, the company is both the issuer (supplier)
        // and the buyer. LHDN rejects general/generic TINs as the issuer, so the
        // company's own registered TIN must be used on the supplier side.
        $company = $this->resolveSupplier(null);

        $dateStr = $issuedDate instanceof \Carbon\Carbon
            ? $issuedDate->format('Y-m-d')
            : (string) $issuedDate;
        $datetimeStr = $issuedDate instanceof \Carbon\Carbon
            ? $issuedDate->format('c')
            : $dateStr;

        $mapped = [
            'document_type' => $selfBilled ? 'self_billed_invoice' : 'invoice',
            '_buyer_same_as_supplier' => true,  // tells applyProviderPayloadDefaults to copy supplier TIN→buyer
            'profile_version' => 'myinvoice-v1',
            'invoice_id' => $journalEntry->id,
            'invoice_number' => $journalEntry->entry_number,
            'invoice_date' => $dateStr,
            'issue_datetime' => $datetimeStr,
            'due_date' => null,
            'currency_code' => 'MYR',
            'supplier' => $this->mapParty($company, true),
            'buyer' => $this->mapParty($company, true),
            'line_items' => $lineItems,
            'totals' => [
                'subtotal' => $totalDebit,
                'discount_total' => 0.0,
                'subtotal_after_discount' => $totalDebit,
                'sst_amount' => 0.0,
                'gross_amount' => $totalDebit,
                'paid_amount' => 0.0,
                'amount_due' => $totalDebit,
            ],
            'tax_summary' => [
                'sst_rate' => 0.0,
                'sst_amount' => 0.0,
                'sst_exemption' => true,
                'sst_exemption_code' => null,
            ],
            'payment_terms' => null,
            'narration' => $journalEntry->description ?? null,
            'attachment_reference' => null,
        ];

        Log::info('myinvois:mapJournalEntry — done', [
            'journal_entry_id' => $journalEntry->id,
            'entry_number' => $journalEntry->entry_number,
            'line_item_count' => count($lineItems),
            'total_debit' => $totalDebit,
        ]);

        return $mapped;
    }

    /**
     * Map GL invoice to MyInvoice format
     */
    public function mapInvoice(Invoice $invoice): array
    {
        Log::info('myinvois:mapInvoice — begin', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ]);

        $issuedDate = $invoice->invoice_date ?? $invoice->issued_date ?? now();
        $currency = $invoice->currency_code ?? 'MYR';

        $lineCollection = $invoice->lineItems ?? $invoice->lines ?? collect();

        // G-37 (#274): e-invoice tax identity is sourced from the PERSISTED
        // determination (treatment, rate, amount), not recomputed guesses.
        // No determination (or no tenant context, e.g. pure unit tests) keeps
        // the legacy booked-line behaviour.
        $determinationLines = [];
        if (function_exists('tenant') && tenant() !== null) {
            $determinationLines = app(\App\Services\TaxDeterminationService::class)
                ->latestDeterminationLines('invoice', (int) $invoice->id);
        }

        $lineItems = $lineCollection->map(function ($line, int $index) use ($determinationLines) {
            return $this->mapLineItem($line, $index + 1, $determinationLines[$line->id] ?? null);
        })->toArray();

        if (empty($lineItems)) {
            $lineItems[] = $this->buildFallbackLineItem($invoice);
        }

        $supplier = $this->resolveSupplier($invoice);
        $buyer = $invoice->customer;

        $supplierParty = $this->mapParty($supplier, true);
        $buyerParty = $this->mapParty($buyer, false);

        $mapped = [
            'document_type' => 'invoice',
            'profile_version' => 'myinvoice-v1',
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $issuedDate->format('Y-m-d'),
            'issue_datetime' => $issuedDate->format('c'),
            'due_date' => $invoice->due_date?->format('Y-m-d') ?? null,
            'currency_code' => $currency,
            // #820 EP9: the document's OWN rate snapshot (never today's rate).
            'exchange_rate' => $invoice->exchange_rate,
            'supplier' => $supplierParty,
            'buyer' => $buyerParty,
            // Issue #664: an invoice to a business buyer must say so explicitly
            // so the provider never auto-classifies it as a consolidated
            // general-public document. Only an individual buyer without a real
            // TIN (general-public TIN) is a consolidated B2C invoice.
            'is_consolidated' => $this->isGeneralPublicBuyerParty($buyerParty),
            'line_items' => $lineItems,
            'totals' => $this->mapTotals($invoice, $lineCollection),
            'tax_summary' => $this->mapTaxDetails($invoice),
            'payment_terms' => $invoice->payment_terms ?? null,
            'narration' => $invoice->notes ?? null,
            'attachment_reference' => null,
        ];

        Log::info('myinvois:mapInvoice — done', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'line_item_count' => count($lineItems),
            'currency' => $currency,
            'payload_hash' => hash('sha256', (string) json_encode($mapped)),
        ]);

        return $mapped;
    }

    private function buildFallbackLineItem(Invoice $invoice): array
    {
        $subtotal = round((float) ($invoice->subtotal ?? $invoice->total ?? 0), 2);
        $taxAmount = round((float) ($invoice->tax_amount ?? 0), 2);
        $taxRate = (float) ($invoice->tax_rate ?? 0);

        if ($taxRate <= 0 && $subtotal > 0 && $taxAmount > 0) {
            $taxRate = round(($taxAmount / $subtotal) * 100, 2);
        }

        return [
            'line_number' => 1,
            'item_id' => (string) ($invoice->id ?? '1'),
            'description' => $invoice->notes ?: ('Invoice '.($invoice->invoice_number ?? 'Line Item')),
            'quantity' => 1.0,
            'unit_code' => 'C62',
            'unit_price' => $subtotal,
            'line_amount' => $subtotal,
            'discount_amount' => 0.0,
            'discount_percent' => 0.0,
            'tax_type' => $taxAmount > 0 ? 'SST' : 'E',
            'sst_rate' => round($taxRate, 2),
            'sst_amount' => $taxAmount,
            'tax_exemption_reason' => null,
        ];
    }

    private function resolveSupplier(mixed $invoice): object
    {
        if ($invoice !== null && ! empty($invoice->supplier)) {
            return $invoice->supplier;
        }

        // All identity fields (TIN, BRN, address) must come from the tenant DB config
        // via applyProviderPayloadDefaults() — never from .env in multi-tenant SaaS.
        $company = DB::table('public.company_settings')->first();

        return (object) [
            'name' => $company->company_name ?? null,
            'tax_identification_number' => null,
            'registration_number' => null,
            'company_registration' => null,
            'identity_type' => 'BRN',
            'identity_number' => null,
            'country_code' => 'MY',
            'is_individual' => false,
            'address_line_1' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
        ];
    }

    /**
     * Map CustomerCreditNote to MyInvois payload format.
     */
    public function mapCreditNote(object $creditNote): array
    {
        $issuedDate = $creditNote->credit_note_date ?? now();
        $currency = $creditNote->currency_code ?? 'MYR';

        $lineCollection = $creditNote->lines ?? collect();
        $lineItems = collect($lineCollection)->map(function ($line, int $index) {
            return $this->mapLineItem($line, $index + 1);
        })->values()->toArray();

        if (empty($lineItems)) {
            $lineItems[] = $this->buildFallbackCreditNoteLineItem($creditNote);
        }

        $originalInvoice = $creditNote->invoice ?? null;
        $supplier = $this->resolveSupplier($originalInvoice);
        $buyer = $creditNote->customer;

        $mapped = [
            'document_type' => 'credit_note',
            'profile_version' => 'myinvoice-v1',
            'invoice_id' => $creditNote->id,
            'invoice_number' => $creditNote->credit_note_number,
            'invoice_date' => is_string($issuedDate) ? $issuedDate : $issuedDate->format('Y-m-d'),
            'issue_datetime' => is_string($issuedDate) ? $issuedDate : $issuedDate->format('c'),
            'due_date' => null,
            'currency_code' => $currency,
            'supplier' => $this->mapParty($supplier, true),
            'buyer' => $this->mapParty($buyer, false),
            'line_items' => $lineItems,
            'totals' => $this->mapTotals($creditNote, collect($lineCollection)),
            'tax_summary' => $this->mapTaxDetails($creditNote),
            'original_invoice_references' => $this->resolveOriginalInvoiceRefs($creditNote),
            'payment_terms' => null,
            'narration' => $creditNote->reason ?? $creditNote->notes ?? null,
            'attachment_reference' => null,
        ];

        Log::info('myinvois:mapCreditNote — done', [
            'credit_note_id' => $creditNote->id,
            'credit_note_number' => $creditNote->credit_note_number,
            'line_item_count' => count($lineItems),
            'currency' => $currency,
        ]);

        return $mapped;
    }

    /**
     * #816 EP5: map a customer refund (a credit-note cash refund, or a payment
     * correction refund) to a MyInvois REFUND NOTE (LHDN type 04), referencing
     * the original invoice's accepted UUID so LHDN links the money returned to
     * the document it relates to.
     */
    public function mapRefundNote(object $refund, ?object $originalInvoice = null): array
    {
        $invoice = $originalInvoice ?? ($refund->invoice ?? null);

        $issuedDate = $refund->refund_date ?? $refund->credit_note_date ?? $refund->payment_date ?? now();
        $currency = $refund->currency_code ?? ($invoice->currency_code ?? 'MYR');

        // #816 EP5: two refund sources. A credit-note-shaped refund carries its
        // own subtotal/tax columns; a payment correction refund is a Payment —
        // signed amount, no tax columns — so the refunded tax is split off the
        // original invoice in proportion to its own tax. The payment records no
        // tax leg of its own, and the proportion is derived arithmetic on this
        // tenant's invoice (flagged pending tax-agent confirmation on #816).
        $hasDocumentTotals = ($refund->subtotal ?? $refund->total_amount ?? null) !== null;

        if ($hasDocumentTotals) {
            $subtotal = round((float) ($refund->subtotal ?? $refund->total_amount), 2);
            $refundTax = round((float) ($refund->tax_amount ?? 0), 2);
        } else {
            $gross = abs((float) ($refund->amount ?? 0));
            $invoiceTotal = (float) ($invoice->total ?? 0);
            $invoiceTax = (float) ($invoice->tax_amount ?? 0);
            $refundTax = $invoiceTotal > 0 ? round($gross * $invoiceTax / $invoiceTotal, 2) : 0.0;
            $subtotal = round($gross - $refundTax, 2);
        }

        $refundRate = $subtotal > 0 ? round(($refundTax / $subtotal) * 100, 2) : 0.0;

        $lineCollection = $refund->lines ?? collect();
        $lineItems = collect($lineCollection)->map(
            fn ($line, int $index) => $this->mapLineItem($line, $index + 1)
        )->values()->toArray();

        if (empty($lineItems)) {
            $lineItems[] = $hasDocumentTotals
                ? $this->buildFallbackCreditNoteLineItem($refund)
                : [
                    'line_number' => 1,
                    'item_id' => (string) ($refund->id ?? '1'),
                    'description' => (string) ($refund->notes ?? $refund->reason ?? 'Refund'),
                    'quantity' => 1.0,
                    'unit_code' => 'C62',
                    'unit_price' => $subtotal,
                    'line_amount' => $subtotal,
                    'discount_amount' => 0.0,
                    'discount_percent' => 0.0,
                    'tax_type' => $refundTax > 0 ? 'SST' : 'E',
                    'sst_rate' => $refundRate,
                    'sst_amount' => $refundTax,
                    'tax_exemption_reason' => null,
                ];
        }

        $supplier = $this->resolveSupplier($invoice);
        $buyer = $refund->customer ?? null;

        // mapTotals/mapTaxDetails read document columns a Payment does not have,
        // so a payment refund is fed the split computed above.
        $totalsSource = $hasDocumentTotals
            ? $refund
            : (object) ['subtotal' => $subtotal, 'discount_amount' => 0, 'tax_amount' => $refundTax, 'paid_amount' => 0];
        $totalsLines = $hasDocumentTotals
            ? collect($lineCollection)
            : collect([(object) ['quantity' => 1, 'unit_price' => $subtotal, 'discount_amount' => 0, 'tax_rate' => $refundRate, 'tax_amount' => $refundTax]]);
        $taxSummarySource = $hasDocumentTotals
            ? $refund
            : (object) ['tax_rate' => $refundRate, 'tax_amount' => $refundTax];

        $mapped = [
            'document_type' => 'refund_note',
            'profile_version' => 'myinvoice-v1',
            'invoice_id' => $refund->id,
            'invoice_number' => $refund->refund_number ?? $refund->credit_note_number ?? $refund->payment_number,
            'invoice_date' => is_string($issuedDate) ? $issuedDate : $issuedDate->format('Y-m-d'),
            'issue_datetime' => is_string($issuedDate) ? $issuedDate : $issuedDate->format('c'),
            'due_date' => null,
            'currency_code' => $currency,
            'exchange_rate' => $refund->exchange_rate ?? null,
            'supplier' => $this->mapParty($supplier, true),
            'buyer' => $this->mapParty($buyer, false),
            'line_items' => $lineItems,
            'totals' => $this->mapTotals($totalsSource, $totalsLines),
            'tax_summary' => $this->mapTaxDetails($taxSummarySource),
            'original_invoice_references' => $this->resolveOriginalInvoiceRefs((object) ['invoice' => $invoice]),
            'payment_terms' => null,
            'narration' => $refund->reason ?? $refund->notes ?? 'Refund',
            'attachment_reference' => null,
        ];

        Log::info('myinvois:mapRefundNote — done', [
            'refund_id' => $refund->id,
            'invoice_number' => $mapped['invoice_number'],
            'currency' => $currency,
        ]);

        return $mapped;
    }

    private function resolveOriginalInvoiceRefs(object $creditNote): array
    {
        $originalInvoice = $creditNote->invoice ?? null;
        if (! $originalInvoice) {
            return [];
        }

        $ref = ['invoice_number' => $originalInvoice->invoice_number];

        $uuid = $this->lookupLhdnUuid('invoice', $originalInvoice->id, strictAccepted: true);
        if ($uuid) {
            $ref['uuid'] = $uuid;
        }

        return [$ref];
    }

    private function resolveOriginalBillRefs(object $debitNote): array
    {
        $originalBill = $debitNote->bill ?? null;
        if (! $originalBill) {
            return [];
        }

        $ref = ['invoice_number' => $originalBill->bill_number];

        // Only attach a UUID when the original bill is fully accepted by LHDN.
        // 'ack' means LHDN received it but may still reject it in background validation —
        // sending an unconfirmed UUID causes DR319 / DR313.
        $uuid = $this->lookupLhdnUuid('bill', $originalBill->id, strictAccepted: true);
        if ($uuid) {
            $ref['uuid'] = $uuid;
        }

        return [$ref];
    }

    private function lookupLhdnUuid(string $documentType, int $glId, bool $strictAccepted = false): ?string
    {
        $complianceDoc = \App\Models\Accounting\ComplianceDocument::where('document_type', $documentType)
            ->where('document_id', $glId)
            ->first();

        if (! $complianceDoc) {
            return null;
        }

        $statuses = $strictAccepted ? ['accepted'] : ['accepted', 'ack'];

        return \App\Models\Accounting\ComplianceSubmission::where('document_id', $complianceDoc->id)
            ->whereIn('submission_status', $statuses)
            ->orderByDesc('id')
            ->value('external_document_uuid');
    }

    /**
     * Map Bill (vendor bill) to Invoice (LHDN type 01).
     * Company = supplier (matches authenticated TIN), vendor = buyer.
     */
    public function mapBill(object $bill): array
    {
        $issuedDate = $bill->bill_date ?? now();
        $currency = $bill->currency_code ?? 'MYR';
        $lineCollection = $bill->lines ?? collect();

        $lineItems = collect($lineCollection)->map(function ($line, int $index) {
            return $this->mapLineItem($line, $index + 1);
        })->values()->toArray();

        if (empty($lineItems)) {
            $lineItems[] = $this->buildFallbackBillLineItem($bill);
        }

        $company = $this->resolveSupplier(null);
        $vendor = $bill->vendor;

        $mapped = [
            'document_type' => 'invoice',
            'profile_version' => 'myinvoice-v1',
            'invoice_id' => $bill->id,
            'invoice_number' => $bill->bill_number,
            'invoice_date' => is_string($issuedDate) ? $issuedDate : $issuedDate->format('Y-m-d'),
            'issue_datetime' => is_string($issuedDate) ? $issuedDate : $issuedDate->format('c'),
            'due_date' => $bill->due_date
                ? (is_string($bill->due_date) ? $bill->due_date : $bill->due_date->format('Y-m-d'))
                : null,
            'currency_code' => $currency,
            'supplier' => $this->mapParty($company, true),
            'buyer' => $this->mapParty($vendor, false),
            'line_items' => $lineItems,
            'totals' => $this->mapTotals($bill, collect($lineCollection)),
            'tax_summary' => $this->mapTaxDetails($bill),
            'payment_terms' => $bill->terms ?? null,
            'narration' => $bill->notes ?? null,
            'attachment_reference' => null,
        ];

        Log::info('myinvois:mapBill — done', [
            'bill_id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'line_item_count' => count($lineItems),
            'currency' => $currency,
        ]);

        return $mapped;
    }

    /**
     * Map VendorDebitNote to Debit Note (LHDN type 03).
     * Company = supplier (matches authenticated TIN), vendor = buyer.
     */
    public function mapVendorDebitNote(object $debitNote): array
    {
        $issuedDate = $debitNote->debit_note_date ?? now();
        $currency = $debitNote->currency_code ?? 'MYR';
        $lineCollection = $debitNote->lines ?? collect();

        $lineItems = collect($lineCollection)->map(function ($line, int $index) {
            return $this->mapLineItem($line, $index + 1);
        })->values()->toArray();

        if (empty($lineItems)) {
            $lineItems[] = $this->buildFallbackVendorDebitNoteLineItem($debitNote);
        }

        $company = $this->resolveSupplier(null);
        $vendor = $debitNote->vendor;

        $mapped = [
            'document_type' => 'debit_note',
            'profile_version' => 'myinvoice-v1',
            'invoice_id' => $debitNote->id,
            'invoice_number' => $debitNote->debit_note_number,
            'invoice_date' => is_string($issuedDate) ? $issuedDate : $issuedDate->format('Y-m-d'),
            'issue_datetime' => is_string($issuedDate) ? $issuedDate : $issuedDate->format('c'),
            'due_date' => null,
            'currency_code' => $currency,
            'supplier' => $this->mapParty($company, true),
            'buyer' => $this->mapParty($vendor, false),
            'line_items' => $lineItems,
            'totals' => $this->mapTotals($debitNote, collect($lineCollection)),
            'tax_summary' => $this->mapTaxDetails($debitNote),
            'original_invoice_references' => $this->resolveOriginalBillRefs($debitNote),
            'payment_terms' => null,
            'narration' => $debitNote->reason ?? $debitNote->notes ?? null,
            'attachment_reference' => null,
        ];

        Log::info('myinvois:mapVendorDebitNote — done', [
            'debit_note_id' => $debitNote->id,
            'debit_note_number' => $debitNote->debit_note_number,
            'line_item_count' => count($lineItems),
            'currency' => $currency,
        ]);

        return $mapped;
    }

    private function buildFallbackBillLineItem(object $bill): array
    {
        $subtotal = round((float) ($bill->subtotal ?? $bill->total ?? 0), 2);
        $taxAmount = round((float) ($bill->tax_amount ?? 0), 2);
        $taxRate = ($taxAmount > 0 && $subtotal > 0) ? round(($taxAmount / $subtotal) * 100, 2) : 0.0;

        return [
            'line_number' => 1,
            'item_id' => (string) ($bill->id ?? '1'),
            'description' => 'Bill '.($bill->bill_number ?? 'Line Item'),
            'quantity' => 1.0,
            'unit_code' => 'C62',
            'unit_price' => $subtotal,
            'line_amount' => $subtotal,
            'discount_amount' => 0.0,
            'discount_percent' => 0.0,
            'tax_type' => $taxAmount > 0 ? 'SST' : 'E',
            'sst_rate' => $taxRate,
            'sst_amount' => $taxAmount,
            'tax_exemption_reason' => null,
        ];
    }

    private function buildFallbackVendorDebitNoteLineItem(object $debitNote): array
    {
        $subtotal = round((float) ($debitNote->subtotal ?? $debitNote->total_amount ?? 0), 2);
        $taxAmount = round((float) ($debitNote->tax_amount ?? 0), 2);
        $taxRate = ($taxAmount > 0 && $subtotal > 0) ? round(($taxAmount / $subtotal) * 100, 2) : 0.0;

        return [
            'line_number' => 1,
            'item_id' => (string) ($debitNote->id ?? '1'),
            'description' => 'Debit Note '.($debitNote->debit_note_number ?? 'Line Item'),
            'quantity' => 1.0,
            'unit_code' => 'C62',
            'unit_price' => $subtotal,
            'line_amount' => $subtotal,
            'discount_amount' => 0.0,
            'discount_percent' => 0.0,
            'tax_type' => $taxAmount > 0 ? 'SST' : 'E',
            'sst_rate' => $taxRate,
            'sst_amount' => $taxAmount,
            'tax_exemption_reason' => null,
        ];
    }

    private function buildFallbackCreditNoteLineItem(object $creditNote): array
    {
        $subtotal = round((float) ($creditNote->subtotal ?? $creditNote->total_amount ?? 0), 2);
        $taxAmount = round((float) ($creditNote->tax_amount ?? 0), 2);
        $taxRate = ($taxAmount > 0 && $subtotal > 0) ? round(($taxAmount / $subtotal) * 100, 2) : 0.0;

        return [
            'line_number' => 1,
            'item_id' => (string) ($creditNote->id ?? '1'),
            'description' => $creditNote->reason ?: ('Credit Note '.($creditNote->credit_note_number ?? 'Line Item')),
            'quantity' => 1.0,
            'unit_code' => 'C62',
            'unit_price' => $subtotal,
            'line_amount' => $subtotal,
            'discount_amount' => 0.0,
            'discount_percent' => 0.0,
            'tax_type' => $taxAmount > 0 ? 'SST' : 'E',
            'sst_rate' => $taxRate,
            'sst_amount' => $taxAmount,
            'tax_exemption_reason' => null,
        ];
    }

    /**
     * Get required fields for MY MyInvois payload.
     */
    public function getRequiredFields(): array
    {
        return [
            'document_type',
            'invoice_id',
            'invoice_number',
            'invoice_date',
            'currency_code',
            'supplier',
            'buyer',
            'line_items',
            'totals',
        ];
    }

    /**
     * Validate mapped payload
     */
    public function validatePayload(array $payload): bool
    {
        $docType = $payload['document_type'] ?? 'unknown';

        $required = ['document_type', 'invoice_id', 'invoice_number', 'invoice_date', 'currency_code', 'supplier', 'buyer', 'line_items', 'totals'];
        foreach ($required as $field) {
            if (! isset($payload[$field])) {
                Log::warning("myinvois:validatePayload — missing required field '{$field}'", ['document_type' => $docType, 'invoice_id' => $payload['invoice_id'] ?? null]);

                return false;
            }
        }

        if (! in_array($docType, ['invoice', 'credit_note', 'debit_note', 'refund_note', 'self_billed_invoice', 'self_billed_credit_note', 'self_billed_debit_note', 'self_billed_refund_note'], true)) {
            Log::warning("myinvois:validatePayload — unrecognised document_type '{$docType}'");

            return false;
        }

        if (! is_array($payload['line_items']) || empty($payload['line_items'])) {
            Log::warning('myinvois:validatePayload — line_items empty or not an array', ['document_type' => $docType, 'invoice_id' => $payload['invoice_id'] ?? null]);

            return false;
        }

        if (! isset($payload['supplier']['name']) || empty($payload['supplier']['tin'])) {
            Log::warning('myinvois:validatePayload — supplier name missing or TIN empty', [
                'document_type' => $docType,
                'invoice_id' => $payload['invoice_id'] ?? null,
                'supplier_name' => $payload['supplier']['name'] ?? null,
                'supplier_tin' => $payload['supplier']['tin'] ?? null,
            ]);

            return false;
        }

        $supplierHasCompanyRegistration = ! empty($payload['supplier']['company_registration']);
        $supplierHasIdentityDocument = ! empty($payload['supplier']['identity_type'])
            && ! empty($payload['supplier']['identity_number']);

        if (! $supplierHasCompanyRegistration && ! $supplierHasIdentityDocument) {
            Log::warning('myinvois:validatePayload — supplier has neither company_registration nor identity document', [
                'document_type' => $docType,
                'invoice_id' => $payload['invoice_id'] ?? null,
                'supplier_name' => $payload['supplier']['name'] ?? null,
                'company_registration' => $payload['supplier']['company_registration'] ?? null,
                'identity_type' => $payload['supplier']['identity_type'] ?? null,
                'identity_number' => $payload['supplier']['identity_number'] ?? null,
            ]);

            return false;
        }

        if (! isset($payload['buyer']['name'])) {
            Log::warning('myinvois:validatePayload — buyer name missing', ['document_type' => $docType, 'invoice_id' => $payload['invoice_id'] ?? null]);

            return false;
        }

        // Issue #664: a business/government/foreign buyer must have a real TIN —
        // the general-public TIN is reserved for individuals and consolidated
        // B2C invoices. This runs before the provider is called so the service
        // can return an actionable message and no submission is made.
        $buyerType = strtolower(trim((string) ($payload['buyer']['buyer_type'] ?? '')));
        $buyerTin = trim((string) ($payload['buyer']['tin'] ?? ''));
        if ($buyerTin === '' && in_array($buyerType, self::NON_INDIVIDUAL_BUYER_TYPES, true)) {
            Log::warning('myinvois:validatePayload — business buyer has no TIN', [
                'document_type' => $docType,
                'invoice_id' => $payload['invoice_id'] ?? null,
                'buyer_name' => $payload['buyer']['name'] ?? null,
                'buyer_type' => $buyerType,
            ]);

            return false;
        }

        if (! isset($payload['totals']['gross_amount']) || ! is_numeric($payload['totals']['gross_amount'])) {
            Log::warning('myinvois:validatePayload — totals.gross_amount missing or non-numeric', [
                'document_type' => $docType,
                'invoice_id' => $payload['invoice_id'] ?? null,
                'gross_amount' => $payload['totals']['gross_amount'] ?? null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Map party to MyInvoice format
     */
    private function mapParty($party, bool $isSupplier = false): array
    {
        $party = $party ?? (object) [];

        $legacyAddress = $this->extractLegacyAddress($party);

        // BRN/NRIC registration number — check all common field names used across models.
        // tax_number is intentionally excluded here; it stores the TIN, not the registration.
        $registration = $party->registration_number
            ?? $party->company_registration
            ?? $party->company_registration_number   // Customer model field
            ?? $party->tax_registration_number
            ?? null;

        // TIN: Customer model stores it as tax_number ("TIN / Tax Number" in the UI).
        $tin = $this->firstNonEmptyString(
            $party->tax_identification_number ?? null,
            $party->tin ?? null,
            $party->tax_number ?? null,
            $party->tax_id ?? null,
        ) ?: null;

        $buyerType = $isSupplier ? null : $this->resolveBuyerType($party);

        if ($tin === null && $buyerType === self::BUYER_TYPE_INDIVIDUAL) {
            // LHDN general-public TIN for consolidated/end-consumer invoices (LHDN constant).
            // Issue #664: never fall back to it for a business/government/foreign
            // buyer — those must carry a real TIN and be rejected otherwise.
            $tin = self::LHDN_GENERAL_PUBLIC_TIN;
        }

        // Derive identity_type from entity type when not explicitly set.
        // LHDN schemeID: BRN for companies, NRIC for individuals (default in UBL builder).
        // Vendor model has no 'type' field, so also infer BRN when a registration number exists.
        $identityType = $party->identity_type
            ?? (isset($party->type) && $party->type === 'company' ? 'BRN' : null)
            ?? ($registration !== null ? 'BRN' : null);

        return [
            'name' => $party->name ?? 'Unknown Party',
            'tin' => $tin,
            'company_registration' => $registration,
            'sst_registration' => $party->sst_registration_number ?? null,
            'identity_type' => $identityType,
            'identity_number' => $party->identity_number ?? $registration,
            'buyer_type' => $buyerType,
            'address' => [
                'street' => $this->firstNonEmptyString(
                    $party->address_line_1 ?? null,
                    $legacyAddress['street'] ?? null,
                ),
                'city' => $this->firstNonEmptyString(
                    $party->city ?? null,
                    $legacyAddress['city'] ?? null,
                ),
                'state' => $this->firstNonEmptyString(
                    $party->state ?? null,
                    $legacyAddress['state'] ?? null,
                ),
                'postal_code' => $this->firstNonEmptyString(
                    $party->postal_code ?? null,
                    $legacyAddress['postal_code'] ?? null,
                ),
                'country' => $this->normalizeCountryCode(
                    $party->country_code
                        ?? $legacyAddress['country']
                        ?? 'MY'
                ),
            ],
            'contact' => [
                'person' => $party->contact_person ?? null,
                'phone' => $this->firstNonEmptyString(
                    $party->phone ?? null,
                    $party->mobile ?? null,
                    $legacyAddress['phone'] ?? null,
                ),
                'email' => $party->email ?? null,
            ],
            'is_business' => $isSupplier
                ? ! ($party->is_individual ?? false)
                : $buyerType !== self::BUYER_TYPE_INDIVIDUAL,
        ];
    }

    /**
     * Resolve the Malaysia buyer type from the customer record.
     *
     * Prefers the explicit customers.buyer_type column (business / individual /
     * government / foreign), then the legacy customers.type enum
     * ('company' → business, 'individual' → individual), then an explicit
     * is_individual flag on ad-hoc party objects. Unknown parties (walk-in
     * customers, POS callers, vendors) keep the historic end-consumer
     * treatment so they remain eligible for consolidated B2C invoices.
     */
    private function resolveBuyerType(object $party): string
    {
        $explicit = strtolower(trim((string) ($party->buyer_type ?? '')));
        if (in_array($explicit, self::BUYER_TYPES, true)) {
            return $explicit;
        }

        $type = strtolower(trim((string) ($party->type ?? '')));
        if ($type === 'company') {
            return self::BUYER_TYPE_BUSINESS;
        }
        if ($type === 'individual') {
            return self::BUYER_TYPE_INDIVIDUAL;
        }
        if (in_array($type, self::BUYER_TYPES, true)) {
            return $type;
        }

        if (($party->is_individual ?? null) === false) {
            return self::BUYER_TYPE_BUSINESS;
        }

        return self::BUYER_TYPE_INDIVIDUAL;
    }

    private function isGeneralPublicBuyerParty(array $buyerParty): bool
    {
        return strtoupper((string) ($buyerParty['tin'] ?? '')) === self::LHDN_GENERAL_PUBLIC_TIN;
    }

    private function extractLegacyAddress(object $party): array
    {
        $shippingAddress = $this->decodeAddressValue($party->shipping_address ?? null);
        $billingAddress = $this->decodeAddressValue($party->billing_address ?? null);
        // Vendor model stores address in a plain 'address' field (may be JSON or plain string)
        $plainAddress = $this->decodeAddressValue($party->address ?? null);
        $address = ! empty($shippingAddress) ? $shippingAddress
                 : (! empty($billingAddress) ? $billingAddress : $plainAddress);

        return [
            'street' => $this->firstNonEmptyString(
                $address['address'] ?? null,
                $address['line1'] ?? null,
                $address['street'] ?? null,
            ),
            'city' => $this->firstNonEmptyString($address['city'] ?? null),
            'state' => $this->firstNonEmptyString($address['state'] ?? null),
            'postal_code' => $this->firstNonEmptyString(
                $address['postal_code'] ?? null,
                $address['zip'] ?? null,
            ),
            'country' => $this->firstNonEmptyString(
                $address['country_code'] ?? null,
                $address['country'] ?? null,
            ),
            'phone' => $this->firstNonEmptyString(
                $address['phone'] ?? null,
                $address['mobile'] ?? null,
            ),
        ];
    }

    private function decodeAddressValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return [];
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function firstNonEmptyString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    private function normalizeCountryCode(mixed $value): string
    {
        if (! is_string($value)) {
            return 'MY';
        }

        return match (strtoupper(trim($value))) {
            '', 'MY', 'MYS', 'MALAYSIA' => 'MY',
            'SG', 'SGP', 'SINGAPORE' => 'SGP',
            'US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA' => 'USA',
            default => strtoupper(trim($value)),
        };
    }

    /**
     * Map line item
     */
    private function mapLineItem($line, int $lineNumber, ?array $determinationLine = null): array
    {
        $quantity = (float) ($line->quantity ?? 1);
        $unitPrice = (float) ($line->unit_price ?? 0);
        $discountAmount = (float) ($line->discount_amount ?? 0);
        $taxRate = (float) ($determinationLine['tax_rate'] ?? $line->tax_rate ?? 0);
        $taxAmount = (float) ($determinationLine['tax_amount'] ?? $line->tax_amount ?? 0);
        $lineSubtotal = round(($quantity * $unitPrice) - $discountAmount, 2);

        if ($lineSubtotal < 0) {
            $lineSubtotal = 0.0;
        }

        if ($taxAmount <= 0 && $taxRate > 0 && $determinationLine === null) {
            // MRD R3.1: single tax arithmetic via TaxEngineService::calculate().
            $taxAmount = \App\Services\TaxEngineService::calculate((float) $lineSubtotal, (float) $taxRate, false)['tax'];
        }

        // G-37 (#274): LHDN tax type per line — standard-rated sales uses 01,
        // services 02; zero-rated is '06' (Not Applicable); exempt/out-of-scope
        // is 'E' with exemption reason + amount (distinct from zero-rated).
        $treatment = $determinationLine['treatment'] ?? null;
        $engineTaxType = $determinationLine['tax_type'] ?? null;

        $taxTypeCode = match (true) {
            in_array($treatment, ['exempt', 'out_of_scope'], true) => 'E',
            $treatment === 'zero_rated' => '06',
            $treatment === 'standard_rated' => $engineTaxType === 'service' ? '02' : '01',
            default => $taxAmount > 0 ? '01' : 'E',
        };

        $isExemptOrZero = in_array($taxTypeCode, ['E', '06'], true);

        // #665 EI3: per-line LHDN classification + UN/ECE unit. A product's own
        // code wins; otherwise the pack's declared default. A truly missing
        // classification is a validation error, never a silent '001'.
        $product = $line->product ?? null;
        $classificationCode = $line->classification_code
            ?? $product?->einvoice_classification_code
            ?? config('tax_packs.MY.einvoice.default_classification');

        if (empty($classificationCode)) {
            throw new \InvalidArgumentException(
                'EINVOICE_CLASSIFICATION_REQUIRED: line '.$lineNumber
                .' has no LHDN classification code (set it on the product or the pack default).'
            );
        }

        $unitCode = $line->unit_code
            ?? $product?->einvoice_unit_code
            ?? config('tax_packs.MY.einvoice.default_unit', 'C62');

        return [
            'line_number' => $lineNumber,
            'item_id' => (string) $line->id,
            'description' => $line->description ?? 'Line Item',
            'classification_code' => $classificationCode,
            'quantity' => $quantity,
            'unit_code' => $unitCode,
            'unit_price' => round($unitPrice, 2),
            'line_amount' => $lineSubtotal,
            'discount_amount' => round($discountAmount, 2),
            'discount_percent' => round((float) ($line->discount_percent ?? 0), 2),
            'tax_type' => $line->tax_type ?? 'SST',
            'tax_type_code' => $taxTypeCode,
            'sst_rate' => $taxRate,
            'sst_amount' => round($taxAmount, 2),
            'tax_exemption_reason' => $line->tax_exemption_reason ?? null,
            'tax_exemption_amount' => $isExemptOrZero ? $lineSubtotal : null,
        ];
    }

    /**
     * Map totals
     */
    private function mapTotals($invoice, $lineCollection = null): array
    {
        $lineCollection = $lineCollection ?? ($invoice->lineItems ?? $invoice->lines ?? collect());
        $computedSubtotal = 0.0;
        $computedTax = 0.0;

        foreach ($lineCollection as $line) {
            $quantity = (float) ($line->quantity ?? 1);
            $unitPrice = (float) ($line->unit_price ?? 0);
            $lineDiscount = (float) ($line->discount_amount ?? 0);
            $lineSubtotal = round(($quantity * $unitPrice) - $lineDiscount, 2);
            if ($lineSubtotal < 0) {
                $lineSubtotal = 0.0;
            }

            $computedSubtotal += $lineSubtotal;
            $computedTax += (float) ($line->tax_amount ?? \App\Services\TaxEngineService::calculate((float) $lineSubtotal, (float) ($line->tax_rate ?? 0), false)['tax']);
        }

        $subtotal = $computedSubtotal > 0 ? $computedSubtotal : (float) ($invoice->subtotal ?? 0);
        $discount = (float) ($invoice->discount_amount ?? 0);
        $tax = $computedTax > 0 ? $computedTax : (float) ($invoice->tax_amount ?? 0);
        $total = $subtotal - $discount + $tax;
        $paid = (float) ($invoice->paid_amount ?? 0);

        return [
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discount, 2),
            'subtotal_after_discount' => round($subtotal - $discount, 2),
            'sst_amount' => round($tax, 2),
            'gross_amount' => round($total, 2),
            'paid_amount' => round($paid, 2),
            'amount_due' => round($total - $paid, 2),
        ];
    }

    /**
     * Map tax details
     */
    private function mapTaxDetails($invoice): array
    {
        $rate = (float) ($invoice->tax_rate ?? 0);

        return [
            'sst_rate' => $rate,
            'sst_amount' => round((float) ($invoice->tax_amount ?? 0), 2),
            'sst_exemption' => $rate === 0.0,
            'sst_exemption_code' => $invoice->tax_exemption_code ?? null,
        ];
    }
}
