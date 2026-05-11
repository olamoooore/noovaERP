<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\EventManagement\Models\Event as EventManagementEvent;
use Modules\Inventory\Models\Brand;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\Supplier;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Product Model - Central Master Data Hub
 * Comprehensive ERP product management with full lifecycle support
 */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    // Status constants
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISCONTINUED = 'discontinued';
    public const STATUS_ARCHIVED = 'archived';

    // Tracking method constants
    public const TRACKING_NONE = 'none';
    public const TRACKING_QUANTITY = 'quantity';
    public const TRACKING_BATCH = 'batch';
    public const TRACKING_SERIAL = 'serial';

    // Product types
    public const TYPE_PRODUCT = 'product';
    public const TYPE_SERVICE = 'service';

    protected $fillable = [
        // Basic Info
        'name',
        'secondary_name',
        'slug',
        'sku',
        'barcode',
        'barcodes',
        'description',
        'short_description',
        'category_id',
        'brand_id',
        'type',
        'is_digital',
        'is_subscription',
        'download_url',
        'license_key',
        
        // Bundle
        'is_bundle',
        'bundle_type',
        
        // Pricing
        'cost',
        'price',
        'wholesale_price',
        'msrp',
        'min_price',
        'max_price',
        
        // Tax
        'tax_rate',
        'tax_mode',
        'tax_included',
        'tax_rate_override',
        'tax_id',
        
        // Accounting
        'income_account_id',
        'expense_account_id',
        
        // Inventory
        'track_inventory',
        'tracking_method',
        // NOTE: 'stock_quantity' removed from fillable - it is computed from stock_levels
        // All stock mutations MUST go through InventoryService::adjustStock()
        'reorder_level',
        'safety_stock',
        'max_stock',
        'reorder_quantity',
        'min_stock_threshold',
        'unit',
        
        // Physical Attributes
        'weight',
        'length',
        'width',
        'height',
        'dimensional_weight',
        
        // Storage & Handling
        'is_hazmat',
        'hazmat_class',
        'storage_conditions',
        'is_fragile',
        'shelf_life_days',
        
        // Compliance
        'hs_code',
        'country_of_origin',
        'warranty_months',
        'warranty_type',
        'certifications',
        
        // Manufacturer
        'manufacturer',
        'manufacturer_part_number',
        
        // Media
        'image',
        'gallery',
        
        // SEO
        'meta_title',
        'meta_description',
        'meta_keywords',
        
        // Localization
        'localized_names',
        'localized_descriptions',
        
        // Attributes
        'attributes',
        
        // Status & Flags
        'status',
        'is_active',
        'is_preorder',
        'is_new_arrival',
        'is_merchandise',
        'show_on_website',
        'preorder_available_at',
        
        // Approval
        'approved_by',
        'approved_at',
        'discontinued_by',
        'discontinued_at',
    ];

    protected $casts = [
        // Pricing
        'cost' => 'decimal:2',
        'price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'msrp' => 'decimal:2',
        'min_price' => 'decimal:2',
        'max_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_rate_override' => 'decimal:2',
        
        // Physical
        'weight' => 'decimal:3',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'dimensional_weight' => 'decimal:3',
        
        // Booleans
        'tax_included' => 'boolean',
        'track_inventory' => 'boolean',
        'is_active' => 'boolean',
        'is_preorder' => 'boolean',
        'is_new_arrival' => 'boolean',
        'is_merchandise' => 'boolean',
        'show_on_website' => 'boolean',
        'is_hazmat' => 'boolean',
        'is_fragile' => 'boolean',
        'is_digital' => 'boolean',
        'is_subscription' => 'boolean',
        'is_bundle' => 'boolean',
        
        // Dates
        'preorder_available_at' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'discontinued_at' => 'datetime',
        
        // JSON
        'gallery' => 'array',
        'attributes' => 'array',
        'barcodes' => 'array',
        'certifications' => 'array',
        'localized_names' => 'array',
        'localized_descriptions' => 'array',
    ];

    protected $appends = ['stock', 'effective_tax_rate', 'effective_tax_is_inclusive'];

    // ==================== RELATIONSHIPS ====================

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'income_account_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function discontinuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discontinued_by');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function defaultVariant()
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    public function featureValues(): HasMany
    {
        return $this->hasMany(ProductFeatureValue::class);
    }

    public function customFieldValues(): HasMany
    {
        return $this->hasMany(ProductCustomFieldValue::class);
    }

    // UOMs
    public function uoms(): HasMany
    {
        return $this->hasMany(ProductUom::class);
    }

    // Suppliers
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_suppliers')
            ->withPivot([
                'supplier_sku', 'supplier_barcode', 'cost', 'last_cost',
                'lead_time_days', 'moq', 'order_multiple', 'priority',
                'is_preferred', 'last_purchase_date', 'notes', 'is_active'
            ])
            ->withTimestamps();
    }

    public function productSuppliers(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    public function preferredSupplier()
    {
        return $this->productSuppliers()->where('is_preferred', true)->first();
    }

    // Bundles
    public function bundleComponents(): HasMany
    {
        return $this->hasMany(ProductBundle::class, 'bundle_product_id');
    }

    public function partOfBundles(): HasMany
    {
        return $this->hasMany(ProductBundle::class, 'component_product_id');
    }

    public function linkedEvents(): BelongsToMany
    {
        return $this->belongsToMany(EventManagementEvent::class, 'event_product_links')
            ->withPivot(['relation_type', 'sort_order', 'is_featured'])
            ->withTimestamps();
    }

    public function merchandiseEvents(): BelongsToMany
    {
        return $this->linkedEvents()->wherePivot('relation_type', 'merchandise');
    }

    public function bundleEvents(): BelongsToMany
    {
        return $this->linkedEvents()->wherePivot('relation_type', 'bundle');
    }

    // Relationships (cross-sell, up-sell, etc.)
    public function relationships(): HasMany
    {
        return $this->hasMany(ProductRelationship::class);
    }

    public function crossSellProducts()
    {
        return $this->relationships()->where('type', 'cross_sell')->with('relatedProduct');
    }

    public function upSellProducts()
    {
        return $this->relationships()->where('type', 'up_sell')->with('relatedProduct');
    }

    public function substituteProducts()
    {
        return $this->relationships()->where('type', 'substitute')->with('relatedProduct');
    }

    public function accessoryProducts()
    {
        return $this->relationships()->where('type', 'accessory')->with('relatedProduct');
    }

    // Pricing
    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class);
    }

    // Batch & Serial
    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class);
    }

    // Documents
    public function documents(): HasMany
    {
        return $this->hasMany(ProductDocument::class);
    }

    // Audit
    public function auditLogs(): HasMany
    {
        return $this->hasMany(ProductAuditLog::class);
    }

    // ==================== ACCESSORS ====================

    public function getStockAttribute(): int
    {
        return $this->stock_quantity ?? 0;
    }

    /**
     * Get effective tax rate - uses product's tax_rate or falls back to default system tax
     * This ensures POS always has access to the correct tax rate
     */
    public function getEffectiveTaxRateAttribute(): float
    {
        // If product has its own tax rate set, use it
        if ($this->tax_rate !== null && (float) $this->tax_rate > 0) {
            return (float) $this->tax_rate;
        }

        // If product is linked to a Tax model, use that rate
        if ($this->tax_id) {
            $productTax = $this->tax;
            if ($productTax && $productTax->rate > 0) {
                return (float) $productTax->rate;
            }
        }

        // Fall back to default system tax from settings
        $taxSetting = Setting::getValue('tax', [
            'enabled' => true,
            'default_tax_id' => null,
        ]);

        if (!empty($taxSetting['enabled']) && !empty($taxSetting['default_tax_id'])) {
            $defaultTax = Tax::query()->whereKey($taxSetting['default_tax_id'])->first();
            if ($defaultTax) {
                return (float) $defaultTax->rate;
            }
        }

        return 0.0;
    }

    /**
     * Get whether the effective tax is inclusive - uses product's tax or falls back to default system tax
     * This ensures POS always knows whether to add tax on top or extract from price
     */
    public function getEffectiveTaxIsInclusiveAttribute(): bool
    {
        // If product uses custom tax mode, use the product's tax_included setting
        if ($this->tax_mode === 'custom') {
            return (bool) $this->tax_included;
        }

        // If product is linked to a Tax model, use that tax's is_inclusive
        if ($this->tax_id) {
            $productTax = $this->tax;
            if ($productTax) {
                return (bool) $productTax->is_inclusive;
            }
        }

        // Fall back to default system tax from settings
        $taxSetting = Setting::getValue('tax', [
            'enabled' => true,
            'default_tax_id' => null,
        ]);

        if (!empty($taxSetting['enabled']) && !empty($taxSetting['default_tax_id'])) {
            $defaultTax = Tax::query()->whereKey($taxSetting['default_tax_id'])->first();
            if ($defaultTax) {
                return (bool) $defaultTax->is_inclusive;
            }
        }

        // Default to exclusive (tax added on top)
        return false;
    }

    public function getVolumeAttribute(): ?float
    {
        if ($this->length && $this->width && $this->height) {
            return $this->length * $this->width * $this->height;
        }
        return null;
    }

    public function getMarginAttribute(): float
    {
        if (!$this->cost || $this->cost == 0) {
            return 100;
        }
        return (($this->price - $this->cost) / $this->price) * 100;
    }

    public function getMarkupAttribute(): float
    {
        if (!$this->cost || $this->cost == 0) {
            return 0;
        }
        return (($this->price - $this->cost) / $this->cost) * 100;
    }

    // ==================== STATUS METHODS ====================

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function isActiveStatus(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDiscontinued(): bool
    {
        return $this->status === self::STATUS_DISCONTINUED;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function submitForApproval(): void
    {
        if ($this->status === self::STATUS_DRAFT) {
            $this->status = self::STATUS_PENDING_APPROVAL;
            $this->save();
            ProductAuditLog::log($this->id, ProductAuditLog::ACTION_STATUS_CHANGED, 'status', 'draft', 'pending_approval');
        }
    }

    public function approve(): void
    {
        if ($this->status === self::STATUS_PENDING_APPROVAL) {
            $this->status = self::STATUS_ACTIVE;
            $this->approved_by = TenantUserHelper::resolveTenantUserId();
            $this->approved_at = now();
            $this->save();
            ProductAuditLog::log($this->id, ProductAuditLog::ACTION_APPROVED, 'status', 'pending_approval', 'active');
        }
    }

    public function discontinue(): void
    {
        $oldStatus = $this->status;
        $this->status = self::STATUS_DISCONTINUED;
        $this->discontinued_by = TenantUserHelper::resolveTenantUserId();
        $this->discontinued_at = now();
        $this->save();
        ProductAuditLog::log($this->id, ProductAuditLog::ACTION_DISCONTINUED, 'status', $oldStatus, 'discontinued');
    }

    public function archive(): void
    {
        $oldStatus = $this->status;
        $this->status = self::STATUS_ARCHIVED;
        $this->save();
        ProductAuditLog::log($this->id, ProductAuditLog::ACTION_STATUS_CHANGED, 'status', $oldStatus, 'archived');
    }

    // ==================== STOCK METHODS ====================

    public function getEffectiveLowStockThreshold(): int
    {
        return (int) ($this->min_stock_threshold ?: ($this->reorder_level ?: 10));
    }

    public function isLowStock(): bool
    {
        return (bool) $this->track_inventory
            && $this->stock_quantity > 0
            && $this->stock_quantity <= $this->getEffectiveLowStockThreshold();
    }

    public function isOutOfStock(): bool
    {
        return $this->stock_quantity <= 0;
    }

    public function needsReorder(): bool
    {
        return $this->stock_quantity <= $this->reorder_level;
    }

    public function getStockInWarehouse(int $warehouseId): int
    {
        $stockLevel = $this->stockLevels()->where('warehouse_id', $warehouseId)->first();
        return $stockLevel ? $stockLevel->quantity : 0;
    }

    public function getAvailableStockInWarehouse(int $warehouseId): int
    {
        $stockLevel = $this->stockLevels()->where('warehouse_id', $warehouseId)->first();
        return $stockLevel ? $stockLevel->available_quantity : 0;
    }

    public function getTotalAvailableStock(): int
    {
        return (int) $this->stockLevels()->get()->sum('available_quantity');
    }

    /**
     * Adjust stock for this product.
     *
     * @deprecated This method is retained for backward compatibility only.
     *             All new code MUST call InventoryService::adjustStock() directly so that
     *             stock_levels (the operational source of truth) and stock_movements
     *             are updated atomically. Direct mutation of products.stock_quantity
     *             via this method bypasses warehouse tracking and the inventory ledger.
     *
     * Migration path: replace $product->adjustStock($qty, $type, $ref) with
     *   app(InventoryService::class)->adjustStock($productId, $warehouseId, $qty, $type, $ref)
     *
     * @internal This method is now private to prevent new usage. Existing callers must migrate.
     */
    private function adjustStock(int $quantity, string $type, ?string $reference = null, ?int $warehouseId = null): void
    {
        // Resolve warehouse: use provided ID, else fall back to the default warehouse.
        // Without a warehouse all stock changes are untrackable at the location level.
        $resolvedWarehouseId = $warehouseId ?? Warehouse::getDefault()?->id;

        if ($resolvedWarehouseId) {
            // Delegate to the central InventoryService so stock_levels stays authoritative.
            // products.stock_quantity will be re-synced inside adjustStock() via updateProductTotalStock().
            try {
                app(\Modules\Inventory\Services\InventoryService::class)->adjustStock(
                    productId:   $this->id,
                    warehouseId: $resolvedWarehouseId,
                    quantity:    abs($quantity),
                    type:        $type,
                    reference:   $reference,
                );
                return;
            } catch (\Throwable $e) {
                // Log the failure but do not silently swallow critical stock errors
                \Illuminate\Support\Facades\Log::error('Product::adjustStock delegation failed', [
                    'product_id'  => $this->id,
                    'warehouse_id' => $resolvedWarehouseId,
                    'type'        => $type,
                    'quantity'    => $quantity,
                    'error'       => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        // Hard fail: no warehouse means no trackable stock mutation.
        // All callers must provide or resolve a warehouse.
        throw new \RuntimeException(
            "Cannot adjust stock for product {$this->id}: no warehouse available. "
            . "All stock mutations must go through InventoryService with an explicit warehouse."
        );
    }

    // ==================== PRICING METHODS ====================

    public function getPriceForQuantity(int $quantity, ?int $customerId = null): float
    {
        // Check for customer-specific pricing first
        if ($customerId) {
            $customerTier = $this->priceTiers()
                ->where('customer_id', $customerId)
                ->where('is_active', true)
                ->where('min_quantity', '<=', $quantity)
                ->where(function ($q) use ($quantity) {
                    $q->whereNull('max_quantity')
                      ->orWhere('max_quantity', '>=', $quantity);
                })
                ->orderBy('min_quantity', 'desc')
                ->first();

            if ($customerTier) {
                return $customerTier->calculatePrice($this->price);
            }
        }

        // Check for volume pricing
        $tier = $this->priceTiers()
            ->whereNull('customer_id')
            ->where('is_active', true)
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($q) use ($quantity) {
                $q->whereNull('max_quantity')
                  ->orWhere('max_quantity', '>=', $quantity);
            })
            ->orderBy('min_quantity', 'desc')
            ->first();

        if ($tier) {
            return $tier->calculatePrice($this->price);
        }

        return (float) $this->price;
    }

    // ==================== BUNDLE METHODS ====================

    public function getBundleTotalCost(): float
    {
        if (!$this->is_bundle) {
            return (float) $this->cost;
        }

        return $this->bundleComponents->sum(function ($component) {
            return $component->componentProduct->cost * $component->quantity;
        });
    }

    public function getBundleTotalPrice(): float
    {
        if (!$this->is_bundle) {
            return (float) $this->price;
        }

        return $this->bundleComponents->sum(function ($component) {
            return $component->discounted_price;
        });
    }

    public function canFulfillBundle(int $quantity = 1): bool
    {
        if (!$this->is_bundle) {
            return $this->getTotalAvailableStock() >= $quantity;
        }

        foreach ($this->bundleComponents as $component) {
            if ($component->is_optional) {
                continue;
            }
            
            $needed = $component->quantity * $quantity;
            if ($component->componentProduct->getTotalAvailableStock() < $needed) {
                return false;
            }
        }

        return true;
    }

    // ==================== STATIC METHODS ====================

    public static function findSellableByBarcodeOrSku(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        // Check variants first
        $variant = ProductVariant::query()
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)
                  ->orWhere('sku', $code)
                  ->orWhereJsonContains('barcodes', $code);
            })
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($variant) {
            return [
                'type' => 'variant',
                'variant' => $variant,
                'product' => $variant->product,
            ];
        }

        // Check products
        $product = Product::query()
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)
                  ->orWhere('sku', $code)
                  ->orWhereJsonContains('barcodes', $code);
            })
            ->where('is_active', true)
            ->where('status', self::STATUS_ACTIVE)
            ->orderBy('id')
            ->first();

        if (!$product) {
            return null;
        }

        return [
            'type' => 'product',
            'product' => $product,
        ];
    }

    /**
     * Find ALL sellable products/variants by barcode or SKU
     * Used when multiple matches exist and user needs to select
     */
    public static function findAllSellableByBarcodeOrSku(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return [];
        }

        $results = [];

        // Check variants
        $variants = ProductVariant::query()
            ->with('product')
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)
                  ->orWhere('sku', $code)
                  ->orWhereJsonContains('barcodes', $code);
            })
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($variants as $variant) {
            $results[] = [
                'type' => 'variant',
                'variant' => $variant,
                'product' => $variant->product,
                'display_name' => $variant->product->name . ' - ' . $variant->name,
                'sku' => $variant->sku,
                'price' => $variant->price,
            ];
        }

        // Check products
        $products = Product::query()
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)
                  ->orWhere('sku', $code)
                  ->orWhereJsonContains('barcodes', $code);
            })
            ->where('is_active', true)
            ->where('status', self::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        foreach ($products as $product) {
            $results[] = [
                'type' => 'product',
                'product' => $product,
                'display_name' => $product->name,
                'sku' => $product->sku,
                'price' => $product->price,
            ];
        }

        return $results;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_ACTIVE,
            self::STATUS_DISCONTINUED,
            self::STATUS_ARCHIVED,
        ];
    }

    public static function trackingMethods(): array
    {
        return [
            self::TRACKING_NONE,
            self::TRACKING_QUANTITY,
            self::TRACKING_BATCH,
            self::TRACKING_SERIAL,
        ];
    }

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            // Generate slug if not provided
            if (empty($product->slug)) {
                $product->slug = \Illuminate\Support\Str::slug($product->name);
                $count = static::where('slug', 'like', $product->slug . '%')->count();
                if ($count > 0) {
                    $product->slug .= '-' . ($count + 1);
                }
            }

            // Set default status
            if (empty($product->status)) {
                $product->status = self::STATUS_ACTIVE;
            }
        });

        static::created(function ($product) {
            ProductAuditLog::log($product->id, ProductAuditLog::ACTION_CREATED);
        });

        static::updating(function ($product) {
            // Log changes
            $original = $product->getOriginal();
            $changes = $product->getDirty();
            ProductAuditLog::logChanges($product->id, $original, $changes);
        });

        static::deleted(function ($product) {
            ProductAuditLog::log($product->id, ProductAuditLog::ACTION_DELETED);
        });
    }
}
