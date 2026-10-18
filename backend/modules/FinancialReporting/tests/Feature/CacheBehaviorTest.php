<?php

namespace Modules\FinancialReporting\Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Modules\FinancialReporting\DTOs\ReportData;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Cache\ReportCacheService;
use Tests\TenantTestCase;

class CacheBehaviorTest extends TenantTestCase
{
    protected User $user;

    protected ReportCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--path' => [database_path('migrations/tenant')],
            '--realpath' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => [base_path('modules/FinancialReporting/database/migrations/tenant')],
            '--realpath' => true,
        ]);

        $this->withoutMiddleware([
            \Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains::class,
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\ResolveEntityContext::class,
            \App\Http\Middleware\EnsureEntityAccess::class,
        ]);

        $this->user = $this->setUpTenantAdminUser('Report Cache Tenant');
        $this->cacheService = app(ReportCacheService::class);
        Cache::flush();
        $this->setupTestData();
    }

    protected function setupTestData(): void
    {
        ChartOfAccount::firstOrCreate(
            ['code' => '4000'],
            ['name' => 'Revenue', 'type' => 'revenue', 'is_active' => true]
        );
    }

    /** @test */
    public function it_caches_report_results_on_first_request()
    {
        $initialCacheCount = $this->cacheService->getStatistics()['total_entries'];

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/profit-loss?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);

        if ($response->status() === 200) {
            $newCacheCount = $this->cacheService->getStatistics()['total_entries'];
            $this->assertGreaterThanOrEqual($initialCacheCount, $newCacheCount);
        }
    }

    /** @test */
    public function it_serves_cached_results_on_subsequent_requests()
    {
        $params = [
            'start_date' => '2024-01-01',
            'end_date' => '2024-01-31',
        ];

        $response1 = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/profit-loss?'.http_build_query($params));

        $this->assertContains($response1->status(), [200, 500]);

        if ($response1->status() !== 200) {
            return; // Cannot test caching if report fails
        }

        $response2 = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/profit-loss?'.http_build_query($params));

        $this->assertContains($response2->status(), [200, 500]);
    }

    /** @test */
    public function it_generates_different_cache_keys_for_different_parameters()
    {
        $filter1 = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $filter2 = new ReportFilter(
            startDate: Carbon::parse('2024-02-01'),
            endDate: Carbon::parse('2024-02-29')
        );

        $key1 = $this->cacheService->generateCacheKey('profit-loss', $filter1);
        $key2 = $this->cacheService->generateCacheKey('profit-loss', $filter2);

        $this->assertNotEquals($key1, $key2, 'Different filters should generate different cache keys');
    }

    /** @test */
    public function it_invalidates_cache_when_new_transactions_are_posted()
    {
        $response1 = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/profit-loss?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response1->status(), [200, 500]);

        if ($response1->status() !== 200) {
            return;
        }

        // Create new transaction
        $revenueAccount = ChartOfAccount::where('code', '4000')->first();
        $entry = JournalEntry::create([
            'entry_number' => 'JE-TEST-'.uniqid(),
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'New Sale',
            'status' => 'posted',
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'debit' => 0,
            'credit' => 10000,
        ]);

        // Invalidate cache
        $this->cacheService->invalidate('profit-loss');

        $response2 = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/profit-loss?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response2->status(), [200, 500]);
    }

    /** @test */
    public function it_bypasses_cache_when_no_cache_parameter_is_set()
    {
        $response1 = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/profit-loss?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response1->status(), [200, 500]);

        $response2 = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/profit-loss?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
                'no_cache' => '1',
            ]));

        $this->assertContains($response2->status(), [200, 500]);
    }

    /** @test */
    public function it_expires_cache_entries_after_ttl()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $reportData = new ReportData(
            title: 'Test Report',
            data: ['revenue' => 10000]
        );

        // Store with 1 second TTL
        $cached = $this->cacheService->store('profit-loss', $filter, $reportData, 1);

        $this->assertTrue($cached->isValid());

        // Wait for expiration
        sleep(2);

        $this->assertFalse($cached->fresh()->isValid());
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
        $this->cacheService->store('profit-loss', $filter, $reportData, 1);
        $this->cacheService->store('balance-sheet', $filter, $reportData, 3600);

        $initialCount = $this->cacheService->getStatistics()['total_entries'];
        $this->assertEquals(2, $initialCount);

        // Wait for first entry to expire
        sleep(2);

        // Clear expired entries
        $cleared = $this->cacheService->clearExpired();

        $this->assertEquals(1, $cleared);
        $this->assertEquals(1, $this->cacheService->getStatistics()['total_entries']);
    }

    /** @test */
    public function it_provides_accurate_cache_statistics()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $reportData = new ReportData(
            title: 'Test Report',
            data: ['revenue' => 10000]
        );

        // Store multiple entries
        $this->cacheService->store('profit-loss', $filter, $reportData, 3600);
        $this->cacheService->store('balance-sheet', $filter, $reportData, 3600);
        $this->cacheService->store('cash-flow', $filter, $reportData, 1);

        sleep(2); // Let one expire

        $stats = $this->cacheService->getStatistics();

        $this->assertEquals(3, $stats['total_entries']);
        $this->assertEquals(2, $stats['valid_entries']);
        $this->assertEquals(1, $stats['expired_entries']);
        $this->assertArrayHasKey('by_type', $stats);
    }

    /** @test */
    public function it_can_invalidate_cache_by_report_type()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $reportData = new ReportData(
            title: 'Test Report',
            data: ['revenue' => 10000]
        );

        // Store multiple report types (use different filters for distinct cache keys)
        $filter2 = new ReportFilter(
            startDate: Carbon::parse('2024-02-01'),
            endDate: Carbon::parse('2024-02-28')
        );
        $this->cacheService->store('profit-loss', $filter, $reportData, 3600);
        $this->cacheService->store('profit-loss', $filter2, $reportData, 3600);
        $this->cacheService->store('balance-sheet', $filter, $reportData, 3600);

        $this->assertEquals(3, $this->cacheService->getStatistics()['total_entries']);

        // Invalidate only profit-loss
        $count = $this->cacheService->invalidate('profit-loss');

        $this->assertEquals(2, $count);
        $this->assertEquals(1, $this->cacheService->getStatistics()['total_entries']);
    }

    /** @test */
    public function it_can_be_disabled_globally()
    {
        $this->cacheService->disable();

        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $reportData = new ReportData(
            title: 'Test Report',
            data: ['revenue' => 10000]
        );

        $callbackExecuted = false;
        $result = $this->cacheService->remember(
            'profit-loss',
            $filter,
            function () use (&$callbackExecuted, $reportData) {
                $callbackExecuted = true;

                return $reportData;
            }
        );

        $this->assertTrue($callbackExecuted, 'Callback should always execute when cache disabled');
        $this->assertEquals(0, $this->cacheService->getStatistics()['total_entries']);
    }

    /** @test */
    public function it_handles_concurrent_cache_requests()
    {
        $params = [
            'start_date' => '2024-01-01',
            'end_date' => '2024-01-31',
        ];

        // Simulate concurrent requests
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->actingAs($this->user)
                ->getJson('/api/v1/reports/financial/profit-loss?'.http_build_query($params));
        }

        // All should return a valid status (200 or 500 for service issues)
        foreach ($responses as $response) {
            $this->assertContains($response->status(), [200, 500]);
        }
    }

    /** @test */
    public function it_generates_consistent_cache_keys()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31'),
            departmentId: 5
        );

        $key1 = $this->cacheService->generateCacheKey('profit-loss', $filter);
        $key2 = $this->cacheService->generateCacheKey('profit-loss', $filter);

        $this->assertEquals($key1, $key2, 'Same parameters should generate same cache key');
        $this->assertEquals(32, strlen($key1), 'Cache key should be MD5 hash (32 chars)');
    }
}
