<?php

namespace Modules\FinancialReporting\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Modules\FinancialReporting\Models\ReportCache;
use App\Models\User;
use Carbon\Carbon;

class CacheManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

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

        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_get_cache_statistics()
    {
        // Create some cache entries
        ReportCache::factory()->count(5)->create();
        ReportCache::factory()->count(2)->expired()->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/cache/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_entries',
                    'active_entries',
                    'expired_entries',
                    'by_type',
                    'oldest_entry',
                    'newest_entry',
                ]
            ]);

        $data = $response->json('data');
        $this->assertEquals(7, $data['total_entries']);
        $this->assertEquals(5, $data['active_entries']);
        $this->assertEquals(2, $data['expired_entries']);
    }

    /** @test */
    public function it_can_clear_all_cache()
    {
        ReportCache::factory()->count(5)->create();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/cache/clear-all');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Cache clearing job dispatched successfully',
            ]);
    }

    /** @test */
    public function it_can_clear_expired_cache()
    {
        ReportCache::factory()->count(3)->create();
        ReportCache::factory()->count(2)->expired()->create();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/cache/clear-expired');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Expired cache clearing job dispatched successfully',
            ]);
    }

    /** @test */
    public function it_can_clear_cache_by_type()
    {
        $data = [
            'report_type' => 'profit_loss',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/cache/clear-by-type', $data);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Cache cleared for report type',
            ]);
    }

    /** @test */
    public function it_validates_report_type_when_clearing_by_type()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/cache/clear-by-type', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['report_type']);
    }

    /** @test */
    public function it_can_warm_cache()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/cache/warm');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Cache warming initiated',
            ]);
    }

    /** @test */
    public function it_can_get_specific_cache_entry()
    {
        $cache = ReportCache::factory()->create([
            'cache_key' => 'test_cache_key_123',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/reports/cache/{$cache->cache_key}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'cache_key' => 'test_cache_key_123',
                ]
            ]);
    }

    /** @test */
    public function it_returns_404_for_non_existent_cache_entry()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/cache/non_existent_key');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Cache entry not found',
            ]);
    }

    /** @test */
    public function it_can_delete_specific_cache_entry()
    {
        $cache = ReportCache::factory()->create([
            'cache_key' => 'test_cache_key_456',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/reports/cache/{$cache->cache_key}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Cache entry deleted successfully',
            ]);

        $this->assertDatabaseMissing('report_cache', [
            'cache_key' => 'test_cache_key_456',
        ]);
    }

    /** @test */
    public function it_returns_404_when_deleting_non_existent_cache_entry()
    {
        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/reports/cache/non_existent_key');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Cache entry not found',
            ]);
    }

    /** @test */
    public function it_requires_authentication_for_cache_operations()
    {
        $response = $this->getJson('/api/v1/reports/cache/stats');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/reports/cache/clear-all');
        $response->assertStatus(401);
    }

    /** @test */
    public function cache_statistics_groups_by_report_type()
    {
        ReportCache::factory()->count(3)->create(['report_type' => 'profit_loss']);
        ReportCache::factory()->count(2)->create(['report_type' => 'balance_sheet']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/cache/stats');

        $response->assertStatus(200);
        $byType = $response->json('data.by_type');

        $this->assertEquals(3, $byType['profit_loss']);
        $this->assertEquals(2, $byType['balance_sheet']);
    }
}
