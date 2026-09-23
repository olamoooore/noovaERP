<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #64 (hostile re-review HR64-01): the depreciation-schedule dedupe
 * migration must never silently delete a row that carries a ledger effect.
 *
 *   - unposted duplicates  -> lowest id survives, extras removed;
 *   - one posted duplicate -> the posted row survives, unposted noise removed;
 *   - two posted duplicates -> migration fails closed with the row ids.
 */
class HR64DepreciationDedupeGuardTest extends TenantTestCase
{
    private string $tenantId;
    private User $user;
    private FixedAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = (string) $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->assertTenantSchemaReady(['depreciation_schedules', 'fixed_assets', 'journal_entries']);

        $this->user = User::factory()->create();

        $assetAccount = ChartOfAccount::factory()->create(['code' => 'HR64-A-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $expenseAccount = ChartOfAccount::factory()->create(['code' => 'HR64-E-'.Str::upper(Str::random(4)), 'type' => 'expense', 'is_active' => true]);
        $accumAccount = ChartOfAccount::factory()->create(['code' => 'HR64-C-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);

        $this->asset = FixedAsset::create([
            'asset_number' => 'FA-HR64-'.Str::upper(Str::random(6)),
            'name' => 'HR64 Asset',
            'category' => 'plant',
            'purchase_date' => now()->toDateString(),
            'purchase_cost' => 1000,
            'depreciation_method' => 'straight_line',
            'depreciation_frequency' => 'monthly',
            'useful_life_years' => 5,
            'salvage_value' => 0,
            'asset_account_id' => $assetAccount->id,
            'depreciation_account_id' => $expenseAccount->id,
            'accumulated_depreciation_account_id' => $accumAccount->id,
        ]);
    }

    private function dropIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS depreciation_schedules_asset_period_unique');
    }

    private function runMigration(): void
    {
        $migration = require base_path('database/migrations/tenant/2026_09_04_000001_add_unique_depreciation_schedule_asset_period.php');
        $migration->up();
    }

    private function scheduleRow(?int $jeId = null, bool $isPosted = false, float $amount = 100): int
    {
        return (int) DB::table('depreciation_schedules')->insertGetId([
            'asset_id' => $this->asset->id,
            'period_date' => '2026-01-31',
            'depreciation_amount' => $amount,
            'accumulated_depreciation' => $amount,
            'book_value' => 1000 - $amount,
            'journal_entry_id' => $jeId,
            'is_posted' => $isPosted,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function journalEntry(): int
    {
        return (int) DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JE-HR64-'.Str::upper(Str::random(5)),
            'entry_date' => now()->toDateString(),
            'description' => 'HR64 fixture',
            'status' => 'posted',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unposted_duplicates_are_collapsed_to_the_lowest_id(): void
    {
        $this->dropIndex();
        $first = $this->scheduleRow();
        $second = $this->scheduleRow();
        $third = $this->scheduleRow();

        $this->runMigration();

        $ids = DB::table('depreciation_schedules')->where('asset_id', $this->asset->id)->pluck('id')->all();
        $this->assertSame([$first], $ids, 'Only the lowest-id unposted row may survive.');
        $this->assertNull(DB::table('depreciation_schedules')->where('id', $second)->first());
        $this->assertNull(DB::table('depreciation_schedules')->where('id', $third)->first());
    }

    public function test_a_posted_duplicate_survives_and_unposted_noise_is_removed(): void
    {
        $this->dropIndex();
        $unposted = $this->scheduleRow();
        $posted = $this->scheduleRow($this->journalEntry(), true);

        $this->runMigration();

        $ids = DB::table('depreciation_schedules')->where('asset_id', $this->asset->id)->pluck('id')->all();
        $this->assertSame([$posted], $ids, 'The ledger-connected row must survive even if it is not the lowest id.');
        $this->assertNull(DB::table('depreciation_schedules')->where('id', $unposted)->first());
    }

    public function test_multiple_posted_duplicates_fail_closed_with_the_row_ids(): void
    {
        $this->dropIndex();
        $first = $this->scheduleRow($this->journalEntry(), true);
        $second = $this->scheduleRow($this->journalEntry(), true);

        try {
            $this->runMigration();
            $this->fail('Expected the migration to fail closed on two posted duplicates.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('DEPRECIATION_SCHEDULE_DUPLICATES_NEED_RECONCILIATION', $e->getMessage());
            $this->assertStringContainsString((string) $first, $e->getMessage());
            $this->assertStringContainsString((string) $second, $e->getMessage());
        }

        // Nothing may have been deleted.
        $ids = DB::table('depreciation_schedules')->where('asset_id', $this->asset->id)->pluck('id')->all();
        $this->assertCount(2, $ids, 'A failed-closed migration must not delete any rows.');
    }

    public function test_index_is_created_after_a_clean_run(): void
    {
        $this->dropIndex();
        $this->scheduleRow();

        $this->runMigration();

        $index = DB::selectOne(
            "SELECT 1 AS present FROM pg_indexes WHERE tablename = 'depreciation_schedules' AND indexname = 'depreciation_schedules_asset_period_unique'"
        );
        $this->assertNotNull($index, 'The unique backstop must exist after the migration.');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->scheduleRow();
    }
}
