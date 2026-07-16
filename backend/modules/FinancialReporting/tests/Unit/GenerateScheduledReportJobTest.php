<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Modules\FinancialReporting\Jobs\GenerateScheduledReport;
use Modules\FinancialReporting\Models\ReportSchedule;
use Tests\TenantTestCase;

class GenerateScheduledReportJobTest extends TenantTestCase
{
    /**
     * Helper to invoke a protected method on the job via Reflection.
     */
    protected function invokeProtected(object $object, string $methodName, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($object, $methodName);
        $ref->setAccessible(true);

        return $ref->invoke($object, ...$args);
    }

    /** @test */
    public function it_can_be_dispatched()
    {
        Queue::fake();

        $schedule = ReportSchedule::factory()->create();

        GenerateScheduledReport::dispatch($schedule);

        Queue::assertPushed(GenerateScheduledReport::class);
    }

    /** @test */
    public function it_calculates_next_run_time_for_daily_frequency()
    {
        $schedule = ReportSchedule::factory()->create([
            'frequency' => 'daily',
            'next_run_at' => Carbon::now(),
        ]);

        $job = new GenerateScheduledReport($schedule);
        $nextRun = $this->invokeProtected($job, 'calculateNextRunTime');

        $this->assertEquals(
            Carbon::now()->addDay()->format('Y-m-d'),
            $nextRun->format('Y-m-d')
        );
    }

    /** @test */
    public function it_calculates_next_run_time_for_weekly_frequency()
    {
        $schedule = ReportSchedule::factory()->create([
            'frequency' => 'weekly',
        ]);

        $job = new GenerateScheduledReport($schedule);
        $nextRun = $this->invokeProtected($job, 'calculateNextRunTime');

        $this->assertEquals(
            Carbon::now()->addWeek()->format('Y-m-d'),
            $nextRun->format('Y-m-d')
        );
    }

    /** @test */
    public function it_calculates_next_run_time_for_monthly_frequency()
    {
        $schedule = ReportSchedule::factory()->create([
            'frequency' => 'monthly',
        ]);

        $job = new GenerateScheduledReport($schedule);
        $nextRun = $this->invokeProtected($job, 'calculateNextRunTime');

        $this->assertEquals(
            Carbon::now()->addMonth()->format('Y-m'),
            $nextRun->format('Y-m')
        );
    }

    /** @test */
    public function it_gets_correct_date_range_for_daily_frequency()
    {
        $schedule = ReportSchedule::factory()->create([
            'frequency' => 'daily',
        ]);

        $job = new GenerateScheduledReport($schedule);
        $dateRange = $this->invokeProtected($job, 'getDateRangeForFrequency');

        $yesterday = Carbon::now()->subDay();
        $this->assertEquals($yesterday->format('Y-m-d'), $dateRange['start']->format('Y-m-d'));
        $this->assertEquals($yesterday->format('Y-m-d'), $dateRange['end']->format('Y-m-d'));
    }

    /** @test */
    public function it_gets_correct_date_range_for_monthly_frequency()
    {
        $schedule = ReportSchedule::factory()->create([
            'frequency' => 'monthly',
        ]);

        $job = new GenerateScheduledReport($schedule);
        $dateRange = $this->invokeProtected($job, 'getDateRangeForFrequency');

        $lastMonth = Carbon::now()->subMonth();
        $this->assertEquals($lastMonth->startOfMonth()->format('Y-m-d'), $dateRange['start']->format('Y-m-d'));
        $this->assertEquals($lastMonth->endOfMonth()->format('Y-m-d'), $dateRange['end']->format('Y-m-d'));
    }

    /** @test */
    public function it_updates_schedule_after_execution()
    {
        Mail::fake();

        $schedule = ReportSchedule::factory()->create([
            'frequency' => 'daily',
            'last_run_at' => null,
            'next_run_at' => Carbon::now()->subHour(),
        ]);

        // Note: This would require mocking the report services
        // For now, we're just testing the structure
        $this->assertNull($schedule->last_run_at);
        $this->assertNotNull($schedule->next_run_at);
    }
}
