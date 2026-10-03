<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $previouslyAllowedNegativeStock = (bool) $settings->allow_negative_stock;

        $settings->update($validated);

        // Issue #686: allow_negative_stock is the tenant-level override of the
        // reservation-aware goods-issue guard. Switching it on is a
        // stock-control decision (route permission: inventory.settings) and is
        // logged so the override has an audit trail.
        if (array_key_exists('allow_negative_stock', $validated)
            && (bool) $validated['allow_negative_stock'] !== $previouslyAllowedNegativeStock
        ) {
            Log::warning('Inventory settings: allow_negative_stock changed', [
                'tenant_id' => function_exists('tenant') && tenant() ? tenant('id') : null,
                'from' => $previouslyAllowedNegativeStock,
                'to' => (bool) $validated['allow_negative_stock'],
                'actor_id' => $request->user()?->id,
            ]);
        }

        return response()->json($settings);
    }
}
