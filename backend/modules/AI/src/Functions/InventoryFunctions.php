<?php

namespace Modules\AI\Functions;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\StockLevel;

/**
 * Inventory Functions
 *
 * AI-callable functions for inventory and stock queries
 */
class InventoryFunctions
{
    public static function checkStock(array $args): array
    {
        try {
            $productId = $args['product_id'] ?? null;
            $sku = $args['sku'] ?? null;
            $warehouseId = $args['warehouse_id'] ?? null;

            if (! $productId && ! $sku) {
                return [
                    'success' => false,
                    'error' => 'Please provide either product_id or sku',
                ];
            }

            $productQuery = Product::query()->where('is_active', true);
            $product = $productId
                ? $productQuery->find($productId)
                : $productQuery->where('sku', $sku)->first();

            if (! $product) {
                return [
                    'success' => false,
                    'error' => 'Product not found',
                ];
            }

            if (! $product->track_inventory) {
                return [
                    'success' => true,
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'track_inventory' => false,
                    ],
                    'message' => 'Inventory is not tracked for this product.',
                ];
            }

            if ($warehouseId) {
                $level = StockLevel::query()
                    ->with(['warehouse:id,name,code'])
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouseId)
                    ->first();

                if (! $level) {
                    return [
                        'success' => true,
                        'product' => [
                            'id' => $product->id,
                            'name' => $product->name,
                            'sku' => $product->sku,
                        ],
                        'warehouse_id' => (int) $warehouseId,
                        'message' => 'No warehouse stock level found for this product in that warehouse.',
                    ];
                }

                return [
                    'success' => true,
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                    ],
                    'warehouse' => [
                        'id' => $level->warehouse_id,
                        'name' => $level->warehouse?->name,
                        'code' => $level->warehouse?->code,
                    ],
                    'quantity' => (int) $level->quantity,
                    'reserved_quantity' => (int) $level->reserved_quantity,
                    'available_quantity' => (int) $level->available_quantity,
                    'min_quantity' => (int) $level->min_quantity,
                    'is_low_stock' => $level->isLowStock(),
                    'is_out_of_stock' => $level->isOutOfStock(),
                ];
            }

            return [
                'success' => true,
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ],
                'stock_quantity' => (int) $product->stock_quantity,
                'min_stock_threshold' => (int) $product->min_stock_threshold,
                'reorder_level' => (int) $product->reorder_level,
                'is_low_stock' => $product->isLowStock(),
                'is_out_of_stock' => $product->isOutOfStock(),
            ];
        } catch (\Exception $e) {
            Log::error('InventoryFunctions::checkStock failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Unable to check stock at this time',
            ];
        }
    }

    public static function listLowStock(array $args = []): array
    {
        try {
            $warehouseId = $args['warehouse_id'] ?? null;
            $limit = (int) ($args['limit'] ?? 10);
            $limit = max(1, min(50, $limit));

            if ($warehouseId) {
                $items = StockLevel::query()
                    ->with([
                        'product:id,name,sku,track_inventory',
                        'warehouse:id,name,code',
                    ])
                    ->where('warehouse_id', $warehouseId)
                    ->where('min_quantity', '>', 0)
                    ->whereColumn('quantity', '<=', 'min_quantity')
                    ->orderBy('quantity')
                    ->take($limit)
                    ->get()
                    ->map(function (StockLevel $level) {
                        return [
                            'product_id' => $level->product_id,
                            'product_name' => $level->product?->name,
                            'sku' => $level->product?->sku,
                            'warehouse_id' => $level->warehouse_id,
                            'warehouse_name' => $level->warehouse?->name,
                            'warehouse_code' => $level->warehouse?->code,
                            'quantity' => (int) $level->quantity,
                            'min_quantity' => (int) $level->min_quantity,
                            'available_quantity' => (int) $level->available_quantity,
                        ];
                    })
                    ->toArray();

                return [
                    'success' => true,
                    'warehouse_id' => (int) $warehouseId,
                    'found' => count($items),
                    'items' => $items,
                ];
            }

            $items = Product::query()
                ->where('is_active', true)
                ->where('track_inventory', true)
                ->where('min_stock_threshold', '>', 0)
                ->whereColumn('stock_quantity', '<=', 'min_stock_threshold')
                ->orderBy('stock_quantity')
                ->select('id', 'name', 'sku', 'stock_quantity', 'min_stock_threshold', 'reorder_level')
                ->take($limit)
                ->get()
                ->map(function (Product $product) {
                    return [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'stock_quantity' => (int) $product->stock_quantity,
                        'min_stock_threshold' => (int) $product->min_stock_threshold,
                        'reorder_level' => (int) $product->reorder_level,
                    ];
                })
                ->toArray();

            return [
                'success' => true,
                'found' => count($items),
                'items' => $items,
                'note' => 'Low stock uses product.min_stock_threshold (> 0).',
            ];
        } catch (\Exception $e) {
            Log::error('InventoryFunctions::listLowStock failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Unable to list low stock items',
            ];
        }
    }

    public static function listOutOfStock(array $args = []): array
    {
        try {
            $warehouseId = $args['warehouse_id'] ?? null;
            $limit = (int) ($args['limit'] ?? 10);
            $limit = max(1, min(50, $limit));

            if ($warehouseId) {
                $items = StockLevel::query()
                    ->with([
                        'product:id,name,sku,track_inventory',
                        'warehouse:id,name,code',
                    ])
                    ->where('warehouse_id', $warehouseId)
                    ->where('quantity', '<=', 0)
                    ->orderBy('product_id')
                    ->take($limit)
                    ->get()
                    ->map(function (StockLevel $level) {
                        return [
                            'product_id' => $level->product_id,
                            'product_name' => $level->product?->name,
                            'sku' => $level->product?->sku,
                            'warehouse_id' => $level->warehouse_id,
                            'warehouse_name' => $level->warehouse?->name,
                            'warehouse_code' => $level->warehouse?->code,
                            'quantity' => (int) $level->quantity,
                            'available_quantity' => (int) $level->available_quantity,
                        ];
                    })
                    ->toArray();

                return [
                    'success' => true,
                    'warehouse_id' => (int) $warehouseId,
                    'found' => count($items),
                    'items' => $items,
                ];
            }

            $items = Product::query()
                ->where('is_active', true)
                ->where('track_inventory', true)
                ->where('stock_quantity', '<=', 0)
                ->orderBy('name')
                ->select('id', 'name', 'sku', 'stock_quantity')
                ->take($limit)
                ->get()
                ->map(function (Product $product) {
                    return [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'stock_quantity' => (int) $product->stock_quantity,
                    ];
                })
                ->toArray();

            return [
                'success' => true,
                'found' => count($items),
                'items' => $items,
            ];
        } catch (\Exception $e) {
            Log::error('InventoryFunctions::listOutOfStock failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Unable to list out of stock items',
            ];
        }
    }

    public static function listWarehouses(array $args = []): array
    {
        try {
            $warehouses = Warehouse::query()
                ->where('is_active', true)
                ->select('id', 'name', 'code', 'is_default')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(function (Warehouse $warehouse) {
                    return [
                        'id' => $warehouse->id,
                        'name' => $warehouse->name,
                        'code' => $warehouse->code,
                        'is_default' => (bool) $warehouse->is_default,
                    ];
                })
                ->toArray();

            return [
                'success' => true,
                'total' => count($warehouses),
                'warehouses' => $warehouses,
            ];
        } catch (\Exception $e) {
            Log::error('InventoryFunctions::listWarehouses failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Unable to list warehouses',
            ];
        }
    }

    public static function listRecentStockMovements(array $args = []): array
    {
        try {
            $warehouseId = $args['warehouse_id'] ?? null;
            $limit = (int) ($args['limit'] ?? 10);
            $limit = max(1, min(50, $limit));

            $query = StockMovement::query()
                ->with([
                    'product:id,name,sku',
                    'warehouse:id,name,code',
                ])
                ->orderByDesc('created_at');

            if ($warehouseId) {
                $query->where('warehouse_id', $warehouseId);
            }

            $movements = $query
                ->take($limit)
                ->get()
                ->map(function (StockMovement $movement) {
                    return [
                        'id' => $movement->id,
                        'type' => $movement->type,
                        'quantity' => (int) $movement->quantity,
                        'product_id' => $movement->product_id,
                        'product_name' => $movement->product?->name,
                        'sku' => $movement->product?->sku,
                        'warehouse_id' => $movement->warehouse_id,
                        'warehouse_name' => $movement->warehouse?->name,
                        'warehouse_code' => $movement->warehouse?->code,
                        'reference' => $movement->reference,
                        'created_at' => $movement->created_at?->toISOString(),
                    ];
                })
                ->toArray();

            return [
                'success' => true,
                'found' => count($movements),
                'warehouse_id' => $warehouseId ? (int) $warehouseId : null,
                'movements' => $movements,
            ];
        } catch (\Exception $e) {
            Log::error('InventoryFunctions::listRecentStockMovements failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Unable to list stock movements',
            ];
        }
    }
}
