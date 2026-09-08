<?php

namespace App\Exports;

use App\Platform\Export\SalesDocumentExport;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ProductsExport extends SalesDocumentExport
{
    public const COLUMNS = [
        'sku' => ['heading' => 'SKU', 'type' => 'text'],
        'name' => ['heading' => 'Product Name', 'type' => 'text'],
        'secondary_name' => ['heading' => 'Secondary Name', 'type' => 'text'],
        'short_description' => ['heading' => 'Short Description', 'type' => 'text'],
        'description' => ['heading' => 'Full Description', 'type' => 'text'],
        'type' => ['heading' => 'Type', 'type' => 'text'],
        'category_name' => ['heading' => 'Category', 'type' => 'text'],
        'brand_name' => ['heading' => 'Brand', 'type' => 'text'],
        'barcode' => ['heading' => 'Barcode', 'type' => 'text'],
        'manufacturer_part_number' => ['heading' => 'Manufacturer Part No', 'type' => 'text'],
        'manufacturer' => ['heading' => 'Manufacturer', 'type' => 'text'],
        'cost' => ['heading' => 'Cost', 'type' => 'amount'],
        'vendor_price' => ['heading' => 'Vendor Price', 'type' => 'amount'],
        'price' => ['heading' => 'Price', 'type' => 'amount'],
        'wholesale_price' => ['heading' => 'Wholesale Price', 'type' => 'amount'],
        'msrp' => ['heading' => 'MSRP', 'type' => 'amount'],
        'min_price' => ['heading' => 'Minimum Price', 'type' => 'amount'],
        'max_price' => ['heading' => 'Maximum Price', 'type' => 'amount'],
        'tax_rate' => ['heading' => 'Tax Rate (%)', 'type' => 'number'],
        'tax_mode' => ['heading' => 'Tax Mode', 'type' => 'text'],
        'tax_included' => ['heading' => 'Tax Included', 'type' => 'text'],
        'stock_quantity' => ['heading' => 'Stock Quantity', 'type' => 'number'],
        'unit' => ['heading' => 'Unit', 'type' => 'text'],
        'track_inventory' => ['heading' => 'Track Inventory', 'type' => 'text'],
        'tracking_method' => ['heading' => 'Tracking Method', 'type' => 'text'],
        'reorder_level' => ['heading' => 'Reorder Level', 'type' => 'number'],
        'safety_stock' => ['heading' => 'Safety Stock', 'type' => 'number'],
        'max_stock' => ['heading' => 'Maximum Stock', 'type' => 'number'],
        'reorder_quantity' => ['heading' => 'Reorder Quantity', 'type' => 'number'],
        'weight' => ['heading' => 'Weight', 'type' => 'number'],
        'length' => ['heading' => 'Length', 'type' => 'number'],
        'width' => ['heading' => 'Width', 'type' => 'number'],
        'height' => ['heading' => 'Height', 'type' => 'number'],
        'is_hazmat' => ['heading' => 'Hazardous Material', 'type' => 'text'],
        'hazmat_class' => ['heading' => 'Hazmat Class', 'type' => 'text'],
        'storage_conditions' => ['heading' => 'Storage Conditions', 'type' => 'text'],
        'is_fragile' => ['heading' => 'Fragile', 'type' => 'text'],
        'shelf_life_days' => ['heading' => 'Shelf Life (Days)', 'type' => 'number'],
        'lot_number' => ['heading' => 'Lot Number', 'type' => 'text'],
        'expiry_date' => ['heading' => 'Expiry Date', 'type' => 'date'],
        'hs_code' => ['heading' => 'HS Code', 'type' => 'text'],
        'country_of_origin' => ['heading' => 'Country of Origin', 'type' => 'text'],
        'warranty_months' => ['heading' => 'Warranty (Months)', 'type' => 'number'],
        'warranty_type' => ['heading' => 'Warranty Type', 'type' => 'text'],
        'income_account_code' => ['heading' => 'Income Account', 'type' => 'text'],
        'expense_account_code' => ['heading' => 'Expense Account', 'type' => 'text'],
        'status' => ['heading' => 'Status', 'type' => 'text'],
        'is_active' => ['heading' => 'Active', 'type' => 'text'],
        'is_new_arrival' => ['heading' => 'New Arrival', 'type' => 'text'],
        'is_preorder' => ['heading' => 'Preorder', 'type' => 'text'],
        'show_on_website' => ['heading' => 'Show on Website', 'type' => 'text'],
    ];

    protected function relations(): array { return ['category', 'brand', 'incomeAccount', 'expenseAccount', 'stockLevels']; }
    protected function definitions(): array { return self::COLUMNS; }

    protected function value($product, $line, string $column): mixed
    {
        return match ($column) {
            'category_name' => $product->category?->name,
            'brand_name' => $product->brand?->name,
            'income_account_code' => $product->incomeAccount?->code,
            'expense_account_code' => $product->expenseAccount?->code,
            'stock_quantity' => (float) $product->stockLevels->sum('quantity'),
            'cost', 'vendor_price', 'price', 'wholesale_price', 'msrp', 'min_price', 'max_price', 'tax_rate', 'reorder_level', 'safety_stock', 'max_stock', 'reorder_quantity', 'weight', 'length', 'width', 'height' => (float) $product->{$column},
            'expiry_date' => $product->expiry_date ? Date::dateTimeToExcel($product->expiry_date) : null,
            'is_active', 'tax_included', 'track_inventory', 'is_hazmat', 'is_fragile', 'is_new_arrival', 'is_preorder', 'show_on_website' => $product->{$column} ? 'Yes' : 'No',
            default => $product->{$column},
        };
    }
}
