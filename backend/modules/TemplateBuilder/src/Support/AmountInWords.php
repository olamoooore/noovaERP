<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Support;

use App\Models\Currency;

/**
 * Issue #614 (TP4): amount-in-words for printed invoices.
 *
 * No number-to-words helper existed in the repository (checked backend,
 * frontend and the OCR service), so this is the single formatting owner. It
 * renders the document total in the document currency and language:
 *   "Malaysian Ringgit Eight Thousand Fifty-Six Only"
 *   "Malaysian Ringgit Eight Thousand Fifty-Six and Sen Twenty-Five Only"
 *
 * The spoken currency name comes from the `currencies` table (already seeded
 * per tenant); the static map is only a no-DB/sample fallback. Minor units
 * (Sen/Cent) are linguistic, not statutory rates.
 */
final class AmountInWords
{
    /** @var array<int,string> */
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    /** @var array<int,string> */
    private const TENS = [
        2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
        6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety',
    ];

    /** @var array<int,string> */
    private const SCALES = [
        1_000_000_000_000 => 'Trillion',
        1_000_000_000 => 'Billion',
        1_000_000 => 'Million',
        1_000 => 'Thousand',
    ];

    /** @var array<string,string> */
    private const CURRENCY_NAMES = [
        'AUD' => 'Australian Dollar',
        'CNY' => 'Chinese Yuan',
        'EUR' => 'Euro',
        'GBP' => 'British Pound',
        'IDR' => 'Indonesian Rupiah',
        'INR' => 'Indian Rupee',
        'JPY' => 'Japanese Yen',
        'MYR' => 'Malaysian Ringgit',
        'SGD' => 'Singapore Dollar',
        'THB' => 'Thai Baht',
        'USD' => 'US Dollar',
    ];

    /** @var array<string,string> */
    private const MINOR_UNITS = [
        'MYR' => 'Sen',
        'IDR' => 'Sen',
        'INR' => 'Paise',
        'SGD' => 'Cent',
        'USD' => 'Cent',
        'AUD' => 'Cent',
    ];

    /** @var array<int,string> */
    private const MS_ONES = [
        'Kosong', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Lapan',
        'Sembilan', 'Sepuluh', 'Sebelas', 'Dua Belas', 'Tiga Belas', 'Empat Belas',
        'Lima Belas', 'Enam Belas', 'Tujuh Belas', 'Lapan Belas', 'Sembilan Belas',
    ];

    /** @var array<int,string> */
    private const MS_TENS = [
        2 => 'Dua Puluh', 3 => 'Tiga Puluh', 4 => 'Empat Puluh', 5 => 'Lima Puluh',
        6 => 'Enam Puluh', 7 => 'Tujuh Puluh', 8 => 'Lapan Puluh', 9 => 'Sembilan Puluh',
    ];

    public static function format(float $amount, string $currencyCode, ?string $language = null): string
    {
        $code = strtoupper(trim($currencyCode));
        $lang = strtolower(substr(trim((string) ($language ?: 'en')), 0, 2));

        $absolute = abs($amount);
        $whole = (int) floor($absolute + 0.0000001);
        $cents = (int) round(($absolute - $whole) * 100);

        if ($cents === 100) {
            $whole++;
            $cents = 0;
        }

        $currencyName = self::currencyName($code);

        if ($lang === 'ms') {
            $words = self::malay($whole);
            $phrase = $currencyName !== '' ? $currencyName.' '.$words : $words;
            if ($cents > 0) {
                $phrase .= ' dan '.self::minorUnit($code).' '.self::malay($cents);
            }

            return trim($phrase.' Sahaja');
        }

        $words = self::english($whole);
        $phrase = $currencyName !== '' ? $currencyName.' '.$words : $words;
        if ($cents > 0) {
            $phrase .= ' and '.self::minorUnit($code).' '.self::english($cents);
        }

        return trim($phrase.' Only');
    }

    public static function currencyName(string $code): string
    {
        try {
            $name = Currency::query()->whereKey(strtoupper($code))->value('name');
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        } catch (\Throwable) {
            // No tenant/DB context (catalog sample): fall through to the map.
        }

        return self::CURRENCY_NAMES[strtoupper($code)] ?? strtoupper($code);
    }

    private static function minorUnit(string $code): string
    {
        return self::MINOR_UNITS[strtoupper($code)] ?? 'Cent';
    }

    private static function english(int $number): string
    {
        if ($number < 20) {
            return self::ONES[$number] !== '' ? self::ONES[$number] : 'Zero';
        }

        if ($number < 100) {
            $tens = intdiv($number, 10);
            $rest = $number % 10;

            return self::TENS[$tens].($rest > 0 ? '-'.self::ONES[$rest] : '');
        }

        if ($number < 1000) {
            $hundreds = intdiv($number, 100);
            $rest = $number % 100;

            return self::ONES[$hundreds].' Hundred'.($rest > 0 ? ' '.self::english($rest) : '');
        }

        foreach (self::SCALES as $scale => $label) {
            if ($number >= $scale) {
                $rest = $number % $scale;

                return self::english(intdiv($number, $scale)).' '.$label
                    .($rest > 0 ? ' '.self::english($rest) : '');
            }
        }

        return (string) $number;
    }

    private static function malay(int $number): string
    {
        if ($number < 20) {
            return self::MS_ONES[$number] !== '' ? self::MS_ONES[$number] : 'Kosong';
        }

        if ($number < 100) {
            $tens = intdiv($number, 10);
            $rest = $number % 10;

            return self::MS_TENS[$tens].($rest > 0 ? ' '.self::MS_ONES[$rest] : '');
        }

        if ($number < 1000) {
            $hundreds = intdiv($number, 100);
            $rest = $number % 100;
            $prefix = $hundreds === 1 ? 'Seratus' : self::MS_ONES[$hundreds].' Ratus';

            return $prefix.($rest > 0 ? ' '.self::malay($rest) : '');
        }

        foreach (self::SCALES as $scale => $label) {
            if ($number >= $scale) {
                $rest = $number % $scale;
                $count = intdiv($number, $scale);

                if ($label === 'Thousand' && $count === 1) {
                    return 'Seribu'.($rest > 0 ? ' '.self::malay($rest) : '');
                }

                $prefix = $count === 1 ? 'Satu' : self::malay($count);

                return $prefix.' '.self::MALAY_SCALES[$label].($rest > 0 ? ' '.self::malay($rest) : '');
            }
        }

        return (string) $number;
    }

    /** @var array<string,string> */
    private const MALAY_SCALES = [
        'Trillion' => 'Trilion',
        'Billion' => 'Bilion',
        'Million' => 'Juta',
        'Thousand' => 'Ribu',
    ];
}
