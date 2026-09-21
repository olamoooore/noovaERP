<?php

namespace App\Services;

use App\Models\CompanySettings;

/**
 * Issue #98: single source of truth for an AR/AP document's currency.
 *
 * AR/AP documents are denominated in the tenant's base currency unless an
 * explicit currency is supplied at creation. Before this, the ar_transactions
 * and ap_transactions views hardcoded 'USD' for invoice/bill/payment rows
 * regardless of the tenant's actual base currency (SGD/MYR/…), so AR/AP
 * reconciliation and reporting silently reported fabricated currency data.
 */
class DocumentCurrencyResolver
{
    public const FALLBACK = 'USD';

    public static function resolve(?string $explicit = null): string
    {
        if (is_string($explicit)) {
            $explicit = trim($explicit);
            if ($explicit !== '') {
                return strtoupper(substr($explicit, 0, 3));
            }
        }

        $base = CompanySettings::query()->value('base_currency');

        return is_string($base) && trim($base) !== ''
            ? strtoupper(substr(trim($base), 0, 3))
            : self::FALLBACK;
    }
}
