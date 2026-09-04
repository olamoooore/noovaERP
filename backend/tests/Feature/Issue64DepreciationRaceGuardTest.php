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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue noovaerp/NoovaERP#64 — depreciation TOCTOU double-post.
 */
class Issue64DepreciationRaceGuardTest extends TenantTestCase
{
    private string $tenantUuid;

    private User $user;

    private FixedAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = (string) Str::uuid();
        $this->bindTenantContext($this->tenantUuid);

        $this->user = User::factory()->create();
        $this->seed(RolePermissionSeeder::class);
        $this->user->assignRole('Admin');
        Auth::login($this->user);

        FiscalPeriod::create([
            'name' => 'FY 2026',
            'code' => 'FY-'.Str::random(6),
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'period_type' => 'yearly',
            'fiscal_year' => 2026,
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);

        $acc = fn (string $code, string $type) => ChartOfAccount::create([
            'code' => $code, 'name' => $code, 'type' => $type, 'account_type' => $type, 'is_active' => true,
        ]);

        $this->asset = FixedAsset::create([
            'asset_number' => 'AST-'.Str::random(6),
            'name' => 'Race Probe Asset',
            'asset_account_id' => $acc('9601', 'asset')->id,
            'depreciation_account_id' => $acc('9602', 'expense')->id,
            'accumulated_depreciation_account_id' => $acc('9603', 'asset')->id,
            'purchase_cost' => 12000.00,
            'purchase_date' => '2026-01-01',
            'depreciation_start_date' => '2026-01-01',
            'useful_life_years' => 3,
            'useful_life_months' => 36,
            'salvage_value' => 0,
            'depreciation_method' => 'straight_line',
            'depreciation_frequency' => 'monthly',
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    public function test_unique_index_rejects_duplicate_asset_period_schedule_rows(): void
    {
        $attrs = [
            'asset_id' => $this->asset->id,
            'period_date' => '2026-02-28',
            'depreciation_amount' => 100,
            'accumulated_depreciation' => 100,
            'book_value' => 11900,
            'is_posted' => false,
        ];

        DepreciationSchedule::create($attrs);

        $this->expectException(QueryException::class);
        DepreciationSchedule::create($attrs);
    }

    public function test_second_calculation_run_is_idempotent(): void
    {
        $service = app(DepreciationService::class);

        $first = $service->calculateDepreciationForPeriod('2026-02-28', [$this->asset->id]);
        $second = $service->calculateDepreciationForPeriod('2026-02-28', [$this->asset->id]);

        $this->assertSame('success', $first[0]['status']);
        $this->assertSame('skipped', $second[0]['status']);
        $this->assertSame(1, DepreciationSchedule::where('asset_id', $this->asset->id)->where('period_date', '2026-02-28')->count());
    }

    public function test_post_pending_is_guarded_against_double_post(): void
    {
        // An unposted schedule row (JE failed at generation time, e.g. no
        // open period then) must post exactly once even when
        // postPendingScheduleEntries is invoked twice.
        $service = app(DepreciationService::class);
        $schedule = DepreciationSchedule::create([
            'asset_id' => $this->asset->id,
            'period_date' => '2026-03-31',
            'depreciation_amount' => 1000,
            'accumulated_depreciation' => 1000,
            'book_value' => 11000,
            'is_posted' => false,
        ]);

        $resultA = $service->postPendingScheduleEntries($this->asset->id);
        $resultB = $service->postPendingScheduleEntries($this->asset->id);

        $this->assertSame(1, $resultA['posted']);
        $this->assertSame(0, $resultB['posted']);

        $schedule->refresh();
        $this->assertNotNull($schedule->journal_entry_id);
        $this->assertTrue((bool) $schedule->is_posted);
    }
}
