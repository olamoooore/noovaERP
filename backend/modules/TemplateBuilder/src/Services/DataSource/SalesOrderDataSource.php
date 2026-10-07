<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use App\Services\CustomerAddressResolver;
use Modules\Sales\Models\SalesOrder;
use Modules\TemplateBuilder\Contracts\DataSourceContract;
use Modules\TemplateBuilder\Support\AmountInWords;

/**
 * Issue #615 (TP5) — data source for Sales Orders.
 *
 * Loads the Sales module's SalesOrder and maps it to a merge-field payload.
 * Order confirmation and pro forma invoice are thin variants of this source
 * (see SalesOrderConfirmationDataSource / SalesProformaInvoiceDataSource):
 * they render the same order record under their own registered type key and
 * template.
 *
 * The `invoice` namespace mirrors the invoice-safe tokens (number, dates,
 * addresses, totals) so blocks authored for invoice-shaped documents can be
 * reused, exactly as QuotationDataSource does.
 */
class SalesOrderDataSource implements DataSourceContract
{
    protected ?SalesOrder $order = null;

    public static function documentType(): string
    {
        return 'sales_order';
    }

    public function permission(): string
    {
        return 'sales.view';
    }

    public function legalEntityId(): ?int
    {
        $order = $this->order ?? throw new \RuntimeException('Sales order not loaded.');
        // Issue #140: the order carries its own legal entity; the customer's is
        // only a fallback for rows created before that backfill.
        $entityId = $order->legal_entity_id ?? $order->customer?->legal_entity_id;

        return $entityId !== null ? (int) $entityId : null;
    }

    public function resolutionContext(string $documentId): array
    {
        $order = $this->load($documentId)->order;

        return DocumentResolutionContext::build([
            'entity_id' => $this->legalEntityId(),
            'currency' => $order->currency_code,
            'language' => $order->customer?->language,
            'customer_group' => $order->customer?->customer_group,
            'channel' => $order->getAttribute('channel'),
        ]);
    }

    public function load(string $documentId): self
    {
        $this->order = SalesOrder::with([
            'customer.salesperson',
            'lines.product',
            'warehouse',
            'quotation',
        ])->findOrFail($documentId);

        return $this;
    }

    public function toArray(): array
    {
        $order = $this->order ?? throw new \RuntimeException('Sales order not loaded.');
        $company = CompanyDataSource::forDocument($order);
        // MRD FR-8.5: document currency primary; base equivalent secondary.
        $companyCurrency = $company['currency_code'] ?? 'MYR';
        $currencyCode = $order->currency_code ?: $companyCurrency;
        $baseCurrencyCode = $order->base_currency_code ?: $companyCurrency;
        $exchangeRate = $order->exchange_rate !== null ? (float) $order->exchange_rate : 1.0;

        // ADR-003B W2b: the order's own address, else the customer's address
        // from the one shared rule (never the older text columns).
        $addresses = app(CustomerAddressResolver::class);
        $billingAddress = $this->formatAddress(
            $order->billing_address_snapshot ?? $addresses->snapshotFor($order->customer, 'billing'),
        );
        $shippingAddress = $this->formatAddress(
            $order->shipping_address_snapshot ?? $addresses->snapshotFor($order->customer, 'shipping'),
        );
        $salesperson = $order->customer?->salesperson?->name ?? '';

        $lines = [];
        $productIndex = 0;
        foreach ($order->lines as $line) {
            $isProduct = ($line->line_type ?? 'product') === 'product';
            if ($isProduct) {
                $productIndex++;
            }
            $lines[] = [
                'line_type' => $line->line_type ?? 'product',
                'index' => $isProduct ? $productIndex : null,
                'sku' => $line->sku ?? $line->product?->sku ?? '',
                'description' => $line->description ?? '',
                'quantity' => $isProduct ? (float) $line->quantity : null,
                'unit' => $isProduct ? ($line->unit ?: 'pcs') : null,
                'unit_price' => $isProduct ? (float) $line->unit_price : null,
                'tax_rate' => (float) $line->tax_rate,
                'discount' => (float) ($line->discount_amount ?? 0),
                'line_total' => $isProduct ? (float) $line->line_total : null,
                'salesperson' => $salesperson,
                'attention_to' => $order->attention_to ?? '',
            ];
        }

        return [
            'sales_order' => [
                'number' => $order->sales_order_number,
                'order_date' => optional($order->order_date)->format('Y-m-d'),
                'required_date' => optional($order->required_date)->format('Y-m-d'),
                'status' => $order->status,
                'accounting_status' => $order->accounting_status,
                'customer_reference' => $order->customer_reference ?? '',
                'internal_reference' => $order->internal_reference ?? '',
                'po_number' => $order->po_number ?? '',
                'attention_to' => $order->attention_to ?? '',
                'contact_email' => $order->contact_email ?? '',
                'contact_phone' => $order->contact_phone ?? '',
                'notes' => $order->notes ?? '',
                'internal_notes' => $order->internal_notes ?? '',
                'billing_address' => $billingAddress,
                'shipping_address' => $shippingAddress,
                'warehouse' => $order->warehouse?->name ?? '',
                'salesperson' => $salesperson,
                'quotation_number' => $order->quotation?->quotation_number ?? '',
                'accepted_at' => optional($order->accepted_at)->format('Y-m-d') ?? '',
                // Issue #333: Incoterm code + place (blank when unset).
                'incoterm_code' => $order->incoterm_code ?? '',
                'incoterm_place' => $order->incoterm_place ?? '',
            ],
            // Compatibility namespace for invoice-shaped templates; mirrors
            // the QuotationDataSource contract.
            'invoice' => [
                'number' => $order->sales_order_number,
                'issue_date' => optional($order->order_date)->format('Y-m-d'),
                'due_date' => optional($order->required_date)->format('Y-m-d'),
                'status' => $order->status,
                'reference' => $order->customer_reference ?? '',
                'po_number' => $order->po_number ?? '',
                'billing_address' => $billingAddress,
                'shipping_address' => $shippingAddress,
                'notes' => $order->notes ?? '',
                'attention_to' => $order->attention_to ?? '',
                'salesperson' => $salesperson,
                'internal_reference' => $order->internal_reference ?? '',
                // Issue #333: Incoterm code + place (blank when unset).
                'incoterm_code' => $order->incoterm_code ?? '',
                'incoterm_place' => $order->incoterm_place ?? '',
            ],
            'customer' => [
                'code' => $order->customer?->code ?? '',
                'name' => $order->customer?->name ?? '',
                'email' => $order->customer?->email ?? $order->contact_email ?? '',
                'phone' => $order->customer?->phone ?? $order->contact_phone ?? '',
                'tax_id' => $order->customer?->tax_number ?? '',
                // Decision 2026-10-06: the customer Bill To follows the
                // document's own billing address.
                'billing_address' => $billingAddress,
            ],
            'company' => $company,
            'warehouse' => ['name' => $order->warehouse?->name ?? ''],
            'quotation' => ['number' => $order->quotation?->quotation_number ?? ''],
            'lines' => $lines,
            'totals' => [
                'subtotal' => (float) $order->subtotal,
                'tax' => (float) $order->tax_amount,
                'discount' => (float) ($order->discount_amount ?? 0),
                'shipping' => 0.0,
                'total' => (float) $order->total,
                'paid' => 0.0,
                'balance' => (float) $order->total,
                'amount_in_words' => AmountInWords::format(
                    (float) $order->total,
                    $currencyCode,
                    $order->customer?->language,
                ),
                'base_total' => round((float) $order->total * $exchangeRate, 2),
                'exchange_rate' => $exchangeRate,
            ],
            'meta' => [
                'currency_code' => $currencyCode,
                'base_currency_code' => $baseCurrencyCode,
                'exchange_rate' => $exchangeRate,
                'currency_symbol' => InvoiceDataSource::currencySymbol($currencyCode),
                'number_format' => '1,234.56',
                'locale' => 'en',
                'language' => strtolower((string) ($order->customer?->language ?: 'en')),
            ],
        ];
    }

    public function fieldCatalog(): array
    {
        return [
            ['group' => 'Sales Order', 'fields' => [
                ['token' => 'sales_order.number', 'label' => 'Sales Order Number'],
                ['token' => 'sales_order.order_date', 'label' => 'Order Date'],
                ['token' => 'sales_order.required_date', 'label' => 'Required Date'],
                ['token' => 'sales_order.status', 'label' => 'Status'],
                ['token' => 'sales_order.customer_reference', 'label' => 'Customer Reference'],
                ['token' => 'sales_order.internal_reference', 'label' => 'Internal Reference'],
                ['token' => 'sales_order.po_number', 'label' => 'PO Number'],
                ['token' => 'sales_order.attention_to', 'label' => 'Attention To'],
                ['token' => 'sales_order.contact_email', 'label' => 'Contact Email'],
                ['token' => 'sales_order.contact_phone', 'label' => 'Contact Phone'],
                ['token' => 'sales_order.billing_address', 'label' => 'Billing Address'],
                ['token' => 'sales_order.shipping_address', 'label' => 'Shipping Address'],
                ['token' => 'sales_order.warehouse', 'label' => 'Warehouse'],
                ['token' => 'sales_order.salesperson', 'label' => 'Salesperson'],
                ['token' => 'sales_order.quotation_number', 'label' => 'Source Quotation'],
                ['token' => 'sales_order.incoterm_code', 'label' => 'Incoterm Code'],
                ['token' => 'sales_order.incoterm_place', 'label' => 'Incoterm Place'],
                ['token' => 'sales_order.notes', 'label' => 'Notes'],
            ]],
            ['group' => 'Customer', 'fields' => [
                ['token' => 'customer.code', 'label' => 'Customer Code'],
                ['token' => 'customer.name', 'label' => 'Name'],
                ['token' => 'customer.email', 'label' => 'Email'],
                ['token' => 'customer.phone', 'label' => 'Phone'],
                ['token' => 'customer.tax_id', 'label' => 'Tax / GST Registration No.'],
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
                ['token' => 'totals.amount_in_words', 'label' => 'Amount in Words'],
            ]],
            ['group' => 'Line Items', 'fields' => [
                ['token' => 'lines', 'label' => 'Line items table (binds a Table block)'],
            ]],
        ];
    }

    public function sample(): array
    {
        $base = (new InvoiceDataSource)->sample();
        $base['sales_order'] = [
            'number' => 'SO-2026-0042',
            'order_date' => '2026-07-08',
            'required_date' => '2026-07-22',
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'customer_reference' => 'RFQ-1001',
            'internal_reference' => 'SALES-2026-01',
            'po_number' => 'PO-0042',
            'attention_to' => 'Dr. Sarah Chen',
            'contact_email' => 'ap@globex.example',
            'contact_phone' => '+1 415 555 0100',
            'notes' => 'Please confirm delivery window.',
            'internal_notes' => 'Approved by sales manager.',
            'billing_address' => $base['invoice']['billing_address'],
            'shipping_address' => $base['invoice']['shipping_address'],
            'warehouse' => 'Main Warehouse — KL',
            'salesperson' => 'John Smith',
            'quotation_number' => 'QT-2026-0001',
            'accepted_at' => '2026-07-07',
            'incoterm_code' => 'FOB',
            'incoterm_place' => 'Port Klang',
        ];
        $base['invoice']['number'] = 'SO-2026-0042';
        $base['invoice']['issue_date'] = '2026-07-08';
        $base['invoice']['due_date'] = '2026-07-22';
        $base['invoice']['status'] = 'confirmed';
        $base['warehouse'] = ['name' => 'Main Warehouse — KL'];
        $base['quotation'] = ['number' => 'QT-2026-0001'];

        foreach ($base['lines'] as $index => $line) {
            $base['lines'][$index]['unit'] = 'pcs';
        }

        return $base;
    }

    protected function formatAddress(mixed $address): string
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
