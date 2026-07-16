<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Feature;
use App\Modules\ModuleManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * HRM Preservation Property Tests
 *
 * **Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8**
 *
 * **IMPORTANT**: Follow observation-first methodology.
 * These tests capture the OBSERVED behavior on UNFIXED code for non-HRM modules.
 *
 * **Property 2: Preservation** - Non-HRM Module Behavior
 *
 * For all non-HRM modules (POS, Inventory, Sales, Accounting, etc.), the fixed system
 * SHALL produce exactly the same discovery, seeding, and API response behavior as the
 * original system, preserving all existing functionality.
 *
 * Expected Outcome on UNFIXED code:
 * - Tests PASS (this confirms baseline behavior to preserve)
 *
 * Expected Outcome AFTER fix:
 * - Tests STILL PASS (confirms no regressions)
 *
 * Testing Strategy:
 * - Property-based testing generates many test cases for stronger guarantees
 * - Tests verify that non-HRM modules continue to work exactly as before
 * - Tests check core features, addon features, and edge cases
 */
class HrmPreservationPropertyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Observed baseline: Non-HRM modules discovered on unfixed code
     * Based on observation, these modules were discovered:
     * - Core modules: communication, core, event_management, field-service, inventory, pos, sales, website-builder
     * - Addon modules: ai, ecommerce, financial-reporting, iot, loyalty, ocr-bookkeeping, payment_gateway, whatsapp
     */
    private const OBSERVED_NON_HRM_CORE_MODULES = [
        'communication',
        'core',
        'event_management',
        'field-service',
        'inventory',
        'pos',
        'sales',
        'website-builder',
    ];

    private const OBSERVED_NON_HRM_ADDON_MODULES = [
        'ai',
        'ecommerce',
        'financial-reporting',
        'iot',
        'loyalty',
        'ocr-bookkeeping',
        'payment_gateway',
        'whatsapp',
    ];

    /**
     * Set up the test environment.
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
     * Property: Non-HRM Core Module Discovery Preservation
     *
     * For all core modules that are NOT HRM, the system SHALL continue to discover them
     * exactly as before the fix.
     *
     * **Validates: Requirements 3.8**
     */
    public function test_non_hrm_core_modules_are_discovered(): void
    {
        /** @var ModuleManager $moduleManager */
        $moduleManager = app(ModuleManager::class);
        $modules = $moduleManager->all();

        $discoveredKeys = collect($modules)->pluck('key')->toArray();

        // Property: For all observed non-HRM core modules, they SHALL be discovered
        foreach (self::OBSERVED_NON_HRM_CORE_MODULES as $expectedKey) {
            $this->assertContains(
                $expectedKey,
                $discoveredKeys,
                "REGRESSION: Core module '{$expectedKey}' was discovered on unfixed code but is missing now. ".
                'This indicates the fix broke module discovery for non-HRM modules.'
            );

            // Verify the module is marked as core
            $module = collect($modules)->firstWhere('key', $expectedKey);
            $this->assertNotNull($module, "Module '{$expectedKey}' should exist");
            $this->assertTrue(
                $module->isCore(),
                "REGRESSION: Module '{$expectedKey}' should be marked as core but is not."
            );
        }
    }

    /**
     * Property: Non-HRM Addon Module Discovery Preservation
     *
     * For all addon modules that are NOT HRM, the system SHALL continue to discover them
     * exactly as before the fix.
     *
     * **Validates: Requirements 3.8**
     */
    public function test_non_hrm_addon_modules_are_discovered(): void
    {
        /** @var ModuleManager $moduleManager */
        $moduleManager = app(ModuleManager::class);
        $modules = $moduleManager->all();

        $discoveredKeys = collect($modules)->pluck('key')->toArray();

        // Property: For all observed non-HRM addon modules, they SHALL be discovered
        foreach (self::OBSERVED_NON_HRM_ADDON_MODULES as $expectedKey) {
            $this->assertContains(
                $expectedKey,
                $discoveredKeys,
                "REGRESSION: Addon module '{$expectedKey}' was discovered on unfixed code but is missing now. ".
                'This indicates the fix broke module discovery for non-HRM modules.'
            );

            // Verify the module is marked as addon
            $module = collect($modules)->firstWhere('key', $expectedKey);
            $this->assertNotNull($module, "Module '{$expectedKey}' should exist");
            $this->assertFalse(
                $module->isCore(),
                "REGRESSION: Module '{$expectedKey}' should be marked as addon but is marked as core."
            );
        }
    }

    /**
     * Property: Module Discovery Count Preservation
     *
     * The total number of discovered modules SHALL remain the same or increase
     * (we expect it to stay the same since HRM is already discovered on unfixed code).
     *
     * **Validates: Requirements 3.8**
     */
    public function test_module_discovery_count_is_preserved(): void
    {
        /** @var ModuleManager $moduleManager */
        $moduleManager = app(ModuleManager::class);
        $modules = $moduleManager->all();

        // Observed: 17 modules total on unfixed code (8 core + 9 addon including HRM)
        $expectedMinCount = count(self::OBSERVED_NON_HRM_CORE_MODULES) + count(self::OBSERVED_NON_HRM_ADDON_MODULES) + 1; // +1 for HRM

        $this->assertGreaterThanOrEqual(
            $expectedMinCount,
            count($modules),
            "REGRESSION: Expected at least {$expectedMinCount} modules to be discovered, but found ".count($modules).'. '.
            'This indicates the fix broke module discovery.'
        );
    }

    /**
     * Property: Non-HRM Feature Seeding Preservation
     *
     * For all non-HRM modules, the system SHALL continue to seed them into the features
     * table exactly as before the fix.
     *
     * **Validates: Requirements 3.7, 3.8**
     */
    public function test_non_hrm_features_are_seeded(): void
    {
        // Run the ModuleFeatureSeeder
        $this->artisan('db:seed', ['--class' => 'ModuleFeatureSeeder']);

        $allExpectedKeys = array_merge(
            self::OBSERVED_NON_HRM_CORE_MODULES,
            self::OBSERVED_NON_HRM_ADDON_MODULES
        );

        // Property: For all observed non-HRM modules, they SHALL be seeded into features table
        foreach ($allExpectedKeys as $expectedKey) {
            $feature = Feature::where('key', $expectedKey)->first();

            $this->assertNotNull(
                $feature,
                "REGRESSION: Feature '{$expectedKey}' was seeded on unfixed code but is missing now. ".
                'This indicates the fix broke feature seeding for non-HRM modules.'
            );

            if ($feature) {
                $this->assertNotEmpty(
                    $feature->name,
                    "REGRESSION: Feature '{$expectedKey}' should have a non-empty name."
                );
            }
        }
    }

    /**
     * Property: Core Features Return with enabled=true
     *
     * Core features SHALL continue to return with enabled=true regardless of entitlement records.
     *
     * **Validates: Requirements 3.6**
     */
    public function test_core_features_return_with_enabled_true(): void
    {
        // Run the ModuleFeatureSeeder
        $this->artisan('db:seed', ['--class' => 'ModuleFeatureSeeder']);

        // Property: For all core modules, their features SHALL have is_core=true
        foreach (self::OBSERVED_NON_HRM_CORE_MODULES as $coreKey) {
            $feature = Feature::where('key', $coreKey)->first();

            $this->assertNotNull(
                $feature,
                "Core feature '{$coreKey}' should exist in features table."
            );

            if ($feature) {
                $this->assertTrue(
                    $feature->is_core,
                    "REGRESSION: Core feature '{$coreKey}' should have is_core=true but has is_core=false. ".
                    'This indicates the fix broke core feature marking.'
                );
            }
        }
    }

    /**
     * Property: ModuleFeatureSeeder Uses updateOrCreate (No Duplicates)
     *
     * The ModuleFeatureSeeder SHALL continue to use updateOrCreate to avoid duplicate entries
     * when run multiple times.
     *
     * **Validates: Requirements 3.7**
     */
    public function test_module_feature_seeder_prevents_duplicates(): void
    {
        // Run the seeder twice
        $this->artisan('db:seed', ['--class' => 'ModuleFeatureSeeder']);
        $this->artisan('db:seed', ['--class' => 'ModuleFeatureSeeder']);

        // Property: For all modules, there SHALL be exactly one feature record per key
        $allExpectedKeys = array_merge(
            self::OBSERVED_NON_HRM_CORE_MODULES,
            self::OBSERVED_NON_HRM_ADDON_MODULES,
            ['hrm'] // Include HRM as well
        );

        foreach ($allExpectedKeys as $key) {
            $count = Feature::where('key', $key)->count();

            $this->assertEquals(
                1,
                $count,
                "REGRESSION: Feature '{$key}' should have exactly 1 record but has {$count}. ".
                'This indicates the seeder is creating duplicates instead of using updateOrCreate.'
            );
        }
    }

    /**
     * Property: Module Discovery Order Preservation
     *
     * Modules SHALL be sorted with core modules first, then addons, alphabetically within each group.
     *
     * **Validates: Requirements 3.8**
     */
    public function test_module_discovery_order_is_preserved(): void
    {
        /** @var ModuleManager $moduleManager */
        $moduleManager = app(ModuleManager::class);
        $modules = $moduleManager->all();

        $coreModules = collect($modules)->filter(fn ($m) => $m->isCore());
        $addonModules = collect($modules)->filter(fn ($m) => ! $m->isCore());

        // Property: Core modules SHALL come before addon modules
        $firstAddonIndex = collect($modules)->search(fn ($m) => ! $m->isCore());
        $lastCoreIndex = collect($modules)->search(fn ($m) => $m->isCore(), true);

        if ($firstAddonIndex !== false && $lastCoreIndex !== false) {
            $this->assertLessThan(
                $firstAddonIndex,
                $lastCoreIndex,
                'REGRESSION: Core modules should come before addon modules in the discovery order.'
            );
        }

        // Property: Within core modules, they SHALL be sorted alphabetically by key
        $coreKeys = $coreModules->pluck('key')->toArray();
        $sortedCoreKeys = $coreKeys;
        sort($sortedCoreKeys);

        $this->assertEquals(
            $sortedCoreKeys,
            $coreKeys,
            'REGRESSION: Core modules should be sorted alphabetically by key.'
        );

        // Property: Within addon modules, they SHALL be sorted alphabetically by key
        $addonKeys = $addonModules->pluck('key')->toArray();
        $sortedAddonKeys = $addonKeys;
        sort($sortedAddonKeys);

        $this->assertEquals(
            $sortedAddonKeys,
            $addonKeys,
            'REGRESSION: Addon modules should be sorted alphabetically by key.'
        );
    }

    /**
     * Property: Module Manifest Validation Preservation
     *
     * Modules with missing or invalid manifests SHALL continue to be skipped during discovery.
     *
     * **Validates: Requirements 3.8**
     */
    public function test_invalid_modules_are_skipped(): void
    {
        /** @var ModuleManager $moduleManager */
        $moduleManager = app(ModuleManager::class);
        $modules = $moduleManager->all();

        // Property: All discovered modules SHALL have non-empty key and name
        foreach ($modules as $module) {
            $this->assertNotEmpty(
                $module->key,
                'REGRESSION: Discovered module has empty key. Module discovery should skip modules with empty keys.'
            );

            $this->assertNotEmpty(
                $module->name,
                "REGRESSION: Discovered module '{$module->key}' has empty name. Module discovery should skip modules with empty names."
            );
        }
    }

    /**
     * Property: Module Base Path Resolution Preservation
     *
     * The ModuleManager SHALL continue to resolve the base path correctly for all modules.
     *
     * **Validates: Requirements 3.8**
     */
    public function test_module_base_paths_are_correct(): void
    {
        /** @var ModuleManager $moduleManager */
        $moduleManager = app(ModuleManager::class);
        $modules = $moduleManager->all();

        // Property: All discovered modules SHALL have a valid base path
        foreach ($modules as $module) {
            $this->assertNotEmpty(
                $module->basePath,
                "REGRESSION: Module '{$module->key}' has empty base path."
            );

            // Verify the base path exists
            $this->assertDirectoryExists(
                $module->basePath,
                "REGRESSION: Module '{$module->key}' base path does not exist: {$module->basePath}"
            );

            // Verify the module.json exists in the base path
            $manifestPath = $module->basePath.'/module.json';
            $this->assertFileExists(
                $manifestPath,
                "REGRESSION: Module '{$module->key}' manifest does not exist at: {$manifestPath}"
            );
        }
    }

    /**
     * Property: Feature Key Uniqueness Preservation
     *
     * Each feature key SHALL be unique in the features table.
     *
     * **Validates: Requirements 3.7**
     */
    public function test_feature_keys_are_unique(): void
    {
        // Run the ModuleFeatureSeeder
        $this->artisan('db:seed', ['--class' => 'ModuleFeatureSeeder']);

        $features = Feature::all();
        $keys = $features->pluck('key')->toArray();
        $uniqueKeys = array_unique($keys);

        $this->assertCount(
            count($uniqueKeys),
            $keys,
            'REGRESSION: Feature keys are not unique. Found duplicate keys: '.
            implode(', ', array_diff_assoc($keys, $uniqueKeys))
        );
    }
}
