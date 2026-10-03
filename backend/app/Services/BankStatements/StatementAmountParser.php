<?php

namespace App\Services\BankStatements;

/**
 * Issue #834 (BK2): the ONE amount parser for bank statement imports.
 * Local exports use trailing/leading signs, DR/CR markers, parentheses,
 * currency codes/symbols and thousands separators — the old
 * (float) str_replace() turned "1,234.56-" into +1234.56 and "RM 1,234.56"
 * into 0 (silently skipped as a zero row).
 */
final class StatementAmountParser
{
    /** Currency codes accepted as a prefix (word chars directly after a digit boundary). */
    private const CURRENCY_CODES = 'RM|MYR|SGD|USD|EUR|GBP|IDR|THB|PHP|VND|HKD|CNY|AUD|NZD|JPY|SEK|NOK|DKK|CHF|INR|AED|SAR|KWD|BHD|QAR|OMR|ZAR|NZD|TWD|KRW';

    /** Currency symbols accepted as a prefix. */
    private const CURRENCY_SYMBOLS = '[$\x{20AC}\x{00A3}\x{00A5}\x{20B9}]|S\$|R\$|HK\$|US\$|A\$';

    /**
     * Parse an amount. Returns 0.0 only for a genuinely empty cell; anything
     * that cannot be parsed throws — callers must surface it as a row error
     * naming the field, never coerce it to 0.
     */
    public function parse(string $raw, string $field): float
    {
        $value = trim($raw);
        if ($value === '') {
            // An empty mapped cell means "no amount here" (e.g. the debit
            // column on a credit row) — not an unparsable value.
            return 0.0;
        }

        $negative = false;

        // Parentheses: (1,234.56) is an accounting negative.
        if (preg_match('/^\((.*)\)$/u', $value, $m)) {
            $negative = true;
            $value = trim($m[1]);
        }

        // Trailing DR/CR marker (1,234.56 DR / 1,234.56DR / 1,234.56 cr).
        if (preg_match('/^(.*?)\s*(DR|CR)\s*$/iu', $value, $m)) {
            $negative = $negative || strtoupper($m[2]) === 'DR';
            $value = trim($m[1]);
        }

        // Leading DR/CR marker (DR 1,234.56 / CR 1,234.56).
        if (preg_match('/^(DR|CR)\s+/iu', $value, $m)) {
            $negative = $negative || strtoupper($m[1]) === 'DR';
            $value = substr($value, strlen($m[0]));
        }

        // Leading sign.
        if (preg_match('/^([+-])\s*/u', $value, $m)) {
            if ($m[1] === '-') {
                $negative = true;
            }
            $value = substr($value, strlen($m[0]));
        }

        // Trailing sign: 1,234.56- / 1234.56 -
        if (preg_match('/^(.*[0-9])\s*-$/u', $value, $m)) {
            $negative = true;
            $value = $m[1];
        }

        // Currency codes (RM, SGD, ...) then symbols ($, S$, ...).
        if (preg_match('/^(?:'.self::CURRENCY_CODES.')/iu', $value, $m, PREG_OFFSET_CAPTURE)
            && preg_match('/\d/u', substr($value, strlen($m[0][0])))) {
            $value = substr($value, strlen($m[0][0]));
        }
        if (preg_match('/^(?:'.self::CURRENCY_SYMBOLS.')\s*/u', $value)) {
            $value = preg_replace('/^(?:'.self::CURRENCY_SYMBOLS.')\s*/u', '', $value, 1);
        }
        $value = ltrim($value);

        // Thousands separators: comma, space, NBSP.
        $value = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], '', $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/u', $value)) {
            throw new UnparsableAmountException($field, $raw);
        }

        $amount = (float) $value;

        return $negative ? -$amount : $amount;
    }
}
