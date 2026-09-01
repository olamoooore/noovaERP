<?php

namespace App\Exports;

use App\Platform\Export\SalesDocumentExport;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class InvoicesExport extends SalesDocumentExport
{
    protected function relations(): array
    {
        return ['customer', 'buyer', 'lines.product'];
    }

    public const COLUMNS = [
        'invoice_number' => ['heading' => 'Invoice No', 'type' => 'text'],
        'invoice_date' => ['heading' => 'Date', 'type' => 'date'],
        'customer_name' => ['heading' => 'Customer', 'type' => 'text'],
        'customer_code' => ['heading' => 'Customer Code', 'type' => 'text'],
        'subtotal' => ['heading' => 'Subtotal', 'type' => 'amount'],
        'tax_amount' => ['heading' => 'Tax', 'type' => 'amount'],
        'total' => ['heading' => 'Amount', 'type' => 'amount'],
        'paid_amount' => ['heading' => 'Paid Amount', 'type' => 'amount'],
        'balance' => ['heading' => 'Balance', 'type' => 'amount'],
        'status' => ['heading' => 'Status', 'type' => 'text'],
        'due_date' => ['heading' => 'Due Date', 'type' => 'date'],
        'pr_no' => ['heading' => 'PR No', 'type' => 'text'], 'contract_no' => ['heading' => 'Contract No', 'type' => 'text'],
        'buyer_name' => ['heading' => 'Buyer', 'type' => 'text'], 'plant_code' => ['heading' => 'Plant Code', 'type' => 'text'], 'company_code' => ['heading' => 'Company Code', 'type' => 'text'],
        'product_code' => ['heading' => 'Product Code', 'type' => 'text', 'line' => true],
        'product_name' => ['heading' => 'Product', 'type' => 'text', 'line' => true],
        'line_description' => ['heading' => 'Line Description', 'type' => 'text', 'line' => true],
        'line_type' => ['heading' => 'Line Type', 'type' => 'text', 'line' => true],
        'model_number' => ['heading' => 'Model Number', 'type' => 'text', 'line' => true],
        'lot_number' => ['heading' => 'Lot Number', 'type' => 'text', 'line' => true],
        'expiry_date' => ['heading' => 'Expiry Date', 'type' => 'date', 'line' => true],
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

    protected function value($invoice, $line, string $column): mixed
    {
        return match ($column) {
            'customer_name' => $invoice->customer?->name, 'buyer_name' => $invoice->buyer?->full_name ?? $invoice->buyer?->display_name,
            'customer_code' => $invoice->customer?->code,
            'invoice_date', 'due_date' => $invoice->{$column} ? Date::dateTimeToExcel($invoice->{$column}) : null,
            'subtotal', 'tax_amount', 'total', 'paid_amount', 'balance' => (float) $invoice->{$column},
            'product_code' => $line?->product?->sku,
            'product_name' => $line?->product?->name,
            'line_description' => $line?->description,
            'line_type' => $line?->line_type ?? 'product',
            'model_number', 'lot_number' => $line?->{$column},
            'expiry_date' => $line?->expiry_date ? Date::dateTimeToExcel($line->expiry_date) : null,
            'quantity', 'unit_price', 'tax_rate', 'line_total' => $line && ($line->line_type ?? 'product') === 'product' ? (float) $line->{$column} : null,
            'line_discount' => $line && ($line->line_type ?? 'product') === 'product' ? (float) $line->discount_amount : null,
            'line_tax_amount' => $line && ($line->line_type ?? 'product') === 'product' ? (float) $line->tax_amount : null,
            default => $invoice->{$column},
        };
    }
}
