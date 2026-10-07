<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\ComplianceSubmission;
use App\Models\Invoice;
use App\Models\LegalEntity;
use App\Services\QrCodeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\TemplateBuilder\Contracts\DataSourceContract;
use Modules\TemplateBuilder\Support\AmountInWords;

class InvoiceDataSource implements DataSourceContract
{
    private ?Invoice $invoice = null;

    public static function documentType(): string
    {
        return 'invoice';
    }

    public function permission(): string
    {
        return 'invoices.view';
    }

    public function legalEntityId(): ?int
    {
        $invoice = $this->invoice ?? throw new \RuntimeException('Invoice not loaded.');
        // Issue #875: the invoice carries its own legal entity; the customer's
        // entity is only a fallback for rows created before that backfill.
        $entityId = $invoice->legal_entity_id ?? $invoice->customer?->legal_entity_id;

        return $entityId !== null ? (int) $entityId : null;
    }

    public function resolutionContext(string $documentId): array
    {
        $invoice = $this->load($documentId)->invoice;

        return DocumentResolutionContext::build([
            'entity_id' => $this->legalEntityId(),
            'branch_id' => $invoice->getAttribute('branch_id'),
            'currency' => $invoice->currency_code,
            'language' => $invoice->customer?->language,
            'customer_group' => $invoice->customer?->customer_group,
            'channel' => $invoice->getAttribute('created_via_module') ?? $invoice->getAttribute('origin_type'),
        ]);
    }

    public function load(string $documentId): self
    {
        $this->invoice = Invoice::with(['customer.salesperson', 'lines.product', 'doctor', 'requestor', 'buyer', 'billingContact', 'shippingContact'])->findOrFail($documentId);

        return $this;
    }

    public function toArray(): array
    {
        $invoice = $this->invoice ?? throw new \RuntimeException('Invoice not loaded.');
        // Issue #614: the selling entity's jurisdiction decides the statutory
        // labels (TIN/SST/GST) and is passed down to the company block.
        $jurisdiction = $this->documentJurisdiction($invoice);
        $company = CompanyDataSource::forDocument($invoice, $jurisdiction);
        // MRD pricelist-multicurrency FR-8.5: the DOCUMENT currency drives
        // the printed figures when the invoice carries a snapshot; the base
        // currency equivalent is exposed as a secondary token.
        $companyCurrency = $company['currency_code'] ?? 'MYR';
        $currencyCode = $invoice->currency_code ?: $companyCurrency;
        $baseCurrencyCode = $invoice->base_currency_code ?: $companyCurrency;
        $exchangeRate = $invoice->exchange_rate !== null ? (float) $invoice->exchange_rate : 1.0;

        // Issue #614 (TP4): statutory tokens — tax summary per posted tax
        // line, e-invoice reference and amount in words.
        $taxSummary = $this->taxSummary($invoice);
        $einvoice = $this->einvoice($invoice);
        $amountInWords = AmountInWords::format(
            (float) $invoice->total,
            $currencyCode,
            $invoice->customer?->language,
        );

        // Issue #621 (TPL-UX4): per-line FOC marker/list value, medical case
        // number and linked SO/DO numbers.
        $lineLinks = $this->lineLinks($invoice);

        $lines = [];
        $salesperson = $invoice->customer?->salesperson?->name ?? '';
        $doctor = $invoice->doctor?->full_name ?? $invoice->doctor?->display_name ?? '';
        $requestor = $invoice->requestor?->full_name ?? $invoice->requestor?->display_name ?? '';
        $productIndex = 0;
        foreach ($invoice->lines as $i => $line) {
            $isProduct = ($line->line_type ?? 'product') === 'product';
            if ($isProduct) {
                $productIndex++;
            }
            $links = $lineLinks[$line->id] ?? [];
            $foc = $isProduct ? $this->focFor($line, $links) : ['is_foc' => false, 'list_value' => 0.0];
            $lines[] = [
                'line_type' => $line->line_type ?? 'product',
                'index' => $isProduct ? $productIndex : null,
                'sku' => $line->product?->sku ?? '',
                'description' => $line->description ?? '',
                // #1004: sanitized rich-text description (empty when the line
                // has no formatting) so templates/PDFs render it verbatim.
                'description_html' => $line->description_html ?? '',
                'quantity' => $isProduct ? (float) $line->quantity : null,
                'unit_price' => $isProduct ? (float) $line->unit_price : null,
                'tax_rate' => (float) $line->tax_rate,
                'discount' => (float) ($line->discount_amount ?? 0),
                'line_total' => $isProduct ? (float) $line->line_total : null,
                'lot_number' => $line->lot_number ?? '',
                'serial_number' => $line->serial_number ?? '',
                'expiry_date' => optional($line->expiry_date)->format('Y-m-d') ?? '',
                'salesperson' => $salesperson,
                'doctor' => $doctor,
                'requestor' => $requestor,
                'attention_to' => $invoice->attention_to ?? '',
                'po_number' => $invoice->po_number ?? '',
                // Issue #621: the line's own medical case(s); the invoice's
                // case number when the line is not billed from a case.
                'case_number' => ($links['case_numbers'] ?? []) !== []
                    ? implode(', ', $links['case_numbers'])
                    : ($invoice->case_number ?? ''),
                'is_foc' => $foc['is_foc'],
                'list_value' => $foc['list_value'],
                'so_number' => implode(', ', $links['so_numbers'] ?? []),
                'do_number' => implode(', ', $links['do_numbers'] ?? []),
                'pr_no' => $invoice->pr_no ?? '', 'contract_no' => $invoice->contract_no ?? '',
                'buyer' => $invoice->buyer?->full_name ?? $invoice->buyer?->display_name ?? '',
                'plant_code' => $invoice->plant_code ?? '', 'company_code' => $invoice->company_code ?? '',
                'date_of_case' => optional($invoice->date_of_case)->format('Y-m-d') ?? '',
                'case_location' => $invoice->case_location ?? '',
                'patient_name' => $invoice->patient_name ?? '',
                'shipping_method' => $invoice->shipping_method ?? '',
                'case_location' => $invoice->case_location ?? '',
                'date_of_case' => optional($invoice->date_of_case)->format('Y-m-d') ?? '',
                'patient_name' => $invoice->patient_name ?? '',
            ];
        }

        return [
            'invoice' => [
                'number' => $invoice->invoice_number,
                'issue_date' => optional($invoice->invoice_date)->format('Y-m-d'),
                'due_date' => optional($invoice->due_date)->format('Y-m-d'),
                'status' => $invoice->lifecycle_status,
                'reference' => $invoice->customer_reference,
                'po_number' => $invoice->po_number ?? '',
                'case_number' => $invoice->case_number ?? '',
                // Issue #621: document-level case tokens the catalog already
                // offered (invoice.date_of_case / case_location /
                // patient_name) now carry data. patient_name is masked by the
                // DataSourceRegistry PHI choke point like the line copy.
                'date_of_case' => optional($invoice->date_of_case)->format('Y-m-d') ?? '',
                'case_location' => $invoice->case_location ?? '',
                'patient_name' => $invoice->patient_name ?? '',
                'pr_no' => $invoice->pr_no ?? '',
                'contract_no' => $invoice->contract_no ?? '',
                'buyer' => $invoice->buyer?->full_name ?? $invoice->buyer?->display_name ?? '',
                'plant_code' => $invoice->plant_code ?? '',
                'company_code' => $invoice->company_code ?? '',
                'billing_address' => $this->formatAddress($invoice->billing_address_snapshot),
                'shipping_address' => $this->formatAddress($invoice->shipping_address_snapshot),
                'notes' => $invoice->notes ?? '',
                // Printed Payment Terms: the free-text terms win when written;
                // otherwise fall back to the Net-N snapshot so picking "30
                // days" on the invoice actually shows on the PDF.
                // Issue #324: a master payment term prints by its name.
                'terms' => $invoice->terms
                    ?: ($invoice->paymentTerm?->name
                        ?: (($invoice->payment_terms_snapshot ?: $invoice->customer?->payment_terms)
                            ? 'Net '.($invoice->payment_terms_snapshot ?: $invoice->customer?->payment_terms).' days'
                            : '')),
                'payment_terms' => $invoice->paymentTerm?->name
                    ?: (($invoice->payment_terms_snapshot ?: $invoice->customer?->payment_terms)
                        ? 'Net '.($invoice->payment_terms_snapshot ?: $invoice->customer?->payment_terms).' days'
                        : ''),
                'attention_to' => $invoice->attention_to ?? '',
                'shipping_method' => $invoice->shipping_method ?? '',
                // Issue #333: Incoterm code + place (blank when unset).
                'incoterm_code' => $invoice->incoterm_code ?? '',
                'incoterm_place' => $invoice->incoterm_place ?? '',
                'delivery_date' => optional($invoice->delivery_date)->format('Y-m-d') ?? '',
                'internal_reference' => $invoice->internal_reference ?? '',
                'doctor' => $invoice->doctor?->full_name ?? $invoice->doctor?->display_name ?? '',
                'requestor' => $invoice->requestor?->full_name ?? $invoice->requestor?->display_name ?? '',
                'billing_contact_name' => $invoice->billingContact?->display_name ?? '',
                'shipping_contact_name' => $invoice->shippingContact?->display_name ?? '',
                'salesperson' => $invoice->customer?->salesperson?->name ?? '',
            ],
            'customer' => [
                'code' => $invoice->customer?->code ?? '',
                'name' => $invoice->customer?->name ?? '',
                'email' => $invoice->customer?->email ?? $invoice->contact_email ?? '',
                'phone' => $invoice->customer?->phone ?? $invoice->contact_phone ?? '',
                'tax_id' => $invoice->tax_registration_number ?? $invoice->customer?->tax_number ?? '',
                'company_registration_number' => $invoice->company_registration_number ?? $invoice->customer?->company_registration_number ?? '',
                'billing_address' => $this->formatAddress($invoice->billing_address_snapshot),
            ],
            'company' => $company,
            // Issue #614 (TP4): per-tax-code/rate summary derived from the
            // POSTED tax lines (see taxSummary()), e-invoice reference and
            // the amount in words in the document currency/language.
            'tax_summary' => $taxSummary,
            'einvoice' => $einvoice,
            'lines' => $lines,
            'totals' => [
                'subtotal' => (float) $invoice->subtotal,
                'tax' => (float) $invoice->tax_amount,
                'discount' => (float) ($invoice->discount_amount ?? 0),
                'shipping' => (float) ($invoice->shipping_cost ?? 0),
                // Issue #333: signed cash-rounding delta already included in
                // the grand total (0.00 when rounding is disabled).
                'rounding' => (float) ($invoice->rounding_amount ?? 0),
                'total' => (float) $invoice->total,
                'paid' => (float) ($invoice->paid_amount ?? 0),
                'balance' => (float) $invoice->balance,
                'amount_in_words' => $amountInWords,
                // Dual display (FR-5.4/FR-8.5): base-currency equivalents at
                // the document's immutable snapshot rate.
                'base_total' => round((float) $invoice->total * $exchangeRate, 2),
                'base_subtotal' => round((float) $invoice->subtotal * $exchangeRate, 2),
                'base_tax' => round((float) $invoice->tax_amount * $exchangeRate, 2),
                'exchange_rate' => $exchangeRate,
            ],
            'meta' => [
                'currency_code' => $currencyCode,
                'base_currency_code' => $baseCurrencyCode,
                'exchange_rate' => $exchangeRate,
                'currency_symbol' => self::currencySymbol($currencyCode),
                'number_format' => '1,234.56',
                'locale' => 'en',
                'language' => strtolower((string) ($invoice->customer?->language ?: 'en')),
            ],
        ];
    }

    /**
     * Issue #621 (TPL-UX4): per invoice line — the medical case number(s),
     * FOC quantity from live FOC case lines, and the linked sales order /
     * delivery order numbers.
     *
     * FOC: until #560 adds `invoice_lines.is_foc`, the flag lives on the
     * medical case line that bills the invoice line (`medical_case_items.
     * is_foc`, `invoice_item_id`) — the same source and the same "cancelled
     * or deleted cases gave nothing away" rule as the #600 analytics model.
     *
     * DO: the case line's own delivery order line when set; otherwise the
     * lines of the invoice's linked delivery orders that ship the same sales
     * order line; otherwise the single linked delivery order.
     *
     * @return array<int, array{case_numbers: list<string>, foc_qty: float, has_foc: bool, so_numbers: list<string>, do_numbers: list<string>}>
     */
    private function lineLinks(Invoice $invoice): array
    {
        $lines = $invoice->lines->filter(fn ($line) => $line->id !== null);
        if ($lines->isEmpty()) {
            return [];
        }

        $links = [];
        foreach ($lines as $line) {
            $links[$line->id] = ['case_numbers' => [], 'foc_qty' => 0.0, 'has_foc' => false, 'so_numbers' => [], 'do_numbers' => []];
        }
        $lineIds = array_keys($links);

        $caseDoLineIds = [];
        if (Schema::hasTable('medical_case_items') && Schema::hasTable('medical_cases')) {
            $caseItems = DB::table('medical_case_items as mci')
                ->join('medical_cases as mc', 'mc.id', '=', 'mci.medical_case_id')
                ->whereIn('mci.invoice_item_id', $lineIds)
                ->where('mc.status', '<>', 'cancelled')
                ->whereNull('mc.deleted_at')
                ->orderBy('mci.id')
                ->get(['mci.invoice_item_id', 'mci.is_foc', 'mci.invoiced_qty', 'mci.delivery_order_item_id', 'mc.case_no']);

            foreach ($caseItems as $item) {
                $lineId = (int) $item->invoice_item_id;
                if ($item->case_no !== null && $item->case_no !== '' && ! in_array($item->case_no, $links[$lineId]['case_numbers'], true)) {
                    $links[$lineId]['case_numbers'][] = (string) $item->case_no;
                }
                if ((bool) $item->is_foc) {
                    $links[$lineId]['has_foc'] = true;
                    $links[$lineId]['foc_qty'] += (float) $item->invoiced_qty;
                }
                if ($item->delivery_order_item_id !== null) {
                    $caseDoLineIds[$lineId][] = (int) $item->delivery_order_item_id;
                }
            }
        }

        $soLineIds = $lines->pluck('sales_order_line_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $soNumbers = $soLineIds === [] ? collect() : DB::table('sales_order_lines as sol')
            ->join('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
            ->whereIn('sol.id', $soLineIds)
            ->pluck('so.sales_order_number', 'sol.id');

        $linkedDoIds = DB::table('invoice_delivery_orders')->where('invoice_id', $invoice->id)->pluck('delivery_order_id')->map(fn ($id) => (int) $id)->all();
        $doLinesBySoLine = ($soLineIds === [] || $linkedDoIds === []) ? collect() : DB::table('delivery_order_lines as dol')
            ->join('delivery_orders as do', 'do.id', '=', 'dol.delivery_order_id')
            ->whereIn('dol.sales_order_line_id', $soLineIds)
            ->whereIn('do.id', $linkedDoIds)
            ->orderBy('do.id')
            ->get(['dol.sales_order_line_id', 'do.delivery_order_number'])
            ->groupBy('sales_order_line_id');
        $caseDoNumbers = $caseDoLineIds === [] ? collect() : DB::table('delivery_order_lines as dol')
            ->join('delivery_orders as do', 'do.id', '=', 'dol.delivery_order_id')
            ->whereIn('dol.id', array_merge(...array_values($caseDoLineIds)))
            ->pluck('do.delivery_order_number', 'dol.id');
        $singleDoNumber = count($linkedDoIds) === 1
            ? DB::table('delivery_orders')->where('id', $linkedDoIds[0])->value('delivery_order_number')
            : null;
        $headerSoNumber = $invoice->sales_order_id
            ? DB::table('sales_orders')->where('id', $invoice->sales_order_id)->value('sales_order_number')
            : null;

        foreach ($lines as $line) {
            $soLineId = $line->sales_order_line_id !== null ? (int) $line->sales_order_line_id : null;

            $so = $soLineId !== null ? ($soNumbers[$soLineId] ?? null) : null;
            $so ??= $headerSoNumber;
            if ($so !== null && $so !== '') {
                $links[$line->id]['so_numbers'][] = (string) $so;
            }

            $doNumbers = collect($caseDoLineIds[$line->id] ?? [])->map(fn ($id) => $caseDoNumbers[$id] ?? null);
            if ($doNumbers->filter()->isEmpty() && $soLineId !== null) {
                $doNumbers = collect($doLinesBySoLine[$soLineId] ?? [])->pluck('delivery_order_number');
            }
            if ($doNumbers->filter()->isEmpty() && $singleDoNumber !== null) {
                $doNumbers = collect([$singleDoNumber]);
            }
            $links[$line->id]['do_numbers'] = $doNumbers->filter()->map(fn ($n) => (string) $n)->unique()->values()->all();
        }

        return $links;
    }

    /**
     * Issue #621: FOC flag and FOC list value of a product line. The list
     * value is the FOC quantity (capped at the line quantity; the whole line
     * when the case line records no invoiced quantity) at the line's unit
     * (list) price — the #600 valuation. A #560 `invoice_lines.is_foc` flag,
     * once it exists, marks the whole line FOC.
     *
     * @param  array<string,mixed>  $links
     * @return array{is_foc: bool, list_value: float}
     */
    private function focFor(\App\Models\InvoiceLine $line, array $links): array
    {
        $lineQty = (float) $line->quantity;
        $ownFlag = (bool) ($line->getAttribute('is_foc') ?? false);

        if (! $ownFlag && ! ($links['has_foc'] ?? false)) {
            return ['is_foc' => false, 'list_value' => 0.0];
        }

        $focQty = $ownFlag ? $lineQty : (float) ($links['foc_qty'] ?? 0);
        $focQty = $focQty > 0 ? min($focQty, $lineQty) : $lineQty;

        return ['is_foc' => true, 'list_value' => round($focQty * (float) $line->unit_price, 2)];
    }

    /**
     * Issue #614 (TP4): the statutory jurisdiction for this document — the
     * invoice's own tax jurisdiction when stamped, else the selling entity's
     * country. Null lets CompanyDataSource fall back to the tenant's pack.
     */
    private function documentJurisdiction(Invoice $invoice): ?string
    {
        $explicit = strtoupper(trim((string) ($invoice->tax_jurisdiction ?? '')));
        if ($explicit !== '') {
            return $explicit;
        }

        $entityId = $invoice->legal_entity_id ?? $invoice->customer?->legal_entity_id;
        if ($entityId !== null) {
            $country = LegalEntity::query()->whereKey($entityId)->value('country_code');
            if (is_string($country) && trim($country) !== '') {
                return strtoupper(trim($country));
            }
        }

        return null;
    }

    /**
     * Issue #614 (TP4): the tax summary equals the DOCUMENT'S POSTED tax
     * lines (`tax_transaction_details`, written by PostingEngine from the
     * TaxDeterminationResult), grouped by tax code + rate. It never
     * recomputes tax from rates.
     *
     * A document not yet posted has no tax trail; its own booked tax columns
     * (line tax_rate/tax_amount/tax_type) are the only available source and
     * are used as-is, grouped the same way.
     *
     * @return list<array{code:string, rate:float, taxable:float, tax:float}>
     */
    private function taxSummary(Invoice $invoice): array
    {
        $posted = DB::table('tax_transaction_details')
            ->where('source_document_type', 'invoice')
            ->where('source_document_id', $invoice->id)
            ->orderBy('id')
            ->get(['tax_code', 'tax_rate', 'taxable_amount', 'tax_amount']);

        if ($posted->isNotEmpty()) {
            return $posted
                ->groupBy(fn ($row) => ((string) $row->tax_code).'|'.number_format((float) $row->tax_rate, 2, '.', ''))
                ->map(fn ($rows) => [
                    'code' => (string) $rows->first()->tax_code,
                    'rate' => (float) $rows->first()->tax_rate,
                    'taxable' => round($rows->sum(fn ($row) => (float) $row->taxable_amount), 2),
                    'tax' => round($rows->sum(fn ($row) => (float) $row->tax_amount), 2),
                ])
                ->values()
                ->all();
        }

        return $invoice->lines
            ->reject(fn ($line) => in_array($line->line_type ?? 'product', ['note', 'section'], true))
            ->groupBy(fn ($line) => ((string) ($line->tax_type ?? '')).'|'.number_format((float) $line->tax_rate, 2, '.', ''))
            ->map(fn ($lines) => [
                'code' => (string) ($lines->first()->tax_type ?? ''),
                'rate' => (float) $lines->first()->tax_rate,
                // line_total always includes tax (InvoiceLine::calculateTotals).
                'taxable' => round($lines->sum(fn ($line) => (float) $line->line_total - (float) $line->tax_amount), 2),
                'tax' => round($lines->sum(fn ($line) => (float) $line->tax_amount), 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Issue #614 (TP4): the e-invoice reference for this invoice. The UUID,
     * validation date and link come from the stored compliance document /
     * submission (MyInvois records) — nothing is synthesised. The QR image is
     * only rendered from a STORED payload (qr_payload, then validation_link);
     * when only the UUID exists, `qr` stays empty.
     *
     * @return array<string,string>
     */
    private function einvoice(Invoice $invoice): array
    {
        $document = ComplianceDocument::query()
            ->where('document_type', 'invoice')
            ->where('document_id', $invoice->id)
            ->orderByDesc('id')
            ->first();

        $submission = $document?->submissions()->orderByDesc('id')->first();

        $uuid = (string) ($submission?->external_document_uuid ?? '');
        $status = (string) ($submission?->submission_status ?? $document?->compliance_status ?? '');
        $validationLink = (string) ($submission?->validation_link ?? $document?->validation_link ?? '');

        $qrPayload = $this->qrPayload($document?->qr_payload, $validationLink);
        $qr = $qrPayload !== '' ? app(QrCodeService::class)->dataUri($qrPayload) : '';

        $explicitValidation = $this->validationDateFrom($submission);
        $validatedAt = $explicitValidation
            ?? (in_array($status, ['accepted', 'valid'], true) && $submission?->submitted_at
                ? $submission->submitted_at->toIso8601String()
                : '');

        return [
            'uuid' => $uuid,
            'validated_at' => (string) $validatedAt,
            'qr' => $qr,
            'status' => $status,
            'validation_link' => $validationLink,
        ];
    }

    private function qrPayload(mixed $stored, string $validationLink): string
    {
        if (is_array($stored)) {
            foreach (['payload', 'url', 'link', 'qr'] as $key) {
                if (isset($stored[$key]) && is_string($stored[$key]) && trim($stored[$key]) !== '') {
                    return trim($stored[$key]);
                }
            }
            foreach ($stored as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            return '';
        }

        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }

        return $validationLink;
    }

    private function validationDateFrom(?ComplianceSubmission $submission): ?string
    {
        if ($submission === null) {
            return null;
        }

        $keys = ['dateTimeValidated', 'validatedAt', 'validationDate', 'validation_datetime', 'dateTimeValidatedUtc'];

        foreach (['status_details', 'provider_response'] as $attribute) {
            $found = $this->findDateValue($submission->{$attribute}, $keys);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param list<string> $keys */
    private function findDateValue(mixed $node, array $keys): ?string
    {
        if (! is_array($node)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $node[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $found = $this->findDateValue($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Legacy internal references ("Sales Order #SO-…") must not print on the
     * customer PDF while genuine invoice notes are preserved. Mirrors the
     * frontend filter (invoicePresentation.ts) so PDF and UI agree.
     *
     * Regression: a merge on main kept this call but dropped the method,
     * returning 500 for every invoice PDF (report 2026-10-01).
     */
    private function visibleNotes(?string $notes): string
    {
        if (! $notes) {
            return '';
        }

        $lines = preg_split('/\r?\n/', $notes) ?: [];

        $kept = array_filter(
            $lines,
            fn (string $line): bool => ! preg_match('/^\s*Sales Order\s*#\s*\S+\s*$/i', $line),
        );

        return trim(implode("\n", $kept));
    }

    private function paymentTerms(Invoice $invoice): string
    {
        $terms = trim((string) ($invoice->terms ?? ''));
        if ($terms !== '') {
            return $terms;
        }

        // Issue #324: a master payment term prints by its name.
        $termName = trim((string) ($invoice->paymentTerm?->name ?? ''));
        if ($termName !== '') {
            return $termName;
        }

        $snapshot = trim((string) ($invoice->payment_terms_snapshot ?? ''));
        if ($snapshot !== '') {
            return is_numeric($snapshot) ? $snapshot.' days' : $snapshot;
        }

        if ($invoice->invoice_date && $invoice->due_date) {
            $days = $invoice->invoice_date->copy()->startOfDay()->diffInDays(
                $invoice->due_date->copy()->startOfDay(),
                false,
            );

            if ($days >= 0) {
                return $days.' days';
            }
        }

        $customerTerms = $invoice->customer?->payment_terms;
        if ($customerTerms !== null && is_numeric($customerTerms)) {
            return $customerTerms.' days';
        }

        return '';
    }

    private function customerAddress(Invoice $invoice, string $type): string
    {
        $defaultAddress = $invoice->customer?->addresses
            ?->first(fn ($address) => $address->type === $type && (bool) $address->is_default);

        if ($defaultAddress) {
            $parts = [
                $defaultAddress->label,
                $defaultAddress->street,
                $defaultAddress->street2,
                $defaultAddress->city,
                $defaultAddress->state,
                $defaultAddress->zip,
                $defaultAddress->country,
            ];

            $seen = [];
            $parts = array_filter($parts, function ($part) use (&$seen): bool {
                $value = trim((string) $part);
                if ($value === '') {
                    return false;
                }

                $key = mb_strtolower($value);
                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            });

            return implode("\n", $parts);
        }

        $snapshot = $type === 'billing'
            ? $invoice->billing_address_snapshot
            : $invoice->shipping_address_snapshot;
        $legacy = $type === 'billing'
            ? $invoice->customer?->billing_address
            : $invoice->customer?->shipping_address;

        return $this->formatAddress($snapshot ?: $legacy);
    }

    public function fieldCatalog(): array
    {
        return [
            ['group' => 'Invoice', 'fields' => [
                ['token' => 'invoice.number', 'label' => 'Invoice Number'],
                ['token' => 'invoice.issue_date', 'label' => 'Issue Date'],
                ['token' => 'invoice.due_date', 'label' => 'Due Date'],
                ['token' => 'invoice.status', 'label' => 'Status'],
                ['token' => 'invoice.reference', 'label' => 'Customer Reference'],
                ['token' => 'invoice.po_number', 'label' => 'PO Number'],
                ['token' => 'invoice.case_number', 'label' => 'Case Number'],
                ['token' => 'invoice.pr_no', 'label' => 'PR No'], ['token' => 'invoice.contract_no', 'label' => 'Contract No'], ['token' => 'invoice.buyer', 'label' => 'Buyer'], ['token' => 'invoice.plant_code', 'label' => 'Plant Code'], ['token' => 'invoice.company_code', 'label' => 'Company Code'],
                ['token' => 'invoice.date_of_case', 'label' => 'Date of Case'],
                ['token' => 'invoice.case_location', 'label' => 'Case Location'],
                ['token' => 'invoice.patient_name', 'label' => 'Patient Name'],
                ['token' => 'invoice.billing_address', 'label' => 'Billing Address'],
                ['token' => 'invoice.shipping_address', 'label' => 'Shipping Address'],
                ['token' => 'invoice.attention_to', 'label' => 'Attention To'],
                ['token' => 'invoice.doctor', 'label' => 'Doctor'],
                ['token' => 'invoice.requestor', 'label' => 'Requestor'],
                ['token' => 'invoice.billing_contact_name', 'label' => 'Billing Contact'],
                ['token' => 'invoice.shipping_contact_name', 'label' => 'Shipping Contact'],
                ['token' => 'invoice.shipping_method', 'label' => 'Shipping Method'],
                ['token' => 'invoice.incoterm_code', 'label' => 'Incoterm Code'],
                ['token' => 'invoice.incoterm_place', 'label' => 'Incoterm Place'],
                ['token' => 'invoice.salesperson', 'label' => 'Salesperson'],
                ['token' => 'invoice.delivery_date', 'label' => 'Delivery Date'],
                ['token' => 'invoice.notes', 'label' => 'Notes'],
                ['token' => 'invoice.terms', 'label' => 'Terms & Conditions'],
                // Issue #617: filled by the render service when a non-final
                // variant (acceptance copy) of the invoice is requested.
                ['token' => 'document_variant', 'label' => 'Document Variant Banner (blank for the final invoice)'],
            ]],
            ['group' => 'Customer', 'fields' => [
                ['token' => 'customer.code', 'label' => 'Customer Code'],
                ['token' => 'customer.name', 'label' => 'Name'],
                ['token' => 'customer.email', 'label' => 'Email'],
                ['token' => 'customer.phone', 'label' => 'Phone'],
                ['token' => 'customer.tax_id', 'label' => 'Tax / GST Registration No.'],
                ['token' => 'customer.company_registration_number', 'label' => 'Company Registration No.'],
            ]],
            ['group' => 'Company', 'fields' => [
                ['token' => 'company.name', 'label' => 'Company Name'],
                ['token' => 'company.logo_url', 'label' => 'Logo'],
                ['token' => 'company.address', 'label' => 'Address'],
                ['token' => 'company.email', 'label' => 'Email'],
                ['token' => 'company.phone', 'label' => 'Phone'],
                ['token' => 'company.registration_number', 'label' => 'Company Registration No.'],
                ['token' => 'company.tax_id', 'label' => 'Tax Registration No. (legacy, may collapse TIN and SST/GST)'],
                // Issue #614: statutory registration numbers are separate
                // tokens; the *_label/*_required tokens resolve from the
                // document jurisdiction's country pack.
                ['token' => 'company.tin', 'label' => 'Tax Identification No. (TIN / UEN)'],
                ['token' => 'company.tin_label', 'label' => 'TIN Label (country pack)'],
                ['token' => 'company.sst_number', 'label' => 'SST Registration No.'],
                ['token' => 'company.sst_number_label', 'label' => 'SST Label (country pack)'],
                ['token' => 'company.gst_number', 'label' => 'GST Registration No.'],
                ['token' => 'company.gst_number_label', 'label' => 'GST Label (country pack)'],
                ['token' => 'company.tax_number', 'label' => 'Regime Registration No. (SST/GST per pack)'],
                ['token' => 'company.tax_number_label', 'label' => 'Regime Registration Label (per pack)'],
                ['token' => 'company.bank.name', 'label' => 'Bank Name'],
                ['token' => 'company.bank.account_name', 'label' => 'Bank Account Name'],
                ['token' => 'company.bank.account_number', 'label' => 'Bank Account Number'],
                ['token' => 'company.bank.swift_code', 'label' => 'Bank SWIFT Code'],
            ]],
            ['group' => 'Totals', 'fields' => [
                ['token' => 'totals.subtotal', 'label' => 'Subtotal'],
                ['token' => 'totals.tax', 'label' => 'Tax'],
                ['token' => 'totals.discount', 'label' => 'Discount'],
                ['token' => 'totals.shipping', 'label' => 'Shipping'],
                ['token' => 'totals.rounding', 'label' => 'Cash Rounding'],
                ['token' => 'totals.total', 'label' => 'Grand Total'],
                ['token' => 'totals.paid', 'label' => 'Amount Paid'],
                ['token' => 'totals.balance', 'label' => 'Balance Due'],
                ['token' => 'totals.amount_in_words', 'label' => 'Amount in Words'],
            ]],
            ['group' => 'Tax Summary', 'fields' => [
                ['token' => 'tax_summary', 'label' => 'Tax summary table (binds a Table block)'],
                ['token' => 'tax_summary.code', 'label' => 'Tax Code'],
                ['token' => 'tax_summary.rate', 'label' => 'Tax Rate'],
                ['token' => 'tax_summary.taxable', 'label' => 'Taxable Amount'],
                ['token' => 'tax_summary.tax', 'label' => 'Tax Amount'],
            ]],
            ['group' => 'E-Invoice', 'fields' => [
                ['token' => 'einvoice.uuid', 'label' => 'E-Invoice UUID'],
                ['token' => 'einvoice.validated_at', 'label' => 'E-Invoice Validation Date'],
                ['token' => 'einvoice.qr', 'label' => 'E-Invoice QR (image)'],
                ['token' => 'einvoice.status', 'label' => 'E-Invoice Status'],
                ['token' => 'einvoice.validation_link', 'label' => 'E-Invoice Validation Link'],
            ]],
            ['group' => 'Line Items', 'fields' => [
                ['token' => 'lines', 'label' => 'Line items table (binds a Table block)'],
                ['token' => 'lines.lot_number', 'label' => 'Lot / Batch Number'],
                ['token' => 'lines.expiry_date', 'label' => 'Expiry Date'],
                ['token' => 'lines.serial_number', 'label' => 'Serial Number'],
                // Issue #621 (TPL-UX4)
                ['token' => 'lines.is_foc', 'label' => 'FOC Line (free of charge)'],
                ['token' => 'lines.list_value', 'label' => 'FOC List Value (quantity × list price)'],
                ['token' => 'lines.case_number', 'label' => 'Medical Case Number (per line)'],
                ['token' => 'lines.patient_name', 'label' => 'Patient Name (per line, masked without PHI permission)'],
                ['token' => 'lines.so_number', 'label' => 'Sales Order Number (per line)'],
                ['token' => 'lines.do_number', 'label' => 'Delivery Order Number (per line)'],
            ]],
        ];
    }

    public function sample(): array
    {
        return [
            'invoice' => [
                'number' => 'INV-2026-0001',
                'issue_date' => '2026-07-08',
                'due_date' => '2026-08-07',
                'status' => 'posted',
                'reference' => 'PO-0042',
                'po_number' => 'PO-0042',
                'case_number' => 'CASE-2026-001',
                'pr_no' => 'PR-2026-001',
                'contract_no' => 'CONTRACT-2026-001',
                'buyer' => 'Ms. Emily Park',
                'plant_code' => 'PLANT-01',
                'company_code' => 'COMP-01',
                'date_of_case' => '2026-07-07',
                'case_location' => 'Main Operating Theatre',
                'patient_name' => 'Sample Patient',
                'delivery_date' => '2026-07-20',
                'billing_address' => "Globex Corporation\n1 Infinite Loop\nSan Francisco, CA 94102",
                'shipping_address' => "Globex Corporation\n1 Infinite Loop\nSan Francisco, CA 94102",
                'notes' => 'Thank you for your business.',
                'terms' => 'Payment due within 30 days. Late payments incur 1.5% monthly interest.',
                'attention_to' => 'Dr. Sarah Chen',
                'doctor' => 'Dr. James Wilson',
                'requestor' => 'Ms. Emily Park',
                'billing_contact_name' => 'Dr. Sarah Chen',
                'shipping_contact_name' => 'Dr. Sarah Chen',
                'salesperson' => 'John Smith',
                'shipping_method' => 'Courier',
                'incoterm_code' => 'FOB',
                'incoterm_place' => 'Port Klang',
            ],
            'customer' => [
                'code' => 'CUST-0001',
                'name' => 'Globex Corporation',
                'email' => 'ap@globex.example',
                'phone' => '+1 415 555 0100',
                'tax_id' => 'US-123456789',
                'company_registration_number' => 'GLB-2024-00123',
                'billing_address' => "Globex Corporation\n1 Infinite Loop\nSan Francisco, CA 94102",
            ],
            'company' => CompanyDataSource::sample(),
            'tax_summary' => [
                ['code' => 'SG-GST-6', 'rate' => 6.0, 'taxable' => 7600.00, 'tax' => 456.00],
            ],
            'einvoice' => [
                'uuid' => '0f8fad5b-d9cb-469f-a165-70867728950e',
                'validated_at' => '2026-07-08T09:30:00Z',
                'qr' => 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="90" height="90"><rect width="90" height="90" fill="#fff"/></svg>'),
                'status' => 'valid',
                'validation_link' => 'https://myinvois.example/0f8fad5b-d9cb-469f-a165-70867728950e/share/LONGID',
            ],
            // Issue #617: overridden by the render service for variants
            // (acceptance copies); empty means the canonical final invoice.
            'document_variant' => '',
            'lines' => [
                ['index' => 1, 'sku' => 'SKU-1001', 'description' => 'Enterprise licence — 50 seats', 'quantity' => 1, 'unit_price' => 4500.00, 'tax_rate' => 6.0, 'discount' => 0, 'line_total' => 4770.00, 'lot_number' => 'LOT-2607A', 'expiry_date' => '2029-06-07', 'is_foc' => false, 'list_value' => 0.0, 'case_number' => 'CASE-2026-001', 'so_number' => 'SO-2026-0001', 'do_number' => 'DO-2026-0001'],
                ['index' => 2, 'sku' => 'SKU-2099', 'description' => 'On-site training (2 days)',     'quantity' => 2, 'unit_price' => 1200.00, 'tax_rate' => 6.0, 'discount' => 0, 'line_total' => 2544.00, 'lot_number' => '', 'expiry_date' => '', 'is_foc' => false, 'list_value' => 0.0, 'case_number' => 'CASE-2026-001', 'so_number' => 'SO-2026-0001', 'do_number' => 'DO-2026-0001'],
                ['index' => 3, 'sku' => 'SKU-3300', 'description' => 'Premium support — annual',      'quantity' => 1, 'unit_price' => 800.00,  'tax_rate' => 6.0, 'discount' => 100, 'line_total' => 742.00, 'lot_number' => 'LOT-2511B', 'expiry_date' => '2028-09-27', 'is_foc' => false, 'list_value' => 0.0, 'case_number' => 'CASE-2026-001', 'so_number' => 'SO-2026-0001', 'do_number' => 'DO-2026-0001'],
            ],
            'totals' => [
                'subtotal' => 7600.00, 'tax' => 456.00, 'discount' => 100.00, 'shipping' => 0.00,
                'rounding' => 0.00,
                'total' => 8056.00, 'paid' => 0.00, 'balance' => 8056.00,
                'amount_in_words' => AmountInWords::format(8056.00, 'SGD', 'en'),
            ],
            'meta' => ['currency_code' => 'SGD', 'currency_symbol' => 'S$', 'number_format' => '1,234.56', 'locale' => 'en', 'language' => 'en'],
        ];
    }

    public static function currencySymbol(string $code): string
    {
        return [
            'MYR' => 'RM ',
            'SGD' => 'S$',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'IDR' => 'Rp ',
            'INR' => '₹',
            'THB' => '฿',
            'AUD' => 'A$',
            'CNY' => '¥',
            'JPY' => '¥',
        ][strtoupper($code)] ?? ($code.' ');
    }

    private function formatAddress(mixed $address): string
    {
        if (empty($address)) {
            return '';
        }
        if (is_string($address)) {
            $decoded = json_decode($address, true);
            if (is_array($decoded)) {
                return $this->formatAddress($decoded);
            }

            return trim($address);
        }
        if (is_array($address)) {
            $parts = array_values(array_filter(array_map('trim', array_map('strval', $address)), fn ($v) => $v !== ''));

            return implode("\n", $parts);
        }

        return (string) $address;
    }
}
