<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exchange Rate - Currency conversion rates
 */
class ExchangeRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'effective_date',
        'source',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'effective_date' => 'date',
    ];

    /**
     * Get the from currency
     */
    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'from_currency', 'code');
    }

    /**
     * Get the to currency
     */
    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'to_currency', 'code');
    }

    /**
     * Get the latest rate for a currency pair
     */
    public static function getLatestRate(string $fromCurrency, string $toCurrency, $asOfDate = null)
    {
        $query = static::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency);

        if ($asOfDate) {
            $query->where('effective_date', '<=', $asOfDate);
        }

        return $query->orderBy('effective_date', 'desc')->first();
    }

    /**
     * Convert an amount from one currency to another
     */
    public static function convert(float $amount, string $fromCurrency, string $toCurrency, $asOfDate = null): float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        $rate = static::getLatestRate($fromCurrency, $toCurrency, $asOfDate);

        if (!$rate) {
            throw new \Exception("No exchange rate found for {$fromCurrency} to {$toCurrency}");
        }

        return $amount * (float) $rate->rate;
    }

    /**
     * Scope to get rates for a specific date
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('effective_date', '<=', $date)
            ->orderBy('effective_date', 'desc');
    }

    /**
     * Scope to get rates for a currency pair
     */
    public function scopeForPair($query, string $fromCurrency, string $toCurrency)
    {
        return $query->where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency);
    }
}
