<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Carbon\Carbon;
use Modules\FinancialReporting\DTOs\ReportData;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Models\ReportCache;
use Modules\FinancialReporting\Services\Cache\ReportCacheService;
use Tests\TenantTestCase;

class ReportCacheServiceTest extends TenantTestCase
{
    protected ReportCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReportCacheService;
    }

    /** @test */
    public function it_generates_consistent_cache_keys()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31'),
            accountType: 'revenue'
        );

        $key1 = $this->service->generateCacheKey('profit-loss', $filter);
        $key2 = $this->service->generateCacheKey('profit-loss', $filter);

        $this->assertEquals($key1, $key2);
        $this->assertEquals(32, strlen($key1)); // MD5 hash length
    }

    /** @test */
    public function it_generates_different_keys_for_different_filters()
    {
        $filter1 = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $filter2 = new ReportFilter(
            startDate: Carbon::parse('2024-02-01'),
            endDate: Carbon::parse('2024-02-29')
        );

        $key1 = $this->service->generateCacheKey('profit-loss', $filter1);
        $key2 = $this->service->generateCacheKey('profit-loss', $filter2);

        $this->assertNotEquals($key1, $key2);
    }

    /** @test */
    public function it_stores_report_in_cache()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $reportData = new ReportData(
            title: 'Test Report',
            data: ['revenue' => 10000],
            summary: ['total' => 10000]
        );

        $cached = $this->service->store(
            reportType: 'profit-loss',
            filter: $filter,
            reportData: $reportData,
            ttl: 3600
        );

        $this->assertInstanceOf(ReportCache::class, $cached);
        $this->assertEquals('profit-loss', $cached->report_type);
        $this->assertTrue($cached->isValid());
    }

    /** @test */
    public function it_retrieves_cached_report()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $reportData = new ReportData(
            title: 'Test Report',
            data: ['revenue' => 10000],
            summary: ['total' => 10000]
        );

        // Store in cache
        $this->service->store('profit-loss', $filter, $reportData, 3600);

        // Retrieve from cache
        $callbackExecuted = false;
        $result = $this->service->remember(
            'profit-loss',
            $filter,
            function () use (&$callbackExecuted, $reportData) {
                $callbackExecuted = true;

                return $reportData;
            }
        );

        $this->assertFalse($callbackExecuted, 'Callback should not be executed when cache hit');
        $this->assertEquals('Test Report', $result->title);
        $this->assertEquals(['revenue' => 10000], $result->data);
    }

    /** @test */
    public function it_executes_callback_on_cache_miss()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $reportData = new ReportData(
            title: 'Test Report',
            data: ['revenue' => 10000]
        );

        $callbackExecuted = false;
        $result = $this->service->remember(
            'profit-loss',
            $filter,
            function () use (&$callbackExecuted, $reportData) {
                $callbackExecuted = true;

                return $reportData;
            }
        );

        $this->assertTrue($callbackExecuted, 'Callback should be executed on cache miss');
        $this->assertEquals('Test Report', $result->title);
    }

    /** @test */
    public function it_invalidates_cache_by_report_type()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $reportData = new ReportData(
            title: 'Test Report',
            data: ['revenue' => 10000]
        );

        // Store multiple reports
        $this->service->store('profit-loss', $filter, $reportData, 3600);
        $this->service->store('balance-sheet', $filter, $reportData, 3600);

        $this->assertEquals(2, ReportCache::count());

        // Invalidate profit-loss
        $count = $this->service->invalidate('profit-loss');

        $this->assertEquals(1, $count);
        $this->assertEquals(1, ReportCache::count());
        $this->assertEquals('balance-sheet', ReportCache::first()->report_type);
    }

    /** @test */
    public function it_clears_expired_cache_entries()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $reportData = new ReportData(
            title: 'Test Report',
            data: ['revenue' => 10000]
        );

        // Store with short TTL
        $this->service->store('profit-loss', $filter, $reportData, 1);

        // Wait for expiration
        sleep(2);

        $count = $this->service->clearExpired();

        $this->assertEquals(1, $count);
        $this->assertEquals(0, ReportCache::count());
    }

    /** @test */
    public function it_returns_cache_statistics()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $reportData = new ReportData(
            title: 'Test Report',
            data: ['revenue' => 10000]
        );

        $this->service->store('profit-loss', $filter, $reportData, 3600);
        $this->service->store('balance-sheet', $filter, $reportData, 3600);

        $stats = $this->service->getStatistics();

        $this->assertArrayHasKey('total_entries', $stats);
        $this->assertArrayHasKey('valid_entries', $stats);
        $this->assertArrayHasKey('expired_entries', $stats);
        $this->assertArrayHasKey('by_type', $stats);
        $this->assertEquals(2, $stats['total_entries']);
        $this->assertEquals(2, $stats['valid_entries']);
    }

    /** @test */
    public function it_can_be_disabled()
    {
        $this->service->disable();

        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $reportData = new ReportData(
            title: 'Test Report',
            data: ['revenue' => 10000]
        );

        $callbackExecuted = false;
        $result = $this->service->remember(
            'profit-loss',
            $filter,
            function () use (&$callbackExecuted, $reportData) {
                $callbackExecuted = true;

                return $reportData;
            }
        );

        $this->assertTrue($callbackExecuted, 'Callback should always execute when cache disabled');
        $this->assertEquals(0, ReportCache::count(), 'Nothing should be cached when disabled');
    }
}
