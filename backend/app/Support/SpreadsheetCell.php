<?php

namespace App\Support;

/**
 * Report CSVs are opened in spreadsheets by reviewers. Free text (names)
 * starting with = + - @ or a tab/CR would run as a formula there
 * (CSV/formula injection), so such a value is prefixed with an apostrophe.
 */
final class SpreadsheetCell
{
    public static function escape(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
