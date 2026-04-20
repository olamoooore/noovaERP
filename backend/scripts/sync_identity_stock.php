<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Services\InventoryService;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$arguments = $argv;
array_shift($arguments);

if (count($arguments) < 2) {
    fwrite(STDERR, "Usage: php scripts/sync_identity_stock.php <tenant-id> <source-tsv> [warehouse-code] [--deactivate-others] [--dry-run]\n");
    exit(1);
}

$tenantId = array_shift($arguments);
$sourcePath = array_shift($arguments);
$warehouseCode = null;
$deactivateOthers = false;
$dryRun = false;

foreach ($arguments as $argument) {
    if ($argument === '--deactivate-others') {
        $deactivateOthers = true;
        continue;
    }

    if ($argument === '--dry-run') {
        $dryRun = true;
        continue;
    }

    if ($warehouseCode === null) {
        $warehouseCode = $argument;
    }
}

if (!is_file($sourcePath)) {
    fwrite(STDERR, "Source TSV not found: {$sourcePath}\n");
    exit(1);
}

$tenant = Tenant::find($tenantId);
if (!$tenant) {
    fwrite(STDERR, "Tenant not found: {$tenantId}\n");
    exit(1);
}

tenancy()->initialize($tenant);

$desiredQuantities = [];
$sourceRows = file($sourcePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
foreach ($sourceRows as $line) {
    $parts = explode("\t", $line, 4);
    if (count($parts) < 2) {
        continue;
    }

    $desiredQuantities[$parts[0]] = (int) $parts[1];
}

$targetWarehouse = $warehouseCode
    ? Warehouse::where('code', $warehouseCode)->first()
    : null;

if (!$targetWarehouse) {
    $targetWarehouse = Warehouse::where('is_active', true)->orderBy('id')->first();
}

if (!$targetWarehouse) {
    fwrite(STDERR, "No warehouse available for tenant {$tenantId}\n");
    exit(1);
}

$timestamp = now()->format('Ymd_His');
$backupPath = sys_get_temp_dir() . "/stock_sync_backup_{$tenantId}_{$timestamp}.json";

$backup = [
    'tenant_id' => $tenantId,
    'generated_at' => now()->toIso8601String(),
    'target_warehouse_id' => $targetWarehouse->id,
    'target_warehouse_code' => $targetWarehouse->code,
    'warehouses' => Warehouse::query()->orderBy('id')->get()->toArray(),
    'stock_levels' => StockLevel::query()->orderBy('warehouse_id')->orderBy('product_id')->get()->toArray(),
    'product_stock' => Product::query()->orderBy('id')->get(['id', 'name', 'stock_quantity'])->toArray(),
];

file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$products = Product::query()
    ->orderBy('id')
    ->get(['id', 'name', 'secondary_name', 'barcode', 'stock_quantity']);

$summary = [
    'tenant_id' => $tenantId,
    'target_warehouse_id' => $targetWarehouse->id,
    'target_warehouse_code' => $targetWarehouse->code,
    'backup_path' => $backupPath,
    'dry_run' => $dryRun,
    'deactivate_others' => $deactivateOthers,
    'products_considered' => $products->count(),
    'matched_products' => 0,
    'missing_products' => 0,
    'positive_products' => 0,
    'sum_quantity' => 0,
    'local_only_keys' => 0,
    'sample_missing_products' => [],
];

$matchedKeys = [];

$applySync = function () use ($products, $desiredQuantities, &$summary, &$matchedKeys, $targetWarehouse, $deactivateOthers) {
    $inventoryService = app(InventoryService::class);

    foreach ($products as $product) {
        try {
            $inventoryService->adjustStock(
                productId: $product->id,
                warehouseId: $targetWarehouse->id,
                quantity: 0,
                type: 'adjustment',
                reference: 'sync_identity_stock',
                referenceType: 'Script',
                referenceId: 0,
            );
        } catch (\Exception $e) {
            fwrite(STDERR, "Warning: Failed to zero stock for product {$product->id}: {$e->getMessage()}\n");
        }
    }

    Warehouse::whereKey($targetWarehouse->id)->update([
        'is_active' => true,
        'is_default' => true,
    ]);

    $otherWarehouses = Warehouse::query()->whereKeyNot($targetWarehouse->id);
    $otherWarehouses->update([
        'is_default' => false,
    ]);

    if ($deactivateOthers) {
        $otherWarehouses->update([
            'is_active' => false,
        ]);
    }

    foreach ($products as $product) {
        $identity = buildIdentityKey($product->barcode, $product->name, $product->secondary_name);
        $desiredQuantity = $desiredQuantities[$identity] ?? 0;

        if (array_key_exists($identity, $desiredQuantities)) {
            $summary['matched_products']++;
            $matchedKeys[$identity] = true;
        } else {
            $summary['missing_products']++;
            if (count($summary['sample_missing_products']) < 10) {
                $summary['sample_missing_products'][] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'barcode' => $product->barcode,
                ];
            }
        }

        if ($desiredQuantity > 0) {
            $summary['positive_products']++;
            $summary['sum_quantity'] += $desiredQuantity;
        }

        if ((int) $product->stock_quantity !== $desiredQuantity) {
            try {
                $inventoryService = app(InventoryService::class);
                
                $inventoryService->adjustStock(
                    productId: $product->id,
                    warehouseId: $targetWarehouse->id,
                    quantity: $desiredQuantity,
                    type: 'adjustment',
                    reference: 'sync_identity_stock',
                    referenceType: 'Script',
                    referenceId: 0,
                );
            } catch (\Exception $e) {
                fwrite(STDERR, "Warning: Failed to adjust stock for product {$product->id}: {$e->getMessage()}\n");
            }
        }
    }
};

if ($dryRun) {
    foreach ($products as $product) {
        $identity = buildIdentityKey($product->barcode, $product->name, $product->secondary_name);
        $desiredQuantity = $desiredQuantities[$identity] ?? 0;

        if (array_key_exists($identity, $desiredQuantities)) {
            $summary['matched_products']++;
            $matchedKeys[$identity] = true;
        } else {
            $summary['missing_products']++;
            if (count($summary['sample_missing_products']) < 10) {
                $summary['sample_missing_products'][] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'barcode' => $product->barcode,
                ];
            }
        }

        if ($desiredQuantity > 0) {
            $summary['positive_products']++;
            $summary['sum_quantity'] += $desiredQuantity;
        }
    }
} else {
    DB::transaction($applySync);
}

$summary['local_only_keys'] = count(array_diff_key($desiredQuantities, $matchedKeys));

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

function buildIdentityKey(?string $barcode, ?string $name, ?string $secondaryName): string
{
    return mb_strtolower(trim((string) $barcode))
        . '|'
        . mb_strtolower(trim((string) $name))
        . '|'
        . mb_strtolower(trim((string) ($secondaryName ?? '')));
}