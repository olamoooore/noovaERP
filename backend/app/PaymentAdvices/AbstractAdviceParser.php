<?php

declare(strict_types=1);

namespace App\PaymentAdvices;

/**
 * Shared regex helpers for the bank advice parsers. OCR text is best-effort:
 * lookups are whitespace-tolerant and fall back to null rather than inventing
 * a value.
 */
abstract class AbstractAdviceParser
{
    abstract public function bankCode(): string;

    abstract public function parse(string $text): ParsedAdvice;

    protected function first(string $pattern, string $text): ?string
    {
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }

        $value = trim((string) ($m[1] ?? ''));

        return $value !== '' ? $value : null;
    }

    protected function money(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(' ', '', trim($value));

        // European grouping can leak from OCR ("8.938,00"): thousands dots and
        // a decimal comma — convert before stripping separators.
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $normalized) === 1) {
            $normalized = str_replace(['.', ','], ['', '.'], $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }

        return is_numeric($normalized) ? round((float) $normalized, 2) : null;
    }

    /** Normalise an OCR date (dd/mm/yyyy, dd-MMM-yyyy) to Y-m-d when possible. */
    protected function isoDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        foreach (['d/m/Y', 'd-m-Y', 'j M Y', 'd M Y', 'd-M-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    protected function cleanLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $value !== '' ? $value : null;
    }
}
