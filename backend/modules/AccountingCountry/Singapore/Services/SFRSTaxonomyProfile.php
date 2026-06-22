<?php

namespace Modules\AccountingCountry\Singapore\Services;

class SFRSTaxonomyProfile
{
    public const TAXONOMY_VERSION = 'ACRA-SFRS-2024';
    public const SCHEMA_HREF = 'sg-sfrs-2024.xsd';

    private const CODE_GROUP_RANGES = [
        'asset' => ['1000', '1999'],
        'liability' => ['2000', '2999'],
        'equity' => ['3000', '3999'],
        'revenue' => ['4000', '4999'],
        'expense' => ['5000', '5999'],
    ];

    public function taxonomyVersion(): string
    {
        return self::TAXONOMY_VERSION;
    }

    public function schemaHref(): string
    {
        return self::SCHEMA_HREF;
    }

    public function validateAccountMapping(string $accountCode, string $accountType): array
    {
        $errors = [];
        $accountType = strtolower(trim($accountType));
        $accountCode = trim($accountCode);

        if ($accountCode === '' || !preg_match('/^\d{4,}$/', $accountCode)) {
            $errors[] = 'Account code must be numeric and at least 4 digits';
        }

        if (!array_key_exists($accountType, self::CODE_GROUP_RANGES)) {
            $errors[] = "Unsupported account type: {$accountType}";
        }

        if (empty($errors)) {
            [$start, $end] = self::CODE_GROUP_RANGES[$accountType];
            if ($accountCode < $start || $accountCode > $end) {
                $errors[] = "Account code {$accountCode} is outside the expected {$accountType} range {$start}-{$end}";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function mapAccount(array $account): ?array
    {
        $code = (string) ($account['code'] ?? '');
        $type = strtolower((string) ($account['type'] ?? ''));
        $subtype = strtolower((string) ($account['subtype'] ?? ''));

        return match ($type) {
            'asset' => $this->mapAsset($code, $subtype),
            'liability' => $this->mapLiability($code, $subtype),
            'equity' => $this->mapEquity($code, $subtype),
            'revenue' => $this->mapRevenue($code, $subtype),
            'expense' => $this->mapExpense($code, $subtype),
            default => null,
        };
    }

    private function mapAsset(string $code, string $subtype): array
    {
        if ($subtype === 'current_asset') {
            if ($code >= '1000' && $code <= '1099') {
                return $this->concept('balance_sheet', 'assets.current_assets', 'CashAndCashEquivalents', 'Cash and cash equivalents', 'debit');
            }
            if ($code >= '1100' && $code <= '1199') {
                return $this->concept('balance_sheet', 'assets.current_assets', 'TradeAndOtherReceivables', 'Trade and other receivables', 'debit');
            }
            if ($code >= '1200' && $code <= '1299') {
                return $this->concept('balance_sheet', 'assets.current_assets', 'Inventories', 'Inventories', 'debit');
            }

            return $this->concept('balance_sheet', 'assets.current_assets', 'OtherCurrentAssets', 'Other current assets', 'debit');
        }

        if ($subtype === 'fixed_asset') {
            return $this->concept('balance_sheet', 'assets.non_current_assets', 'PropertyPlantAndEquipment', 'Property, plant and equipment', 'debit');
        }

        return $this->concept('balance_sheet', 'assets.non_current_assets', 'OtherNonCurrentAssets', 'Other non-current assets', 'debit');
    }

    private function mapLiability(string $code, string $subtype): array
    {
        if ($subtype === 'current_liability') {
            if ($code >= '2200' && $code <= '2299') {
                return $this->concept('balance_sheet', 'liabilities.current_liabilities', 'TaxPayables', 'Tax payables', 'credit');
            }

            return $this->concept('balance_sheet', 'liabilities.current_liabilities', 'TradeAndOtherPayables', 'Trade and other payables', 'credit');
        }

        return $this->concept('balance_sheet', 'liabilities.non_current_liabilities', 'OtherNonCurrentLiabilities', 'Other non-current liabilities', 'credit');
    }

    private function mapEquity(string $code, string $subtype): array
    {
        if ($code >= '3000' && $code <= '3099') {
            return $this->concept('balance_sheet', 'equity.capital_and_reserves', 'ShareCapital', 'Share capital', 'credit');
        }

        return $this->concept('balance_sheet', 'equity.capital_and_reserves', 'RetainedEarningsAndReserves', 'Retained earnings and reserves', 'credit');
    }

    private function mapRevenue(string $code, string $subtype): array
    {
        if ($subtype === 'other_revenue') {
            return $this->concept('income_statement', 'other_income', 'OtherIncome', 'Other income', 'credit');
        }

        return $this->concept('income_statement', 'revenue', 'Revenue', 'Revenue', 'credit');
    }

    private function mapExpense(string $code, string $subtype): array
    {
        if ($subtype === 'cost_of_goods_sold') {
            return $this->concept('income_statement', 'cost_of_goods_sold', 'CostOfSales', 'Cost of sales', 'debit');
        }

        if ($subtype === 'other_expense') {
            return $this->concept('income_statement', 'other_expenses', 'OtherExpenses', 'Other expenses', 'debit');
        }

        return $this->concept('income_statement', 'operating_expenses', 'AdministrativeAndOperatingExpenses', 'Administrative and operating expenses', 'debit');
    }

    private function concept(
        string $statement,
        string $section,
        string $element,
        string $label,
        string $normalBalance
    ): array {
        return [
            'statement' => $statement,
            'section' => $section,
            'element' => $element,
            'label' => $label,
            'normal_balance' => $normalBalance,
            'taxonomy_version' => self::TAXONOMY_VERSION,
        ];
    }
}
