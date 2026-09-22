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
 * Issue #233 (HIGH) — FixedAssetsController::export() was the one action #163
 * left unscoped: an entity-scoped user's CSV/XLSX export contained every
 * entity's assets.
 */
class HR233FixedAssetExportEntityScopingTest extends TenantTestCase
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
            ]);
        };
        $this->assetANumber = $mkAsset('Entity A Machine', $this->entityA)->asset_number;
        $this->assetBNumber = $mkAsset('Entity B Machine', $this->entityB)->asset_number;
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

    public function test_export_contains_only_the_scoped_users_entity_assets(): void
    {
        $response = $this->actingAs($this->scopedUser())
            ->get('/api/v1/accounting/assets/export?'.http_build_query(['columns' => ['asset_number']]));

        $this->assertSame(200, $response->getStatusCode());

        $path = $response->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray();
        $exported = array_map(fn ($row) => (string) ($row[0] ?? ''), $sheet);

        $this->assertContains($this->assetANumber, $exported,
            'Scoped user must see their own entity asset');
        $this->assertNotContains($this->assetBNumber, $exported,
            'Entity B asset must NOT leak into the export');
    }
}
