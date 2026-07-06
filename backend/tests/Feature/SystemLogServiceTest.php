<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Platform\SystemLog\SystemLog;
use App\Platform\SystemLog\SystemLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class SystemLogServiceTest extends TenantTestCase
{
    protected string $tenantUuid;
    protected SystemLogService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->service = new SystemLogService();
    }

    public function test_log_creates_entry(): void
    {
        $result = $this->service->log(
            'info',
            'system',
            'System started',
            ['version' => '1.0']
        );

        $this->assertNotEmpty($result->id);
        $this->assertEquals('info', $result->level);
        $this->assertEquals('system', $result->category);
        $this->assertEquals('System started', $result->message);
        $this->assertNotEmpty($result->correlation_id);
    }

    public function test_query_filters_by_level(): void
    {
        SystemLog::create([
            'tenant_id' => $this->tenantUuid,
            'level' => 'info',
            'category' => 'system',
            'message' => 'Info log',
            'correlation_id' => Str::uuid(),
        ]);

        SystemLog::create([
            'tenant_id' => $this->tenantUuid,
            'level' => 'error',
            'category' => 'system',
            'message' => 'Error log',
            'correlation_id' => Str::uuid(),
        ]);

        $results = $this->service->query(['level' => 'error']);

        $this->assertCount(1, $results);
        $this->assertEquals('error', $results[0]['level']);
    }

    public function test_query_filters_by_category(): void
    {
        SystemLog::create([
            'tenant_id' => $this->tenantUuid,
            'level' => 'info',
            'category' => 'system',
            'message' => 'System log',
            'correlation_id' => Str::uuid(),
        ]);

        SystemLog::create([
            'tenant_id' => $this->tenantUuid,
            'level' => 'info',
            'category' => 'performance',
            'message' => 'Performance log',
            'correlation_id' => Str::uuid(),
        ]);

        $results = $this->service->query(['category' => 'performance']);

        $this->assertCount(1, $results);
        $this->assertEquals('performance', $results[0]['category']);
    }

    public function test_get_by_correlation_id_returns_all_logs(): void
    {
        $correlationId = Str::uuid()->toString();

        SystemLog::create([
            'tenant_id' => $this->tenantUuid,
            'level' => 'info',
            'category' => 'system',
            'message' => 'Step 1',
            'correlation_id' => $correlationId,
        ]);

        SystemLog::create([
            'tenant_id' => $this->tenantUuid,
            'level' => 'info',
            'category' => 'system',
            'message' => 'Step 2',
            'correlation_id' => $correlationId,
        ]);

        SystemLog::create([
            'tenant_id' => $this->tenantUuid,
            'level' => 'info',
            'category' => 'system',
            'message' => 'Other log',
            'correlation_id' => Str::uuid()->toString(),
        ]);

        $results = $this->service->getByCorrelationId($correlationId);

        $this->assertCount(2, $results);
    }

    public function test_get_recent_returns_limited_results(): void
    {
        for ($i = 0; $i < 5; $i++) {
            SystemLog::create([
                'tenant_id' => $this->tenantUuid,
                'level' => 'info',
                'category' => 'system',
                'message' => "Log {$i}",
                'correlation_id' => Str::uuid(),
            ]);
        }

        $results = $this->service->getRecent(3);

        $this->assertCount(3, $results);
    }

    public function test_query_filters_by_date_range(): void
    {
        SystemLog::create([
            'tenant_id' => $this->tenantUuid,
            'level' => 'info',
            'category' => 'system',
            'message' => 'Old log',
            'correlation_id' => Str::uuid(),
            'created_at' => now()->subDays(10),
        ]);

        SystemLog::create([
            'tenant_id' => $this->tenantUuid,
            'level' => 'info',
            'category' => 'system',
            'message' => 'Recent log',
            'correlation_id' => Str::uuid(),
            'created_at' => now(),
        ]);

        $results = $this->service->query([
            'date_from' => now()->subDays(5)->toDateTimeString(),
        ]);

        $this->assertCount(1, $results);
        $this->assertEquals('Recent log', $results[0]['message']);
    }
}
