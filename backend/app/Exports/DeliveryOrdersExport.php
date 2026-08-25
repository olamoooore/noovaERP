<?php

namespace App\Exports;

use App\Platform\Export\SalesDocumentExport;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class DeliveryOrdersExport extends SalesDocumentExport
{
    public const COLUMNS = [
        'delivery_order_number' => ['heading' => 'Delivery Order No', 'type' => 'text'],
        'delivery_date' => ['heading' => 'Delivery Date', 'type' => 'date'],
        'expected_date' => ['heading' => 'Due Date', 'type' => 'date'],
        'customer_name' => ['heading' => 'Customer', 'type' => 'text'],
        'customer_code' => ['heading' => 'Customer Code', 'type' => 'text'],
        'sales_order_number' => ['heading' => 'Sales Order No', 'type' => 'text'],
        'invoice_number' => ['heading' => 'Invoice No', 'type' => 'text'],
        'quotation_number' => ['heading' => 'Quotation No', 'type' => 'text'],
        'po_number' => ['heading' => 'PO Number', 'type' => 'text'],
        'payment_terms' => ['heading' => 'Payment Terms', 'type' => 'text'],
        'total' => ['heading' => 'Total', 'type' => 'amount'],
        'status' => ['heading' => 'Status', 'type' => 'text'],
        'product_code' => ['heading' => 'Product Code', 'type' => 'text', 'line' => true],
        'product_name' => ['heading' => 'Product', 'type' => 'text', 'line' => true],
        'line_description' => ['heading' => 'Line Description', 'type' => 'text', 'line' => true],
        'model_number' => ['heading' => 'Model Number', 'type' => 'text', 'line' => true],
        'lot_number' => ['heading' => 'Lot Number', 'type' => 'text', 'line' => true],
        'batch_number' => ['heading' => 'Batch Number', 'type' => 'text', 'line' => true],
        'expiry_date' => ['heading' => 'Expiry Date', 'type' => 'date', 'line' => true],
        'quantity_ordered' => ['heading' => 'Quantity Ordered', 'type' => 'number', 'line' => true],
        'quantity_delivered' => ['heading' => 'Quantity Delivered', 'type' => 'number', 'line' => true],
        'unit' => ['heading' => 'Unit', 'type' => 'text', 'line' => true],
        'unit_price' => ['heading' => 'Unit Price', 'type' => 'amount', 'line' => true],
        'line_total' => ['heading' => 'Line Total', 'type' => 'amount', 'line' => true],
    ];

    protected function definitions(): array
    {
        return self::COLUMNS;
    }

    protected function relations(): array
    {
        return ['customer', 'salesOrder', 'invoice', 'quotation', 'lines.product'];
    }

    protected function value($deliveryOrder, $line, string $column): mixed
    {
        return match ($column) {
            'customer_name' => $deliveryOrder->customer?->name,
            'customer_code' => $deliveryOrder->customer?->code,
            'sales_order_number' => $deliveryOrder->salesOrder?->sales_order_number,
            'invoice_number' => $deliveryOrder->invoice?->invoice_number,
            'quotation_number' => $deliveryOrder->quotation?->quotation_number,
            'delivery_date', 'expected_date' => $deliveryOrder->{$column} ? Date::dateTimeToExcel($deliveryOrder->{$column}) : null,
            'total' => (float) $deliveryOrder->total,
            'product_code' => $line?->product?->sku,
            'product_name' => $line?->product?->name,
            'line_description' => $line?->description,
            'model_number', 'lot_number', 'batch_number', 'unit' => $line?->{$column},
            'expiry_date' => $line?->expiry_date ? Date::dateTimeToExcel($line->expiry_date) : null,
            'quantity_ordered', 'quantity_delivered', 'unit_price', 'line_total' => $line ? (float) $line->{$column} : null,
            default => $deliveryOrder->{$column},
        };
    }
}
