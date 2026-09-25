<?php

namespace App\Services;

use App\Models\CompanySettings;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;

/**
 * Exchange Rate Service
 * Handles currency conversion and rate management
 */
class ExchangeRateService
{
    /**
     * Get the latest exchange rate for a currency pair.
     *
     * Resolution order: direct rate → inverse rate → cross rate via the
     * tenant's base currency. Rates are normally stored only against the
     * base (SGD→USD, SGD→MYR), so without triangulation every non-base pair
     * (USD→EUR, GBP→MYR, …) failed with "No exchange rate found" and the
     * converter returned 422.
     */
    public function getLatestRate(string $fromCurrency, string $toCurrency, $asOfDate = null)
    {
        if ($fromCurrency === $toCurrency) {
            return [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'rate' => 1.0,
                'effective_date' => $asOfDate ?? now()->format('Y-m-d'),
                'source' => 'same_currency',
            ];
        }

        $resolved = $this->resolveDirectOrInverse($fromCurrency, $toCurrency, $asOfDate);
        if ($resolved) {
            return $resolved;
        }

        // Cross rate via the base currency: X→BASE × BASE→Y.
        $base = CompanySettings::query()->value('base_currency') ?: 'USD';

        if ($base !== $fromCurrency && $base !== $toCurrency) {
            $leg1 = $this->resolveDirectOrInverse($fromCurrency, $base, $asOfDate);
            $leg2 = $this->resolveDirectOrInverse($base, $toCurrency, $asOfDate);

            if ($leg1 && $leg2) {
                return [
                    'from_currency' => $fromCurrency,
                    'to_currency' => $toCurrency,
                    'rate' => (float) $leg1['rate'] * (float) $leg2['rate'],
                    'effective_date' => min($leg1['effective_date'], $leg2['effective_date']),
                    'source' => 'cross_via_'.$base,
                ];
            }
        }

        return null;
    }

    /**
     * Resolve a pair from a stored direct rate, falling back to the inverse.
     *
     * @return array{from_currency:string,to_currency:string,rate:float,effective_date:string,source:string}|null
     */
    protected function resolveDirectOrInverse(string $fromCurrency, string $toCurrency, $asOfDate = null): ?array
    {
        $rate = ExchangeRate::getLatestRate($fromCurrency, $toCurrency, $asOfDate);
        if ($rate) {
            return [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'rate' => (float) $rate->rate,
                'effective_date' => $rate->effective_date->format('Y-m-d'),
                'source' => $rate->source ?? 'manual',
            ];
        }

        $inverseRate = ExchangeRate::getLatestRate($toCurrency, $fromCurrency, $asOfDate);
        if ($inverseRate) {
            return [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'rate' => 1 / (float) $inverseRate->rate,
                'effective_date' => $inverseRate->effective_date->format('Y-m-d'),
                'source' => 'inverse_'.($inverseRate->source ?? 'manual'),
            ];
        }

        return null;
    }

    /**
     * Convert an amount from one currency to another
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency, $asOfDate = null): array
    {
        if ($fromCurrency === $toCurrency) {
            return [
                'original_amount' => $amount,
                'original_currency' => $fromCurrency,
                'converted_amount' => $amount,
                'converted_currency' => $toCurrency,
                'exchange_rate' => 1.0,
                'effective_date' => $asOfDate ?? now()->format('Y-m-d'),
            ];
        }

        $rate = $this->getLatestRate($fromCurrency, $toCurrency, $asOfDate);

        if (! $rate) {
            throw new \Exception("No exchange rate found for {$fromCurrency} to {$toCurrency}");
        }

        $rateValue = is_array($rate) ? $rate['rate'] : (float) $rate->rate;
        $convertedAmount = $amount * $rateValue;

        return [
            'original_amount' => $amount,
            'original_currency' => $fromCurrency,
            'converted_amount' => round($convertedAmount, 2),
            'converted_currency' => $toCurrency,
            'exchange_rate' => $rateValue,
            'effective_date' => is_array($rate) ? $rate['effective_date'] : $rate->effective_date->format('Y-m-d'),
        ];
    }

    /**
     * Calculate exchange gain/loss
     */
    public function calculateExchangeGainLoss(
        float $originalAmount,
        string $originalCurrency,
        float $originalRate,
        float $currentRate,
        string $baseCurrency
    ): array {
        $originalBaseAmount = $originalAmount * $originalRate;
        $currentBaseAmount = $originalAmount * $currentRate;
        $gainLoss = $currentBaseAmount - $originalBaseAmount;

        return [
            'original_base_amount' => $originalBaseAmount,
            'current_base_amount' => $currentBaseAmount,
            'gain_loss' => $gainLoss,
            'is_gain' => $gainLoss > 0,
            'is_loss' => $gainLoss < 0,
        ];
    }

    /**
     * Fetch the raw rate map (currency code => rate) for a base currency.
     *
     * Uses the keyed exchangerate-api.com provider when a key is configured.
     * Otherwise falls back to keyless public providers so rate syncing works
     * out of the box (the keyed provider alone silently blocked every sync
     * because no EXCHANGE_RATE_API_KEY was ever configured).
     *
     * @return array<string,float>
     */
    protected function fetchRates(string $baseCurrency): array
    {
        $apiKey = config('services.exchange_rate.api_key') ?: env('EXCHANGE_RATE_API_KEY');

        if ($apiKey) {
            $response = Http::timeout(15)->get("https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$baseCurrency}");

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch rates from API');
            }

            return $response->json('conversion_rates') ?? [];
        }

        // Keyless provider 1: open.er-api.com (broad currency coverage).
        try {
            $response = Http::timeout(15)->get("https://open.er-api.com/v6/latest/{$baseCurrency}");
            if ($response->successful() && $response->json('result') !== 'error') {
                $rates = $response->json('rates') ?? [];
                if (! empty($rates)) {
                    return $rates;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the next provider.
        }

        // Keyless provider 2: frankfurter.app (ECB reference rates).
        try {
            $response = Http::timeout(15)->get('https://api.frankfurter.app/latest', ['from' => $baseCurrency]);
            if ($response->successful()) {
                $rates = $response->json('rates') ?? [];
                if (! empty($rates)) {
                    return $rates;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the failure below.
        }

        throw new \Exception('Unable to fetch exchange rates from any provider (no API key configured and keyless providers unavailable)');
    }

    /**
     * Sync exchange rates from an external provider.
     *
     * Configured provider: exchangerate-api.com (EXCHANGE_RATE_API_KEY).
     * Fallback providers (keyless): open.er-api.com, frankfurter.app.
     */
    public function syncRatesFromAPI(string $baseCurrency, array $targetCurrencies): array
    {
        $results = [];

        try {
            $rates = $this->fetchRates($baseCurrency);
            $effectiveDate = now()->format('Y-m-d');

            foreach ($targetCurrencies as $targetCurrency) {
                if (! isset($rates[$targetCurrency])) {
                    $results[$targetCurrency] = [
                        'success' => false,
                        'message' => 'Rate not available from API',
                    ];

                    continue;
                }

                $rate = ExchangeRate::updateOrCreate(
                    [
                        'from_currency' => $baseCurrency,
                        'to_currency' => $targetCurrency,
                        'effective_date' => $effectiveDate,
                    ],
                    [
                        'rate' => $rates[$targetCurrency],
                        'source' => 'api',
                    ]
                );

                $results[$targetCurrency] = [
                    'success' => true,
                    'rate' => $rates[$targetCurrency],
                    'effective_date' => $effectiveDate,
                ];
            }

        } catch (\Exception $e) {
            throw new \Exception('Failed to sync rates: '.$e->getMessage());
        }

        return $results;
    }

    /**
     * Get all rates for a specific date
     */
    public function getRatesForDate(string $baseCurrency, $date = null): array
    {
        $date = $date ?? now()->format('Y-m-d');

        $rates = ExchangeRate::where('from_currency', $baseCurrency)
            ->where('effective_date', '<=', $date)
            ->orderBy('to_currency')
            ->orderBy('effective_date', 'desc')
            ->get()
            ->unique('to_currency')
            ->pluck('rate', 'to_currency')
            ->toArray();

        return $rates;
    }

    /**
     * Validate if a rate exists for a currency pair
     */
    public function hasRate(string $fromCurrency, string $toCurrency, $asOfDate = null): bool
    {
        if ($fromCurrency === $toCurrency) {
            return true;
        }

        $rate = $this->getLatestRate($fromCurrency, $toCurrency, $asOfDate);

        return $rate !== null;
    }

    /**
     * Get exchange rate trend (for charts)
     */
    public function getRateTrend(string $fromCurrency, string $toCurrency, int $days = 30): array
    {
        $startDate = now()->subDays($days)->format('Y-m-d');

        $rates = ExchangeRate::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('effective_date', '>=', $startDate)
            ->orderBy('effective_date', 'asc')
            ->get();

        return $rates->map(function ($rate) {
            return [
                'date' => $rate->effective_date->format('Y-m-d'),
                'rate' => (float) $rate->rate,
            ];
        })->toArray();
    }
}
