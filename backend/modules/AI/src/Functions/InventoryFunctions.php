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
                $base = StockLevel::query()
                    ->with([
                        'product:id,name,sku,track_inventory',
                        'warehouse:id,name,code',
                    ])
                    ->where('warehouse_id', $warehouseId)
                    ->where('quantity', '>', 0)
                    ->where(function ($query) {
                        $query->whereRaw('quantity <= NULLIF(min_quantity, 0)')
                            ->orWhere(function ($fallback) {
                                $fallback->whereRaw('(min_quantity IS NULL OR min_quantity = 0)')
                                    ->where('quantity', '<=', 10);
                            });
                    });

                $total = (clone $base)->count();

                $items = (clone $base)
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
                    'total_count' => $total,
                    'found' => count($items),
                    'items' => $items,
                    'note' => 'total_count is the complete number of low-stock entries; items is a sample.',
                ];
            }

            // Same source of truth as the inventory dashboard: low stock
            // comes from stock_levels (per-warehouse min_quantity, falling
            // back to a threshold of 10 when no minimum is configured).
            $base = StockLevel::query()
                ->with(['product:id,name,sku,track_inventory,is_active', 'warehouse:id,name,code'])
                ->where('quantity', '>', 0)
                ->where(function ($query) {
                    $query->whereRaw('quantity <= NULLIF(min_quantity, 0)')
                        ->orWhere(function ($fallback) {
                            $fallback->whereRaw('(min_quantity IS NULL OR min_quantity = 0)')
                                ->where('quantity', '<=', 10);
                        });
                })
                ->whereHas('product', fn ($q) => $q->where('is_active', true)->where('track_inventory', true));

            $total = (clone $base)->distinct()->count('product_id');

            $items = (clone $base)
                ->orderBy('quantity')
                ->take($limit)
                ->get()
                ->map(function (StockLevel $level) {
                    return [
                        'product_id' => $level->product_id,
                        'product_name' => $level->product?->name,
                        'sku' => $level->product?->sku,
                        'warehouse_name' => $level->warehouse?->name,
                        'quantity' => (int) $level->quantity,
                        'min_quantity' => (int) $level->min_quantity,
                    ];
                })
                ->toArray();

            return [
                'success' => true,
                'total_count' => $total,
                'found' => count($items),
                'items' => $items,
                'note' => 'total_count is the complete number of low-stock products; items is a sample. Always report total_count when the user asks how many.',
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
                $base = StockLevel::query()
                    ->with([
                        'product:id,name,sku,track_inventory',
                        'warehouse:id,name,code',
                    ])
                    ->where('warehouse_id', $warehouseId)
                    ->where('quantity', '<=', 0);

                $total = (clone $base)->count();

                $items = (clone $base)
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
                    'total_count' => $total,
                    'found' => count($items),
                    'items' => $items,
                    'note' => 'total_count is the complete number of out-of-stock entries; items is a sample.',
                ];
            }

            // Same source of truth as the inventory dashboard: a product is
            // out of stock when it tracks inventory and has NO stock level
            // with a positive quantity (product.stock_quantity is legacy).
            $base = Product::query()
                ->where('is_active', true)
                ->where('track_inventory', true)
                ->whereDoesntHave('stockLevels', fn ($q) => $q->where('quantity', '>', 0));

            $total = (clone $base)->count();

            $items = (clone $base)
                ->orderBy('name')
                ->select('id', 'name', 'sku')
                ->take($limit)
                ->get()
                ->map(function (Product $product) {
                    return [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'stock_quantity' => 0,
                    ];
                })
                ->toArray();

            return [
                'success' => true,
                'total_count' => $total,
                'found' => count($items),
                'items' => $items,
                'note' => 'total_count is the complete number of out-of-stock products; items is a sample (up to the requested limit). Always report total_count when the user asks how many.',
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
