<?php

namespace App\Exports;

use App\Platform\Export\SalesDocumentExport;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class QuotationsExport extends SalesDocumentExport
{
    public const COLUMNS = [
        'quotation_number' => ['heading' => 'Quotation No', 'type' => 'text'],
        'quotation_date' => ['heading' => 'Date', 'type' => 'date'],
        'valid_until' => ['heading' => 'Valid Until', 'type' => 'date'],
        'customer_name' => ['heading' => 'Customer', 'type' => 'text'],
        'customer_code' => ['heading' => 'Customer Code', 'type' => 'text'],
        'customer_reference' => ['heading' => 'Customer Reference', 'type' => 'text'],
        'subtotal' => ['heading' => 'Subtotal', 'type' => 'amount'],
        'tax_amount' => ['heading' => 'Tax', 'type' => 'amount'],
        'discount_amount' => ['heading' => 'Discount', 'type' => 'amount'],
        'total' => ['heading' => 'Total', 'type' => 'amount'],
        'status' => ['heading' => 'Status', 'type' => 'text'],
        'product_code' => ['heading' => 'Product Code', 'type' => 'text', 'line' => true],
        'product_name' => ['heading' => 'Product', 'type' => 'text', 'line' => true],
        'line_description' => ['heading' => 'Line Description', 'type' => 'text', 'line' => true],
        'line_type' => ['heading' => 'Line Type', 'type' => 'text', 'line' => true],
        'model_number' => ['heading' => 'Model Number', 'type' => 'text', 'line' => true],
        'quantity' => ['heading' => 'Quantity', 'type' => 'number', 'line' => true],
        'unit_price' => ['heading' => 'Unit Price', 'type' => 'amount', 'line' => true],
        'line_discount' => ['heading' => 'Line Discount', 'type' => 'amount', 'line' => true],
        'tax_rate' => ['heading' => 'Tax Rate (%)', 'type' => 'number', 'line' => true],
        'line_tax_amount' => ['heading' => 'Line Tax', 'type' => 'amount', 'line' => true],
        'line_total' => ['heading' => 'Line Total', 'type' => 'amount', 'line' => true],
    ];

    protected function definitions(): array
    {
        return self::COLUMNS;
    }

    protected function value($quotation, $line, string $column): mixed
    {
        return match ($column) {
            'customer_name' => $quotation->customer?->name,
            'customer_code' => $quotation->customer?->code,
            'quotation_date', 'valid_until' => $quotation->{$column} ? Date::dateTimeToExcel($quotation->{$column}) : null,
            'subtotal', 'tax_amount', 'discount_amount', 'total' => (float) $quotation->{$column},
            'product_code' => $line?->product?->sku,
            'product_name' => $line?->product?->name,
            'line_description' => $line?->description,
            'line_type' => $line?->line_type ?? 'product',
            'model_number' => $line?->model_number,
            'quantity', 'unit_price', 'tax_rate', 'line_total' => $line && ($line->line_type ?? 'product') === 'product' ? (float) $line->{$column} : null,
            'line_discount' => $line ? (float) $line->discount_amount : null,
            'line_tax_amount' => $line ? (float) $line->tax_amount : null,
            default => $quotation->{$column},
        };
    }
}
