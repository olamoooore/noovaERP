<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCustomFieldValue;
use App\Models\ProductFeatureValue;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        // Only eager load relationships that exist
        $query = Product::with(['incomeAccount', 'expenseAccount', 'category', 'tax'])
            ->orderBy('name');
        
        // Try to load brand relationship if the table exists
        try {
            if (\Schema::hasTable('brands')) {
                $query->with('brand');
            }
        } catch (\Exception $e) {
            // Ignore if brands table doesn't exist
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('secondary_name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', (int) $request->input('brand_id'));
        }

        if ($request->filled('type')) {
            $type = (string) $request->input('type');
            if (in_array($type, ['product', 'service'], true)) {
                $query->where('type', $type);
            }
        }

        if ($request->filled('is_active')) {
            $isActive = $request->input('is_active');
            $bool = filter_var($isActive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool !== null) {
                $query->where('is_active', $bool);
            }
        }

        if ($request->filled('is_new_arrival')) {
            $query->where('is_new_arrival', true);
        }
        if ($request->filled('is_preorder')) {
            $query->where('is_preorder', true);
        }
        if ($request->filled('show_on_website')) {
            $query->where('show_on_website', true);
        }
        if ($request->filled('out_of_stock')) {
            $query->where('stock_quantity', '<=', 0);
        }
        if ($request->filled('low_stock')) {
            $query->whereColumn('stock_quantity', '<=', 'min_stock_threshold')
                  ->where('stock_quantity', '>', 0);
        }

        $allowedSorts = ['name', 'price', 'cost', 'stock_quantity', 'created_at', 'updated_at'];
        $sortBy = (string) $request->input('sort_by', 'name');
        $sortDir = strtolower((string) $request->input('sort_dir', 'asc'));
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'name';
        }
        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'asc';
        }

        $query->reorder($sortBy, $sortDir);

        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min(200, $perPage));

        $products = $query->paginate($perPage);

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'secondary_name' => 'nullable|string|max:255',
            'sku' => 'required|string|unique:products,sku',
            'barcode' => 'nullable|string|unique:products,barcode',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'type' => 'required|in:product,service',
            'cost' => 'required|numeric|min:0',
            'price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_mode' => 'nullable|in:default,none,custom,tax',
            'tax_included' => 'nullable|boolean',
            'tax_rate_override' => 'nullable|numeric|min:0|max:100',
            'tax_id' => 'nullable|exists:taxes,id',
            'income_account_id' => 'nullable|exists:chart_of_accounts,id',
            'expense_account_id' => 'nullable|exists:chart_of_accounts,id',
            'track_inventory' => 'boolean',
            'is_preorder' => 'boolean',
            'preorder_available_at' => 'nullable|date',
            'stock_quantity' => 'nullable|integer|min:0',
            'reorder_level' => 'nullable|integer|min:0',
            'unit' => 'nullable|string',
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
            'is_active' => 'boolean',
            'is_new_arrival' => 'boolean',
            'show_on_website' => 'boolean',
            'variants' => 'nullable|array',
            'variants.*.name' => 'required|string',
            'variants.*.sku' => 'required|string',
            'variants.*.price' => 'required|numeric',
            'variants.*.stock_quantity' => 'nullable|integer',
            'variants.*.attributes' => 'nullable|array',
        ]);

        $featureValues = $validated['feature_values'] ?? null;
        $customFieldValues = $validated['custom_field_values'] ?? null;
        $variants = $validated['variants'] ?? null;

        unset($validated['feature_values'], $validated['custom_field_values'], $validated['variants']);

        $product = DB::transaction(function () use ($validated, $featureValues, $customFieldValues, $variants) {
            $product = Product::create($validated);

            if (is_array($featureValues)) {
                foreach ($featureValues as $row) {
                    $value = isset($row['value']) ? (string) $row['value'] : '';
                    ProductFeatureValue::updateOrCreate(
                        ['product_id' => $product->id, 'product_feature_id' => (int) $row['product_feature_id']],
                        ['value' => $value]
                    );
                }
            }

            if (is_array($customFieldValues)) {
                foreach ($customFieldValues as $row) {
                    $value = isset($row['value']) ? (string) $row['value'] : '';
                    ProductCustomFieldValue::updateOrCreate(
                        ['product_id' => $product->id, 'product_custom_field_id' => (int) $row['product_custom_field_id']],
                        ['value' => $value]
                    );
                }
            }

            if (is_array($variants)) {
                foreach ($variants as $variantData) {
                    $product->variants()->create($variantData);
                }
            }

            return $product;
        });

        return response()->json($product->load(['incomeAccount', 'expenseAccount', 'category', 'tax']), 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->load([
            'incomeAccount',
            'expenseAccount',
            'category',
            'brand',
            'tax',
            'variants',
            'stockMovements' => fn($q) => $q->latest()->limit(20),
            'stockLevels.warehouse',
            'featureValues',
            'customFieldValues',
        ]));
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'secondary_name' => 'nullable|string|max:255',
            'sku' => 'string|unique:products,sku,' . $product->id,
            'barcode' => 'nullable|string|unique:products,barcode,' . $product->id,
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'type' => 'in:product,service',
            'cost' => 'numeric|min:0',
            'price' => 'numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_mode' => 'nullable|in:default,none,custom,tax',
            'tax_included' => 'nullable|boolean',
            'tax_rate_override' => 'nullable|numeric|min:0|max:100',
            'tax_id' => 'nullable|exists:taxes,id',
            'income_account_id' => 'nullable|exists:chart_of_accounts,id',
            'expense_account_id' => 'nullable|exists:chart_of_accounts,id',
            'track_inventory' => 'boolean',
            'is_preorder' => 'boolean',
            'preorder_available_at' => 'nullable|date',
            'reorder_level' => 'nullable|integer|min:0',
            'unit' => 'nullable|string',
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
            'is_active' => 'boolean',
            'is_new_arrival' => 'boolean',
            'show_on_website' => 'boolean',
        ]);

        $featureValues = $validated['feature_values'] ?? null;
        $customFieldValues = $validated['custom_field_values'] ?? null;
        unset($validated['feature_values'], $validated['custom_field_values']);

        DB::transaction(function () use ($product, $validated, $featureValues, $customFieldValues) {
            $product->update($validated);

            if (is_array($featureValues)) {
                foreach ($featureValues as $row) {
                    $value = isset($row['value']) ? (string) $row['value'] : '';
                    ProductFeatureValue::updateOrCreate(
                        ['product_id' => $product->id, 'product_feature_id' => (int) $row['product_feature_id']],
                        ['value' => $value]
                    );
                }
            }

            if (is_array($customFieldValues)) {
                foreach ($customFieldValues as $row) {
                    $value = isset($row['value']) ? (string) $row['value'] : '';
                    ProductCustomFieldValue::updateOrCreate(
                        ['product_id' => $product->id, 'product_custom_field_id' => (int) $row['product_custom_field_id']],
                        ['value' => $value]
                    );
                }
            }
        });

        return response()->json($product->fresh(['incomeAccount', 'expenseAccount', 'category', 'tax']));
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json(['message' => 'Product deleted successfully']);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['message' => 'Unable to read file'], 400);
        }

        $header = fgetcsv($handle);
        if (!is_array($header) || count($header) === 0) {
            fclose($handle);
            return response()->json(['message' => 'CSV header missing'], 422);
        }

        $header = array_map(fn ($h) => Str::snake(trim((string) $h)), $header);
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (!is_array($data) || count(array_filter($data, fn ($v) => (string) $v !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $idx => $key) {
                $row[$key] = $data[$idx] ?? null;
            }
            $rows[] = $row;
        }
        fclose($handle);

        if (count($rows) === 0) {
            return response()->json(['message' => 'No rows found'], 422);
        }

        $created = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $name = trim((string) ($row['name'] ?? ''));
                $price = $row['price'] ?? null;
                $cost = $row['cost'] ?? null;

                if ($name === '' || $price === null || $cost === null) {
                    $errors[] = [
                        'row' => $index + 2,
                        'message' => 'Missing required fields: name, cost, price',
                    ];
                    continue;
                }

                $sku = trim((string) ($row['sku'] ?? ''));
                if ($sku === '') {
                    $base = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]+/', '', $name), 0, 8));
                    $base = $base !== '' ? $base : 'SKU';
                    $sku = $base . '-' . Str::upper(Str::random(6));
                }

                try {
                    Product::create([
                        'name' => $name,
                        'secondary_name' => $row['secondary_name'] ? trim((string) $row['secondary_name']) : null,
                        'sku' => $sku,
                        'barcode' => $row['barcode'] ? trim((string) $row['barcode']) : null,
                        'description' => $row['description'] ? trim((string) $row['description']) : null,
                        'category_id' => $row['category_id'] ? (int) $row['category_id'] : null,
                        'type' => in_array(($row['type'] ?? ''), ['product', 'service'], true) ? $row['type'] : 'product',
                        'cost' => (float) $cost,
                        'price' => (float) $price,
                        'track_inventory' => isset($row['track_inventory']) ? filter_var($row['track_inventory'], FILTER_VALIDATE_BOOLEAN) : true,
                        'stock_quantity' => isset($row['stock_quantity']) && $row['stock_quantity'] !== '' ? (int) $row['stock_quantity'] : null,
                        'is_active' => isset($row['is_active']) ? filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN) : true,
                    ]);
                    $created++;
                } catch (\Throwable $e) {
                    $errors[] = [
                        'row' => $index + 2,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Import failed', 'error' => $e->getMessage()], 500);
        }

        return response()->json([
            'created' => $created,
            'errors' => $errors,
        ]);
    }
}
