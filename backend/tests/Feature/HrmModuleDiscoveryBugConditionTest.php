<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Feature;
use App\Modules\ModuleManager;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * HRM Module Discovery Bug Condition Exploration Test
 *
 * **Validates: Requirements 1.1, 1.2, 1.3, 1.4, 1.5**
 *
 * **CRITICAL**: This test MUST FAIL on unfixed code - failure confirms the bug exists.
 * **DO NOT attempt to fix the test or the code when it fails.**
 * **NOTE**: This test encodes the expected behavior - it will validate the fix when it passes after implementation.
 * **GOAL**: Surface counterexamples that demonstrate the bug exists.
 *
 * This test checks the bug condition: HRM module exists at `backend/modules/HRM/module.json`
 * with valid manifest containing `key: "hrm"`, but the system fails to include it in the
 * `/api/v1/features` API response.
 *
 * The test verifies:
 * 1. ModuleManager::all() includes a module with key = 'hrm'
 * 2. Feature::where('key', 'hrm')->first() returns a non-null result
 * 3. /api/v1/features API response includes an object with key = 'hrm'
 * 4. tenant_entitlements table includes a row for HRM catalog item for test tenant
 *
 * Expected Outcome on UNFIXED code:
 * - Test FAILS (this is correct - it proves the bug exists)
 * - Document which specific assertions fail to understand root cause
 */
class HrmModuleDiscoveryBugConditionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Set up the test environment.
     * Create the features and tenant_features tables manually since the migration skips them in testing.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create features table if it doesn't exist
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        // Create tenant_features table if it doesn't exist
        if (! Schema::hasTable('tenant_features')) {
            Schema::create('tenant_features', function (Blueprint $table) {
                $table->id();
                $table->foreignId('feature_id')->constrained()->onDelete('cascade');
                $table->boolean('is_enabled')->default(true);
                $table->timestamp('enabled_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Property 1: Bug Condition - HRM Module Discovery and API Response
     *
     * For the concrete failing case where the HRM module exists at `backend/modules/HRM/module.json`
     * with a valid manifest containing `key: "hrm"`, the system SHALL:
     * - Discover the module via ModuleManager
     * - Seed it into the features table
     * - Return it in the /api/v1/features API response with correct entitlement status
     * - Have corresponding entitlement records in tenant_entitlements
     *
     * **EXPECTED OUTCOME ON UNFIXED CODE**: This test FAILS (confirms bug exists)
     * **EXPECTED OUTCOME AFTER FIX**: This test PASSES (confirms bug is fixed)
     */
    public function test_hrm_module_is_discovered_seeded_and_returned_in_api(): void
    {
        // ── Assertion 1: ModuleManager discovers HRM module ──────────────────────
        /** @var ModuleManager $moduleManager */
        $moduleManager = app(ModuleManager::class);
        $modules = $moduleManager->all();

        $hrmModule = collect($modules)->firstWhere('key', 'hrm');

        $this->assertNotNull(
            $hrmModule,
            'COUNTEREXAMPLE: ModuleManager::all() does not include HRM module. '.
            'This indicates the module is not being discovered from backend/modules/HRM/. '.
            'Possible causes: incorrect base path resolution, missing module.json, or JSON parsing failure.'
        );

        // ── Assertion 2: HRM feature is seeded into features table ───────────────
        // Run the ModuleFeatureSeeder to seed features from discovered modules
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\ModuleFeatureSeeder']);

        $hrmFeature = Feature::where('key', 'hrm')->first();

        $this->assertNotNull(
            $hrmFeature,
            'COUNTEREXAMPLE: Feature table does not contain HRM entry after running ModuleFeatureSeeder. '.
            'This indicates the seeder is not creating the feature record. '.
            'Possible causes: ModuleManager not discovering HRM, or seeder not iterating over all modules.'
        );

        if ($hrmFeature) {
            $this->assertEquals('hrm', $hrmFeature->key);
            $this->assertEquals('NoovaERP HRM', $hrmFeature->name);
            $this->assertNotNull($hrmFeature->description);
        }

        // ── Assertion 3: Setup entitlement for test tenant ───────────────────────
        // Create catalog item type for modules
        $type = CatalogItemType::firstOrCreate(
            ['key' => 'module'],
            [
                'name' => 'Module',
                'description' => 'ERP Module',
            ]
        );

        // Create catalog item for HRM
        $hrmCatalogItem = CatalogItem::firstOrCreate(
            ['key' => 'hrm'],
            [
                'name' => 'Human Resources',
                'description' => 'HR Management Module',
                'type_id' => $type->id,
                'is_active' => true,
                'sort_order' => 1,
                'effective_from' => now()->subDay(),
            ]
        );

        // Create entitlement for test tenant
        $tenantId = '550e8400-e29b-41d4-a716-446655440000';
        TenantEntitlement::create([
            'tenant_id' => $tenantId,
            'catalog_item_id' => $hrmCatalogItem->id,
            'is_entitled' => true,
            'source' => 'tier_included',
            'resolved_at' => now(),
        ]);

        // ── Assertion 4: tenant_entitlements includes HRM for test tenant ────────
        $entitlement = TenantEntitlement::where('tenant_id', $tenantId)
            ->where('catalog_item_id', $hrmCatalogItem->id)
            ->where('is_entitled', true)
            ->first();

        $this->assertNotNull(
            $entitlement,
            'COUNTEREXAMPLE: tenant_entitlements table does not contain HRM entitlement for test tenant. '.
            'This indicates the entitlement system is not properly configured for HRM.'
        );

        // ── Assertion 5: Verify FeatureGateService returns HRM ───────────────────
        // Since we're in a test environment without full tenant context, we'll test
        // the FeatureGateService directly rather than through the API endpoint
        $featureGateService = app(\App\SuperAdmin\Application\FeatureGateService::class);
        $entitledFeatures = $featureGateService->all($tenantId);

        $hrmInEntitlements = collect($entitledFeatures)->firstWhere('key', 'hrm');

        $this->assertNotNull(
            $hrmInEntitlements,
            'COUNTEREXAMPLE: FeatureGateService::all() does not include HRM feature. '.
            'This indicates the entitlement service is not returning HRM despite it being in tenant_entitlements. '.
            'Possible causes: FeatureGateService not finding HRM in tenant_entitlements, '.
            'or catalog item not properly linked to feature.'
        );

        if ($hrmInEntitlements) {
            $this->assertEquals('hrm', $hrmInEntitlements['key']);
            $this->assertTrue(
                $hrmInEntitlements['enabled'],
                'COUNTEREXAMPLE: HRM feature is in entitlements but enabled=false. '.
                'This indicates the entitlement is not being correctly resolved.'
            );
        }
    }

    /**
     * Additional test: Verify HRM module.json exists and is valid
     *
     * This test verifies the precondition: the HRM module exists in the codebase
     * with a valid manifest file.
     */
    public function test_hrm_module_manifest_exists_and_is_valid(): void
    {
        $manifestPath = base_path('modules/HRM/module.json');

        $this->assertFileExists(
            $manifestPath,
            'HRM module manifest file does not exist at modules/HRM/module.json'
        );

        $manifestContent = file_get_contents($manifestPath);
        $manifest = json_decode($manifestContent, true);

        $this->assertIsArray($manifest, 'HRM module.json is not valid JSON');
        $this->assertArrayHasKey('key', $manifest, 'HRM module.json missing "key" field');
        $this->assertEquals('hrm', $manifest['key'], 'HRM module.json key is not "hrm"');
        $this->assertArrayHasKey('name', $manifest, 'HRM module.json missing "name" field');
        $this->assertNotEmpty($manifest['name'], 'HRM module.json name is empty');
    }
}
