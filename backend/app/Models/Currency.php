<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Currency - Supported currencies for multi-currency transactions
 */
class Currency extends Model
{
    use HasFactory;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimal_places',
        'is_active',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get exchange rates where this is the from currency
     */
    public function exchangeRatesFrom(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'from_currency', 'code');
    }

    /**
     * Get exchange rates where this is the to currency
     */
    public function exchangeRatesTo(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'to_currency', 'code');
    }

    /**
     * Format an amount according to currency settings
     */
    public function formatAmount(float $amount): string
    {
        return $this->symbol.' '.number_format($amount, $this->decimal_places);
    }

    /**
     * Scope to get only active currencies
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the latest exchange rate to another currency
     */
    public function getLatestRateTo(string $toCurrency)
    {
        return ExchangeRate::where('from_currency', $this->code)
            ->where('to_currency', $toCurrency)
            ->orderBy('effective_date', 'desc')
            ->first();
    }
}
