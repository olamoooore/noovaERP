<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/**
 * Formats monetary values using the company-wide currency preference.
 *
 * This is the backend counterpart of frontend/src/lib/utils.ts::formatCurrency().
 * Keeping the preference lookup here lets PDFs, emails, and other server-side
 * output use the same currency code/display choice as the application UI.
 */
final class TenantCurrencyFormatter
{
    /** @param array{code?:string,display_format?:string}|null $preferencesOverride */
    public function __construct(private readonly ?array $preferencesOverride = null) {}

    /** @var array<string,string> */
    private const SYMBOLS = [
        'AED' => 'AED ',
        'AUD' => 'A$',
        'BHD' => 'BHD ',
        'CNY' => '¥',
        'EUR' => '€',
        'GBP' => '£',
        'IDR' => 'Rp ',
        'INR' => '₹',
        'JPY' => '¥',
        'KWD' => 'KWD ',
        'MYR' => 'RM ',
        'OMR' => 'OMR ',
        'QAR' => 'QAR ',
        'SAR' => 'SAR ',
        'SGD' => 'S$',
        'THB' => '฿',
        'USD' => '$',
    ];

    /** @var array<string,string> */
    private const NARROW_SYMBOLS = [
        'AUD' => '$',
        'CNY' => '¥',
        'EUR' => '€',
        'GBP' => '£',
        'INR' => '₹',
        'JPY' => '¥',
        'SGD' => '$',
        'USD' => '$',
    ];

    public function format(mixed $amount, ?string $currencyCode = null, string $numberPattern = '1,234.56'): string
    {
        if (! is_numeric($amount)) {
            return (string) ($amount ?? '');
        }

        $preferences = $this->preferences();
        $code = strtoupper(trim($currencyCode ?: $preferences['code']));
        $value = (float) $amount;
        $number = $this->formatNumber(abs($value), $numberPattern);
        $sign = $value < 0 ? '-' : '';

        return $sign.$this->prefix($code, $preferences['display_format']).$number;
    }

    public function prefix(?string $currencyCode = null, ?string $displayFormat = null): string
    {
        $preferences = $this->preferences();
        $code = strtoupper(trim($currencyCode ?: $preferences['code']));
        $format = $displayFormat ?: $preferences['display_format'];

        return match ($format) {
            'code' => $code.' ',
            'narrow_symbol' => self::NARROW_SYMBOLS[$code] ?? self::SYMBOLS[$code] ?? $code.' ',
            default => self::SYMBOLS[$code] ?? $code.' ',
        };
    }

    /** @return array{code:string,display_format:string} */
    public function preferences(): array
    {
        $stored = $this->preferencesOverride;
        if ($stored === null) {
            try {
                $stored = Setting::getValue('currency', []);
            } catch (\Throwable) {
                $stored = [];
            }
        }

        if (! is_array($stored)) {
            $stored = [];
        }

        $code = strtoupper(trim((string) ($stored['code'] ?? 'USD')));
        $displayFormat = (string) ($stored['display_format'] ?? 'symbol');

        if (! in_array($displayFormat, ['symbol', 'narrow_symbol', 'code'], true)) {
            $displayFormat = 'symbol';
        }

        return [
            'code' => $code !== '' ? $code : 'USD',
            'display_format' => $displayFormat,
        ];
    }

    private function formatNumber(float $amount, string $pattern): string
    {
        $comma = strpos($pattern, ',');
        $dot = strpos($pattern, '.');
        $usesDecimalComma = $comma !== false && $dot !== false && $comma > $dot;

        return number_format($amount, 2, $usesDecimalComma ? ',' : '.', $usesDecimalComma ? '.' : ',');
    }
}
