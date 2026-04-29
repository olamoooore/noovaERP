<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureGateApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the /api/v1/features endpoint returns entitled features with enabled field.
     *
     * This test verifies the fix for the HRM sidebar visibility bug where the frontend
     * expects an 'enabled' field in the feature response.
     */
    public function test_features_endpoint_returns_enabled_field_for_entitled_features(): void
    {
        // Arrange: Create a test catalog item type
        $type = CatalogItemType::create([
            'key' => 'module',
            'name' => 'Module',
            'description' => 'ERP Module',
        ]);

        // Create a test catalog item (HRM module)
        $catalogItem = CatalogItem::create([
            'key' => 'hrm',
            'name' => 'Human Resources',
            'description' => 'HR Management Module',
            'type_id' => $type->id,
            'is_active' => true,
            'sort_order' => 1,
            'effective_from' => now()->subDay(),
        ]);

        // Create an entitled feature for a test tenant
        $tenantId = '550e8400-e29b-41d4-a716-446655440000';
        TenantEntitlement::create([
            'tenant_id' => $tenantId,
            'catalog_item_id' => $catalogItem->id,
            'is_entitled' => true,
            'source' => 'tier_included',
            'resolved_at' => now(),
        ]);

        // Act: Call the features endpoint
        $response = $this->withHeaders([
            'X-Tenant-ID' => $tenantId,
        ])->getJson('/api/v1/features');

        // Assert: Response should be successful
        $response->assertStatus(200);

        // Assert: Response should contain the feature with enabled field
        $response->assertJsonStructure([
            'tenant_id',
            'count',
            'features' => [
                '*' => [
                    'key',
                    'name',
                    'enabled',
                    'source',
                ],
            ],
            'cached_at',
        ]);

        // Assert: The HRM feature should be present with enabled: true
        $features = $response->json('features');
        $hrmFeature = collect($features)->firstWhere('key', 'hrm');

        $this->assertNotNull($hrmFeature, 'HRM feature should be present in the response');
        $this->assertEquals('hrm', $hrmFeature['key']);
        $this->assertEquals('Human Resources', $hrmFeature['name']);
        $this->assertTrue($hrmFeature['enabled'], 'HRM feature should have enabled: true');
        $this->assertEquals('tier_included', $hrmFeature['source']);
    }

    /**
     * Test that non-entitled features are not returned in the response.
     */
    public function test_features_endpoint_excludes_non_entitled_features(): void
    {
        // Arrange: Create a test catalog item type
        $type = CatalogItemType::create([
            'key' => 'module',
            'name' => 'Module',
            'description' => 'ERP Module',
        ]);

        // Create a test catalog item
        $catalogItem = CatalogItem::create([
            'key' => 'premium_module',
            'name' => 'Premium Module',
            'description' => 'Premium Feature',
            'type_id' => $type->id,
            'is_active' => true,
            'sort_order' => 1,
            'effective_from' => now()->subDay(),
        ]);

        // Create a non-entitled feature (is_entitled = false)
        $tenantId = '550e8400-e29b-41d4-a716-446655440001';
        TenantEntitlement::create([
            'tenant_id' => $tenantId,
            'catalog_item_id' => $catalogItem->id,
            'is_entitled' => false,
            'source' => 'override_exclude',
            'resolved_at' => now(),
        ]);

        // Act: Call the features endpoint
        $response = $this->withHeaders([
            'X-Tenant-ID' => $tenantId,
        ])->getJson('/api/v1/features');

        // Assert: Response should be successful
        $response->assertStatus(200);

        // Assert: The premium module should NOT be in the response
        $features = $response->json('features');
        $premiumFeature = collect($features)->firstWhere('key', 'premium_module');

        $this->assertNull($premiumFeature, 'Non-entitled features should not be in the response');
    }
}
