<?php

namespace Modules\POS\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosPaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'type',
        'icon',
        'opens_cash_drawer',
        'requires_approval',
        'surcharge_percent',
        'surcharge_fixed',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'opens_cash_drawer' => 'boolean',
        'requires_approval' => 'boolean',
        'surcharge_percent' => 'decimal:2',
        'surcharge_fixed' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(PosPayment::class, 'payment_method_id');
    }

    /**
     * Calculate surcharge for an amount
     */
    public function calculateSurcharge(float $amount): float
    {
        $surcharge = 0;

        if ($this->surcharge_percent > 0) {
            $surcharge += $amount * ($this->surcharge_percent / 100);
        }

        if ($this->surcharge_fixed > 0) {
            $surcharge += $this->surcharge_fixed;
        }

        return round($surcharge, 2);
    }
}
