<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

DB::statement("SET search_path TO 'e255e52c-a4a6-4158-954a-af04408e9386', public");

$product = App\Models\Product::find(10648);
echo "Product: " . $product->name . "\n";
echo "Cost: " . var_export($product->cost, true) . "\n";
echo "Price: " . var_export($product->price, true) . "\n";
echo "Category: " . $product->category_id . "\n";

// Check category exists
if ($product->category_id) {
    $cat = DB::table('product_categories')->where('id', $product->category_id)->first();
    echo "Category exists: " . ($cat ? "YES - {$cat->name}" : "NO") . "\n";
}

// Simulate what the frontend sends
$payload = [
    'name' => $product->name,
    'secondary_name' => $product->secondary_name,
    'sku' => $product->sku,
    'barcode' => $product->barcode,
    'type' => $product->type ?? 'product',
    'category_id' => $product->category_id ? (int)$product->category_id : null,
    'cost' => $product->cost !== null ? (float)$product->cost : null,
    'price' => $product->price !== null ? (float)$product->price : null,
    'wholesale_price' => $product->wholesale_price !== null ? (float)$product->wholesale_price : null,
    'msrp' => $product->msrp !== null ? (float)$product->msrp : null,
    'track_inventory' => (bool)$product->track_inventory,
    'is_preorder' => (bool)$product->is_preorder,
    'is_new_arrival' => (bool)$product->is_new_arrival,
    'show_on_website' => (bool)$product->show_on_website,
    'stock_quantity' => $product->track_inventory ? (int)$product->stock_quantity : null,
    'description' => 'Testing a new description',
    'short_description' => 'Testing short description',
    'image' => $product->image,
    'gallery' => $product->gallery ?? [],
    'unit' => $product->unit,
    'tax_mode' => $product->tax_mode ?? 'default',
    'tax_included' => (bool)$product->tax_included,
    'tax_rate_override' => null,
    'tax_id' => null,
    'is_active' => true,
    'status' => $product->status ?? 'active',
    'tracking_method' => $product->tracking_method ?? 'quantity',
    'reorder_quantity' => null,
    'weight' => null,
    'length' => null,
    'width' => null,
    'height' => null,
    'suppliers' => [],
    'feature_values' => [],
    'custom_field_values' => [],
    'attributes' => [],
];

echo "\nPayload:\n";
echo json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

$rules = [
    'name' => 'string|max:255',
    'secondary_name' => 'nullable|string|max:255',
    'sku' => 'string|unique:products,sku,' . $product->id,
    'barcode' => 'nullable|string',
    'description' => 'nullable|string',
    'short_description' => 'nullable|string',
    'category_id' => 'nullable|exists:product_categories,id',
    'type' => 'in:product,service',
    'cost' => 'nullable|numeric|min:0',
    'price' => 'nullable|numeric|min:0',
    'wholesale_price' => 'nullable|numeric|min:0',
    'msrp' => 'nullable|numeric|min:0',
    'tax_rate' => 'nullable|numeric|min:0|max:100',
    'tax_mode' => 'nullable|in:default,none,custom,tax',
    'tax_included' => 'nullable|boolean',
    'tax_rate_override' => 'nullable|numeric|min:0|max:100',
    'tax_id' => 'nullable|exists:taxes,id',
    'income_account_id' => 'nullable|exists:chart_of_accounts,id',
    'expense_account_id' => 'nullable|exists:chart_of_accounts,id',
    'track_inventory' => 'boolean',
    'stock_quantity' => 'nullable|integer|min:0',
    'is_preorder' => 'boolean',
    'preorder_available_at' => 'nullable|date',
    'reorder_level' => 'nullable|integer|min:0',
    'reorder_quantity' => 'nullable|integer|min:0',
    'unit' => 'nullable|string',
    'status' => 'nullable|string|in:draft,pending_approval,active,discontinued,archived',
    'tracking_method' => 'nullable|string|in:none,quantity,batch,serial',
    'show_on_website' => 'boolean',
    'weight' => 'nullable|numeric|min:0',
    'length' => 'nullable|numeric|min:0',
    'width' => 'nullable|numeric|min:0',
    'height' => 'nullable|numeric|min:0',
    'image' => 'nullable|string',
    'gallery' => 'nullable|array',
    'gallery.*' => 'nullable|string',
    'attributes' => 'nullable|array',
    'attributes.*.attribute_id' => 'nullable|exists:product_attributes,id',
    'attributes.*.attribute_name' => 'nullable|string|max:255',
    'attributes.*.values' => 'required|array|min:1',
    'attributes.*.values.*' => 'required|string|max:255',
    'feature_values' => 'nullable|array',
    'feature_values.*.product_feature_id' => 'required|exists:product_features,id',
    'feature_values.*.value' => 'nullable|string',
    'custom_field_values' => 'nullable|array',
    'custom_field_values.*.product_custom_field_id' => 'required|exists:product_custom_fields,id',
    'custom_field_values.*.value' => 'nullable|string',
    'suppliers' => 'nullable|array',
    'is_active' => 'boolean',
    'is_new_arrival' => 'boolean',
];

$validator = Validator::make($payload, $rules);

if ($validator->fails()) {
    echo "VALIDATION FAILED:\n";
    echo json_encode($validator->errors()->toArray(), JSON_PRETTY_PRINT) . "\n";
} else {
    echo "VALIDATION PASSED\n";
    echo "Validated data keys: " . implode(', ', array_keys($validator->validated())) . "\n";
}
