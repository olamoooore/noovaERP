<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Platform\Exceptions\ExceptionQueue;
use App\Platform\Exceptions\ExceptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class ExceptionServiceTest extends TenantTestCase
{
    protected string $tenantUuid;
    protected ExceptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->service = new ExceptionService();
    }

    public function test_log_creates_exception(): void
    {
        $result = $this->service->log(
            'auth',
            'ValidationError',
            'high',
            'Invalid credentials',
            ['ip' => '192.168.1.1']
        );

        $this->assertNotEmpty($result->id);
        $this->assertEquals('auth', $result->module);
        $this->assertEquals('high', $result->severity);
        $this->assertEquals('open', $result->status);
    }

    public function test_assign_updates_status(): void
    {
        $exception = ExceptionQueue::create([
            'tenant_id' => $this->tenantUuid,
            'module' => 'auth',
            'exception_type' => 'ValidationError',
            'severity' => 'high',
            'message' => 'Invalid credentials',
            'status' => 'open',
        ]);

        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password']);
        $result = $this->service->assign($exception->id, $user->id);

        $this->assertEquals('in_progress', $result->status);
        $this->assertEquals($user->id, $result->assigned_to);
    }

    public function test_resolve_sets_resolved_fields(): void
    {
        $exception = ExceptionQueue::create([
            'tenant_id' => $this->tenantUuid,
            'module' => 'auth',
            'exception_type' => 'ValidationError',
            'severity' => 'high',
            'message' => 'Invalid credentials',
            'status' => 'in_progress',
        ]);

        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password']);
        $result = $this->service->resolve($exception->id, $user->id, 'Fixed by validating input');

        $this->assertEquals('resolved', $result->status);
        $this->assertEquals($user->id, $result->resolved_by);
        $this->assertNotNull($result->resolved_at);
        $this->assertEquals('Fixed by validating input', $result->resolution);
    }

    public function test_retry_increments_count(): void
    {
        $exception = ExceptionQueue::create([
            'tenant_id' => $this->tenantUuid,
            'module' => 'auth',
            'exception_type' => 'ConnectionTimeout',
            'severity' => 'medium',
            'message' => 'API timeout',
            'status' => 'open',
            'retry_count' => 0,
        ]);

        $result = $this->service->retry($exception->id);

        $this->assertEquals('retrying', $result->status);
        $this->assertEquals(1, $result->retry_count);
        $this->assertNotNull($result->next_retry_at);
    }

    public function test_get_stats_returns_counts(): void
    {
        ExceptionQueue::create([
            'tenant_id' => $this->tenantUuid,
            'module' => 'auth',
            'exception_type' => 'Error',
            'severity' => 'high',
            'message' => 'Error 1',
            'status' => 'open',
        ]);

        ExceptionQueue::create([
            'tenant_id' => $this->tenantUuid,
            'module' => 'payment',
            'exception_type' => 'Error',
            'severity' => 'critical',
            'message' => 'Error 2',
            'status' => 'resolved',
        ]);

        $stats = $this->service->getStats();

        $this->assertEquals(2, $stats['total']);
        $this->assertArrayHasKey('auth', $stats['by_module']);
        $this->assertArrayHasKey('payment', $stats['by_module']);
        $this->assertArrayHasKey('high', $stats['by_severity']);
        $this->assertArrayHasKey('critical', $stats['by_severity']);
        $this->assertArrayHasKey('open', $stats['by_status']);
        $this->assertArrayHasKey('resolved', $stats['by_status']);
    }

    public function test_log_with_null_context(): void
    {
        $result = $this->service->log(
            'system',
            'RuntimeError',
            'critical',
            'System crash'
        );

        $this->assertNotEmpty($result->id);
        $this->assertEquals('system', $result->module);
        $this->assertNull($result->context);
    }
}
