<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'barcode',
        'cost',
        'price',
        'tax_rate',
        'track_inventory',
        'stock_quantity',
        'reorder_level',
        'unit',
        'attributes',
        'is_active',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'track_inventory' => 'bool',
        'attributes' => 'array',
        'is_active' => 'bool',
    ];

    protected $appends = ['stock'];

    public function getStockAttribute()
    {
        return $this->stock_quantity;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Adjust stock for this variant.
     *
     * @deprecated All new code must call InventoryService::adjustStock() directly.
     *             This method retains backward compatibility but now delegates to
     *             InventoryService so that stock_levels stays authoritative.
     *             No warehouse context is available here — default warehouse is used.
     */
    public function adjustStock(float $quantity, string $type, ?string $reference = null): void
    {
        $defaultWarehouse = Warehouse::getDefault();

        if ($defaultWarehouse) {
            try {
                app(\Modules\Inventory\Services\InventoryService::class)->adjustStock(
                    productId: $this->product_id,
                    warehouseId: $defaultWarehouse->id,
                    quantity: abs($quantity),
                    type: $type,
                    reference: $reference,
                    referenceType: 'ProductVariant',
                    referenceId: $this->id,
                );

                return;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('ProductVariant::adjustStock delegation failed', [
                    'variant_id' => $this->id,
                    'product_id' => $this->product_id,
                    'type' => $type,
                    'quantity' => $quantity,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        // Hard fail: no warehouse means no trackable stock mutation.
        throw new \RuntimeException(
            "Cannot adjust stock for variant {$this->id} (product {$this->product_id}): no warehouse available. "
            .'All stock mutations must go through InventoryService with an explicit warehouse.'
        );
    }
}
