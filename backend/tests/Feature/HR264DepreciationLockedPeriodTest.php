<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\DepreciationSchedule;
use App\Models\FixedAsset;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DepreciationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Regression: "Calculate Depreciation" reported success but no schedule
 * appeared, for an asset whose entire useful life falls inside CLOSED
 * fiscal periods (production report 2026-09-24, Acme Asset-001:
 * 2022-09-01 + 3y life → 36 monthly periods, all before the FY2024-25
 * lock at 2025-08-31).
 *
 * Root cause: DepreciationService::calculateDepreciationForPeriod deleted
 * the just-created schedule row when draft-journal creation failed on a
 * locked period, then threw — so the asset was reported as 'error' and
 * the UI showed an empty schedule.
 *
 * Fix: keep the schedule row unposted (matching generateScheduleForAsset),
 * count it as pending_posting, and continue. The plan is visible and
 * postPendingScheduleEntries() can create the journals once a period opens.
 */
class HR264DepreciationLockedPeriodTest extends TenantTestCase
{
    private string $tenantId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'HR264 Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        // A tenant user is required for PostingEngine audit attribution.
        $this->user = User::factory()->create(['email' => 'hr264@example.test']);
        DB::table('public.users')->updateOrInsert(
            ['email' => $this->user->email],
            [
                'name' => $this->user->name,
                'password' => $this->user->password,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // --- Lock the historical periods (simulates Acme's FY lock) ---
        DB::table('lock_dates')->insert([
            'lock_type' => 'all',
            'lock_date' => '2025-08-31',
            'is_active' => true,
            'set_by' => $this->user->id,
            'reason' => 'HR264 test lock',
            'set_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeAsset(array $overrides = []): FixedAsset
    {
        $assetAccount = ChartOfAccount::factory()->create(['type' => 'asset', 'is_active' => true]);
        $expenseAccount = ChartOfAccount::factory()->create(['type' => 'expense', 'is_active' => true]);
        $accumAccount = ChartOfAccount::factory()->create(['type' => 'asset', 'is_active' => true]);

        return FixedAsset::create(array_merge([
            'asset_number' => 'FA-'.Str::upper(Str::random(6)),
            'name' => 'Locked Period Computer',
            'category' => 'it_equipment',
            'purchase_date' => '2022-09-01',
            'purchase_cost' => 3600,
            'depreciation_method' => 'straight_line',
            'depreciation_frequency' => 'monthly',
            'useful_life_years' => 3,          // 36 monthly periods
            'salvage_value' => 0,
            'depreciation_start_date' => '2022-09-01',
            'status' => 'active',
            'is_active' => true,
            'accumulated_depreciation' => 0,
            'book_value' => 3600,
            'asset_account_id' => $assetAccount->id,
            'depreciation_account_id' => $expenseAccount->id,
            'accumulated_depreciation_account_id' => $accumAccount->id,
        ], $overrides));
    }

    public function test_schedule_rows_are_kept_when_the_period_is_locked(): void
    {
        $asset = $this->makeAsset();

        $service = app(DepreciationService::class);
        $results = $service->calculateDepreciationForPeriod('2026-09-24', [$asset->id]);

        $this->assertCount(1, $results, 'Exactly one asset result expected.');
        $result = $results[0];

        // The asset must NOT be reported as an error — the schedule was created.
        $this->assertSame('success', $result['status'], 'Asset with locked periods must not be reported as error.');
        $this->assertGreaterThan(0, $result['schedule_count'], 'Schedule rows must be created even when posting is locked.');
        $this->assertGreaterThan(0, $result['pending_posting'], 'Locked periods must be reported as pending posting.');

        // The schedule ROWS must exist in the database (the original bug deleted them).
        $schedules = DepreciationSchedule::where('asset_id', $asset->id)
            ->orderBy('period_date')
            ->get();

        $this->assertCount(36, $schedules, 'A 3-year monthly asset must produce 36 schedule rows.');
        $this->assertSame('2022-09-30', $schedules->first()->period_date->format('Y-m-d'));
        $this->assertSame('2025-08-31', $schedules->last()->period_date->format('Y-m-d'));

        foreach ($schedules as $schedule) {
            $this->assertFalse((bool) $schedule->is_posted, 'Locked periods must stay unposted.');
            $this->assertNull($schedule->journal_entry_id, 'No journal entry should exist for a locked period.');
        }

        // Straight line: 3600 / 36 = 100.00 per month.
        $this->assertSame(100.0, (float) $schedules->first()->depreciation_amount);
    }

    public function test_pending_entries_can_be_posted_once_the_lock_is_removed(): void
    {
        $asset = $this->makeAsset();

        $service = app(DepreciationService::class);
        $service->calculateDepreciationForPeriod('2026-09-24', [$asset->id]);

        $this->assertSame(
            36,
            DepreciationSchedule::where('asset_id', $asset->id)->whereNull('journal_entry_id')->count(),
            'All 36 rows must be pending before the lock is lifted.'
        );

        // Lift the lock and post the pending entries.
        DB::table('lock_dates')->where('lock_type', 'all')->update(['is_active' => false]);

        $postResult = $service->postPendingScheduleEntries($asset->id);

        $this->assertSame(36, $postResult['posted'], 'All 36 pending rows must post after the lock is lifted.');
        $this->assertSame(
            0,
            DepreciationSchedule::where('asset_id', $asset->id)->whereNull('journal_entry_id')->count(),
            'No pending rows must remain after posting.'
        );
    }

    public function test_repeated_calculation_is_idempotent_and_does_not_duplicate(): void
    {
        $asset = $this->makeAsset();

        $service = app(DepreciationService::class);
        $service->calculateDepreciationForPeriod('2026-09-24', [$asset->id]);
        $first = $service->calculateDepreciationForPeriod('2026-09-24', [$asset->id]);

        $this->assertSame(36, DepreciationSchedule::where('asset_id', $asset->id)->count(), 'Re-running must not duplicate schedule rows.');
        $this->assertSame('skipped', $first[0]['status'], 'Second run must report skipped, not success.');
    }
}
