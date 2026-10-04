<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use App\Models\Quotation;
use Modules\TemplateBuilder\Contracts\DataSourceContract;

/**
 * Data source for Sales Quotations.
 *
 * Loads App\Models\Quotation (the Sales module entity) and maps its fields
 * to the same merge-field payload shape used by InvoiceDataSource so that
 * block templates with invoice.* tokens also work for quotations.
 */
final class QuotationDataSource implements DataSourceContract
{
    private ?Quotation $quotation = null;

    public static function documentType(): string
    {
        return 'quotation';
    }

    public function permission(): string
    {
        return 'quotations.view';
    }

    public function legalEntityId(): ?int
    {
        $quotation = $this->quotation ?? throw new \RuntimeException('Quotation not loaded.');
        $entityId = $quotation->customer?->legal_entity_id;

        return $entityId !== null ? (int) $entityId : null;
    }

    public function resolutionContext(string $documentId): array
    {
        $quotation = $this->load($documentId)->quotation;

        return DocumentResolutionContext::build([
            'entity_id' => $this->legalEntityId(),
            'branch_id' => $quotation->getAttribute('branch_id'),
            'currency' => $quotation->currency_code,
            'language' => $quotation->customer?->language,
            'customer_group' => $quotation->customer?->customer_group,
            'channel' => $quotation->getAttribute('channel'),
        ]);
    }

    public function load(string $documentId): self
    {
        $this->quotation = Quotation::with(['customer', 'lines.product', 'billingContact', 'shippingContact'])->findOrFail($documentId);

        return $this;
    }

    public function toArray(): array
    {
        $q = $this->quotation ?? throw new \RuntimeException('Quotation not loaded.');
        $company = CompanyDataSource::forDocument($q);
        // MRD FR-8.5: document currency primary; base equivalent secondary.
        $companyCurrency = $company['currency_code'] ?? 'MYR';
        $currencyCode = $q->currency_code ?: $companyCurrency;
        $baseCurrencyCode = $q->base_currency_code ?: $companyCurrency;
        $exchangeRate = $q->exchange_rate !== null ? (float) $q->exchange_rate : 1.0;

        $lines = [];
        $salesperson = $q->customer?->salesperson?->name ?? $q->salesperson ?? '';
        foreach ($q->lines as $i => $line) {
            $isProduct = ($line->line_type ?? 'product') === 'product';
            $lines[] = [
                'line_type' => $line->line_type ?? 'product',
                'index' => $i + 1,
                'sku' => $line->product?->sku ?? '',
                'description' => $line->description ?? '',
                'quantity' => $isProduct ? (float) $line->quantity : null,
                'unit_price' => $isProduct ? (float) $line->unit_price : null,
                'tax_rate' => (float) $line->tax_rate,
                'discount' => (float) ($line->discount_amount ?? 0),
                'line_total' => $isProduct ? (float) $line->line_total : null,
                'salesperson' => $salesperson,
                'attention_to' => $q->attention_to ?? '',
                'po_number' => $q->customer_reference ?? '',
                'shipping_method' => $q->shipping_method ?? '',
                'shipping_terms' => $q->shipping_terms ?? '',
                'delivery_lead_time' => $q->delivery_lead_time ?? '',
                'validity' => $q->validity ?? '',
            ];
        }

        // Ensure totals are accurate
        $q->calculateTotals();
        $q->refresh();

        return [
            'quotation' => [
                'number' => $q->quotation_number,
                'issue_date' => optional($q->quotation_date)->format('Y-m-d'),
                'valid_until' => optional($q->valid_until)->format('Y-m-d'),
                'status' => $q->status,
                'customer_reference' => $q->customer_reference ?? '',
                'internal_reference' => $q->internal_reference ?? '',
                'attention_to' => $q->attention_to ?? '',
                'salesperson' => $q->salesperson ?? '',
                'delivery_lead_time' => $q->delivery_lead_time ?? '',
                'shipping_method' => $q->shipping_method ?? '',
                'shipping_terms' => $q->shipping_terms ?? '',
                // Issue #333: Incoterm code + place (blank when unset).
                'incoterm_code' => $q->incoterm_code ?? '',
                'incoterm_place' => $q->incoterm_place ?? '',
                'validity' => $q->validity ?? '',
                'notes' => $q->notes ?? '',
                'terms' => $q->terms ?? '',
            ],
            // Map quotation fields into 'invoice' namespace so existing block
            // templates designed for invoices work without modification.
            'invoice' => [
                'number' => $q->quotation_number,
                'issue_date' => optional($q->quotation_date)->format('Y-m-d'),
                'due_date' => optional($q->valid_until)->format('Y-m-d'),
                'status' => $q->status,
                'reference' => $q->customer_reference ?? '',
                'billing_address' => $this->formatAddress($q->billing_address_snapshot ?? $q->customer?->billing_address ?? ''),
                'shipping_address' => $this->formatAddress($q->shipping_address_snapshot ?? $q->customer?->shipping_address ?? ''),
                'notes' => $q->notes ?? '',
                'terms' => $q->terms ?? '',
                'attention_to' => $q->attention_to ?? '',
                'salesperson' => $q->salesperson ?? '',
                'validity' => $q->validity ?? '',
                'delivery_lead_time' => $q->delivery_lead_time ?? '',
                'shipping_method' => $q->shipping_method ?? '',
                'shipping_terms' => $q->shipping_terms ?? '',
                // Issue #333: Incoterm code + place (blank when unset).
                'incoterm_code' => $q->incoterm_code ?? '',
                'incoterm_place' => $q->incoterm_place ?? '',
                'internal_reference' => $q->internal_reference ?? '',
                'billing_contact_name' => $q->billingContact?->display_name ?? '',
                'shipping_contact_name' => $q->shippingContact?->display_name ?? '',
            ],
            'customer' => [
                'code' => $q->customer?->code ?? '',
                'name' => $q->customer?->name ?? '',
                'email' => $q->customer?->email ?? '',
                'phone' => $q->customer?->phone ?? '',
                'tax_id' => $q->customer?->tax_number ?? '',
                'billing_address' => $this->formatAddress($q->customer?->billing_address ?? ''),
            ],
            'company' => $company,
            'lines' => $lines,
            'totals' => [
                'subtotal' => (float) $q->subtotal,
                'tax' => (float) $q->tax_amount,
                'discount' => (float) ($q->discount_amount ?? 0),
                'shipping' => 0.00,
                'total' => (float) $q->total,
                'paid' => 0.00,
                'balance' => (float) $q->total,
                'base_total' => round((float) ($q->total ?? 0) * $exchangeRate, 2),
                'exchange_rate' => $exchangeRate,
            ],
            'meta' => [
                'currency_code' => $currencyCode,
                'base_currency_code' => $baseCurrencyCode,
                'exchange_rate' => $exchangeRate,
                'currency_symbol' => InvoiceDataSource::currencySymbol($currencyCode),
                'number_format' => '1,234.56',
                'locale' => 'en',
            ],
        ];
    }

    public function fieldCatalog(): array
    {
        return [
            ['group' => 'Quotation', 'fields' => [
                ['token' => 'invoice.number', 'label' => 'Quotation Number'],
                ['token' => 'invoice.issue_date', 'label' => 'Quotation Date'],
                ['token' => 'invoice.due_date', 'label' => 'Valid Until'],
                ['token' => 'invoice.status', 'label' => 'Status'],
                ['token' => 'invoice.reference', 'label' => 'Customer Reference'],
                ['token' => 'invoice.billing_address', 'label' => 'Billing Address'],
                ['token' => 'invoice.shipping_address', 'label' => 'Shipping Address'],
                ['token' => 'invoice.attention_to', 'label' => 'Attention To'],
                ['token' => 'invoice.salesperson', 'label' => 'Salesperson'],
                ['token' => 'invoice.validity', 'label' => 'Validity'],
                ['token' => 'invoice.delivery_lead_time', 'label' => 'Delivery Lead Time'],
                ['token' => 'invoice.shipping_method', 'label' => 'Shipping Method'],
                ['token' => 'invoice.shipping_terms', 'label' => 'Shipping Terms'],
                ['token' => 'invoice.incoterm_code', 'label' => 'Incoterm Code'],
                ['token' => 'invoice.incoterm_place', 'label' => 'Incoterm Place'],
                ['token' => 'invoice.billing_contact_name', 'label' => 'Billing Contact'],
                ['token' => 'invoice.shipping_contact_name', 'label' => 'Shipping Contact'],
                ['token' => 'invoice.notes', 'label' => 'Notes'],
                ['token' => 'invoice.terms', 'label' => 'Terms & Conditions'],
            ]],
            ['group' => 'Customer', 'fields' => [
                ['token' => 'customer.code', 'label' => 'Customer Code'],
                ['token' => 'customer.name', 'label' => 'Name'],
                ['token' => 'customer.email', 'label' => 'Email'],
                ['token' => 'customer.phone', 'label' => 'Phone'],
                ['token' => 'customer.tax_id', 'label' => 'Tax / GST No.'],
            ]],
            ['group' => 'Company', 'fields' => [
                ['token' => 'company.name', 'label' => 'Company Name'],
                ['token' => 'company.address', 'label' => 'Address'],
                ['token' => 'company.email', 'label' => 'Email'],
                ['token' => 'company.phone', 'label' => 'Phone'],
            ]],
            ['group' => 'Totals', 'fields' => [
                ['token' => 'totals.subtotal', 'label' => 'Subtotal'],
                ['token' => 'totals.tax', 'label' => 'Tax'],
                ['token' => 'totals.discount', 'label' => 'Discount'],
                ['token' => 'totals.total', 'label' => 'Grand Total'],
            ]],
            ['group' => 'Line Items', 'fields' => [
                ['token' => 'lines', 'label' => 'Line items table (binds a Table block)'],
            ]],
        ];
    }

    public function sample(): array
    {
        $base = (new InvoiceDataSource)->sample();
        $base['invoice']['number'] = 'QT-2026-0001';
        $base['invoice']['status'] = 'draft';
        $base['invoice']['due_date'] = '2026-08-07';
        $base['invoice']['salesperson'] = 'John Smith';
        $base['invoice']['validity'] = '30 days';
        $base['invoice']['delivery_lead_time'] = '2-3 weeks';
        $base['invoice']['billing_contact_name'] = 'Dr. Sarah Chen';
        $base['invoice']['shipping_contact_name'] = 'Dr. Sarah Chen';
        $base['quotation'] = [
            'number' => 'QT-2026-0001',
            'issue_date' => '2026-07-08',
            'valid_until' => '2026-08-07',
            'status' => 'draft',
            'customer_reference' => 'RFQ-1001',
            'internal_reference' => 'SALES-2026-01',
            'attention_to' => 'Dr. Sarah Chen',
            'salesperson' => 'John Smith',
            'delivery_lead_time' => '2-3 weeks',
            'shipping_method' => 'Courier',
            'shipping_terms' => 'Delivered Duty Paid',
            'incoterm_code' => 'FOB',
            'incoterm_place' => 'Port Klang',
            'validity' => '30 days',
            'notes' => 'Sample quotation note',
            'terms' => 'Standard terms apply',
        ];

        return $base;
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
