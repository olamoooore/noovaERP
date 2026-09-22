<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #234 (HIGH) — FixedAssetsController::import() created rows with
 * legal_entity_id = NULL (store() had been updated by #163, import was missed),
 * making every imported asset invisible to entity-scoped users because
 * `whereIn('legal_entity_id', ids)` never matches NULL.
 */
class HR234FixedAssetImportEntityStampingTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'HR234 Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'HR234 Group', 'code' => 'EG-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD', 'fiscal_year_start' => '01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity A', 'code' => 'A-'.Str::upper(Str::random(5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['1590' => 'asset', '5100' => 'expense', '1595' => 'asset'] as $code => $type) {
            ChartOfAccount::firstOrCreate(
                ['code' => $code],
                ['name' => "Account {$code}", 'type' => $type, 'is_active' => true]
            );
        }
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

    public function test_imported_assets_are_stamped_with_the_resolved_entity(): void
    {
        $csv = implode("\n", [
            'asset_number,name,category,purchase_date,purchase_cost,depreciation_method,useful_life_years,depreciation_start_date,asset_account_code,depreciation_account_code,accumulated_depreciation_account_code',
            'IMP-0001,Imported Machine,plant,2026-01-15,300,straight_line,3,2026-01-15,1590,5100,1595',
        ]);

        $response = $this->actingAs($this->scopedUser())
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/accounting/assets/import', [
                'file' => UploadedFile::fake()->createWithContent('assets.csv', $csv),
            ]);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame(1, $response->json('created'));

        $asset = FixedAsset::where('asset_number', 'IMP-0001')->first();
        $this->assertNotNull($asset, 'imported asset must exist');
        $this->assertSame(
            $this->entityA,
            (int) $asset->legal_entity_id,
            'Imported asset must be stamped with the resolved entity context (not NULL)'
        );
    }
}
