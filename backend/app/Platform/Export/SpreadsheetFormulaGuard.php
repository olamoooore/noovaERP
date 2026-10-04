<?php

namespace App\Platform\Export;

/**
 * Issue #898 / #583: shared CSV/spreadsheet formula-injection guard.
 *
 * A string cell that starts with a spreadsheet formula trigger is prefixed
 * with an apostrophe (the OWASP recommendation) so Excel / Google Sheets
 * treat it as inert text instead of executing it. Every CSV writer that emits
 * user-controlled strings must pass them through here.
 */
class SpreadsheetFormulaGuard
{
    public static function neutralise(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
