<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use App\Models\Invoice;
use Modules\TemplateBuilder\Contracts\DataSourceContract;

class InvoiceDataSource implements DataSourceContract
{
    private ?Invoice $invoice = null;

    public static function documentType(): string
    {
        return 'invoice';
    }

    public function load(string $documentId): self
    {
        $this->invoice = Invoice::with(['customer.salesperson', 'customer.addresses', 'lines.product', 'doctor', 'requestor', 'buyer', 'billingContact', 'shippingContact'])->findOrFail($documentId);

        return $this;
    }

    public function toArray(): array
    {
        $invoice = $this->invoice ?? throw new \RuntimeException('Invoice not loaded.');
        $company = CompanyDataSource::forCurrentTenant();
        // MRD pricelist-multicurrency FR-8.5: the DOCUMENT currency drives
        // the printed figures when the invoice carries a snapshot; the base
        // currency equivalent is exposed as a secondary token.
        $companyCurrency = $company['currency_code'] ?? 'MYR';
        $currencyCode = $invoice->currency_code ?: $companyCurrency;
        $baseCurrencyCode = $invoice->base_currency_code ?: $companyCurrency;
        $exchangeRate = $invoice->exchange_rate !== null ? (float) $invoice->exchange_rate : 1.0;

        $lines = [];
        $salesperson = $invoice->customer?->salesperson?->name ?? '';
        $doctor = $invoice->doctor?->full_name ?? $invoice->doctor?->display_name ?? '';
        $requestor = $invoice->requestor?->full_name ?? $invoice->requestor?->display_name ?? '';
        $paymentTerms = $this->paymentTerms($invoice);
        $billingAddress = $this->customerAddress($invoice, 'billing');
        $shippingAddress = $this->customerAddress($invoice, 'shipping');
        $productIndex = 0;
        foreach ($invoice->lines as $i => $line) {
            $isProduct = ($line->line_type ?? 'product') === 'product';
            if ($isProduct) {
                $productIndex++;
            }
            $lines[] = [
                'line_type' => $line->line_type ?? 'product',
                'index' => $isProduct ? $productIndex : null,
                'sku' => $line->product?->sku ?? '',
                'description' => $line->description ?? '',
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
                'case_number' => $invoice->case_number ?? '',
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
                'pr_no' => $invoice->pr_no ?? '',
                'contract_no' => $invoice->contract_no ?? '',
                'buyer' => $invoice->buyer?->full_name ?? $invoice->buyer?->display_name ?? '',
                'plant_code' => $invoice->plant_code ?? '',
                'company_code' => $invoice->company_code ?? '',
                'billing_address' => $billingAddress,
                'shipping_address' => $shippingAddress,
                'notes' => $this->visibleNotes($invoice->notes),
                'payment_terms' => $paymentTerms,
                'terms' => $paymentTerms,
                'attention_to' => $invoice->attention_to ?? '',
                'shipping_method' => $invoice->shipping_method ?? '',
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
                'billing_address' => $billingAddress,
            ],
            'company' => $company,
            'lines' => $lines,
            'totals' => [
                'subtotal' => (float) $invoice->subtotal,
                'tax' => (float) $invoice->tax_amount,
                'discount' => (float) ($invoice->discount_amount ?? 0),
                'shipping' => (float) ($invoice->shipping_cost ?? 0),
                'total' => (float) $invoice->total,
                'paid' => (float) ($invoice->paid_amount ?? 0),
                'balance' => (float) $invoice->balance,
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
            ],
        ];
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
                ['token' => 'invoice.salesperson', 'label' => 'Salesperson'],
                ['token' => 'invoice.delivery_date', 'label' => 'Delivery Date'],
                ['token' => 'invoice.notes', 'label' => 'Notes'],
                ['token' => 'invoice.payment_terms', 'label' => 'Payment Terms'],
                ['token' => 'invoice.terms', 'label' => 'Terms & Conditions'],
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
                ['token' => 'company.tax_id', 'label' => 'Tax Registration No.'],
            ]],
            ['group' => 'Totals', 'fields' => [
                ['token' => 'totals.subtotal', 'label' => 'Subtotal'],
                ['token' => 'totals.tax', 'label' => 'Tax'],
                ['token' => 'totals.discount', 'label' => 'Discount'],
                ['token' => 'totals.shipping', 'label' => 'Shipping'],
                ['token' => 'totals.total', 'label' => 'Grand Total'],
                ['token' => 'totals.paid', 'label' => 'Amount Paid'],
                ['token' => 'totals.balance', 'label' => 'Balance Due'],
            ]],
            ['group' => 'Line Items', 'fields' => [
                ['token' => 'lines', 'label' => 'Line items table (binds a Table block)'],
                ['token' => 'lines.lot_number', 'label' => 'Lot / Batch Number'],
                ['token' => 'lines.expiry_date', 'label' => 'Expiry Date'],
                ['token' => 'lines.serial_number', 'label' => 'Serial Number'],
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
                'payment_terms' => 'Payment due within 30 days. Late payments incur 1.5% monthly interest.',
                'terms' => 'Payment due within 30 days. Late payments incur 1.5% monthly interest.',
                'attention_to' => 'Dr. Sarah Chen',
                'doctor' => 'Dr. James Wilson',
                'requestor' => 'Ms. Emily Park',
                'billing_contact_name' => 'Dr. Sarah Chen',
                'shipping_contact_name' => 'Dr. Sarah Chen',
                'salesperson' => 'John Smith',
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
            'lines' => [
                ['index' => 1, 'sku' => 'SKU-1001', 'description' => 'Enterprise licence — 50 seats', 'quantity' => 1, 'unit_price' => 4500.00, 'tax_rate' => 6.0, 'discount' => 0, 'line_total' => 4770.00, 'lot_number' => 'LOT-2607A', 'expiry_date' => '2029-06-07'],
                ['index' => 2, 'sku' => 'SKU-2099', 'description' => 'On-site training (2 days)',     'quantity' => 2, 'unit_price' => 1200.00, 'tax_rate' => 6.0, 'discount' => 0, 'line_total' => 2544.00, 'lot_number' => '', 'expiry_date' => ''],
                ['index' => 3, 'sku' => 'SKU-3300', 'description' => 'Premium support — annual',      'quantity' => 1, 'unit_price' => 800.00,  'tax_rate' => 6.0, 'discount' => 100, 'line_total' => 742.00, 'lot_number' => 'LOT-2511B', 'expiry_date' => '2028-09-27'],
            ],
            'totals' => [
                'subtotal' => 7600.00, 'tax' => 456.00, 'discount' => 100.00, 'shipping' => 0.00,
                'total' => 8056.00, 'paid' => 0.00, 'balance' => 8056.00,
            ],
            'meta' => ['currency_code' => 'SGD', 'currency_symbol' => 'S$', 'number_format' => '1,234.56', 'locale' => 'en'],
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
