<?php

namespace App\Accounting;

/**
 * Issue #766 (A6) — one canonical reading of `chart_of_accounts.subtype`.
 *
 * The column accepts two naming generations (legacy `current_asset`, … and
 * Odoo-style `asset_cash`, `liability_payable`, `income_other`, …). Reports
 * and the country taxonomy profiles used to each test a different subset, so
 * an `asset_cash` account fell through to "other non-current assets". This
 * class is the single alias layer: consumers resolve a subtype to a canonical
 * group instead of comparing raw strings.
 */
final class AccountSubtypeMap
{
    public const CASH = 'cash';

    public const RECEIVABLE = 'receivable';

    public const INVENTORY = 'inventory';

    public const CURRENT_ASSET = 'current_asset';

    public const FIXED_ASSET = 'fixed_asset';

    public const NON_CURRENT_ASSET = 'non_current_asset';

    public const CURRENT_LIABILITY = 'current_liability';

    public const PAYABLE = 'payable';

    public const NON_CURRENT_LIABILITY = 'non_current_liability';

    public const EQUITY = 'equity';

    public const REVENUE = 'revenue';

    public const OTHER_INCOME = 'other_income';

    public const COST_OF_SALES = 'cost_of_sales';

    public const OPERATING_EXPENSE = 'operating_expense';

    public const OTHER_EXPENSE = 'other_expense';

    private const ALIASES = [
        // Legacy names (pre-Odoo CoA)
        'current_asset' => self::CURRENT_ASSET,
        'fixed_asset' => self::FIXED_ASSET,
        'other_asset' => self::NON_CURRENT_ASSET,
        'current_liability' => self::CURRENT_LIABILITY,
        'long_term_liability' => self::NON_CURRENT_LIABILITY,
        'operating_revenue' => self::REVENUE,
        'other_revenue' => self::OTHER_INCOME,
        'cost_of_goods_sold' => self::COST_OF_SALES,
        'operating_expense' => self::OPERATING_EXPENSE,
        'other_expense' => self::OTHER_EXPENSE,

        // Odoo-style names
        'asset_cash' => self::CASH,
        'asset_receivable' => self::RECEIVABLE,
        'asset_inventory' => self::INVENTORY,
        'asset_current' => self::CURRENT_ASSET,
        'asset_fixed' => self::FIXED_ASSET,
        'asset_non_current' => self::NON_CURRENT_ASSET,
        'liability_payable' => self::PAYABLE,
        'liability_current' => self::CURRENT_LIABILITY,
        'liability_credit_card' => self::CURRENT_LIABILITY,
        'liability_non_current' => self::NON_CURRENT_LIABILITY,
        'equity' => self::EQUITY,
        'income' => self::REVENUE,
        'income_other' => self::OTHER_INCOME,
        'expense_direct_cost' => self::COST_OF_SALES,
        'expense' => self::OPERATING_EXPENSE,
        'expense_depreciation' => self::OPERATING_EXPENSE,
    ];

    /**
     * @return string|null the canonical group, or null for an unknown/empty subtype
     */
    public static function resolve(?string $subtype): ?string
    {
        $subtype = strtolower(trim((string) $subtype));

        return self::ALIASES[$subtype] ?? null;
    }
}
