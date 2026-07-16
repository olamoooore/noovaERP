<?php

namespace Modules\OCRBookkeeping\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Exchange Rate Service
 *
 * Fetches and caches exchange rates for multi-currency support
 */
class ExchangeRateService
{
    /**
     * Base currency (company's functional currency)
     */
    private string $baseCurrency;

    /**
     * Exchange rate API URL
     */
    private string $apiUrl;

    /**
     * Cache duration in minutes
     */
    private int $cacheDuration;

    public function __construct()
    {
        $this->baseCurrency = config('app.currency', env('BASE_CURRENCY', 'USD'));
        $this->apiUrl = env('EXCHANGE_RATE_API_URL', 'https://api.exchangerate-api.com/v4/latest/');
        $this->cacheDuration = (int) env('EXCHANGE_RATE_CACHE_MINUTES', 1440); // 24 hours default
    }

    /**
     * Get exchange rate for a specific date and currency
     *
     * @param  string  $fromCurrency  Source currency code
     * @param  string  $toCurrency  Target currency code (defaults to base currency)
     * @param  Carbon|string|null  $date  Date for historical rate (null = today)
     * @return float Exchange rate
     *
     * @throws \Exception
     */
    public function getRate(string $fromCurrency, ?string $toCurrency = null, $date = null): float
    {
        $toCurrency = $toCurrency ?? $this->baseCurrency;

        // If same currency, rate is 1
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        // Normalize date
        $date = $date ? Carbon::parse($date) : Carbon::now();
        $dateKey = $date->format('Y-m-d');

        // Check cache
        $cacheKey = "exchange_rate:{$fromCurrency}:{$toCurrency}:{$dateKey}";

        if (Cache::has($cacheKey)) {
            return (float) Cache::get($cacheKey);
        }

        // Fetch from API
        try {
            $rate = $this->fetchRateFromApi($fromCurrency, $toCurrency, $date);

            // Cache the rate
            Cache::put($cacheKey, $rate, now()->addMinutes($this->cacheDuration));

            return $rate;

        } catch (\Exception $e) {
            Log::error('Failed to fetch exchange rate', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'date' => $dateKey,
                'error' => $e->getMessage(),
            ]);

            throw new \Exception("Exchange rate not available for {$fromCurrency} to {$toCurrency} on {$dateKey}. Please enter manually.");
        }
    }

    /**
     * Fetch exchange rate from external API
     *
     * @throws \Exception
     */
    private function fetchRateFromApi(string $fromCurrency, string $toCurrency, Carbon $date): float
    {
        // Note: Most free APIs only provide current rates, not historical
        // For production, consider using a paid service like:
        // - https://exchangeratesapi.io/
        // - https://openexchangerates.org/
        // - https://fixer.io/

        $response = Http::timeout(10)->get("{$this->apiUrl}{$fromCurrency}");

        if (! $response->successful()) {
            throw new \Exception('Exchange rate API request failed');
        }

        $data = $response->json();

        if (! isset($data['rates'][$toCurrency])) {
            throw new \Exception("Exchange rate not found for {$toCurrency}");
        }

        return (float) $data['rates'][$toCurrency];
    }

    /**
     * Convert amount from one currency to another
     *
     * @param  float  $amount  Amount to convert
     * @param  string  $fromCurrency  Source currency
     * @param  string|null  $toCurrency  Target currency (defaults to base)
     * @param  Carbon|string|null  $date  Date for exchange rate
     * @return float Converted amount
     */
    public function convert(float $amount, string $fromCurrency, ?string $toCurrency = null, $date = null): float
    {
        $rate = $this->getRate($fromCurrency, $toCurrency, $date);

        return round($amount * $rate, 2);
    }

    /**
     * Store manual exchange rate
     *
     * @param  Carbon|string  $date
     */
    public function storeManualRate(string $fromCurrency, string $toCurrency, float $rate, $date): void
    {
        $date = Carbon::parse($date);
        $dateKey = $date->format('Y-m-d');
        $cacheKey = "exchange_rate:{$fromCurrency}:{$toCurrency}:{$dateKey}";

        // Store in cache
        Cache::put($cacheKey, $rate, now()->addYears(1)); // Long cache for manual rates

        Log::info('Manual exchange rate stored', [
            'from' => $fromCurrency,
            'to' => $toCurrency,
            'rate' => $rate,
            'date' => $dateKey,
        ]);
    }

    /**
     * Get base currency
     */
    public function getBaseCurrency(): string
    {
        return $this->baseCurrency;
    }

    /**
     * Check if currency conversion is needed
     */
    public function needsConversion(string $currency): bool
    {
        return $currency !== $this->baseCurrency;
    }
}
