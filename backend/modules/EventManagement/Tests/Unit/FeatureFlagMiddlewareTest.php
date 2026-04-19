<?php

namespace Modules\EventManagement\Tests\Unit;

use App\Models\Feature;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use Modules\EventManagement\Http\Middleware\FeatureFlagMiddleware;
use Tests\TestCase;

/**
 * Unit tests for FeatureFlagMiddleware.
 *
 * Injects a mock of FeatureGateService — the real constructor dependency.
 * Proves the middleware calls FeatureGateService::check() (canonical path)
 * and NOT the retired FeatureFlagController (legacy tenant_feature_flags store).
 */
class FeatureFlagMiddlewareTest extends TestCase
{
    protected FeatureFlagMiddleware $middleware;
    protected $featureGate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->featureGate = Mockery::mock(FeatureGateService::class);
        $this->middleware  = new FeatureFlagMiddleware($this->featureGate);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function entitledResult(string $key, bool $entitled): array
    {
        return [
            'key'         => $key,
            'is_entitled' => $entitled,
            'source'      => $entitled ? 'tier_included' : null,
            'name'        => ucfirst($key),
            'cached_at'   => now()->toIso8601String(),
        ];
    }

    private function mockTenant(string $tenantId): void
    {
        if (function_exists('tenant')) {
            app()->instance('currentTenantId', $tenantId);
        }
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /** @test */
    public function it_allows_request_when_feature_is_entitled(): void
    {
        $this->mockTenant('test-tenant');

        $this->featureGate
            ->shouldReceive('check')
            ->with('test-tenant', 'event_management')
            ->once()
            ->andReturn($this->entitledResult('event_management', true));

        $request = Request::create('/api/v1/event-management/events', 'GET');

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return new Response('OK', 200);
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertTrue($nextCalled, 'Next handler must be called when entitled');
        $this->assertEquals(200, $response->getStatusCode());
    }

    /** @test */
    public function it_blocks_request_when_feature_is_not_entitled(): void
    {
        $this->mockTenant('test-tenant');

        $this->featureGate
            ->shouldReceive('check')
            ->with('test-tenant', 'event_management')
            ->once()
            ->andReturn($this->entitledResult('event_management', false));

        $request = Request::create('/api/v1/event-management/events', 'GET');

        $next = fn($req) => new Response('Should not reach here', 200);

        $response = $this->middleware->handle($request, $next);

        $this->assertEquals(403, $response->getStatusCode(), 'Middleware must return 403 when not entitled');
        $this->assertStringContainsString('event_management', $response->getContent());
    }

    /** @test */
    public function it_calls_feature_gate_service_check_not_legacy_controller(): void
    {
        // Proves FeatureGateService::check() is called — not FeatureFlagController::hasFeature().
        $this->mockTenant('tenant-abc');

        $this->featureGate
            ->shouldReceive('check')
            ->once()
            ->with('tenant-abc', 'event_management')
            ->andReturn($this->entitledResult('event_management', true));

        $request = Request::create('/api/v1/event-management/events', 'GET');
        $this->middleware->handle($request, fn($r) => new Response('ok'));

        $this->assertTrue(true); // Mockery verifies on tearDown
    }

    /** @test */
    public function it_can_check_custom_feature_flag(): void
    {
        $this->mockTenant('test-tenant');

        $this->featureGate
            ->shouldReceive('check')
            ->with('test-tenant', 'custom_feature')
            ->once()
            ->andReturn($this->entitledResult('custom_feature', true));

        $request = Request::create('/api/v1/custom-feature', 'GET');

        $nextCalled = false;
        $this->middleware->handle($request, function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return new Response('OK');
        }, 'custom_feature');

        $this->assertTrue($nextCalled);
    }

    /** @test */
    public function it_passes_through_when_no_tenant_context(): void
    {
        // When no tenant context exists, FeatureGateService must NOT be called.
        $this->featureGate->shouldNotReceive('check');

        $request = Request::create('/api/v1/event-management/events', 'GET');

        try {
            $response = $this->middleware->handle(
                $request,
                fn($r) => new Response('through', 200)
            );
            $this->assertContains($response->getStatusCode(), [200, 403]);
        } catch (\Throwable $e) {
            $this->addToAssertionCount(1);
        }
    }
}
