<?php

namespace Tests\Unit\Accounting\Compliance;

use Modules\AccountingCountry\Singapore\Services\SFRSTaxonomyProfile;
use PHPUnit\Framework\TestCase;

/**
 * Issue #766 (A6) — the CoA subtype column accepts both the legacy names and
 * the newer Odoo-style names; the SFRS taxonomy profile only understood the
 * legacy ones, so an `asset_cash` account was reported as OtherNonCurrentAssets
 * and `liability_payable` as non-current.
 */
class SfrsTaxonomySubtypeAliasTest extends TestCase
{
    private function map(string $type, string $subtype, string $code = '9999'): array
    {
        $mapped = (new SFRSTaxonomyProfile)->mapAccount([
            'code' => $code,
            'type' => $type,
            'subtype' => $subtype,
        ]);

        $this->assertNotNull($mapped, "{$type}/{$subtype} must map to a statement line");

        return $mapped;
    }

    public function test_odoo_style_asset_subtypes_map_to_their_statement_lines(): void
    {
        $cash = $this->map('asset', 'asset_cash', '1010');
        $this->assertSame('CashAndCashEquivalents', $cash['element']);
        $this->assertSame('assets.current_assets', $cash['section'], 'cash is a current asset');

        $receivable = $this->map('asset', 'asset_receivable', '1210');
        $this->assertSame('TradeAndOtherReceivables', $receivable['element']);
        $this->assertSame('assets.current_assets', $receivable['section']);

        $fixed = $this->map('asset', 'asset_fixed', '1510');
        $this->assertSame('PropertyPlantAndEquipment', $fixed['element']);
        $this->assertSame('assets.non_current_assets', $fixed['section']);

        $nonCurrent = $this->map('asset', 'asset_non_current', '1610');
        $this->assertSame('OtherNonCurrentAssets', $nonCurrent['element']);
    }

    public function test_odoo_style_liability_subtypes_map_to_their_statement_lines(): void
    {
        $payable = $this->map('liability', 'liability_payable', '2110');
        $this->assertSame('TradeAndOtherPayables', $payable['element']);
        $this->assertSame('liabilities.current_liabilities', $payable['section'], 'payables are current');

        $nonCurrent = $this->map('liability', 'liability_non_current', '2610');
        $this->assertSame('OtherNonCurrentLiabilities', $nonCurrent['element']);
        $this->assertSame('liabilities.non_current_liabilities', $nonCurrent['section']);
    }

    public function test_odoo_style_income_and_expense_subtypes_map_to_their_statement_lines(): void
    {
        $this->assertSame('OtherIncome', $this->map('revenue', 'income_other')['element']);
        $this->assertSame('Revenue', $this->map('revenue', 'income')['element']);
        $this->assertSame('Revenue', $this->map('revenue', 'operating_revenue')['element']);

        $this->assertSame('CostOfSales', $this->map('expense', 'expense_direct_cost')['element']);
        $this->assertSame(
            'AdministrativeAndOperatingExpenses',
            $this->map('expense', 'expense_depreciation')['element'],
            'depreciation is an operating expense here'
        );
    }

    public function test_legacy_subtypes_keep_their_previous_mapping(): void
    {
        $this->assertSame('CashAndCashEquivalents', $this->map('asset', 'current_asset', '1010')['element']);
        $this->assertSame('TradeAndOtherReceivables', $this->map('asset', 'current_asset', '1110')['element']);
        $this->assertSame('Inventories', $this->map('asset', 'current_asset', '1210')['element']);
        $this->assertSame('OtherCurrentAssets', $this->map('asset', 'current_asset', '1310')['element']);
        $this->assertSame('PropertyPlantAndEquipment', $this->map('asset', 'fixed_asset')['element']);
        $this->assertSame('OtherIncome', $this->map('revenue', 'other_revenue')['element']);
        $this->assertSame('CostOfSales', $this->map('expense', 'cost_of_goods_sold')['element']);
        $this->assertSame('OtherExpenses', $this->map('expense', 'other_expense')['element']);
    }
}
