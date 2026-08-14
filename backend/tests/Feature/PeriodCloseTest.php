<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\PeriodCloseService;
use Tests\TenantTestCase;

class PeriodCloseTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
    }

    private function createOpenPeriod(): FiscalPeriod
    {
        $user = User::factory()->create();
        $uniqueCode = '2026-08-' . \Illuminate\Support\Str::random(5);

        return FiscalPeriod::create([
            'name' => 'August 2026',
            'code' => $uniqueCode,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2026,
            'status' => 'open',
            'created_by' => $user->id,
        ]);
    }

    private function createClosedPeriod(): FiscalPeriod
    {
        $period = $this->createOpenPeriod();
        $period->close();

        return $period;
    }

    // ─── Positive Tests ──────────────────────────────────────────

    public function test_validate_period_can_close(): void
    {
        $period = $this->createOpenPeriod();
        $service = app(PeriodCloseService::class);

        $issues = $service->validateBeforeClose($period);

        $this->assertEmpty($issues);
    }

    public function test_close_period_success(): void
    {
        $period = $this->createOpenPeriod();
        $service = app(PeriodCloseService::class);

        $result = $service->closePeriod($period, '1');

        $this->assertEquals('closed', $result['status']);
        $this->assertEquals($period->id, $result['period_id']);

        $period->refresh();
        $this->assertTrue($period->isClosed());
    }

    public function test_reopen_period_success(): void
    {
        $period = $this->createClosedPeriod();
        $service = app(PeriodCloseService::class);

        $result = $service->reopenPeriod($period, '1', 'Correcting entries');

        $this->assertEquals('open', $result['status']);

        $period->refresh();
        $this->assertTrue($period->isOpen());
    }

    public function test_period_summary(): void
    {
        $period = $this->createOpenPeriod();
        $service = app(PeriodCloseService::class);

        $summary = $service->getPeriodSummary($period);

        $this->assertArrayHasKey('period', $summary);
        $this->assertArrayHasKey('journal_summary', $summary);
        $this->assertArrayHasKey('can_close', $summary);
        $this->assertTrue($summary['can_close']);
    }

    // ─── Negative Tests ──────────────────────────────────────────

    public function test_cannot_close_locked_period(): void
    {
        $period = $this->createOpenPeriod();
        $period->lock();
        $service = app(PeriodCloseService::class);

        $this->expectException(\DomainException::class);
        $service->closePeriod($period, '1');
    }

    public function test_cannot_close_already_closed(): void
    {
        $period = $this->createClosedPeriod();
        $service = app(PeriodCloseService::class);

        $this->expectException(\DomainException::class);
        $service->closePeriod($period, '1');
    }

    public function test_cannot_reopen_open_period(): void
    {
        $period = $this->createOpenPeriod();
        $service = app(PeriodCloseService::class);

        $this->expectException(\DomainException::class);
        $service->reopenPeriod($period, '1', 'Test');
    }

    public function test_cannot_reopen_locked_period(): void
    {
        $period = $this->createOpenPeriod();
        $period->lock();
        $service = app(PeriodCloseService::class);

        $this->expectException(\DomainException::class);
        $service->reopenPeriod($period, '1', 'Test');
    }
}
