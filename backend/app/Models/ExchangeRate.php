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
     * Get the latest rate for a currency pair.
     *
     * MRD pricelist-multicurrency FR-1.3/FR-1.4: selection is "latest
     * effective_date ≤ asOfDate", with a deterministic tie-break — manual
     * rows win over api/auto rows for the same date, then newest id. Without
     * this ordering two rows for the same pair+date returned whichever row
     * Postgres happened to yield first, silently letting an overnight API
     * sync hide a hand-verified rate.
     */
    public static function getLatestRate(string $fromCurrency, string $toCurrency, $asOfDate = null)
    {
        $query = static::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency);

        if ($asOfDate) {
            $query->where('effective_date', '<=', $asOfDate);
        }

        return static::applyManualPriority($query)
            ->first();
    }

    /**
     * Earliest rate AFTER the cutoff restricted to manual rows — the only
     * future-dated fallback the MRD allows (FR-9.2: future rates may be used
     * solely when explicitly entered, never an API row).
     */
    public static function getEarliestManualRateAfter(string $fromCurrency, string $toCurrency, $asOfDate): ?self
    {
        return static::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('source', 'manual')
            ->where('effective_date', '>', $asOfDate)
            ->orderBy('effective_date', 'asc')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Shared ordering: newest date → manual first → newest id (FR-1.4).
     */
    public static function applyManualPriority($query)
    {
        return $query
            ->orderBy('effective_date', 'desc')
            ->orderByRaw("(CASE WHEN source = 'manual' THEN 0 ELSE 1 END) ASC")
            ->orderBy('id', 'desc');
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

        if (! $rate) {
            throw new \Exception("No exchange rate found for {$fromCurrency} to {$toCurrency}");
        }

        return $amount * (float) $rate->rate;
    }

    /**
     * Scope to get rates for a specific date
     */
    public function scopeForDate($query, $date)
    {
        return static::applyManualPriority(
            $query->where('effective_date', '<=', $date)
        );
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
