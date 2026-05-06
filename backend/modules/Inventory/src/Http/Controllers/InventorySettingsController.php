<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Inventory\Models\InventorySettings;

class InventorySettingsController extends Controller
{
    public function show()
    {
        return response()->json(InventorySettings::current());
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'allow_negative_stock' => 'boolean',
            'auto_generate_sku' => 'boolean',
            'sku_prefix' => 'nullable|string|max:10',
            'sku_length' => 'nullable|integer|min:4|max:20',
            'track_batch_numbers' => 'boolean',
            'track_expiry_dates' => 'boolean',
            'require_adjustment_approval' => 'boolean',
            'low_stock_threshold_days' => 'nullable|integer|min:1|max:365',
            'enable_barcode_scanning' => 'boolean',
            'sme_mode' => 'boolean',
        ]);

        $settings = InventorySettings::current();
        $settings->update($validated);

        return response()->json($settings);
    }
}
