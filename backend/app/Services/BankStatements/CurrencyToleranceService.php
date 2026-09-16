<?php

namespace App\Services\BankStatements;

use App\Models\Currency;

class CurrencyToleranceService
{
    public function for(string $currencyCode): string
    {
        $places = Currency::whereKey(strtoupper($currencyCode))->value('decimal_places');
        if ($places === null) {
            throw new \DomainException("Currency {$currencyCode} is not configured.");
        }

        return number_format(10 ** -((int) $places), 8, '.', '');
    }

    public function within(string|float $left, string|float $right, string|float $tolerance): bool
    {
        return abs((float) $left - (float) $right) <= (float) $tolerance;
    }
}
