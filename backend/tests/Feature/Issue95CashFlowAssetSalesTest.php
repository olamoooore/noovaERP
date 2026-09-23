<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DepreciationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Reports\CashFlowService;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

/**
 * Issue #95 — CashFlowService::getAssetSales() was hardcoded to 0, so the
 * investing section omitted real disposal proceeds even though the disposal
 * posting (DepreciationService -> PostingEngine, documentType 'asset_disposal')
 * already exists.
 */
class Issue95CashFlowAssetSalesTest extends TenantTestCase
{
    private User $user;

    private ChartOfAccount $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();

        // Account-tag resolution defaults to 'mapped' (report_tags chain), which
        // is unseeded in a fresh test tenant; the legacy code-range path exercises
        // the same getAssetSales query with resolvable accounts.
        config(['app.report_classification' => 'legacy']);

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '95959595-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'fixed_assets', 'journal_entries', 'journal_entry_lines',
            'journal_entry_links', 'chart_of_accounts', 'account_mappings',
        ]);

        $this->user = User::create([
            'name' => 'Issue95 User',
            'email' => 'issue95-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        foreach (['accounting.write', 'accounting.post'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo(['accounting.write', 'accounting.post']);

        $this->cashAccount = ChartOfAccount::create([
            'code' => '1010-95',
            'name' => 'Cash',
            'type' => 'asset',
            'account_type' => 'asset',
            'is_active' => true,
        ]);
        $assetAccount = ChartOfAccount::create([
            'code' => '1500-95',
            'name' => 'Fixed Assets',
            'type' => 'asset',
            'account_type' => 'asset',
            'is_active' => true,
        ]);
        $accumulated = ChartOfAccount::create([
            'code' => '1590-95',
            'name' => 'Accumulated Depreciation',
            'type' => 'asset',
            'account_type' => 'asset',
            'is_active' => true,
        ]);
        $gainLoss = ChartOfAccount::create([
            'code' => '7100-95',
            'name' => 'Gain/Loss on Disposal',
            'type' => 'expense',
            'account_type' => 'expense',
            'is_active' => true,
        ]);

        AccountMapping::create(['mapping_key' => 'cash', 'account_id' => $this->cashAccount->id]);
        AccountMapping::create(['mapping_key' => 'accumulated_depreciation', 'account_id' => $accumulated->id]);
        AccountMapping::create(['mapping_key' => 'gain_loss_disposal', 'account_id' => $gainLoss->id]);

        FixedAsset::create([
            'asset_number' => 'FA-95-001',
            'name' => 'Issue95 Machine',
            'purchase_date' => '2025-01-01',
            'purchase_cost' => 1000,
            'accumulated_depreciation' => 200,
            'depreciation_method' => 'straight_line',
            'asset_account_id' => $assetAccount->id,
            'depreciation_account_id' => $gainLoss->id,
            'accumulated_depreciation_account_id' => $accumulated->id,
            'status' => 'active',
        ]);
    }

    private function generateCashFlow(): array
    {
        $report = app(CashFlowService::class)->generate(new ReportFilter(
            startDate: Carbon::parse('2026-03-01'),
            endDate: Carbon::parse('2026-03-31'),
            interval: 'indirect',
        ));

        return $report->data['investing_activities'];
    }

    public function test_disposal_proceeds_appear_in_investing_activities(): void
    {
        // Negative control: no disposals yet.
        $before = $this->generateCashFlow();
        $this->assertSame(0.0, round((float) $before['net_cash'], 2));

        $asset = FixedAsset::firstOrFail();
        app(DepreciationService::class)->disposeAsset($asset, '2026-03-15', 1200.00, 'sale');

        // The disposal posted and is linked as an asset_disposal document.
        $this->assertSame(1, DB::table('journal_entry_links')
            ->where('source_document_type', 'asset_disposal')
            ->where('relation_type', 'post')
            ->count());

        $after = $this->generateCashFlow();

        $saleLine = collect($after['items'])->firstWhere('description', 'Proceeds from Sale of Assets');
        $this->assertNotNull($saleLine, 'Disposal proceeds must appear in the investing section.');
        $this->assertSame(1200.0, round((float) $saleLine['amount'], 2));
        $this->assertSame(1200.0, round((float) $after['net_cash'], 2));
    }

    public function test_disposal_outside_the_period_is_not_counted(): void
    {
        $asset = FixedAsset::firstOrFail();
        app(DepreciationService::class)->disposeAsset($asset, '2026-03-15', 1200.00, 'sale');

        $report = app(CashFlowService::class)->generate(new ReportFilter(
            startDate: Carbon::parse('2026-04-01'),
            endDate: Carbon::parse('2026-04-30'),
            interval: 'indirect',
        ));

        $this->assertSame(0.0, round((float) $report->data['investing_activities']['net_cash'], 2));
    }
}
