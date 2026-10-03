<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TenantTestCase;

/**
 * Issue #357 (S9): SalesAnalyticsService caught every \Throwable, logged it and
 * returned an empty "error envelope", which the controller wrapped as
 * {success: true, data: {products: []}}. A broken query therefore looked like a
 * quiet month on the dashboard. Failures must now surface as a non-success
 * response so the UI can show an error state instead of "no data".
 */
class AnalyticsQueryFailureTest extends TenantTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) \Illuminate\Support\Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $tenantId], [
            'name' => 'Analytics Failure Tenant',
            'email' => 'af-'.substr(md5($tenantId), 0, 8).'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = $this->setUpTenantAdminUser('Analytics Failure Tenant');
        CompanySettings::query()->create(['base_currency' => 'SGD']);
    }

    public function test_a_broken_analytics_query_returns_a_non_success_response(): void
    {
        // Control: a healthy query answers success.
        $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-product')
            ->assertOk()
            ->assertJsonPath('success', true);

        // Force a real query failure by hiding a table the analytics union
        // reads. The rename happens inside RefreshDatabase's wrapping
        // transaction, so it is rolled back with the test — and the failed
        // query aborts that transaction, which is exactly the production
        // failure this test surfaces (no restore command can run afterwards).
        Schema::rename('invoice_lines', 'invoice_lines_hidden_for_test');

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-product');

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);
        $this->assertNotSame(
            [],
            $response->json('error'),
            'an analytics failure must carry an error payload, not an empty data set',
        );
    }
}
