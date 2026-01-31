<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerPriceList extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'discount_type',
        'discount_value',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CustomerPriceListItem::class, 'price_list_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'price_list_id');
    }

    /**
     * Check if price list is currently valid
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }
        
        $today = now()->toDateString();
        
        if ($this->valid_from && $today < $this->valid_from) {
            return false;
        }
        
        if ($this->valid_until && $today > $this->valid_until) {
            return false;
        }
        
        return true;
    }
}
