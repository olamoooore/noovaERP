<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySettings extends Model
{
    protected $table = 'inventory_settings';

    protected $fillable = [
        'allow_negative_stock',
        'auto_generate_sku',
        'sku_prefix',
        'sku_length',
        'track_batch_numbers',
        'track_expiry_dates',
        'require_adjustment_approval',
        'low_stock_threshold_days',
        'enable_barcode_scanning',
        'sme_mode',
    ];

    protected $casts = [
        'allow_negative_stock' => 'boolean',
        'auto_generate_sku' => 'boolean',
        'sku_length' => 'integer',
        'track_batch_numbers' => 'boolean',
        'track_expiry_dates' => 'boolean',
        'require_adjustment_approval' => 'boolean',
        'low_stock_threshold_days' => 'integer',
        'enable_barcode_scanning' => 'boolean',
        'sme_mode' => 'boolean',
    ];

    public static function current(): self
    {
        return static::first() ?? static::create([]);
    }
}
