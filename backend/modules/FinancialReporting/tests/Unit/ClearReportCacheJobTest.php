<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Modules\FinancialReporting\Jobs\ClearReportCache;
use Modules\FinancialReporting\Models\ReportCache;
use Tests\TenantTestCase;

class ClearReportCacheJobTest extends TenantTestCase
{
    /** @test */
    public function it_can_be_dispatched()
    {
        Queue::fake();

        ClearReportCache::dispatch(false);

        Queue::assertPushed(ClearReportCache::class);
    }

    /** @test */
    public function it_clears_expired_cache_entries()
    {
        // Create active cache entries
        ReportCache::factory()->count(3)->create([
            'expires_at' => Carbon::now()->addHours(2),
        ]);

        // Create expired cache entries
        ReportCache::factory()->count(2)->create([
            'expires_at' => Carbon::now()->subHours(2),
        ]);

        $this->assertEquals(5, ReportCache::count());

        $job = new ClearReportCache(false);
        $job->handle();

        // Only active entries should remain
        $this->assertEquals(3, ReportCache::count());
    }

    /** @test */
    public function it_clears_all_cache_when_flag_is_set()
    {
        ReportCache::factory()->count(5)->create();

        $this->assertEquals(5, ReportCache::count());

        $job = new ClearReportCache(true);
        $job->handle();

        $this->assertEquals(0, ReportCache::count());
    }

    /** @test */
    public function it_can_clear_cache_by_report_type()
    {
        ReportCache::factory()->count(3)->create(['report_type' => 'profit_loss']);
        ReportCache::factory()->count(2)->create(['report_type' => 'balance_sheet']);

        $this->assertEquals(5, ReportCache::count());

        ClearReportCache::clearByReportType('profit_loss');

        $this->assertEquals(2, ReportCache::count());
        $this->assertEquals(0, ReportCache::where('report_type', 'profit_loss')->count());
        $this->assertEquals(2, ReportCache::where('report_type', 'balance_sheet')->count());
    }

    /** @test */
    public function it_can_clear_cache_by_date_range()
    {
        $startDate = Carbon::now()->subDays(10);
        $endDate = Carbon::now()->subDays(5);

        // Create cache entries within range
        ReportCache::factory()->count(2)->create([
            'created_at' => Carbon::now()->subDays(7),
        ]);

        // Create cache entries outside range
        ReportCache::factory()->count(3)->create([
            'created_at' => Carbon::now()->subDays(2),
        ]);

        $this->assertEquals(5, ReportCache::count());

        ClearReportCache::clearByDateRange($startDate, $endDate);

        $this->assertEquals(3, ReportCache::count());
    }
}
