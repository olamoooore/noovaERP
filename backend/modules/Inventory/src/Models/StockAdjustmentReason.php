<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentReason extends Model
{
    protected $fillable = [
        'name',
        'code',
        'type',
        'requires_approval',
        'is_active',
    ];

    protected $casts = [
        'requires_approval' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForType($query, string $type)
    {
        return $query->where(function ($q) use ($type) {
            $q->where('type', $type)->orWhere('type', 'both');
        });
    }
}
