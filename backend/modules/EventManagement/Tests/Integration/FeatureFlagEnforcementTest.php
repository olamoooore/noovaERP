<?php

namespace Modules\EventManagement\Tests\Integration;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\EventManagement\Services\FeatureFlagController;
use Modules\EventManagement\Services\EventService;
use Modules\EventManagement\Services\BookingService;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\TenantSchemaManager;
use Modules\EventManagement\Http\Middleware\FeatureFlagMiddleware;
use Modules\EventManagement\Http\Controllers\EventController;
use Modules\EventManagement\Http\Controllers\BookingController;
use Modules\EventManagement\Exceptions\FeatureNotEnabledException;

/**
 * Integration Test for Feature Flag Enforcement
 * 
 * Tests that feature flag enforcement works correctly across all components
 * Validates: Requirements 5.1, 8.5
 */
class FeatureFlagEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private FeatureFlagController $featureFlagController;
    private EventService $eventService;
    private BookingService $bookingService;
    private AuditService $auditService;
    private TenantSchemaManager $tenantSchemaManager;
    private FeatureFlagMiddleware $featureFlagMiddleware;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Run migrations to set up the database schema
        $this->artisan('migrate', [
            '--path' => 'backend/modules/EventManagement/Database/Migrations',
            '--realpath' => true
        ]);

        // Set up service dependencies
        $this->auditService = $this->createMock(AuditService::class);
        $this->tenantSchemaManager = $this->createMock(TenantSchemaManager::class);
        
        $this->featureFlagController = new FeatureFlagController(
            $this->tenantSchemaManager
        );

        $this->eventService = new EventService(
            $this->tenantSchemaManager,
            $this->auditService
        );

        $this->bookingService = new BookingService(
            $this->tenantSchemaManager,
            $this->auditService
        );

        $this->featureFlagMiddleware = new FeatureFlagMiddleware(
            $this->featureFlagController
        );
    }

    /**
     * Test feature flag enforcement at service level
     * 
     * @test
     */
    public function test_feature_flag_enforcement_at_service_level()
    {
        $tenantId = 'tenant_feature_test';
        
        // Configure tenant schema manager
        $this->tenantSchemaManager->expects($this->any())
            ->method('getCurrentTenantId')
            ->willReturn($tenantId);

        // Test with feature disabled
        $this->featureFlagController->disableFeature($tenantId, 'event_management');
        
        $hasFeature = $this->featureFlagController->hasFeature($tenantId, 'event_management');
        $this->assertFalse($hasFeature, 'Feature should be disabled');

        // Test that services respect feature flag
        $this->expectException(FeatureNotEnabledException::class);
        $this->expectExceptionMessage('Event management feature is not enabled for this tenant');
        
        $eventData = [
            'title' => 'Test Event',
            'description' => 'Test event description',
            'event_date' => now()->addDays(30)->toDateTimeString(),
            'venue' => 'Test Venue',
            'total_capacity' => 100,
            'terms_conditions' => 'Test terms'
        ];

        $this->eventService->createEvent($eventData);
    }

    /**
     * Test feature flag enforcement at middleware level
     * 
     * @test
     */
    public function test_feature_flag_enforcement_at_middleware_level()
    {
        $tenantId = 'tenant_middleware_test';
        
        // Configure tenant schema manager
        $this->tenantSchemaManager->expects($this->any())
            ->method('getCurrentTenantId')
            ->willReturn($tenantId);

        // Create a mock request
        $request = Request::create('/api/v1/events', 'GET');
        $request->headers->set('X-Tenant-ID', $tenantId);

        // Test with feature disabled
        $this->featureFlagController->disableFeature($tenantId, 'event_management');

        $response = null;
        $next = function ($req) use (&$response) {
            $response = new Response('Success', 200);
            return $response;
        };

        // Middleware should block the request
        $middlewareResponse = $this->featureFlagMiddleware->handle($request, $next);
        
        $this->assertEquals(403, $middlewareResponse->getStatusCode());
        $this->assertStringContainsString('Event management feature is not enabled', $middlewareResponse->getContent());

        // Test with feature enabled
        $this->featureFlagController->enableFeature($tenantId, 'event_management');

        $middlewareResponse = $this->featureFlagMiddleware->handle($request, $next);
        
        $this->assertEquals(200, $middlewareResponse->getStatusCode());
        $this->assertEquals('Success', $middlewareResponse->getContent());
    }

    /**
     * Test feature flag enforcement at controller level
     * 
     * @test
     */
    public function test_feature_flag_enforcement_at_controller_level()
    {
        $tenantId = 'tenant_controller_test';
        
        // Configure tenant schema manager
        $this->tenantSchemaManager->expects($this->any())
            ->method('getCurrentTenantId')
            ->willReturn($tenantId);

        // Create controllers
        $eventController = new EventController(
            $this->eventService,
            $this->featureFlagController
        );

        $bookingController = new BookingController(
            $this->bookingService,
            $this->featureFlagController
        );

        // Test EventController with feature disabled
        $this->featureFlagController->disableFeature($tenantId, 'event_management');

        $request = Request::create('/api/v1/events', 'GET');
        $request->headers->set('X-Tenant-ID', $tenantId);

        $this->expectException(FeatureNotEnabledException::class);
        $eventController->index($request);
    }

    /**
     * Test feature flag inheritance and hierarchy
     * 
     * @test
     */
    public function test_feature_flag_inheritance_and_hierarchy()
    {
        $tenantId = 'tenant_hierarchy_test';
        
        // Configure tenant schema manager
        $this->tenantSchemaManager->expects($this->any())
            ->method('getCurrentTenantId')
            ->willReturn($tenantId);

        // Test default feature flag state (should be disabled by default)
        $hasFeature = $this->featureFlagController->hasFeature($tenantId, 'event_management');
        $this->assertFalse($hasFeature, 'Feature should be disabled by default');

        // Test enabling feature
        $this->featureFlagController->enableFeature($tenantId, 'event_management');
        $hasFeature = $this->featureFlagController->hasFeature($tenantId, 'event_management');
        $this->assertTrue($hasFeature, 'Feature should be enabled after enabling');

        // Test disabling feature
        $this->featureFlagController->disableFeature($tenantId, 'event_management');
        $hasFeature = $this->featureFlagController->hasFeature($tenantId, 'event_management');
        $this->assertFalse($hasFeature, 'Feature should be disabled after disabling');

        // Test non-existent feature
        $hasNonExistentFeature = $this->featureFlagController->hasFeature($tenantId, 'non_existent_feature');
        $this->assertFalse($hasNonExistentFeature, 'Non-existent feature should be disabled');
    }

    /**
     * Test feature flag enforcement across multiple tenants
     * 
     * @test
     */
    public function test_feature_flag_enforcement_across_multiple_tenants()
    {
        $tenant1Id = 'tenant_multi_1';
        $tenant2Id = 'tenant_multi_2';
        $tenant3Id = 'tenant_multi_3';

        // Enable feature for tenant 1 only
        $this->featureFlagController->enableFeature($tenant1Id, 'event_management');
        $this->featureFlagController->disableFeature($tenant2Id, 'event_management');
        $this->featureFlagController->disableFeature($tenant3Id, 'event_management');

        // Test tenant 1 has access
        $this->assertTrue($this->featureFlagController->hasFeature($tenant1Id, 'event_management'));
        
        // Test tenant 2 and 3 don't have access
        $this->assertFalse($this->featureFlagController->hasFeature($tenant2Id, 'event_management'));
        $this->assertFalse($this->featureFlagController->hasFeature($tenant3Id, 'event_management'));

        // Enable feature for tenant 2
        $this->featureFlagController->enableFeature($tenant2Id, 'event_management');
        
        // Verify tenant 2 now has access
        $this->assertTrue($this->featureFlagController->hasFeature($tenant2Id, 'event_management'));
        
        // Verify tenant 1 still has access
        $this->assertTrue($this->featureFlagController->hasFeature($tenant1Id, 'event_management'));
        
        // Verify tenant 3 still doesn't have access
        $this->assertFalse($this->featureFlagController->hasFeature($tenant3Id, 'event_management'));

        // Disable feature for tenant 1
        $this->featureFlagController->disableFeature($tenant1Id, 'event_management');
        
        // Verify tenant 1 no longer has access
        $this->assertFalse($this->featureFlagController->hasFeature($tenant1Id, 'event_management'));
        
        // Verify tenant 2 still has access
        $this->assertTrue($this->featureFlagController->hasFeature($tenant2Id, 'event_management'));
    }

    /**
     * Test feature flag audit logging
     * 
     * @test
     */
    public function test_feature_flag_audit_logging()
    {
        $tenantId = 'tenant_audit_test';
        
        // Configure audit service to capture logs
        $auditLogs = [];
        
        $this->auditService->expects($this->any())
            ->method('logActivity')
            ->willReturnCallback(function($tenant, $action, $subject, $changes) use (&$auditLogs) {
                $auditLogs[] = [
                    'tenant_id' => $tenant,
                    'action' => $action,
                    'subject' => $subject,
                    'changes' => $changes
                ];
            });

        // Configure tenant schema manager
        $this->tenantSchemaManager->expects($this->any())
            ->method('getCurrentTenantId')
            ->willReturn($tenantId);

        // Enable feature (should be logged)
        $this->featureFlagController->enableFeature($tenantId, 'event_management');
        
        // Disable feature (should be logged)
        $this->featureFlagController->disableFeature($tenantId, 'event_management');

        // Verify audit logs were created
        $enableLogs = array_filter($auditLogs, function($log) {
            return $log['action'] === 'feature_enabled';
        });

        $disableLogs = array_filter($auditLogs, function($log) {
            return $log['action'] === 'feature_disabled';
        });

        $this->assertNotEmpty($enableLogs, 'Feature enable should be logged');
        $this->assertNotEmpty($disableLogs, 'Feature disable should be logged');

        // Verify log details
        $enableLog = array_values($enableLogs)[0];
        $this->assertEquals($tenantId, $enableLog['tenant_id']);
        $this->assertEquals('feature_flag', $enableLog['subject']);
        $this->assertArrayHasKey('feature', $enableLog['changes']);
        $this->assertEquals('event_management', $enableLog['changes']['feature']);
    }

    /**
     * Test feature flag seeding functionality
     * 
     * @test
     */
    public function test_feature_flag_seeding_functionality()
    {
        $tenantId = 'tenant_seeding_test';
        
        // Configure tenant schema manager
        $this->tenantSchemaManager->expects($this->any())
            ->method('getCurrentTenantId')
            ->willReturn($tenantId);

        // Verify feature is disabled by default
        $hasFeature = $this->featureFlagController->hasFeature($tenantId, 'event_management');
        $this->assertFalse($hasFeature, 'Feature should be disabled by default');

        // Seed default flags
        $this->featureFlagController->seedDefaultFlags();

        // Verify feature is still disabled after seeding (default should be disabled)
        $hasFeature = $this->featureFlagController->hasFeature($tenantId, 'event_management');
        $this->assertFalse($hasFeature, 'Feature should remain disabled after seeding defaults');

        // Test that seeding is idempotent
        $this->featureFlagController->seedDefaultFlags();
        $this->featureFlagController->seedDefaultFlags();
        
        $hasFeature = $this->featureFlagController->hasFeature($tenantId, 'event_management');
        $this->assertFalse($hasFeature, 'Multiple seeding calls should not change state');
    }

    /**
     * Test feature flag performance under load
     * 
     * @test
     */
    public function test_feature_flag_performance_under_load()
    {
        $tenantId = 'tenant_performance_test';
        
        // Configure tenant schema manager
        $this->tenantSchemaManager->expects($this->any())
            ->method('getCurrentTenantId')
            ->willReturn($tenantId);

        // Enable feature
        $this->featureFlagController->enableFeature($tenantId, 'event_management');

        // Test multiple rapid feature checks (simulating high load)
        $startTime = microtime(true);
        
        for ($i = 0; $i < 1000; $i++) {
            $hasFeature = $this->featureFlagController->hasFeature($tenantId, 'event_management');
            $this->assertTrue($hasFeature, "Feature check #{$i} should return true");
        }
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // Performance assertion - 1000 checks should complete in reasonable time
        $this->assertLessThan(1.0, $executionTime, 'Feature flag checks should be performant');
        
        // Test feature flag changes under load
        for ($i = 0; $i < 10; $i++) {
            $this->featureFlagController->disableFeature($tenantId, 'event_management');
            $this->assertFalse($this->featureFlagController->hasFeature($tenantId, 'event_management'));
            
            $this->featureFlagController->enableFeature($tenantId, 'event_management');
            $this->assertTrue($this->featureFlagController->hasFeature($tenantId, 'event_management'));
        }
    }
}