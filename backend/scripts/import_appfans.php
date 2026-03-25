<?php
/**
 * Import products from CSV for a specific tenant.
 * Usage: php artisan tinker < /tmp/import_appfans.php
 *        OR: php /tmp/import_appfans.php (standalone with bootstrap)
 */

// Bootstrap Laravel
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

// Find the tenant for admin@demo.com
$tenant = \App\Models\Tenant::find('e255e52c-a4a6-4158-954a-af04408e9386');
if (!$tenant) {
    echo "Tenant not found, trying to import into public schema...\n";
}

// Initialize tenant context if found
if ($tenant) {
    tenancy()->initialize($tenant);
    echo "Initialized tenant: {$tenant->id}\n";
}

// Read CSV
$csvPath = '/tmp/infinitykpop_import_appfans.csv';
$handle = fopen($csvPath, 'r');
if (!$handle) {
    die("Cannot open CSV file: $csvPath\n");
}

$rawHeader = fgetcsv($handle);
$header = array_map(function ($h) {
    $h = strtolower(trim((string) $h));
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    return trim($h, '_');
}, $rawHeader);

// Column aliases
$columnAliases = [
    'item_description' => 'name',
    'item_barcode'     => 'barcode',
    'stock_code'       => 'sku',
    'unit_cost_sgd'    => 'cost',
    'selling_price_sgd'=> 'price',
    'quantity'         => 'stock_quantity',
    'product_type'     => 'category_id',
    'artist'           => 'secondary_name',
    'artist_group'     => 'manufacturer',
    'weblink_for_the_image' => 'image_url',
];
$header = array_map(fn ($key) => $columnAliases[$key] ?? $key, $header);

echo "CSV Headers: " . implode(', ', $header) . "\n\n";

$rows = [];
while (($data = fgetcsv($handle)) !== false) {
    if (!is_array($data) || count(array_filter($data, fn ($v) => (string)$v !== '')) === 0) {
        continue;
    }
    $row = [];
    foreach ($header as $idx => $key) {
        if ($key !== '') {
            $row[$key] = $data[$idx] ?? null;
        }
    }
    $rows[] = $row;
}
fclose($handle);

echo "Total rows to import: " . count($rows) . "\n\n";

$created = 0;
$errors = [];
$categoriesCache = [];

foreach ($rows as $index => $row) {
    $name = trim((string) ($row['name'] ?? ''));
    if ($name === '') {
        $errors[] = "Row " . ($index + 2) . ": Missing name";
        continue;
    }

    // Parse price/cost
    $cost = parseNumeric($row['cost'] ?? null);
    $price = parseNumeric($row['price'] ?? null);

    // Generate SKU from name if missing
    $sku = trim((string) ($row['sku'] ?? ''));
    if ($sku === '' || $sku === '-') {
        $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $name), 0, 8));
        $base = $base !== '' ? $base : 'SKU';
        $sku = $base . '-' . strtoupper(Str::random(6));
    }

    // Resolve category
    $categoryId = resolveCategoryId($row['category_id'] ?? null, $categoriesCache);

    // Image URL
    $imageUrl = trim((string) ($row['image_url'] ?? ''));
    $imageValue = ($imageUrl !== '' && filter_var($imageUrl, FILTER_VALIDATE_URL)) ? $imageUrl : null;

    $barcode = isset($row['barcode']) ? trim((string)$row['barcode']) : '';
    $secondaryName = isset($row['secondary_name']) ? trim((string)$row['secondary_name']) : '';
    $unit = isset($row['unit']) ? trim((string)$row['unit']) : '';
    $manufacturer = isset($row['manufacturer']) ? trim((string)$row['manufacturer']) : '';

    try {
        \App\Models\Product::create([
            'name'             => $name,
            'secondary_name'   => $secondaryName !== '' && $secondaryName !== '-' ? $secondaryName : null,
            'sku'              => $sku,
            'barcode'          => $barcode !== '' ? $barcode : null,
            'description'      => null,
            'category_id'      => $categoryId,
            'type'             => in_array(($row['type'] ?? ''), ['product', 'service'], true) ? $row['type'] : 'product',
            'cost'             => $cost,
            'price'            => $price,
            'track_inventory'  => isset($row['track_inventory']) ? filter_var($row['track_inventory'], FILTER_VALIDATE_BOOLEAN) : true,
            'stock_quantity'   => isset($row['stock_quantity']) && $row['stock_quantity'] !== '' ? (int)$row['stock_quantity'] : 0,
            'is_active'        => isset($row['is_active']) ? filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN) : true,
            'image'            => $imageValue,
            'manufacturer'     => $manufacturer !== '' && $manufacturer !== '-' ? $manufacturer : null,
            'unit'             => $unit !== '' && $unit !== '-' ? $unit : null,
        ]);
        $created++;
        echo ".";
    } catch (\Throwable $e) {
        $errors[] = "Row " . ($index + 2) . ": " . $e->getMessage();
        echo "X";
    }
}

echo "\n\n=== Import Complete ===\n";
echo "Created: $created\n";
echo "Errors: " . count($errors) . "\n";
if (count($errors) > 0) {
    echo "\nError details:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
}

function parseNumeric($value): float {
    if ($value === null || trim((string)$value) === '') return 0;
    $cleaned = preg_replace('/[^0-9.]/', '', trim((string)$value));
    return $cleaned !== '' ? (float)$cleaned : 0;
}

function resolveCategoryId($value, array &$cache): ?int {
    if ($value === null || $value === '') return null;
    $trimmed = trim((string)$value);
    if ($trimmed === '' || $trimmed === '-') return null;

    // Remove parentheses like "(P)"
    $trimmed = trim($trimmed, '()');
    if ($trimmed === '' || strlen($trimmed) <= 1) return null;

    if (isset($cache[$trimmed])) return $cache[$trimmed];

    // If numeric, check if the category exists
    if (is_numeric($trimmed)) {
        $exists = DB::table('product_categories')->where('id', (int)$trimmed)->exists();
        if ($exists) {
            $cache[$trimmed] = (int)$trimmed;
            return (int)$trimmed;
        }
    }

    // Try to find by name
    $cat = DB::table('product_categories')->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])->first();
    if ($cat) {
        $cache[$trimmed] = $cat->id;
        return $cat->id;
    }

    // Create the category
    $id = DB::table('product_categories')->insertGetId([
        'name' => $trimmed,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $cache[$trimmed] = $id;
    return $id;
}
