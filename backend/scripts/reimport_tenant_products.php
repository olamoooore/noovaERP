<?php
/**
 * Delete all tenant products and re-import from CSV.
 * Usage: cd backend && php scripts/reimport_tenant_products.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ── Tenant setup ─────────────────────────────────────────────────
$tenantId = 'e255e52c-a4a6-4158-954a-af04408e9386';
DB::statement("SET search_path TO '{$tenantId}', public");
echo "✓ Set search_path to tenant: {$tenantId}\n";

// ── Step 1: Delete all existing products ─────────────────────────
echo "\n=== STEP 1: Delete all existing products ===\n";
$existingCount = Product::count();
echo "Found {$existingCount} existing products\n";

DB::beginTransaction();
try {
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
            // Table might not exist in tenant schema
        }
    }

    // Also delete categories so we start fresh
    Product::query()->forceDelete();
    DB::commit();
    echo "✓ Deleted {$existingCount} products\n";
} catch (\Throwable $e) {
    DB::rollBack();
    die("✗ Failed to delete products: " . $e->getMessage() . "\n");
}

// ── Step 2: Read CSV ─────────────────────────────────────────────
echo "\n=== STEP 2: Read CSV ===\n";
$csvPath = __DIR__ . '/../../infinitykpop_clean_enriched revised 9 March 26.xlsx - ITEMS WITH BARCODE.csv';
if (!file_exists($csvPath)) {
    die("✗ CSV not found at: {$csvPath}\n");
}

$handle = fopen($csvPath, 'r');
$rawHeader = fgetcsv($handle);

// Normalize headers
$header = array_map(function ($h) {
    $h = strtolower(trim((string) $h));
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    return trim($h, '_');
}, $rawHeader);

// Column aliases
$columnAliases = [
    'item_description'      => 'name',
    'item_barcode'          => 'barcode',
    'stock_code'            => 'sku',
    'unit_cost_sgd'         => 'cost',
    'selling_price_sgd'     => 'price',
    'quantity'              => 'stock_quantity',
    'product_type'          => 'category_name',
    'artist'                => 'secondary_name',
    'artist_group'          => 'manufacturer',
    'weblink_for_the_image' => 'image_url',
];

$header = array_map(fn($key) => $columnAliases[$key] ?? $key, $header);
echo "Headers: " . implode(', ', array_filter($header)) . "\n";

// Read all rows
$rows = [];
while (($line = fgetcsv($handle)) !== false) {
    if (count($line) < 3) continue;
    $row = [];
    foreach ($header as $i => $key) {
        if ($key !== '') {
            $row[$key] = $line[$i] ?? '';
        }
    }
    $name = trim($row['name'] ?? '');
    if ($name !== '') {
        $rows[] = $row;
    }
}
fclose($handle);
echo "✓ Read " . count($rows) . " rows from CSV\n";

// ── Step 3: Import products ──────────────────────────────────────
echo "\n=== STEP 3: Import products ===\n";
$created = 0;
$errors = [];
$categoriesCache = [];

function parsePrice($value): float
{
    if ($value === null || trim((string)$value) === '') return 0;
    $cleaned = preg_replace('/[^0-9.]/', '', trim((string)$value));
    return $cleaned !== '' ? (float)$cleaned : 0;
}

function resolveCategoryId($categoryValue, array &$cache): ?int
{
    if ($categoryValue === null || trim((string)$categoryValue) === '' || trim((string)$categoryValue) === '-') {
        return null;
    }
    $categoryValue = trim((string)$categoryValue);
    $cacheKey = strtolower($categoryValue);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    $category = ProductCategory::whereRaw('LOWER(name) = ?', [$cacheKey])->first();
    if ($category) {
        $cache[$cacheKey] = $category->id;
        return $category->id;
    }
    $newCategory = ProductCategory::create([
        'name'      => $categoryValue,
        'is_active' => true,
    ]);
    $cache[$cacheKey] = $newCategory->id;
    echo "  + Created category: {$categoryValue}\n";
    return $newCategory->id;
}

foreach ($rows as $index => $row) {
    $name = trim((string)($row['name'] ?? ''));
    if ($name === '') continue;

    $cost = parsePrice($row['cost'] ?? null);
    $price = parsePrice($row['price'] ?? null);

    $sku = trim((string)($row['sku'] ?? ''));
    if ($sku === '' || $sku === '-') {
        $base = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]+/', '', $name), 0, 8));
        $base = $base !== '' ? $base : 'SKU';
        $sku = $base . '-' . Str::upper(Str::random(6));
    }

    $categoryId = resolveCategoryId($row['category_name'] ?? null, $categoriesCache);

    // Build description from variant fields
    $description = '';
    $variantParts = [];
    foreach (['variant_edition', 'variant_theme', 'variant_format', 'variant_region', 'variant_colour'] as $vf) {
        $val = trim((string)($row[$vf] ?? ''));
        if ($val !== '' && $val !== '-') {
            $label = ucfirst(str_replace('variant_', '', $vf));
            $variantParts[] = "{$label}: {$val}";
        }
    }
    if (count($variantParts) > 0) {
        $description = implode(' | ', $variantParts);
    }

    $imageUrl = trim((string)($row['image_url'] ?? ''));
    $imageValue = ($imageUrl !== '' && filter_var($imageUrl, FILTER_VALIDATE_URL)) ? $imageUrl : null;

    $barcode = trim((string)($row['barcode'] ?? ''));
    $secondaryName = trim((string)($row['secondary_name'] ?? ''));
    $manufacturer = trim((string)($row['manufacturer'] ?? ''));
    $unit = trim((string)($row['unit'] ?? ''));

    try {
        Product::create([
            'name'              => $name,
            'secondary_name'    => $secondaryName !== '' && $secondaryName !== '-' ? $secondaryName : null,
            'sku'               => $sku,
            'barcode'           => $barcode !== '' ? $barcode : null,
            'description'       => $description !== '' ? $description : null,
            'short_description' => $description !== '' ? $description : null,
            'category_id'       => $categoryId,
            'type'              => 'product',
            'cost'              => $cost,
            'price'             => $price,
            'track_inventory'   => true,
            'stock_quantity'    => isset($row['stock_quantity']) && $row['stock_quantity'] !== '' ? (int)$row['stock_quantity'] : 0,
            'is_active'         => true,
            'image'             => $imageValue,
            'manufacturer'      => $manufacturer !== '' && $manufacturer !== '-' ? $manufacturer : null,
            'unit'              => $unit !== '' && $unit !== '-' ? $unit : null,
        ]);
        $created++;
    } catch (\Throwable $e) {
        $errors[] = "Row " . ($index + 2) . ": " . $e->getMessage();
    }
}

echo "\n✓ Created: {$created} products\n";
if (count($errors) > 0) {
    echo "✗ Errors: " . count($errors) . "\n";
    foreach (array_slice($errors, 0, 20) as $err) {
        echo "  - {$err}\n";
    }
}

// ── Step 4: Stats ────────────────────────────────────────────────
echo "\n=== Final Stats ===\n";
$total = Product::count();
$withArtist = Product::whereNotNull('secondary_name')->where('secondary_name', '!=', '')->count();
$withGroup = Product::whereNotNull('manufacturer')->where('manufacturer', '!=', '')->count();
$withImage = Product::whereNotNull('image')->where('image', '!=', '')->count();
$categories = ProductCategory::count();

echo "Total products: {$total}\n";
echo "With artist (secondary_name): {$withArtist}\n";
echo "With artist group (manufacturer): {$withGroup}\n";
echo "With image URL: {$withImage}\n";
echo "Categories: {$categories}\n";
echo "\nDone! Now run 'php artisan products:download-images --tenant={$tenantId}' to scrape actual images.\n";
