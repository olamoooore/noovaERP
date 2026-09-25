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
 * Client Note 10 (PPE, FY ended 31 Aug 2025) reconciliation.
 *
 * Production report (2026-09-25): the fixed-asset module's depreciation did
 * not agree with the client's audited PPE note:
 *
 *   Computer Equipment: FY24 charge 313; FY25 charge 1,534;
 *     accum 31 Aug 2025 40,965; NBV 5,280
 *   Medical Devices:   FY25 charge 1,903; accum 1,903; NBV 18,797
 *
 * The note is reproducible with:
 *   - computers on a 3-year life (old pool 39,118 fully depreciated by
 *     Aug 2023; additions 3,352 acquired 21 May 2024 and 3,775 acquired
 *     3 May 2025),
 *   - medical devices on a 10-year life acquired 30 Sep 2024,
 *   - and a part-month first period charged on a 30-day month basis.
 *
 * The engine previously charged whole months only (cost ÷ 36), so the
 * mid-month acquisitions were overcharged by a full month.
 */
class HR266PPENoteDepreciationTest extends TenantTestCase
{
    private static int $assetSequence = 0;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext((string) Str::uuid());

        $this->user = User::factory()->create();
        $this->seed(RolePermissionSeeder::class);
        $this->user->assignRole('Admin');
        Auth::login($this->user);

        FiscalPeriod::create([
            'name' => 'FY 2024',
            'code' => 'FY-'.Str::random(6),
            'period_start' => '2024-09-01',
            'period_end' => '2025-08-31',
            'period_type' => 'yearly',
            'fiscal_year' => 2024,
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);
    }

    private function asset(float $cost, string $startDate, int $lifeYears): FixedAsset
    {
        $acc = fn (string $code, string $type) => ChartOfAccount::create([
            'code' => $code, 'name' => $code, 'type' => $type, 'account_type' => $type, 'is_active' => true,
        ]);

        $seq = (string) (++self::$assetSequence);

        return FixedAsset::create([
            'asset_number' => 'HR266-'.$seq,
            'name' => 'HR266 Asset '.$seq,
            'asset_account_id' => $acc('971'.$seq, 'asset')->id,
            'depreciation_account_id' => $acc('972'.$seq, 'expense')->id,
            'accumulated_depreciation_account_id' => $acc('973'.$seq, 'asset')->id,
            'purchase_cost' => $cost,
            'purchase_date' => $startDate,
            'depreciation_start_date' => $startDate,
            'useful_life_years' => $lifeYears,
            'salvage_value' => 0,
            'depreciation_method' => 'straight_line',
            'depreciation_frequency' => 'monthly',
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    /** @param array<int,int> $assetIds */
    private function scheduleSum(array $assetIds, string $from, string $to): float
    {
        return round((float) DepreciationSchedule::whereIn('asset_id', $assetIds)
            ->whereBetween('period_date', [$from, $to])
            ->sum('depreciation_amount'), 2);
    }

    public function test_straight_line_first_period_is_prorated_by_active_days(): void
    {
        // 21 May 2024, 3-year life: 11 active days in May (30-day month rule).
        $asset = $this->asset(3352.00, '2024-05-21', 3);

        app(DepreciationService::class)->calculateDepreciationForPeriod('2024-08-31', [$asset->id]);

        $may = DepreciationSchedule::where('asset_id', $asset->id)
            ->whereBetween('period_date', ['2024-05-01', '2024-05-31'])
            ->value('depreciation_amount');

        $this->assertEqualsWithDelta(34.14, (float) $may, 0.01, 'May must be charged 11/30 of the monthly amount.');
    }

    public function test_mid_month_start_is_trued_up_so_the_asset_fully_depreciates(): void
    {
        $asset = $this->asset(3352.00, '2024-05-21', 3);

        app(DepreciationService::class)->calculateDepreciationForPeriod('2025-08-31', [$asset->id]);

        $scheduled = round((float) DepreciationSchedule::where('asset_id', $asset->id)->sum('depreciation_amount'), 2);
        $this->assertEqualsWithDelta(3352.00, $scheduled, 0.01, 'The final period must true up the prorated first period.');

        $asset2 = $this->asset(3352.00, '2024-05-21', 3);
        app(DepreciationService::class)->generateScheduleForAsset($asset2->id);

        $scheduled2 = round((float) DepreciationSchedule::where('asset_id', $asset2->id)->sum('depreciation_amount'), 2);
        $this->assertEqualsWithDelta(3352.00, $scheduled2, 0.01, 'generateScheduleForAsset must also true up.');
    }

    public function test_computer_additions_reproduce_note10_fy_charges(): void
    {
        $addition1 = $this->asset(3352.00, '2024-05-21', 3);
        $addition2 = $this->asset(3775.00, '2025-05-03', 3);

        app(DepreciationService::class)->calculateDepreciationForPeriod('2025-08-31', [$addition1->id, $addition2->id]);

        $fy2024 = $this->scheduleSum([$addition1->id, $addition2->id], '2023-09-01', '2024-08-31');
        $fy2025 = $this->scheduleSum([$addition1->id, $addition2->id], '2024-09-01', '2025-08-31');

        // Client note: 313 then 1,534 (our exact cents are 313.47 / 1,533.28).
        $this->assertEqualsWithDelta(313, $fy2024, 1.0, 'FY2024 computer charge must match Note 10.');
        $this->assertEqualsWithDelta(1534, $fy2025, 1.0, 'FY2025 computer charge must match Note 10.');
    }

    public function test_medical_devices_reproduce_note10_fy_charge(): void
    {
        $medical = $this->asset(20700.00, '2024-09-30', 10);

        app(DepreciationService::class)->calculateDepreciationForPeriod('2025-08-31', [$medical->id]);

        $fy2025 = $this->scheduleSum([$medical->id], '2024-09-01', '2025-08-31');
        $accumulated = $this->scheduleSum([$medical->id], '2024-09-01', '2025-08-31');

        // Client note: 1,903 charge (1 day of Sep + 11 full months = 1,903.25).
        $this->assertEqualsWithDelta(1903, $fy2025, 1.0, 'FY2025 medical charge must match Note 10.');
        $this->assertEqualsWithDelta(1903, $accumulated, 1.0, 'Accumulated depreciation must match Note 10.');
    }

    public function test_class_accumulated_and_nbv_match_note10_totals(): void
    {
        $pool = $this->asset(39118.00, '2020-09-01', 3);
        $addition1 = $this->asset(3352.00, '2024-05-21', 3);
        $addition2 = $this->asset(3775.00, '2025-05-03', 3);
        $medical = $this->asset(20700.00, '2024-09-30', 10);

        // The old pool is already fully depreciated as at 1 Sep 2023.
        $pool->update(['accumulated_depreciation' => 39118.00, 'book_value' => 0]);

        app(DepreciationService::class)->calculateDepreciationForPeriod('2025-08-31', [
            $addition1->id, $addition2->id, $medical->id,
        ]);

        $computerAccum = 39118.00 + $this->scheduleSum([$addition1->id, $addition2->id], '2023-09-01', '2025-08-31');
        $medicalAccum = $this->scheduleSum([$medical->id], '2024-09-01', '2025-08-31');

        // Note 10: computer accum 40,965 (NBV 5,280 of cost 46,245);
        // medical accum 1,903 (NBV 18,797 of cost 20,700); total NBV 24,077.
        $this->assertEqualsWithDelta(40965, $computerAccum, 1.0, 'Computer accumulated depreciation must match Note 10.');
        $this->assertEqualsWithDelta(1903, $medicalAccum, 1.0, 'Medical accumulated depreciation must match Note 10.');

        $totalNbv = (46245 - $computerAccum) + (20700 - $medicalAccum);
        $this->assertEqualsWithDelta(24077, $totalNbv, 2.0, 'Total NBV must match Note 10.');
    }
}
