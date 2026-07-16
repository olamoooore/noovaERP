<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EntityAccessService;
use App\Services\EntityModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultiEntityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected int $groupId;

    protected int $entityAId;

    protected int $entityBId;

    protected int $bookAId;

    protected int $bookBId;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Run core tenant migrations in the test database (not the full module pipeline)
        Artisan::call('migrate', [
            '--path' => [database_path('migrations/tenant')],
            '--realpath' => true,
        ]);

        $this->user = User::factory()->create();
        $this->seedEntityStructure();
    }

    // ── Entity Module Activation ────────────────────────

    public function test_activate_module_respects_hard_dependencies(): void
    {
        $service = app(EntityModuleService::class);

        // Activate accounting first (no deps)
        $service->activate($this->entityAId, 'accounting');

        // Inventory depends on accounting — should succeed now
        $service->activate($this->entityAId, 'inventory');

        $modules = collect($service->listModules($this->entityAId));
        $this->assertTrue($modules->firstWhere('module_code', 'inventory')['is_active']);
    }

    public function test_activate_module_fails_if_dependency_missing(): void
    {
        $service = app(EntityModuleService::class);

        // POS depends on accounting + inventory
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/hard dependency/');
        $service->activate($this->entityAId, 'pos');
    }

    public function test_deactivate_module_blocked_by_dependents(): void
    {
        $service = app(EntityModuleService::class);
        $service->activate($this->entityAId, 'accounting');
        $service->activate($this->entityAId, 'inventory');

        // Cannot deactivate accounting because inventory depends on it
        $this->expectException(\RuntimeException::class);
        $service->deactivate($this->entityAId, 'accounting');
    }

    public function test_apply_industry_pack(): void
    {
        $service = app(EntityModuleService::class);

        $count = $service->applyIndustryPack($this->entityAId, 'retail_pos');
        $this->assertGreaterThan(0, $count);

        $modules = collect($service->listModules($this->entityAId));
        $this->assertTrue($modules->firstWhere('module_code', 'accounting')['is_active']);
    }

    // ── Entity Access Control ───────────────────────────

    public function test_grant_and_check_access(): void
    {
        $service = app(EntityAccessService::class);

        $service->grant(1, $this->entityAId, 'editor', true);

        $this->assertTrue($service->canAccess(1, $this->entityAId));
        $this->assertTrue($service->hasRole(1, $this->entityAId, 'viewer'));
        $this->assertTrue($service->hasRole(1, $this->entityAId, 'editor'));
        $this->assertFalse($service->hasRole(1, $this->entityAId, 'admin'));
    }

    public function test_revoke_access(): void
    {
        $service = app(EntityAccessService::class);

        $service->grant(1, $this->entityAId, 'viewer');
        $service->revoke(1, $this->entityAId);

        $this->assertFalse($service->canAccess(1, $this->entityAId));
    }

    public function test_default_entity(): void
    {
        $service = app(EntityAccessService::class);

        $service->grant(1, $this->entityAId, 'admin', false);
        $service->grant(1, $this->entityBId, 'viewer', true);

        $default = $service->getDefaultEntity(1);
        $this->assertEquals($this->entityBId, $default->id);
    }

    // ── Entity-Scoped Reports (ReportFilter) ────────────

    public function test_report_filter_includes_entity_params(): void
    {
        $filter = \Modules\FinancialReporting\DTOs\ReportFilter::fromRequest([
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
            'legal_entity_id' => $this->entityAId,
            'entity_book_id' => $this->bookAId,
        ]);

        $this->assertEquals($this->entityAId, $filter->legalEntityId);
        $this->assertEquals($this->bookAId, $filter->entityBookId);
    }

    // ── Entity Management API ───────────────────────────
    // These routes sit behind the full tenant middleware stack.
    // We bypass tenant middleware to test the controller logic.

    public function test_create_group_via_api(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\ResolveEntityContext::class,
            \App\Http\Middleware\EnsureEntityAccess::class,
            \App\Http\Middleware\EnsureAccountingWriteAccess::class,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/groups', [
                'name' => 'Test Group',
                'code' => 'TG2',
                'base_currency' => 'USD',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Test Group']);
    }

    public function test_create_entity_via_api(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\ResolveEntityContext::class,
            \App\Http\Middleware\EnsureEntityAccess::class,
            \App\Http\Middleware\EnsureAccountingWriteAccess::class,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/legal-entities', [
                'group_id' => $this->groupId,
                'name' => 'Test Entity C',
                'code' => 'ENT-C',
                'country_code' => 'US',
                'base_currency' => 'USD',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['code' => 'ENT-C']);
    }

    public function test_list_entities_filtered_by_group(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\ResolveEntityContext::class,
            \App\Http\Middleware\EnsureEntityAccess::class,
            \App\Http\Middleware\EnsureAccountingWriteAccess::class,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/accounting/legal-entities?group_id={$this->groupId}");

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, count($response->json()));
    }

    // ── Helpers ─────────────────────────────────────────

    protected function seedEntityStructure(): void
    {
        $this->groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Test Group',
            'code' => 'TG',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityAId = DB::table('legal_entities')->insertGetId([
            'group_id' => $this->groupId,
            'name' => 'Entity A',
            'code' => 'ENT-A',
            'country_code' => 'SG',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityBId = DB::table('legal_entities')->insertGetId([
            'group_id' => $this->groupId,
            'name' => 'Entity B',
            'code' => 'ENT-B',
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bookAId = DB::table('entity_books')->insertGetId([
            'legal_entity_id' => $this->entityAId,
            'name' => 'Primary Book A',
            'book_type' => 'general',
            'currency_code' => 'SGD',
            'is_primary' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bookBId = DB::table('entity_books')->insertGetId([
            'legal_entity_id' => $this->entityBId,
            'name' => 'Primary Book B',
            'book_type' => 'general',
            'currency_code' => 'MYR',
            'is_primary' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
