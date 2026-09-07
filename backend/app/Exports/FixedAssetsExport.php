<?php

namespace App\Exports;

use App\Platform\Export\SalesDocumentExport;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class FixedAssetsExport extends SalesDocumentExport
{
    public const COLUMNS = [
        'asset_number' => ['heading' => 'Asset No', 'type' => 'text'],
        'name' => ['heading' => 'Asset Name', 'type' => 'text'],
        'category' => ['heading' => 'Category', 'type' => 'text'],
        'purchase_date' => ['heading' => 'Purchase Date', 'type' => 'date'],
        'purchase_cost' => ['heading' => 'Purchase Cost', 'type' => 'amount'],
        'salvage_value' => ['heading' => 'Salvage Value', 'type' => 'amount'],
        'accumulated_depreciation' => ['heading' => 'Accumulated Depreciation', 'type' => 'amount'],
        'book_value' => ['heading' => 'Book Value', 'type' => 'amount'],
        'depreciation_method' => ['heading' => 'Depreciation Method', 'type' => 'text'],
        'depreciation_frequency' => ['heading' => 'Frequency', 'type' => 'text'],
        'useful_life_years' => ['heading' => 'Useful Life (Years)', 'type' => 'number'],
        'depreciation_start_date' => ['heading' => 'Depreciation Start', 'type' => 'date'],
        'status' => ['heading' => 'Status', 'type' => 'text'],
        'location' => ['heading' => 'Location', 'type' => 'text'],
        'serial_number' => ['heading' => 'Serial Number', 'type' => 'text'],
        'asset_account_code' => ['heading' => 'Asset Account', 'type' => 'text'],
        'depreciation_account_code' => ['heading' => 'Depreciation Account', 'type' => 'text'],
    ];

    protected function relations(): array { return ['assetAccount', 'depreciationAccount']; }
    protected function definitions(): array { return self::COLUMNS; }

    protected function value($asset, $line, string $column): mixed
    {
        return match ($column) {
            'purchase_date', 'depreciation_start_date' => $asset->{$column} ? Date::dateTimeToExcel($asset->{$column}) : null,
            'purchase_cost', 'salvage_value', 'accumulated_depreciation', 'book_value' => (float) $asset->{$column},
            'asset_account_code' => $asset->assetAccount?->code,
            'depreciation_account_code' => $asset->depreciationAccount?->code,
            default => $asset->{$column},
        };
    }
}
