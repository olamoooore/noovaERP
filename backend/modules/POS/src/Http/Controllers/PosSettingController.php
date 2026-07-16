<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\POS\Models\PosSetting;

class PosSettingController extends Controller
{
    /**
     * Default POS settings with their default values.
     */
    private array $defaults = [
        // General
        'default_warehouse_id' => null,
        'order_number_prefix' => 'POS',
        'tax_mode' => 'inclusive',   // inclusive | exclusive | none
        'rounding_method' => 'none',        // none | round_up | round_down | round_nearest
        'rounding_precision' => 2,
        'allow_negative_stock' => false,
        'auto_open_cash_drawer' => true,
        'require_customer' => false,
        'default_customer_id' => null,
        'idle_timeout_minutes' => 0,

        // Receipt
        'receipt_header' => '',
        'receipt_footer' => 'Thank you for your purchase!',
        'receipt_show_logo' => true,
        'receipt_logo_url' => '',
        'receipt_show_tax_breakdown' => true,
        'receipt_show_cashier' => true,
        'receipt_paper_size' => '80mm',        // 58mm | 80mm | a4
        'auto_print_receipt' => false,

        // Barcode
        'barcode_type' => 'code128',     // code128 | code39 | ean13 | ean8 | upc
        'barcode_format' => 'svg',         // svg | png | canvas
        'barcode_show_price' => true,
        'barcode_show_name' => true,
        'barcode_show_sku' => true,
        'barcode_bar_width' => 2,
        'barcode_bar_height' => 50,

        // Display
        'show_product_images' => true,
        'products_per_page' => 20,
        'default_product_view' => 'grid',        // grid | list
        'enable_customer_display' => false,
        'customer_display_message' => 'Welcome!',
    ];

    /**
     * Get all POS settings.
     */
    public function index(): JsonResponse
    {
        $stored = PosSetting::allSettings();

        // Merge defaults with stored values
        $settings = [];
        foreach ($this->defaults as $key => $default) {
            $settings[$key] = array_key_exists($key, $stored) ? $stored[$key] : $default;
        }

        return response()->json(['settings' => $settings]);
    }

    /**
     * Update POS settings (bulk upsert).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            // General
            'default_warehouse_id' => 'nullable|integer',
            'order_number_prefix' => 'nullable|string|max:10',
            'tax_mode' => 'nullable|string|in:inclusive,exclusive,none',
            'rounding_method' => 'nullable|string|in:none,round_up,round_down,round_nearest',
            'rounding_precision' => 'nullable|integer|min:0|max:4',
            'allow_negative_stock' => 'nullable|boolean',
            'auto_open_cash_drawer' => 'nullable|boolean',
            'require_customer' => 'nullable|boolean',
            'default_customer_id' => 'nullable|integer',
            'idle_timeout_minutes' => 'nullable|integer|min:0|max:480',

            // Receipt
            'receipt_header' => 'nullable|string|max:500',
            'receipt_footer' => 'nullable|string|max:500',
            'receipt_show_logo' => 'nullable|boolean',
            'receipt_logo_url' => 'nullable|string|max:500',
            'receipt_show_tax_breakdown' => 'nullable|boolean',
            'receipt_show_cashier' => 'nullable|boolean',
            'receipt_paper_size' => 'nullable|string|in:58mm,80mm,a4',
            'auto_print_receipt' => 'nullable|boolean',

            // Barcode
            'barcode_type' => 'nullable|string|in:code128,code39,ean13,ean8,upc',
            'barcode_format' => 'nullable|string|in:svg,png,canvas',
            'barcode_show_price' => 'nullable|boolean',
            'barcode_show_name' => 'nullable|boolean',
            'barcode_show_sku' => 'nullable|boolean',
            'barcode_bar_width' => 'nullable|integer|min:1|max:5',
            'barcode_bar_height' => 'nullable|integer|min:20|max:100',

            // Display
            'show_product_images' => 'nullable|boolean',
            'products_per_page' => 'nullable|integer|min:10|max:100',
            'default_product_view' => 'nullable|string|in:grid,list',
            'enable_customer_display' => 'nullable|boolean',
            'customer_display_message' => 'nullable|string|max:200',
        ]);

        foreach ($data as $key => $value) {
            if (array_key_exists($key, $this->defaults)) {
                PosSetting::setValue($key, $value);
            }
        }

        // Return updated settings
        return $this->index();
    }

    /**
     * Reset all settings to defaults.
     */
    public function reset(): JsonResponse
    {
        PosSetting::query()->delete();

        return response()->json([
            'message' => 'Settings reset to defaults',
            'settings' => $this->defaults,
        ]);
    }
}
