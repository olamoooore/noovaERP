<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlashSale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'campaign_key',
        'name',
        'description',
        'product_ids',
        'image_url',
        'discount_percent',
        'discount_amount',
        'max_quantity',
        'quantity_remaining',
        'starts_at',
        'ends_at',
        'is_active',
        'show_in_waiting_room',
        'metadata',
    ];

    protected $casts = [
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'max_quantity' => 'integer',
        'quantity_remaining' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'show_in_waiting_room' => 'boolean',
        'metadata' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCurrentlyRunning($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            });
    }

    public function scopeShowInWaitingRoom($query)
    {
        return $query->where('show_in_waiting_room', true);
    }

    public function isActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }
        if ($this->ends_at && $this->ends_at->isPast()) {
            return false;
        }

        return true;
    }

    public function isAvailable(): bool
    {
        if (! $this->isActive()) {
            return false;
        }
        if ($this->max_quantity !== null && $this->quantity_remaining <= 0) {
            return false;
        }

        return true;
    }

    public function getProductIdsArrayAttribute(): array
    {
        if (! $this->product_ids) {
            return [];
        }

        return array_map('intval', explode(',', $this->product_ids));
    }

    public function decrementQuantity(int $quantity = 1): bool
    {
        if ($this->quantity_remaining === null) {
            return true;
        }
        if ($this->quantity_remaining < $quantity) {
            return false;
        }
        $this->decrement('quantity_remaining', $quantity);

        return true;
    }

    public function getDiscountDisplayAttribute(): string
    {
        if ($this->discount_percent) {
            return round($this->discount_percent, 0).'% OFF';
        }
        if ($this->discount_amount) {
            return '$'.number_format($this->discount_amount, 2).' OFF';
        }

        return '';
    }
}
