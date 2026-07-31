<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductAuditLog;
use App\Models\ProductCustomFieldValue;
use App\Models\ProductFeatureValue;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Services\InventoryService;

class ProductController extends Controller
{
    /**
     * Treat an empty/whitespace-only barcode as "no barcode" (null) so the
     * nullable uniqueness validation rule is skipped. This allows many products
     * to coexist without a barcode while still rejecting real duplicates.
     */
    private function normalizeBarcodeInput(Request $request): void
    {
        if (! $request->exists('barcode')) {
            return;
        }

        $barcode = $request->input('barcode');
        if ($barcode === null || trim((string) $barcode) === '') {
            $request->merge(['barcode' => null]);
        }
    }

    private function removeUnsupportedProductColumns(array $payload): array
    {
        if (! Schema::hasColumn('products', 'is_merchandise')) {
            unset($payload['is_merchandise']);
        }

        if (! Schema::hasColumn('products', 'show_on_website')) {
            unset($payload['show_on_website']);
        }

        return $payload;
    }

    /**
     * Coerce null values for NOT NULL numeric columns to 0.
     *
     * Several products columns (cost, price, tax_rate, reorder_level,
     * stock_quantity, min_stock_threshold) are defined NOT NULL with a default
     * of 0. A default only applies on INSERT when the column is omitted — an
     * explicit UPDATE setting the column to NULL violates the constraint and
     * throws "Not null violation". The edit form sends null for these fields
     * when the user leaves them blank, so we normalize null → 0 here.
     */
    private function coerceNotNullNumericDefaults(array $payload): array
    {
        $notNullNumeric = ['cost', 'price', 'tax_rate', 'reorder_level', 'stock_quantity'];

        foreach ($notNullNumeric as $column) {
            if (array_key_exists($column, $payload) && ($payload[$column] === null || (string) $payload[$column] === '')) {
                $payload[$column] = 0;
            }
        }

        return $payload;
    }

    public function index(Request $request): JsonResponse
    {
        $warehouseFilter = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;

        $query = Product::with(['incomeAccount', 'expenseAccount', 'category', 'tax', 'brand'])
            ->withSum('stockLevels as aggregated_stock_quantity', 'quantity')
            ->orderBy('created_at', 'desc');

        // When a specific warehouse is requested (e.g. from POS), append the per-warehouse
        // stock as a virtual `stock` column so the UI shows what is actually available at
        // that location, not the aggregate across all warehouses.
        if ($warehouseFilter) {
            $query->addSelect([
                '*',
                \Illuminate\Support\Facades\DB::raw(
                    '(SELECT COALESCE(sl.quantity, 0) FROM stock_levels sl '.
                    "WHERE sl.product_id = products.id AND sl.warehouse_id = {$warehouseFilter} LIMIT 1) AS stock"
                ),
            ]);
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search) {
                // Use ILIKE for case-insensitive search in PostgreSQL
                $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%'])
                    ->orWhereRaw('LOWER(secondary_name) LIKE ?', ['%'.strtolower($search).'%'])
                    ->orWhereRaw('LOWER(sku) LIKE ?', ['%'.strtolower($search).'%'])
                    ->orWhereRaw('LOWER(barcode) LIKE ?', ['%'.strtolower($search).'%'])
                    ->orWhereRaw('LOWER(manufacturer_part_number) LIKE ?', ['%'.strtolower($search).'%']);
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

        // Status filter
        if ($request->filled('status')) {
            $status = $request->input('status');
            if (is_array($status)) {
                $query->whereIn('status', $status);
            } else {
                $query->where('status', $status);
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
        if ($request->filled('is_bundle')) {
            $query->where('is_bundle', true);
        }
        if ($request->filled('is_digital')) {
            $query->where('is_digital', true);
        }
        if ($request->filled('out_of_stock')) {
            $query->where('stock_quantity', '<=', 0);
        }
        if ($request->filled('low_stock')) {
            $query->whereColumn('stock_quantity', '<=', 'reorder_level');
        }

        // Tracking method filter
        if ($request->filled('tracking_method')) {
            $query->where('tracking_method', $request->tracking_method);
        }

        // Price range filter
        if ($request->filled('min_price')) {
            $query->where('price', '>=', (float) $request->min_price);
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', (float) $request->max_price);
        }

        // Supplier filter
        if ($request->filled('supplier_id')) {
            $supplierId = (int) $request->supplier_id;
            $query->whereHas('productSuppliers', function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId);
            });
        }

        $allowedSorts = ['name', 'price', 'cost', 'stock_quantity', 'created_at', 'updated_at', 'sku', 'status'];
        $sortBy = (string) $request->input('sort_by', 'created_at');
        $sortDir = strtolower((string) $request->input('sort_dir', 'desc'));
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'name';
        }
        if (! in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'asc';
        }

        $query->reorder($sortBy, $sortDir);

        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min(200, $perPage));

        $products = $query->paginate($perPage);

        $products->getCollection()->transform(function (Product $product) {
            $aggregatedStockQuantity = $product->track_inventory
                ? (int) ($product->aggregated_stock_quantity ?? 0)
                : (int) ($product->stock_quantity ?? 0);

            $product->setAttribute('stock_quantity', $aggregatedStockQuantity);
            $product->setAttribute('stock', $aggregatedStockQuantity);

            return $product;
        });

        return response()->json($products);
    }

    public function store(Request $request)
    {
        // Normalize empty barcode to null so the (nullable) uniqueness rule is
        // skipped — multiple products may legitimately have no barcode.
        $this->normalizeBarcodeInput($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'secondary_name' => 'nullable|string|max:255',
            'model_number' => 'nullable|string|max:100',
            'sku' => 'required|string|unique:products,sku',
            'barcode' => 'nullable|string|unique:products,barcode',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',
            'category_id' => 'nullable|exists:product_categories,id',
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
            'is_merchandise' => 'boolean',
            'tracking_method' => 'nullable|string|in:none,quantity,batch,serial',
            'shelf_life_days' => 'nullable|integer|min:0',
            'lot_number' => 'nullable|string|max:255',
            'expiry_date' => 'nullable|date',
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

        unset($validated['feature_values'], $validated['custom_field_values'], $validated['variants'], $validated['stock_quantity']);
        $validated = $this->removeUnsupportedProductColumns($validated);
        $validated = $this->coerceNotNullNumericDefaults($validated);

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
                    unset($variantData['stock_quantity']);
                    $product->variants()->create($variantData);
                }
            }

            return $product;
        });

        return response()->json($product->load(['incomeAccount', 'expenseAccount', 'category', 'tax']), 201);
    }

    public function show(int $id): JsonResponse
    {
        // Manually resolve product after tenant context is set
        // Route model binding happens BEFORE middleware, so it doesn't respect tenant search path
        $product = Product::findOrFail($id);

        return response()->json($product->load([
            'incomeAccount',
            'expenseAccount',
            'category',
            'tax',
            'brand',
            'variants',
            'stockMovements' => fn ($q) => $q->latest()->limit(50),
            'featureValues.feature',
            'customFieldValues.customField',
            'uoms',
            'suppliers',
            'bundleComponents.componentProduct' => fn ($q) => $q->select('id', 'name', 'sku', 'price', 'cost', 'image'),
            'priceTiers',
            'documents',
        ]));
    }

    /**
     * Get product audit log
     */
    public function auditLog($product, Request $request): JsonResponse
    {
        $product = Product::findOrFail($product);
        $perPage = $request->get('per_page', 50);

        $logs = $product->auditLogs()
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * Get products needing reorder
     */
    public function reorderReport(Request $request): JsonResponse
    {
        $query = Product::where('track_inventory', true)
            ->whereNotNull('reorder_level')
            ->whereColumn('stock_quantity', '<=', 'reorder_level')
            ->with(['category', 'preferredSupplier.supplier']);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->orderBy('stock_quantity', 'asc')
            ->paginate($request->get('per_page', 50));

        return response()->json($products);
    }

    /**
     * Submit product for approval
     */
    public function submitForApproval($product): JsonResponse
    {
        $product = Product::findOrFail($product);
        if ($product->status !== Product::STATUS_DRAFT) {
            return response()->json([
                'message' => 'Only draft products can be submitted for approval',
            ], 422);
        }

        $product->submitForApproval();

        return response()->json([
            'message' => 'Product submitted for approval',
            'product' => $product,
        ]);
    }

    /**
     * Approve a product
     */
    public function approve($product): JsonResponse
    {
        $product = Product::findOrFail($product);
        if ($product->status !== Product::STATUS_PENDING_APPROVAL) {
            return response()->json([
                'message' => 'Only products pending approval can be approved',
            ], 422);
        }

        $product->approve();

        return response()->json([
            'message' => 'Product approved successfully',
            'product' => $product,
        ]);
    }

    /**
     * Reject a product (send back to draft)
     */
    public function reject(Request $request, $product): JsonResponse
    {
        $product = Product::findOrFail($product);
        if ($product->status !== Product::STATUS_PENDING_APPROVAL) {
            return response()->json([
                'message' => 'Only products pending approval can be rejected',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $product->status = Product::STATUS_DRAFT;
        $product->save();

        ProductAuditLog::log($product->id, 'rejected', 'status', 'pending_approval', 'draft', [
            'rejection_reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'Product rejected and returned to draft',
            'product' => $product,
        ]);
    }

    /**
     * Discontinue a product
     */
    public function discontinue($product): JsonResponse
    {
        $product = Product::findOrFail($product);
        if ($product->status === Product::STATUS_DISCONTINUED) {
            return response()->json([
                'message' => 'Product is already discontinued',
            ], 422);
        }

        $product->discontinue();

        return response()->json([
            'message' => 'Product discontinued successfully',
            'product' => $product,
        ]);
    }

    /**
     * Archive a product
     */
    public function archive($product): JsonResponse
    {
        $product = Product::findOrFail($product);
        $product->archive();

        return response()->json([
            'message' => 'Product archived successfully',
            'product' => $product,
        ]);
    }

    /**
     * Reactivate a product
     */
    public function reactivate($product): JsonResponse
    {
        $product = Product::findOrFail($product);
        if ($product->status === Product::STATUS_ACTIVE) {
            return response()->json([
                'message' => 'Product is already active',
            ], 422);
        }

        $oldStatus = $product->status;
        $product->status = Product::STATUS_ACTIVE;
        $product->discontinued_at = null;
        $product->discontinued_by = null;
        $product->save();

        ProductAuditLog::log($product->id, 'reactivated', 'status', $oldStatus, 'active');

        return response()->json([
            'message' => 'Product reactivated successfully',
            'product' => $product,
        ]);
    }

    /**
     * Get product statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total' => Product::count(),
            'active' => Product::where('status', Product::STATUS_ACTIVE)->count(),
            'draft' => Product::where('status', Product::STATUS_DRAFT)->count(),
            'pending_approval' => Product::where('status', Product::STATUS_PENDING_APPROVAL)->count(),
            'discontinued' => Product::where('status', Product::STATUS_DISCONTINUED)->count(),
            'archived' => Product::where('status', Product::STATUS_ARCHIVED)->count(),
            'out_of_stock' => Product::where('track_inventory', true)->where('stock_quantity', '<=', 0)->count(),
            'low_stock' => Product::where('track_inventory', true)
                ->whereNotNull('reorder_level')
                ->whereColumn('stock_quantity', '<=', 'reorder_level')
                ->count(),
            'bundles' => Product::where('is_bundle', true)->count(),
            'digital' => Product::where('is_digital', true)->count(),
            'by_category' => Product::selectRaw('category_id, COUNT(*) as count')
                ->groupBy('category_id')
                ->with('category:id,name')
                ->get(),
            'by_tracking_method' => Product::selectRaw('tracking_method, COUNT(*) as count')
                ->groupBy('tracking_method')
                ->get(),
        ];

        return response()->json($stats);
    }

    /**
     * Calculate price for quantity
     */
    public function calculatePrice(Request $request, $product): JsonResponse
    {
        $product = Product::findOrFail($product);
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
            'customer_id' => 'nullable|exists:customers,id',
            'uom_id' => 'nullable|exists:product_uoms,id',
        ]);

        $unitPrice = $product->getPriceForQuantity(
            $validated['quantity'],
            $validated['customer_id'] ?? null
        );

        // Apply UOM multiplier if specified
        if (! empty($validated['uom_id'])) {
            $uom = $product->uoms()->find($validated['uom_id']);
            if ($uom) {
                $unitPrice = $unitPrice * $uom->price_multiplier;
            }
        }

        return response()->json([
            'product_id' => $product->id,
            'quantity' => $validated['quantity'],
            'unit_price' => round($unitPrice, 2),
            'total_price' => round($unitPrice * $validated['quantity'], 2),
            'regular_price' => (float) $product->price,
            'discount_amount' => round(($product->price - $unitPrice) * $validated['quantity'], 2),
        ]);
    }

    /**
     * Duplicate a product
     */
    public function duplicate(Request $request, $product): JsonResponse
    {
        $product = Product::findOrFail($product);
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'sku' => 'nullable|string|unique:products,sku',
        ]);

        $newProduct = DB::transaction(function () use ($product, $validated) {
            $newName = $validated['name'] ?? $product->name.' (Copy)';
            $newSku = $validated['sku'] ?? $product->sku.'-COPY-'.Str::random(4);

            $data = $product->toArray();
            unset($data['id'], $data['created_at'], $data['updated_at'], $data['deleted_at']);

            $data['name'] = $newName;
            $data['sku'] = $newSku;
            $data['barcode'] = null;
            $data['status'] = Product::STATUS_DRAFT;
            $data['stock_quantity'] = 0;
            $data['approved_by'] = null;
            $data['approved_at'] = null;

            $newProduct = Product::create($data);

            // Copy UOMs
            foreach ($product->uoms as $uom) {
                $uomData = $uom->toArray();
                unset($uomData['id'], $uomData['product_id'], $uomData['created_at'], $uomData['updated_at']);
                $uomData['barcode'] = null;
                $newProduct->uoms()->create($uomData);
            }

            // Copy price tiers
            foreach ($product->priceTiers as $tier) {
                $tierData = $tier->toArray();
                unset($tierData['id'], $tierData['product_id'], $tierData['created_at'], $tierData['updated_at']);
                $newProduct->priceTiers()->create($tierData);
            }

            // Copy feature values
            foreach ($product->featureValues as $value) {
                ProductFeatureValue::create([
                    'product_id' => $newProduct->id,
                    'product_feature_id' => $value->product_feature_id,
                    'value' => $value->value,
                ]);
            }

            // Copy custom field values
            foreach ($product->customFieldValues as $value) {
                ProductCustomFieldValue::create([
                    'product_id' => $newProduct->id,
                    'product_custom_field_id' => $value->product_custom_field_id,
                    'value' => $value->value,
                ]);
            }

            return $newProduct;
        });

        return response()->json([
            'message' => 'Product duplicated successfully',
            'product' => $newProduct->load(['category', 'brand']),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        // Manually resolve product after tenant context is set
        // Route model binding happens BEFORE middleware, so it doesn't respect tenant search path
        $product = Product::findOrFail($id);

        // Normalize empty barcode to null so the (nullable) uniqueness rule is
        // skipped — multiple products may legitimately have no barcode.
        $this->normalizeBarcodeInput($request);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'secondary_name' => 'nullable|string|max:255',
            'model_number' => 'nullable|string|max:100',
            'sku' => 'string|unique:products,sku,'.$product->id,
            'barcode' => 'nullable|string|unique:products,barcode,'.$product->id,
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
            'shelf_life_days' => 'nullable|integer|min:0',
            'lot_number' => 'nullable|string|max:255',
            'expiry_date' => 'nullable|date',
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
            'is_merchandise' => 'boolean',
        ]);

        $featureValues = $validated['feature_values'] ?? null;
        $customFieldValues = $validated['custom_field_values'] ?? null;
        $suppliersData = $validated['suppliers'] ?? null;
        unset($validated['feature_values'], $validated['custom_field_values'], $validated['suppliers'], $validated['stock_quantity']);
        $validated = $this->removeUnsupportedProductColumns($validated);
        $validated = $this->coerceNotNullNumericDefaults($validated);

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

    public function destroy(int $id)
    {
        // Manually resolve product after tenant context is set
        // Route model binding happens BEFORE middleware, so it doesn't respect tenant search path
        $product = Product::findOrFail($id);
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

        $rawHeader = fgetcsv($handle);
        if (! is_array($rawHeader) || count($rawHeader) === 0) {
            fclose($handle);

            return response()->json(['message' => 'CSV header missing'], 422);
        }

        // Normalize headers: lowercase, non-alphanumeric → underscore, collapse
        $header = array_map(function ($h) {
            $h = strtolower(trim((string) $h));
            $h = preg_replace('/[^a-z0-9]+/', '_', $h);

            return trim($h, '_');
        }, $rawHeader);

        // Column aliases to map various CSV formats to product fields
        $columnAliases = [
            'item_description' => 'name',
            'item_barcode' => 'barcode',
            'stock_code' => 'sku',
            'unit_cost_sgd' => 'cost',
            'selling_price_sgd' => 'price',
            'quantity' => 'stock_quantity',
            'product_type' => 'category_id',
            'artist' => 'secondary_name',
            'artist_group' => 'manufacturer',
            'model_no' => 'model_number',
            'model_num' => 'model_number',
            'weblink_for_the_image' => 'image_url',
            // Batch / lot tracking
            'lot' => 'lot_number',
            'lot_expiry_date' => 'expiry_date',
            'expiry' => 'expiry_date',
            'expire_date' => 'expiry_date',
        ];

        $header = array_map(fn ($key) => $columnAliases[$key] ?? $key, $header);

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (! is_array($data) || count(array_filter($data, fn ($v) => (string) $v !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $idx => $key) {
                if ($key !== '') {
                    $val = $data[$idx] ?? null;
                    if (is_string($val)) {
                        $encoding = mb_detect_encoding($val, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'ISO-8859-1';
                        $val = mb_convert_encoding($val, 'UTF-8', $encoding);
                    }
                    $row[$key] = $val;
                }
            }
            $rows[] = $row;
        }
        fclose($handle);

        if (count($rows) === 0) {
            return response()->json(['message' => 'No rows found'], 422);
        }

        $created = 0;
        $updated = 0;
        $errors = [];
        $categoriesCache = [];
        $defaultWarehouse = null;
        $seenSkus = [];

        foreach ($rows as $index => $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                $errors[] = [
                    'row' => $index + 2,
                    'message' => 'Missing required field: name',
                ];

                continue;
            }

            // Validate numeric columns — flag rows with non-numeric values
            // (e.g. "abc") instead of silently coercing them to 0.
            $numericColumns = ['cost', 'price', 'stock_quantity', 'shelf_life_days'];
            $invalidColumns = [];
            foreach ($numericColumns as $column) {
                if (array_key_exists($column, $row) && $this->isNonNumericImportValue($row[$column])) {
                    $invalidColumns[] = $column;
                }
            }
            if (! empty($invalidColumns)) {
                $errors[] = [
                    'row' => $index + 2,
                    'message' => 'Invalid numeric value(s) for: '.implode(', ', $invalidColumns),
                ];

                continue;
            }

            // Parse price/cost – strip currency symbols ($) and non-numeric chars
            $cost = $this->parseNumericValue($row['cost'] ?? null);
            $price = $this->parseNumericValue($row['price'] ?? null);

            $sku = trim((string) ($row['sku'] ?? ''));
            $barcode = isset($row['barcode']) ? trim((string) $row['barcode']) : '';

            // Resolve category_id – handle both numeric IDs and category names
            $categoryId = $this->resolveCategoryId($row['category_id'] ?? null, $categoriesCache);

            // Build description from variant fields when no explicit description
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                $variantParts = [];
                foreach (['variant_edition', 'variant_theme', 'variant_format', 'variant_region', 'variant_colour'] as $vf) {
                    $val = trim((string) ($row[$vf] ?? ''));
                    if ($val !== '' && $val !== '-') {
                        $label = ucfirst(str_replace('variant_', '', $vf));
                        $variantParts[] = "{$label}: {$val}";
                    }
                }
                if (count($variantParts) > 0) {
                    $description = implode(' | ', $variantParts);
                }
            }

            // Image URL from weblink
            $imageUrl = trim((string) ($row['image_url'] ?? ''));
            $imageValue = ($imageUrl !== '' && filter_var($imageUrl, FILTER_VALIDATE_URL)) ? $imageUrl : null;

            $secondaryName = isset($row['secondary_name']) ? trim((string) $row['secondary_name']) : '';
            $unit = isset($row['unit']) ? trim((string) $row['unit']) : '';
            $manufacturer = isset($row['manufacturer']) ? trim((string) $row['manufacturer']) : '';
            $hasImportedStockQuantity = array_key_exists('stock_quantity', $row);
            $importedStockQuantity = $hasImportedStockQuantity
                ? max(0, (int) round($this->parseNumericValue($row['stock_quantity'] ?? null)))
                : null;

            $lotNumber = isset($row['lot_number']) ? trim((string) $row['lot_number']) : '';
            $expiryDate = $this->parseImportDate($row['expiry_date'] ?? null);

            // Match by SKU when available. Barcode alone is not reliable because
            // multiple distinct products in the same catalog can legitimately share it.
            $existingProduct = $this->findExistingProductForImport($sku, $barcode, $name, $secondaryName);

            // Generate SKU only if no existing product found and no SKU provided
            if (! $existingProduct && ($sku === '' || $sku === '-')) {
                $base = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]+/', '', $name), 0, 8));
                $base = $base !== '' ? $base : 'SKU';
                $sku = $base.'-'.Str::upper(Str::random(6));
            }

            // Detect a SKU that repeats within this import file and flag it
            // instead of silently overwriting the product from its first row.
            if ($sku !== '' && $sku !== '-') {
                $skuKey = mb_strtolower($sku);
                if (isset($seenSkus[$skuKey])) {
                    $errors[] = [
                        'row' => $index + 2,
                        'message' => "Duplicate SKU in import file: {$sku}",
                    ];

                    continue;
                }
                $seenSkus[$skuKey] = true;
            }

            $productData = [
                'name' => $name,
                'sku' => ($sku !== '' && $sku !== '-') ? $sku : ($existingProduct->sku ?? $sku),
                'secondary_name' => $secondaryName !== '' && $secondaryName !== '-' ? $secondaryName : null,
                'model_number' => isset($row['model_number']) && trim((string) $row['model_number']) !== '' && trim((string) $row['model_number']) !== '-'
                    ? trim((string) $row['model_number'])
                    : null,
                'barcode' => $barcode !== '' ? $barcode : null,
                'description' => $description !== '' ? $description : null,
                'short_description' => $row['short_description'] ?? ($description !== '' ? $description : null),
                'category_id' => $categoryId,
                'type' => in_array(($row['type'] ?? ''), ['product', 'service'], true) ? $row['type'] : 'product',
                'cost' => $cost,
                'price' => $price,
                'track_inventory' => isset($row['track_inventory']) ? filter_var($row['track_inventory'], FILTER_VALIDATE_BOOLEAN) : true,
                'is_active' => isset($row['is_active']) ? filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN) : true,
                'image' => $imageValue,
                'manufacturer' => $manufacturer !== '' && $manufacturer !== '-' ? $manufacturer : null,
                'unit' => $unit !== '' && $unit !== '-' ? $unit : null,
                'lot_number' => $lotNumber !== '' && $lotNumber !== '-' ? $lotNumber : null,
                'expiry_date' => $expiryDate,
                'shelf_life_days' => isset($row['shelf_life_days']) && trim((string) $row['shelf_life_days']) !== ''
                    ? (int) round($this->parseNumericValue($row['shelf_life_days']))
                    : null,
            ];

            try {
                DB::transaction(function () use (&$defaultWarehouse, $existingProduct, $productData, $hasImportedStockQuantity, $importedStockQuantity, &$created, &$updated) {
                    if ($existingProduct) {
                        $existingProduct->update($productData);
                        $product = $existingProduct->fresh();
                        $updated++;
                    } else {
                        $product = Product::create($productData);
                        $created++;
                    }

                    if (! $hasImportedStockQuantity || ! $product->track_inventory || $product->type !== 'product') {
                        return;
                    }

                    $defaultWarehouse ??= $this->resolveImportWarehouse();

                    app(InventoryService::class)->adjustStock(
                        productId: $product->id,
                        warehouseId: $defaultWarehouse->id,
                        quantity: $importedStockQuantity,
                        type: 'adjustment',
                        reference: 'PRODUCT-IMPORT',
                        notes: 'CSV product import stock sync'
                    );
                });
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $enc = mb_detect_encoding($msg, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'ISO-8859-1';
                $errors[] = [
                    'row' => $index + 2,
                    'message' => mb_convert_encoding($msg, 'UTF-8', $enc),
                ];
            }
        }

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function resolveImportWarehouse(): Warehouse
    {
        $defaultWarehouse = Warehouse::where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($defaultWarehouse) {
            return $defaultWarehouse;
        }

        return DB::transaction(function () {
            $existingDefault = Warehouse::where('is_default', true)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($existingDefault) {
                return $existingDefault;
            }

            return Warehouse::create([
                'name' => 'Main Warehouse',
                'code' => 'MAIN',
                'is_active' => true,
                'is_default' => true,
            ]);
        });
    }

    /**
     * Parse a numeric value from CSV, stripping currency symbols and non-numeric chars.
     */
    private function parseNumericValue($value): float
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }
        $cleaned = preg_replace('/[^0-9.]/', '', trim((string) $value));

        return $cleaned !== '' ? (float) $cleaned : 0;
    }

    /**
     * Detect a clearly non-numeric CSV value (e.g. "abc", "ten"). Empty/missing
     * values are allowed (they default to 0). Currency symbols, thousands
     * separators and a sign are tolerated, mirroring parseNumericValue().
     */
    private function isNonNumericImportValue($value): bool
    {
        if ($value === null) {
            return false;
        }
        $raw = trim((string) $value);
        if ($raw === '' || $raw === '-') {
            return false;
        }
        $cleaned = preg_replace('/[^0-9.\-]/', '', $raw);

        return $cleaned === '' || ! is_numeric($cleaned);
    }

    /**
     * Parse a date from CSV into Y-m-d. Accepts common formats incl. M/D/YY, M/D/YYYY,
     * D-M-YYYY, and ISO. Returns null for empty/unparseable values.
     */
    private function parseImportDate($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '' || $raw === '-') {
            return null;
        }
        foreach (['Y-m-d', 'm/d/Y', 'n/j/y', 'n/j/Y', 'd-m-Y', 'j-M-Y', 'Y/m/d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt instanceof \DateTimeImmutable && $dt->format($format) === $raw) {
                return $dt->format('Y-m-d');
            }
        }
        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function findExistingProductForImport(string $sku, string $barcode, string $name, string $secondaryName): ?Product
    {
        if ($this->hasImportIdentifier($sku)) {
            return Product::where('sku', $sku)->first();
        }

        if (! $this->hasImportIdentifier($barcode)) {
            return null;
        }

        return Product::query()
            ->where('barcode', $barcode)
            ->whereRaw('LOWER(name) = ?', [$this->normalizeImportIdentityValue($name)])
            ->whereRaw("LOWER(COALESCE(secondary_name, '')) = ?", [$this->normalizeImportIdentityValue($secondaryName)])
            ->first();
    }

    private function hasImportIdentifier(string $value): bool
    {
        return $value !== '' && $value !== '-';
    }

    private function normalizeImportIdentityValue(?string $value): string
    {
        return Str::lower(trim((string) $value));
    }

    /**
     * Bulk delete products
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $ids = $request->input('ids');
        $deleted = Product::whereIn('id', $ids)->delete();

        return response()->json([
            'message' => "{$deleted} product(s) deleted successfully",
            'deleted' => $deleted,
        ]);
    }

    /**
     * Delete all products
     */
    public function destroyAll(): JsonResponse
    {
        $count = Product::count();

        DB::beginTransaction();
        try {
            // Clear FK references first
            $fkTables = [
                'product_suppliers', 'product_uoms', 'product_bundles',
                'stock_movements', 'product_variants', 'product_relationships',
                'product_feature_values', 'product_custom_field_values', 'stock_levels',
                'product_price_tiers', 'product_batches', 'product_serials',
                'product_audit_logs', 'product_documents', 'invoice_lines',
                'purchase_order_items', 'pos_order_items',
            ];
            foreach ($fkTables as $table) {
                try {
                    DB::table($table)->delete();
                } catch (\Throwable $e) {
                }
            }

            Product::query()->forceDelete();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to delete all products: '.$e->getMessage()], 500);
        }

        return response()->json([
            'message' => "{$count} product(s) deleted successfully",
            'deleted' => $count,
        ]);
    }

    /**
     * Resolve category ID from input value (numeric ID or category name)
     * Creates the category if it doesn't exist (when given a name)
     *
     * @param  mixed  $categoryValue  The category_id from CSV (can be ID, name, or empty)
     * @param  array  &$cache  Reference to cache array for performance
     * @return int|null The resolved category ID or null
     */
    private function resolveCategoryId($categoryValue, array &$cache): ?int
    {
        // Empty value - return null (product will have no category)
        if ($categoryValue === null || $categoryValue === '') {
            return null;
        }

        $categoryValue = trim((string) $categoryValue);

        if ($categoryValue === '') {
            return null;
        }

        // Check if it's a pure numeric ID
        if (is_numeric($categoryValue) && intval($categoryValue) > 0) {
            $id = (int) $categoryValue;
            // Verify the ID exists in database
            if (! isset($cache['id_'.$id])) {
                $exists = \App\Models\ProductCategory::where('id', $id)->exists();
                $cache['id_'.$id] = $exists ? $id : null;
            }

            return $cache['id_'.$id];
        }

        // It's a category name - check cache first
        $cacheKey = 'name_'.strtolower($categoryValue);
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        // Try to find existing category by name (case-insensitive)
        $category = \App\Models\ProductCategory::whereRaw('LOWER(name) = ?', [strtolower($categoryValue)])->first();

        if ($category) {
            $cache[$cacheKey] = $category->id;

            return $category->id;
        }

        // Category doesn't exist - create it automatically
        $newCategory = \App\Models\ProductCategory::create([
            'name' => $categoryValue,
            'is_active' => true,
        ]);

        $cache[$cacheKey] = $newCategory->id;

        return $newCategory->id;
    }
}
