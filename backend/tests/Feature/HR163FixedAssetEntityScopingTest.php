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
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #163 (HIGH) — fixed_assets had no entity column, so Asset routes were
 * entity-gated but never entity-scoped: a user scoped to Entity A could read,
 * edit, dispose and depreciate Entity B's assets.
 *
 * Also covers the adjacent #97 gap found while implementing this: the journal
 * entry list was unscoped and the show scoping always produced an empty set
 * (array_column on 'legal_entity_id' while the rows carry 'id'), so in-scope
 * users got 404s.
 */
class HR163FixedAssetEntityScopingTest extends TenantTestCase
{
    private int $entityA;

    private int $entityB;

    private User $scopedUser;

    private User $unscopedUser;

    private FixedAsset $assetA;

    private FixedAsset $assetB;

    private JournalEntry $entryA;

    private JournalEntry $entryB;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Accountant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Fixed Asset Entity Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Asset Group', 'code' => 'AG-'.Str::upper(Str::random(5)),
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

        // Tenant user to satisfy audit attribution, then authenticate as them.
        $actor = User::factory()->create();
        $actor->assignRole('Accountant');
        $this->actingAs($actor);

        $this->scopedUser = $this->makeUserScopedTo($this->entityA);
        $this->unscopedUser = $this->makeUserScopedTo(null);

        $assetAccount = ChartOfAccount::factory()->create(['type' => 'asset', 'is_active' => true]);
        $expenseAccount = ChartOfAccount::factory()->create(['type' => 'expense', 'is_active' => true]);
        $accumAccount = ChartOfAccount::factory()->create(['type' => 'asset', 'is_active' => true]);

        $this->assetA = FixedAsset::create([
            'asset_number' => 'FA-A-'.Str::upper(Str::random(5)),
            'name' => 'Entity A Machine',
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
            'legal_entity_id' => $this->entityA,
        ]);

        $this->assetB = FixedAsset::create([
            'asset_number' => 'FA-B-'.Str::upper(Str::random(5)),
            'name' => 'Entity B Machine',
            'category' => 'plant',
            'purchase_date' => now()->toDateString(),
            'purchase_cost' => 2000,
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
            'legal_entity_id' => $this->entityB,
        ]);

        $this->entryA = JournalEntry::factory()->create(['legal_entity_id' => $this->entityA, 'created_by' => $actor->id]);
        $this->entryB = JournalEntry::factory()->create(['legal_entity_id' => $this->entityB, 'created_by' => $actor->id]);
    }

    private function makeUserScopedTo(?int $entityId): User
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

        if ($entityId !== null) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $entityId,
                'role' => 'accountant',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fixed assets
    // ─────────────────────────────────────────────────────────────────────

    public function test_asset_list_is_scoped_to_accessible_entities(): void
    {
        $response = $this->actingAs($this->scopedUser)->getJson('/api/v1/accounting/assets');
        $response->assertStatus(200, $response->getContent());

        $numbers = collect($response->json('data'))->pluck('asset_number');
        $this->assertTrue($numbers->contains($this->assetA->asset_number));
        $this->assertFalse($numbers->contains($this->assetB->asset_number), 'Entity B asset must not leak');
    }

    public function test_asset_show_returns_404_for_cross_entity_read(): void
    {
        $this->actingAs($this->scopedUser)
            ->getJson("/api/v1/accounting/assets/{$this->assetA->id}")
            ->assertStatus(200);

        $this->actingAs($this->scopedUser)
            ->getJson("/api/v1/accounting/assets/{$this->assetB->id}")
            ->assertStatus(404);
    }

    public function test_cross_entity_asset_mutations_and_depreciation_return_404(): void
    {
        $user = $this->scopedUser;

        $this->actingAs($user)->putJson("/api/v1/accounting/assets/{$this->assetB->id}", ['name' => 'Hijack'])->assertStatus(404);
        $this->actingAs($user)->deleteJson("/api/v1/accounting/assets/{$this->assetB->id}")->assertStatus(404);
        $this->actingAs($user)->postJson("/api/v1/accounting/assets/{$this->assetB->id}/dispose", ['disposal_date' => now()->toDateString(), 'disposal_amount' => 0, 'disposal_method' => 'scrapped'])->assertStatus(404);
        $this->actingAs($user)->getJson("/api/v1/accounting/assets/{$this->assetB->id}/depreciation")->assertStatus(404);
        $this->actingAs($user)->postJson("/api/v1/accounting/assets/{$this->assetB->id}/generate-schedule")->assertStatus(404);
    }

    public function test_created_asset_is_stamped_with_the_resolved_entity(): void
    {
        // ResolveEntityContext resolves the user's default entity (A).
        $response = $this->actingAs($this->scopedUser)->postJson('/api/v1/accounting/assets', [
            'asset_number' => 'FA-NEW-'.Str::upper(Str::random(5)),
            'name' => 'New Asset',
            'category' => 'plant',
            'purchase_date' => now()->toDateString(),
            'purchase_cost' => 500,
            'depreciation_method' => 'straight_line',
            'useful_life_years' => 3,
            'salvage_value' => 0,
            'depreciation_start_date' => now()->toDateString(),
            'asset_account_id' => ChartOfAccount::factory()->create(['type' => 'asset'])->id,
            'depreciation_account_id' => ChartOfAccount::factory()->create(['type' => 'expense'])->id,
            'accumulated_depreciation_account_id' => ChartOfAccount::factory()->create(['type' => 'asset'])->id,
        ]);

        $response->assertStatus(201, $response->getContent());

        $this->assertSame(
            $this->entityA,
            (int) FixedAsset::where('id', $response->json('id'))->value('legal_entity_id'),
            'Created asset must be stamped with the resolved entity context'
        );
    }

    public function test_depreciation_rejects_out_of_scope_asset_ids(): void
    {
        $response = $this->actingAs($this->scopedUser)->postJson('/api/v1/accounting/assets/calculate-depreciation', [
            'period_date' => now()->toDateString(),
            'asset_ids' => [$this->assetB->id],
        ]);

        $response->assertStatus(403);
    }

    public function test_no_grants_user_keeps_the_fail_open_default(): void
    {
        $response = $this->actingAs($this->unscopedUser)->getJson('/api/v1/accounting/assets');
        $response->assertStatus(200);

        $numbers = collect($response->json('data'))->pluck('asset_number');
        $this->assertTrue($numbers->contains($this->assetA->asset_number));
        $this->assertTrue($numbers->contains($this->assetB->asset_number));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Adjacent #97 gaps: journal entry list/show scoping
    // ─────────────────────────────────────────────────────────────────────

    public function test_journal_entry_list_and_show_are_entity_scoped(): void
    {
        $user = $this->scopedUser;

        $list = $this->actingAs($user)->getJson('/api/v1/accounting/journal-entries');
        $list->assertStatus(200);

        $ids = collect($list->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->entryA->id));
        $this->assertFalse($ids->contains($this->entryB->id), 'Entity B journal entry must not leak in the list');

        // In-scope read works (this previously 404'd for everyone — see class docblock).
        $this->actingAs($user)->getJson("/api/v1/accounting/journal-entries/{$this->entryA->id}")->assertStatus(200);
        // Cross-entity read is excluded.
        $this->actingAs($user)->getJson("/api/v1/accounting/journal-entries/{$this->entryB->id}")->assertStatus(404);
    }

    public function test_journal_entry_list_is_fail_open_without_entity_grants(): void
    {
        $list = $this->actingAs($this->unscopedUser)->getJson('/api/v1/accounting/journal-entries');
        $list->assertStatus(200);

        $ids = collect($list->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->entryA->id));
        $this->assertTrue($ids->contains($this->entryB->id));
    }
}
