<?php

namespace Tests\Feature;

use App\Models\FixedAsset;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Fixed-asset edit/deactivate regressions.
 *
 * Two defects reported from the UI:
 *   1. "Saving the year does nothing" — update() persisted only 7 cosmetic
 *      fields, silently dropping useful_life_years and every depreciation
 *      setting.
 *   2. "Delete does nothing" — destroy() set is_active=false but index() never
 *      filtered on it, so the deactivated asset stayed in the register.
 */
class FixedAssetUpdateDeleteTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->assertTenantSchemaReady(['fixed_assets', 'depreciation_schedules']);

        $this->user = User::create([
            'name' => 'Asset Tester',
            'email' => 'asset-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($this->user);
    }

    private function asset(array $overrides = []): FixedAsset
    {
        return FixedAsset::create(array_merge([
            'asset_number' => 'ASSET-'.Str::upper(Str::random(6)),
            'name' => 'Test Asset',
            'category' => 'IT',
            'purchase_date' => '2025-01-10',
            'purchase_cost' => 1000,
            'depreciation_method' => 'straight_line',
            'useful_life_years' => 5,
            'salvage_value' => 0,
            'depreciation_start_date' => '2025-02-01',
            'status' => 'active',
            'is_active' => true,
            'accumulated_depreciation' => 0,
            'book_value' => 1000,
        ], $overrides));
    }

    public function test_update_persists_useful_life_and_recomputes_book_value(): void
    {
        $asset = $this->asset();

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->putJson("/api/v1/accounting/assets/{$asset->id}", [
                'useful_life_years' => 8,
                'name' => 'Renamed Asset',
            ]);

        $response->assertOk();
        $asset->refresh();
        $this->assertSame(8, $asset->useful_life_years, 'useful_life_years must persist (the "year" bug)');
        $this->assertSame('Renamed Asset', $asset->name);

        // Book value follows cost: 1000 - 0 accumulated.
        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->putJson("/api/v1/accounting/assets/{$asset->id}", ['purchase_cost' => 1500])
            ->assertOk();

        $this->assertSame(1500.0, (float) $asset->fresh()->book_value);
    }

    public function test_update_rejects_depreciation_changes_after_posting(): void
    {
        $asset = $this->asset();

        $asset->depreciationSchedules()->create([
            'period_date' => '2025-02-28',
            'depreciation_amount' => 16.67,
            'accumulated_depreciation' => 16.67,
            'book_value' => 983.33,
            'is_posted' => true,
        ]);

        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->putJson("/api/v1/accounting/assets/{$asset->id}", ['useful_life_years' => 10])
            ->assertStatus(422);

        // Cosmetic edits stay allowed.
        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->putJson("/api/v1/accounting/assets/{$asset->id}", ['name' => 'Still Editable'])
            ->assertOk();
        $this->assertSame('Still Editable', $asset->fresh()->name);
    }

    public function test_deactivated_asset_leaves_the_register(): void
    {
        $asset = $this->asset();

        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->deleteJson("/api/v1/accounting/assets/{$asset->id}")
            ->assertOk();

        $this->assertDatabaseHas('fixed_assets', [
            'id' => $asset->id,
            'is_active' => false,
            'status' => 'inactive',
        ]);

        // Gone from the default register…
        $default = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->getJson("/api/v1/accounting/assets?search={$asset->asset_number}");
        $default->assertOk();
        $this->assertSame(0, $default->json('total'));

        // …but still retrievable under the Inactive filter.
        $inactive = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->getJson("/api/v1/accounting/assets?status=inactive&search={$asset->asset_number}");
        $inactive->assertOk();
        $this->assertSame(1, $inactive->json('total'));
    }
}