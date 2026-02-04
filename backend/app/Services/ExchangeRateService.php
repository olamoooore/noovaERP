<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\Currency;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Exchange Rate Service
 * Handles currency conversion and rate management
 */
class ExchangeRateService
{
    /**
     * Get the latest exchange rate for a currency pair
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

        $rate = ExchangeRate::getLatestRate($fromCurrency, $toCurrency, $asOfDate);

        if (!$rate) {
            // Try inverse rate
            $inverseRate = ExchangeRate::getLatestRate($toCurrency, $fromCurrency, $asOfDate);
            if ($inverseRate) {
                return [
                    'from_currency' => $fromCurrency,
                    'to_currency' => $toCurrency,
                    'rate' => 1 / (float) $inverseRate->rate,
                    'effective_date' => $inverseRate->effective_date->format('Y-m-d'),
                    'source' => 'inverse_' . $inverseRate->source,
                ];
            }
        }

        return $rate;
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

        if (!$rate) {
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
     * Sync exchange rates from external API
     * Using exchangerate-api.com as an example (free tier available)
     */
    public function syncRatesFromAPI(string $baseCurrency, array $targetCurrencies): array
    {
        $results = [];

        try {
            // Example using exchangerate-api.com
            // You would need to sign up for an API key
            $apiKey = env('EXCHANGE_RATE_API_KEY');
            
            if (!$apiKey) {
                throw new \Exception('Exchange rate API key not configured');
            }

            $response = Http::get("https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$baseCurrency}");

            if (!$response->successful()) {
                throw new \Exception('Failed to fetch rates from API');
            }

            $data = $response->json();
            $rates = $data['conversion_rates'] ?? [];
            $effectiveDate = now()->format('Y-m-d');

            foreach ($targetCurrencies as $targetCurrency) {
                if (!isset($rates[$targetCurrency])) {
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
            throw new \Exception('Failed to sync rates: ' . $e->getMessage());
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
