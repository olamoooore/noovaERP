<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FixedAsset;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TenantTestCase;

/**
 * Issue #94 — CapabilityRegistry declared ImpairmentRecord for ACC-007 but no
 * impairment implementation existed. Assets can now be impaired: carrying
 * amount reduced to the recoverable amount, loss posted through PostingEngine,
 * and the record entity-scoped like every other asset action.
 */
class HR94FixedAssetImpairmentTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private string $assetANumber;

    private string $assetBNumber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'HR233 Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'HR233 Group', 'code' => 'EG-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD', 'fiscal_year_start' => '01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity A', 'code' => 'A-'.Str::upper(Str::random(5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity B', 'code' => 'B-'.Str::upper(Str::random(5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $assetAccount = \App\Models\ChartOfAccount::factory()->create(['type' => 'asset', 'is_active' => true]);
        $expenseAccount = \App\Models\ChartOfAccount::factory()->create(['type' => 'expense', 'is_active' => true]);
        $accumAccount = \App\Models\ChartOfAccount::factory()->create(['type' => 'asset', 'is_active' => true]);

        $mkAsset = function (string $name, int $entityId) use ($assetAccount, $expenseAccount, $accumAccount): FixedAsset {
            return FixedAsset::create([
                'asset_number' => 'FA-'.Str::upper(Str::random(6)),
                'name' => $name,
                'category' => 'plant',
                'purchase_date' => now()->toDateString(),
                'purchase_cost' => 1000,
                'depreciation_method' => 'straight_line',
                'depreciation_frequency' => 'monthly',
                'useful_life_years' => 3,
                'salvage_value' => 0,
                'depreciation_start_date' => now()->toDateString(),
                'asset_account_id' => $assetAccount->id,
                'depreciation_account_id' => $expenseAccount->id,
                'accumulated_depreciation_account_id' => $accumAccount->id,
                'status' => 'active',
                'is_active' => true,
                'legal_entity_id' => $entityId,
                // Impairment needs a carrying amount to reduce.
                'book_value' => 1000,
                'accumulated_depreciation' => 0,
            ]);
        };
        $this->assetANumber = $mkAsset('Entity A Machine', $this->entityA)->asset_number;
        $this->assetBNumber = $mkAsset('Entity B Machine', $this->entityB)->asset_number;

        $actor = \App\Models\User::factory()->create();
        $this->actingAs($actor);

        // Posting gate requires an open fiscal period (HR105/HR106 pattern).
        \App\Models\FiscalPeriod::firstOrCreate(
            ['code' => now()->format('Y-m')],
            [
                'name' => now()->format('F Y'),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $actor->id,
            ]
        );
    }

    private function scopedUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Accountant');

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'accountant',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_entity_access')->insert([
            'user_id' => $user->id,
            'legal_entity_id' => $this->entityA,
            'role' => 'accountant',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }


    public function test_impairment_reduces_carrying_amount_and_posts_the_loss(): void
    {
        $asset = FixedAsset::where('asset_number', $this->assetANumber)->firstOrFail();
        $carrying = (float) $asset->book_value;
        $this->assertGreaterThan(0, $carrying);

        $recoverable = round($carrying * 0.6, 2);
        $loss = round($carrying - $recoverable, 2);

        $response = $this->actingAs($this->scopedUser())
            ->postJson("/api/v1/accounting/assets/{$asset->id}/impair", [
                'impairment_date' => now()->toDateString(),
                'recoverable_amount' => $recoverable,
                'reason' => 'Market value decline',
            ]);

        $response->assertCreated();
        $this->assertSame($loss, (float) $response->json('impairment.impairment_loss'));

        $asset->refresh();
        $this->assertSame($recoverable, (float) $asset->book_value, 'Carrying amount must drop to the recoverable amount');
        $this->assertSame($loss, (float) $asset->accumulated_depreciation, 'Accumulated depreciation must absorb the loss');

        // The loss is in the ledger: Dr expense / Cr accumulated.
        // Hostile re-review (#94): the posting is keyed on the impairment
        // record (one JE per impairment event), not the asset.
        $impairmentId = (int) $response->json('impairment.id');
        $link = \App\Models\JournalEntryLink::where('source_document_type', 'asset_impairment')
            ->where('source_document_id', $impairmentId)
            ->first();
        $this->assertNotNull($link, 'Impairment must post a journal entry');

        $lines = \App\Models\JournalEntryLine::where('journal_entry_id', $link->journal_entry_id)->get();
        $debit = $lines->sum(fn ($l) => (float) $l->debit);
        $credit = $lines->sum(fn ($l) => (float) $l->credit);
        $this->assertSame($loss, round($debit, 2));
        $this->assertSame($loss, round($credit, 2));
    }

    public function test_recoverable_above_carrying_reports_no_impairment(): void
    {
        $asset = FixedAsset::where('asset_number', $this->assetANumber)->firstOrFail();

        $response = $this->actingAs($this->scopedUser())
            ->postJson("/api/v1/accounting/assets/{$asset->id}/impair", [
                'impairment_date' => now()->toDateString(),
                'recoverable_amount' => (float) $asset->book_value + 500,
            ]);

        $response->assertStatus(422);
    }

    public function test_scoped_user_cannot_impair_another_entity_asset(): void
    {
        $assetB = FixedAsset::where('asset_number', $this->assetBNumber)->firstOrFail();

        $this->actingAs($this->scopedUser())
            ->postJson("/api/v1/accounting/assets/{$assetB->id}/impair", [
                'impairment_date' => now()->toDateString(),
                'recoverable_amount' => 1,
            ])
            ->assertStatus(404);
    }

}
