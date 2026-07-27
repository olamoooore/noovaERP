<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use Modules\Sales\Models\DeliveryOrder;
use Modules\TemplateBuilder\Contracts\DataSourceContract;

final class DeliveryOrderDataSource implements DataSourceContract
{
    private ?DeliveryOrder $do = null;

    public static function documentType(): string
    {
        return 'delivery_order';
    }

    public function load(string $documentId): self
    {
        $this->do = DeliveryOrder::with(['customer', 'warehouse', 'lines.product', 'salesOrder', 'invoice', 'quotation'])->findOrFail($documentId);

        return $this;
    }

    public function toArray(): array
    {
        $do = $this->do ?? throw new \RuntimeException('Delivery order not loaded.');
        $company = CompanyDataSource::forCurrentTenant();
        $currencyCode = $company['currency_code'] ?? 'MYR';

        $lines = [];
        foreach ($do->lines as $i => $line) {
            $lines[] = [
                'index' => $i + 1,
                'sku' => $line->product?->sku ?? '',
                'description' => $line->description ?? '',
                'quantity_ordered' => (float) $line->quantity_ordered,
                'quantity_delivered' => (float) $line->quantity_delivered,
                'remaining' => (float) $line->remaining_quantity,
                'unit' => $line->unit ?? 'pcs',
                'unit_price' => (float) ($line->unit_price ?? 0),
                'line_total' => (float) ($line->line_total ?? 0),
                'lot_number' => $line->lot_number ?? '',
                'batch_number' => $line->batch_number ?? '',
            ];
        }

        return [
            'delivery_order' => [
                'number' => $do->delivery_order_number,
                'status' => $do->status,
                'delivery_date' => optional($do->delivery_date)->format('Y-m-d'),
                'expected_date' => optional($do->expected_date)->format('Y-m-d'),
                'source' => $do->source,
                'external_reference' => $do->external_reference ?? '',
                'po_number' => $do->po_number ?? '',
                'salesperson' => $do->salesperson ?? '',
                'attention_to' => $do->attention_to ?? '',
                'carrier' => $do->carrier ?? '',
                'tracking_number' => $do->tracking_number ?? '',
                'shipping_address' => $this->formatAddress($do->shipping_address),
                'notes' => $do->notes ?? '',
                'total' => (float) ($do->total ?? 0),
            ],
            'customer' => [
                'code' => $do->customer?->code ?? '',
                'name' => $do->customer?->name ?? '',
                'email' => $do->customer?->email ?? '',
                'phone' => $do->customer?->phone ?? '',
            ],
            'sales_order' => [
                'number' => $do->salesOrder?->order_number ?? '',
            ],
            'invoice' => [
                'number' => $do->invoice?->invoice_number ?? '',
            ],
            'quotation' => [
                'number' => $do->quotation?->quotation_number ?? '',
            ],
            'company' => $company,
            'warehouse' => ['name' => $do->warehouse?->name ?? ''],
            'lines' => $lines,
            'meta' => [
                'currency_code' => $currencyCode,
                'currency_symbol' => InvoiceDataSource::currencySymbol($currencyCode),
                'number_format' => '1,234.56',
                'locale' => 'en',
            ],
        ];
    }

    public function fieldCatalog(): array
    {
        return [
            ['group' => 'Delivery Order', 'fields' => [
                ['token' => 'delivery_order.number', 'label' => 'DO Number'],
                ['token' => 'delivery_order.status', 'label' => 'Status'],
                ['token' => 'delivery_order.delivery_date', 'label' => 'Delivery Date'],
                ['token' => 'delivery_order.expected_date', 'label' => 'Expected Date'],
                ['token' => 'delivery_order.po_number', 'label' => 'PO Number'],
                ['token' => 'delivery_order.salesperson', 'label' => 'Salesperson'],
                ['token' => 'delivery_order.attention_to', 'label' => 'Attention To'],
                ['token' => 'delivery_order.carrier', 'label' => 'Carrier'],
                ['token' => 'delivery_order.tracking_number', 'label' => 'Tracking Number'],
                ['token' => 'delivery_order.shipping_address', 'label' => 'Shipping Address'],
                ['token' => 'delivery_order.total', 'label' => 'Total'],
                ['token' => 'delivery_order.notes', 'label' => 'Notes'],
            ]],
            ['group' => 'Invoice', 'fields' => [
                ['token' => 'invoice.number', 'label' => 'Invoice Number'],
            ]],
            ['group' => 'Quotation', 'fields' => [
                ['token' => 'quotation.number', 'label' => 'Quotation Number'],
            ]],
            ['group' => 'Customer', 'fields' => [
                ['token' => 'customer.code', 'label' => 'Customer Code'],
                ['token' => 'customer.name', 'label' => 'Name'],
                ['token' => 'customer.email', 'label' => 'Email'],
                ['token' => 'customer.phone', 'label' => 'Phone'],
            ]],
            ['group' => 'Company', 'fields' => [
                ['token' => 'company.name', 'label' => 'Company Name'],
                ['token' => 'company.address', 'label' => 'Address'],
                ['token' => 'company.email', 'label' => 'Email'],
                ['token' => 'company.phone', 'label' => 'Phone'],
            ]],
            ['group' => 'Warehouse', 'fields' => [
                ['token' => 'warehouse.name', 'label' => 'Warehouse'],
            ]],
            ['group' => 'Line Items', 'fields' => [
                ['token' => 'lines', 'label' => 'Line items table (binds a Table block)'],
            ]],
        ];
    }

    public function sample(): array
    {
        return [
            'delivery_order' => [
                'number' => 'DO-2026-0001',
                'status' => 'confirmed',
                'delivery_date' => '2026-07-22',
                'expected_date' => '2026-07-25',
                'source' => 'sales_order',
                'external_reference' => 'SO-2026-0042',
                'po_number' => 'PO-2026-0042',
                'salesperson' => 'John Smith',
                'attention_to' => 'Warehouse Manager',
                'carrier' => 'DHL Express',
                'tracking_number' => '1234567890',
                'shipping_address' => "Globex Corporation\nWarehouse 7\n1 Infinite Loop\nSan Francisco, CA 94102",
                'notes' => 'Please deliver between 9am–5pm. Signature required.',
                'total' => 5740.00,
            ],
            'customer' => [
                'code' => 'CUST-0001',
                'name' => 'Globex Corporation',
                'email' => 'logistics@globex.example',
                'phone' => '+1 415 555 0100',
            ],
            'sales_order' => [
                'number' => 'SO-2026-0042',
            ],
            'invoice' => [
                'number' => 'INV-2026-0001',
            ],
            'quotation' => [
                'number' => 'QT-2026-0001',
            ],
            'company' => CompanyDataSource::sample(),
            'warehouse' => ['name' => 'Main Warehouse — KL'],
            'lines' => [
                ['index' => 1, 'sku' => 'SKU-1001', 'description' => 'Enterprise licence — 50 seats', 'quantity_ordered' => 10, 'quantity_delivered' => 10, 'remaining' => 0, 'unit' => 'box', 'unit_price' => 450.00, 'line_total' => 4500.00, 'lot_number' => '', 'batch_number' => ''],
                ['index' => 2, 'sku' => 'SKU-2099', 'description' => 'On-site training materials',     'quantity_ordered' => 25, 'quantity_delivered' => 25, 'remaining' => 0, 'unit' => 'pcs', 'unit_price' => 49.60, 'line_total' => 1240.00, 'lot_number' => 'LOT-001', 'batch_number' => 'B-2026-07'],
            ],
            'meta' => ['currency_code' => 'MYR', 'currency_symbol' => 'RM ', 'number_format' => '1,234.56', 'locale' => 'en'],
        ];
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
