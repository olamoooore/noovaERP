<?php

namespace Modules\EventManagement\Tests\Unit;

use Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\EventManagement\Http\Middleware\FeatureFlagMiddleware;
use Modules\EventManagement\Services\FeatureFlagController;
use Modules\EventManagement\Exceptions\FeatureNotEnabledException;
use Mockery;

class FeatureFlagMiddlewareTest extends TestCase
{
    protected FeatureFlagMiddleware $middleware;
    protected $featureFlagController;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->featureFlagController = Mockery::mock(FeatureFlagController::class);
        $this->middleware = new FeatureFlagMiddleware($this->featureFlagController);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_allows_request_when_feature_is_enabled()
    {
        // Arrange
        $request = Request::create('/api/v1/event-management/events', 'GET');
        $request->headers->set('X-Tenant-ID', 'test-tenant');
        
        $this->featureFlagController
            ->shouldReceive('hasFeature')
            ->with('test-tenant', 'event_management')
            ->once()
            ->andReturn(true);

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return new Response('Success');
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertTrue($nextCalled);
        $this->assertEquals('Success', $response->getContent());
    }

    /** @test */
    public function it_blocks_request_when_feature_is_disabled()
    {
        // Arrange
        $request = Request::create('/api/v1/event-management/events', 'GET');
        $request->headers->set('X-Tenant-ID', 'test-tenant');
        
        $this->featureFlagController
            ->shouldReceive('hasFeature')
            ->with('test-tenant', 'event_management')
            ->once()
            ->andReturn(false);

        $next = function ($req) {
            return new Response('Should not reach here');
        };

        // Act & Assert
        $this->expectException(FeatureNotEnabledException::class);
        $this->expectExceptionMessage("Feature 'event_management' is not enabled for this tenant");
        
        $this->middleware->handle($request, $next);
    }

    /** @test */
    public function it_uses_default_tenant_when_no_tenant_header_provided()
    {
        // Arrange
        $request = Request::create('/api/v1/event-management/events', 'GET');
        // No X-Tenant-ID header set
        
        $this->featureFlagController
            ->shouldReceive('hasFeature')
            ->with('default', 'event_management')
            ->once()
            ->andReturn(true);

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return new Response('Success');
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertTrue($nextCalled);
    }

    /** @test */
    public function it_can_check_custom_feature_flag()
    {
        // Arrange
        $request = Request::create('/api/v1/custom-feature', 'GET');
        $request->headers->set('X-Tenant-ID', 'test-tenant');
        
        $this->featureFlagController
            ->shouldReceive('hasFeature')
            ->with('test-tenant', 'custom_feature')
            ->once()
            ->andReturn(true);

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return new Response('Success');
        };

        // Act
        $response = $this->middleware->handle($request, $next, 'custom_feature');

        // Assert
        $this->assertTrue($nextCalled);
    }

    /** @test */
    public function it_extracts_tenant_id_from_request_header()
    {
        // Arrange
        $request = Request::create('/api/v1/event-management/events', 'GET');
        $request->headers->set('X-Tenant-ID', 'custom-tenant-123');
        
        $this->featureFlagController
            ->shouldReceive('hasFeature')
            ->with('custom-tenant-123', 'event_management')
            ->once()
            ->andReturn(true);

        $next = function ($req) {
            return new Response('Success');
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertEquals('Success', $response->getContent());
    }
}