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
 * Issue #162 (CRITICAL) — declining-balance depreciation computed every period
 * off the SAME starting book value, because calculateDecliningBalance() read
 * $asset->calculateBookValue() while the loop only advanced local variables.
 *
 * Verification from the issue: for a declining_balance asset 12,000 / salvage 0
 * / 3 years / monthly, the first 6 schedule amounts must decline each month
 * (666.67, ~629.63, ~594.65, ...) instead of being identical.
 */
class HR162DecliningBalanceDepreciationTest extends TenantTestCase
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

        FiscalPeriod::create([
            'name' => 'FY '.now()->year,
            'code' => 'FY-'.Str::random(6),
            'period_start' => now()->startOfYear()->toDateString(),
            'period_end' => now()->endOfYear()->toDateString(),
            'period_type' => 'yearly',
            'fiscal_year' => (int) now()->year,
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);
    }

    private function asset(string $method = 'declining_balance', float $cost = 12000.00, float $salvage = 0.00): FixedAsset
    {
        $acc = fn (string $code, string $type) => ChartOfAccount::create([
            'code' => $code, 'name' => $code, 'type' => $type, 'account_type' => $type, 'is_active' => true,
        ]);

        $suffix = Str::lower(Str::random(4));

        return FixedAsset::create([
            'asset_number' => 'HR162-'.Str::upper(Str::random(6)),
            'name' => 'HR162 Asset '.$suffix,
            'asset_account_id' => $acc('96'.$suffix[0].'1', 'asset')->id,
            'depreciation_account_id' => $acc('96'.$suffix[1].'2', 'expense')->id,
            'accumulated_depreciation_account_id' => $acc('96'.$suffix[2].'3', 'asset')->id,
            'purchase_cost' => $cost,
            'purchase_date' => now()->startOfYear()->toDateString(),
            'depreciation_start_date' => now()->startOfYear()->toDateString(),
            'useful_life_years' => 3,
            'useful_life_months' => 36,
            'salvage_value' => $salvage,
            'depreciation_method' => $method,
            'depreciation_frequency' => 'monthly',
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    private function amounts(FixedAsset $asset, int $limit = 6): array
    {
        return DepreciationSchedule::where('asset_id', $asset->id)
            ->orderBy('period_date')
            ->limit($limit)
            ->pluck('depreciation_amount')
            ->map(fn ($amount) => (float) $amount)
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    // The issue's verification
    // ─────────────────────────────────────────────────────────────────────

    public function test_declining_balance_amounts_decline_each_period(): void
    {
        $asset = $this->asset('declining_balance');

        app(DepreciationService::class)->generateScheduleForAsset($asset->id);

        $amounts = $this->amounts($asset, 6);

        $this->assertCount(6, $amounts);

        // First period = cost × (2/3) / 12
        $this->assertEqualsWithDelta(666.67, $amounts[0], 0.01, 'Period 1');
        // Second period = (cost − 666.67) × (2/3) / 12
        $this->assertEqualsWithDelta(629.63, $amounts[1], 0.05, 'Period 2 must be computed off the reduced book value');

        // Each period strictly smaller than the previous — no repeated amounts.
        for ($i = 1; $i < count($amounts); $i++) {
            $this->assertLessThan(
                $amounts[$i - 1],
                $amounts[$i],
                'Period '.($i + 1)." amount ({$amounts[$i]}) must be less than period {$i} ({$amounts[$i - 1]})"
            );
        }
    }

    public function test_declining_balance_full_schedule_is_monotonically_decreasing(): void
    {
        $asset = $this->asset('declining_balance');

        app(DepreciationService::class)->generateScheduleForAsset($asset->id);

        $amounts = $this->amounts($asset, 36);

        $this->assertCount(36, $amounts, 'A 3-year monthly schedule must have 36 periods');

        for ($i = 1; $i < count($amounts); $i++) {
            $this->assertLessThanOrEqual($amounts[$i - 1], $amounts[$i]);
            $this->assertGreaterThan(0, $amounts[$i]);
        }

        // No period may ever be depreciated below salvage.
        $finalBookValue = (float) DepreciationSchedule::where('asset_id', $asset->id)
            ->orderByDesc('period_date')
            ->value('book_value');
        $this->assertGreaterThanOrEqual(0.0, $finalBookValue);
    }

    public function test_straight_line_remains_constant(): void
    {
        $asset = $this->asset('straight_line');

        app(DepreciationService::class)->generateScheduleForAsset($asset->id);

        $amounts = $this->amounts($asset, 6);

        $this->assertCount(6, $amounts);
        foreach ($amounts as $amount) {
            $this->assertEqualsWithDelta(333.33, $amount, 0.01, 'Straight-line must stay constant');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Resumed / partially-generated schedules
    // ─────────────────────────────────────────────────────────────────────

    public function test_resumed_schedule_continues_from_the_already_generated_book_value(): void
    {
        $asset = $this->asset('declining_balance');

        // Simulate a previously generated (but not posted) first period.
        DepreciationSchedule::create([
            'asset_id' => $asset->id,
            'period_date' => now()->startOfYear()->toDateString(),
            'depreciation_amount' => 666.67,
            'accumulated_depreciation' => 666.67,
            'book_value' => 11333.33,
            'is_posted' => false,
        ]);

        app(DepreciationService::class)->generateScheduleForAsset($asset->id);

        $amounts = $this->amounts($asset, 2);

        $this->assertEqualsWithDelta(666.67, $amounts[0], 0.01);
        $this->assertEqualsWithDelta(
            629.63,
            $amounts[1],
            0.05,
            'Period 2 must continue from the existing row\'s accumulated depreciation, not the asset start value'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // The other generation path: calculateDepreciationForPeriod()
    // ─────────────────────────────────────────────────────────────────────

    public function test_calculate_for_period_also_declines(): void
    {
        $asset = $this->asset('declining_balance');

        app(DepreciationService::class)->calculateDepreciationForPeriod(now()->startOfYear()->toDateString(), [$asset->id]);

        $amounts = $this->amounts($asset, 4);

        $this->assertCount(4, $amounts);
        $this->assertEqualsWithDelta(666.67, $amounts[0], 0.01);
        $this->assertEqualsWithDelta(629.63, $amounts[1], 0.05);

        for ($i = 1; $i < count($amounts); $i++) {
            $this->assertLessThan($amounts[$i - 1], $amounts[$i]);
        }
    }
}
