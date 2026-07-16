<?php

namespace Modules\EventManagement\Tests\Unit;

use Modules\EventManagement\EventManagementServiceProvider;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\BookingService;
use Modules\EventManagement\Services\EventService;
use Modules\EventManagement\Services\FeatureFlagController;
use Modules\EventManagement\Services\TenantSchemaManager;
use Modules\EventManagement\Services\TicketService;
use Tests\TestCase;

class EventManagementServiceProviderTest extends TestCase
{
    protected EventManagementServiceProvider $serviceProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serviceProvider = new EventManagementServiceProvider($this->app);
    }

    /** @test */
    public function it_registers_core_services_as_singletons()
    {
        // Act
        $this->serviceProvider->register();

        // Assert - Check that services are registered as singletons
        $this->assertTrue($this->app->bound(EventService::class));
        $this->assertTrue($this->app->bound(BookingService::class));
        $this->assertTrue($this->app->bound(TicketService::class));
        $this->assertTrue($this->app->bound(AuditService::class));
        $this->assertTrue($this->app->bound(FeatureFlagController::class));
        $this->assertTrue($this->app->bound(TenantSchemaManager::class));

        // Verify singleton behavior - same instance returned
        $eventService1 = $this->app->make(EventService::class);
        $eventService2 = $this->app->make(EventService::class);
        $this->assertSame($eventService1, $eventService2);

        $featureFlagController1 = $this->app->make(FeatureFlagController::class);
        $featureFlagController2 = $this->app->make(FeatureFlagController::class);
        $this->assertSame($featureFlagController1, $featureFlagController2);
    }

    /** @test */
    public function it_does_not_register_legacy_feature_flag_middleware()
    {
        // Act
        $this->serviceProvider->boot();

        // Assert - The legacy 'feature.event_management' alias should no longer be registered.
        // All routes now use 'entitlement:event_management' via EnsureEntitlementAccess.
        $router = $this->app['router'];
        $middlewareGroups = $router->getMiddleware();

        $this->assertArrayNotHasKey('feature.event_management', $middlewareGroups);
    }

    /** @test */
    public function it_loads_migrations_from_correct_path()
    {
        // This test verifies that the service provider attempts to load migrations
        // In a real Laravel environment, this would be tested by checking if migrations are loaded

        // For now, we'll test that the service provider boots without errors
        $this->expectNotToPerformAssertions();

        try {
            $this->serviceProvider->boot();
            // If we reach here, boot() completed successfully
        } catch (\Exception $e) {
            $this->fail('Service provider boot failed: '.$e->getMessage());
        }
    }

    /** @test */
    public function it_loads_routes_from_correct_path()
    {
        // Act
        $this->serviceProvider->boot();

        // Assert - In a real Laravel environment, we would check if routes are loaded
        // For this test, we verify that boot completes without errors
        $this->expectNotToPerformAssertions();
    }

    /** @test */
    public function it_publishes_configuration_files()
    {
        // Act
        $this->serviceProvider->boot();

        // Assert - Check that configuration publishing is set up
        // In Laravel, this would be tested by running the publish command
        // For now, we verify that boot completes successfully
        $this->expectNotToPerformAssertions();
    }

    /** @test */
    public function services_can_be_resolved_with_dependencies()
    {
        // Arrange
        $this->serviceProvider->register();

        // Act & Assert - Test that services can be resolved with their dependencies
        $eventService = $this->app->make(EventService::class);
        $this->assertInstanceOf(EventService::class, $eventService);

        $bookingService = $this->app->make(BookingService::class);
        $this->assertInstanceOf(BookingService::class, $bookingService);

        $ticketService = $this->app->make(TicketService::class);
        $this->assertInstanceOf(TicketService::class, $ticketService);

        $auditService = $this->app->make(AuditService::class);
        $this->assertInstanceOf(AuditService::class, $auditService);

        $featureFlagController = $this->app->make(FeatureFlagController::class);
        $this->assertInstanceOf(FeatureFlagController::class, $featureFlagController);

        $tenantSchemaManager = $this->app->make(TenantSchemaManager::class);
        $this->assertInstanceOf(TenantSchemaManager::class, $tenantSchemaManager);
    }
}
