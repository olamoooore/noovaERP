<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use Modules\Inventory\Models\PurchaseOrder;
use Modules\TemplateBuilder\Contracts\DataSourceContract;

final class PurchaseOrderDataSource implements DataSourceContract
{
    private ?PurchaseOrder $po = null;

    public static function documentType(): string
    {
        return 'purchase_order';
    }

    public function permission(): string
    {
        return 'inventory.view';
    }

    public function legalEntityId(): ?int
    {
        $po = $this->po ?? throw new \RuntimeException('Purchase order not loaded.');

        // purchase_orders (and its supplier/warehouse relations) carry no
        // legal_entity_id on this base — see issue #611. Entity scoping is
        // therefore not enforceable for this source yet.
        return $po->legal_entity_id !== null ? (int) $po->legal_entity_id : null;
    }

    public function resolutionContext(string $documentId): array
    {
        $po = $this->load($documentId)->po;

        return DocumentResolutionContext::build([
            'entity_id' => $this->legalEntityId(),
            'branch_id' => $po->getAttribute('branch_id'),
            'currency' => $po->currency,
            'channel' => $po->getAttribute('channel'),
        ]);
    }

    public function load(string $documentId): self
    {
        $this->po = PurchaseOrder::with(['supplier', 'warehouse', 'items.product'])->findOrFail($documentId);

        return $this;
    }

    public function toArray(): array
    {
        $po = $this->po ?? throw new \RuntimeException('Purchase order not loaded.');
        $company = CompanyDataSource::forDocument($po);
        $currencyCode = $po->currency ?: ($company['currency_code'] ?? 'MYR');

        $lines = [];
        foreach ($po->items as $i => $item) {
            $isProduct = ($item->line_type ?? 'product') === 'product';
            $lines[] = [
                'line_type' => $item->line_type ?? 'product',
                'index' => $i + 1,
                'sku' => $item->product?->sku ?? '',
                'description' => $item->description ?? '',
                'quantity' => $isProduct ? (float) $item->quantity_ordered : null,
                'received' => $isProduct ? (float) $item->quantity_received : null,
                'unit_price' => $isProduct ? (float) $item->unit_cost : null,
                'tax_rate' => $isProduct ? (float) $item->tax_rate : null,
                'line_total' => $isProduct ? (float) $item->line_total : null,
            ];
        }

        return [
            'po' => [
                'number' => $po->po_number,
                'order_date' => optional($po->order_date)->format('Y-m-d'),
                'expected_date' => optional($po->expected_date)->format('Y-m-d'),
                'status' => $po->status,
                'notes' => $po->notes ?? '',
                'internal_notes' => $po->internal_notes ?? '',
            ],
            'vendor' => [
                'name' => $po->supplier?->name ?? '',
                'email' => $po->supplier?->email ?? '',
                'phone' => $po->supplier?->phone ?? '',
                'address' => $this->formatAddress($po->supplier?->address ?? $po->supplier?->address_line_1 ?? ''),
            ],
            'company' => $company,
            'warehouse' => ['name' => $po->warehouse?->name ?? ''],
            'lines' => $lines,
            'totals' => [
                'subtotal' => (float) $po->subtotal,
                'tax' => (float) $po->tax_amount,
                'discount' => (float) ($po->discount_amount ?? 0),
                'shipping' => (float) ($po->shipping_cost ?? 0),
                'total' => (float) $po->total,
                'balance' => (float) $po->total,
            ],
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
            ['group' => 'Purchase Order', 'fields' => [
                ['token' => 'po.number', 'label' => 'PO Number'],
                ['token' => 'po.order_date', 'label' => 'Order Date'],
                ['token' => 'po.expected_date', 'label' => 'Expected Date'],
                ['token' => 'po.status', 'label' => 'Status'],
                ['token' => 'po.notes', 'label' => 'Notes'],
            ]],
            ['group' => 'Vendor', 'fields' => [
                ['token' => 'vendor.name', 'label' => 'Name'],
                ['token' => 'vendor.email', 'label' => 'Email'],
                ['token' => 'vendor.phone', 'label' => 'Phone'],
                ['token' => 'vendor.address', 'label' => 'Address'],
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
                ['token' => 'totals.shipping', 'label' => 'Shipping'],
                ['token' => 'totals.total', 'label' => 'Grand Total'],
            ]],
        ];
    }

    public function sample(): array
    {
        return [
            'po' => [
                'number' => 'PO-20260708-0001',
                'order_date' => '2026-07-08',
                'expected_date' => '2026-07-18',
                'status' => 'approved',
                'notes' => 'Please deliver between 9am–5pm.',
                'internal_notes' => 'Authorised by procurement lead.',
            ],
            'vendor' => [
                'name' => 'Initech Supplies',
                'email' => 'sales@initech.example',
                'phone' => '+60 3-9876 5432',
                'address' => "Initech Supplies\nBlock C, PJ Industrial Estate\nPetaling Jaya, Selangor, 47810",
            ],
            'company' => CompanyDataSource::sample(),
            'warehouse' => ['name' => 'Main Warehouse — KL'],
            'lines' => [
                ['index' => 1, 'sku' => 'RAW-001', 'description' => 'Aluminium sheet 2mm', 'quantity' => 50, 'received' => 0, 'unit_price' => 120.00, 'tax_rate' => 6.0, 'line_total' => 6360.00],
                ['index' => 2, 'sku' => 'RAW-014', 'description' => 'Stainless bolt M8',    'quantity' => 500, 'received' => 0, 'unit_price' => 1.20, 'tax_rate' => 6.0, 'line_total' => 636.00],
            ],
            'totals' => [
                'subtotal' => 6600.00, 'tax' => 396.00, 'discount' => 0.00, 'shipping' => 150.00,
                'total' => 7146.00, 'balance' => 7146.00,
            ],
            'meta' => ['currency_code' => 'SGD', 'currency_symbol' => 'S$', 'number_format' => '1,234.56', 'locale' => 'en'],
        ];
    }

    private function formatAddress(mixed $address): string
    {
        if (empty($address)) {
            return '';
        }

        return trim(is_string($address) ? $address : (string) $address);
    }
}
