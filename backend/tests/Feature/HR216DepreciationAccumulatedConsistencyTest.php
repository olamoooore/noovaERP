<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\DepreciationSchedule;
use App\Models\FiscalPeriod;
use App\Models\FixedAsset;
use App\Models\User;
use App\Services\DepreciationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #216 (regression from the #162 fix) — generateScheduleForAsset()
 * persisted accumulated_depreciation as the sum of ALL generated rows while
 * calculateDepreciationForPeriod() treats it as the POSTED-only baseline and
 * adds drafts on top, double-counting unposted periods when both are used.
 */
class HR216DepreciationAccumulatedConsistencyTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext((string) Str::uuid());

        $this->user = User::factory()->create();
        $this->seed(RolePermissionSeeder::class);
        $this->user->assignRole('Admin');
        Auth::login($this->user);

        // Only the CURRENT month has an open fiscal period, so future schedule
        // periods legitimately fail JE creation and stay is_posted=false.
        FiscalPeriod::create([
            'name' => now()->format('F Y'),
            'code' => 'FY-'.Str::random(6),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'period_type' => 'monthly',
            'fiscal_year' => (int) now()->format('Y'),
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);
    }

    private function asset(): FixedAsset
    {
        $acc = fn (string $code, string $type) => ChartOfAccount::create([
            'code' => $code, 'name' => $code, 'type' => $type, 'account_type' => $type, 'is_active' => true,
        ]);

        $suffix = Str::lower(Str::random(4));

        return FixedAsset::create([
            'asset_number' => 'HR216-'.Str::upper(Str::random(6)),
            'name' => 'HR216 Asset '.$suffix,
            'asset_account_id' => $acc('96'.$suffix[0].'1', 'asset')->id,
            'depreciation_account_id' => $acc('96'.$suffix[1].'2', 'expense')->id,
            'accumulated_depreciation_account_id' => $acc('96'.$suffix[2].'3', 'asset')->id,
            'purchase_cost' => 12000.00,
            'purchase_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'depreciation_start_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'useful_life_years' => 1,
            'useful_life_months' => 12,
            'salvage_value' => 0,
            'depreciation_method' => 'declining_balance',
            'depreciation_frequency' => 'monthly',
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    public function test_persisted_accumulated_depreciation_is_posted_only(): void
    {
        $asset = $this->asset();

        app(DepreciationService::class)->generateScheduleForAsset($asset->id);

        $postedSum = round((float) DepreciationSchedule::where('asset_id', $asset->id)->where('is_posted', true)->sum('depreciation_amount'), 2);
        $draftSum = round((float) DepreciationSchedule::where('asset_id', $asset->id)->where('is_posted', false)->sum('depreciation_amount'), 2);
        $allSum = round((float) DepreciationSchedule::where('asset_id', $asset->id)->sum('depreciation_amount'), 2);

        // The mixed posted/draft state this regression needs actually occurred.
        $this->assertGreaterThan(0, $postedSum, 'At least the current-period row must post');
        $this->assertGreaterThan(0, $draftSum, 'Future periods must remain drafts (no open fiscal period)');
        $this->assertGreaterThan($postedSum, $allSum);

        // Issue #216: the asset carries the POSTED-only baseline.
        $this->assertSame(
            $postedSum,
            round((float) $asset->fresh()->accumulated_depreciation, 2),
            'accumulated_depreciation must be posted-only so calculateDepreciationForPeriod() does not double-count drafts'
        );

        // Consistency identity: posted baseline + drafts == all scheduled value.
        $this->assertSame($allSum, round($postedSum + $draftSum, 2));

        // Book value follows the posted baseline.
        $this->assertSame(
            round(12000.00 - $postedSum, 2),
            round((float) $asset->fresh()->book_value, 2)
        );
    }

    public function test_calculate_for_period_keeps_the_posted_baseline_consistent(): void
    {
        $asset = $this->asset();

        $service = app(DepreciationService::class);
        $service->generateScheduleForAsset($asset->id);

        $postedSum = round((float) DepreciationSchedule::where('asset_id', $asset->id)->where('is_posted', true)->sum('depreciation_amount'), 2);
        $draftSum = round((float) DepreciationSchedule::where('asset_id', $asset->id)->where('is_posted', false)->sum('depreciation_amount'), 2);

        // The formula calculateDepreciationForPeriod() relies on:
        //   projectedAccumulated = asset.accumulated_depreciation + SUM(drafts)
        // must equal the true total scheduled value — no term counted twice.
        $this->assertSame(
            round($postedSum + $draftSum, 2),
            round((float) $asset->fresh()->accumulated_depreciation + $draftSum, 2),
            'The posted baseline plus drafts must equal the total scheduled value'
        );

        // Running the calculate entry point for the current (open) period must
        // not corrupt the posted-only baseline on the asset.
        $service->calculateDepreciationForPeriod(now()->toDateString(), [$asset->id]);

        $this->assertSame(
            $postedSum,
            round((float) $asset->fresh()->accumulated_depreciation, 2),
            'calculateDepreciationForPeriod() must not change the posted-only baseline'
        );

        // And the mixed posted/draft state is still exactly as generated.
        $this->assertSame($postedSum, round((float) DepreciationSchedule::where('asset_id', $asset->id)->where('is_posted', true)->sum('depreciation_amount'), 2));
        $this->assertSame($draftSum, round((float) DepreciationSchedule::where('asset_id', $asset->id)->where('is_posted', false)->sum('depreciation_amount'), 2));
    }

    /**
     * Production regression (Acme Production, Asset-001): an asset whose
     * depreciation is already fully recorded (imported accumulated baseline)
     * and which has NO schedule rows must not receive a "true-up" period that
     * charges the entire depreciable base in one go.
     *
     * The true-up may only close the mid-month proration rounding gap, so it
     * must measure the remainder against asset.accumulated_depreciation +
     * unposted drafts — not against the schedule rows alone.
     */
    public function test_true_up_does_not_charge_the_whole_base_when_accumulated_is_already_recorded(): void
    {
        $asset = $this->asset();
        $asset->update([
            'purchase_cost' => 39118.00,
            'salvage_value' => 0,
            'useful_life_years' => 3,
            'depreciation_method' => 'straight_line',
            'depreciation_start_date' => '2022-09-01',
            'purchase_date' => '2020-09-01',
            'accumulated_depreciation' => 39118.00,
            'book_value' => 0.00,
            'last_depreciation_date' => '2023-08-31',
        ]);

        $service = app(DepreciationService::class);
        $service->calculateDepreciationForPeriod(now()->toDateString(), [$asset->id]);

        $this->assertSame(
            0,
            DepreciationSchedule::where('asset_id', $asset->id)->count(),
            'A fully depreciated asset with no schedule history must not get a full-cost true-up row'
        );

        $this->assertSame(
            39118.00,
            round((float) $asset->fresh()->accumulated_depreciation, 2),
            'The posted accumulated baseline must be untouched'
        );
    }
}
