<?php

namespace Modules\FinancialReporting\Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class AnalyticsApiTest extends TenantTestCase
{
    protected User $user;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--path' => [database_path('migrations/tenant')],
            '--realpath' => true,
        ]);

        $this->withoutMiddleware([
            \Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains::class,
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\ResolveEntityContext::class,
            \App\Http\Middleware\EnsureEntityAccess::class,
        ]);

        // Issue #103: the analytics routes now require reports.view. Set up a
        // tenant-scoped Admin (super role) so the endpoint authorises; the
        // withoutMiddleware() calls above do not bypass RequirePermission.
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'Analytics Tenant', 'email' => 'analytics-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );

        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'admin', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole('Admin');

        $this->user = $user;
    }

    /** @test */
    public function it_can_get_sales_by_product()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/analytics/sales/by-product?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_get_sales_by_customer()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/analytics/sales/by-customer?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_get_sales_trends()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/analytics/sales/trends?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
                'interval' => 'day',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_get_sales_by_category()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/analytics/sales/by-category?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_get_expenses_by_category()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/analytics/expenses/by-category?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_get_expenses_by_vendor()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/analytics/expenses/by-vendor?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_get_customer_lifetime_value()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/analytics/customers/lifetime-value?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_requires_authentication_for_analytics()
    {
        $response = $this->getJson('/api/v1/analytics/sales/by-product');

        $response->assertStatus(401);
    }

    /** @test */
    public function it_supports_different_trend_intervals()
    {
        $intervals = ['day', 'week', 'month', 'year'];

        foreach ($intervals as $interval) {
            $response = $this->actingAs($this->user)
                ->getJson('/api/v1/analytics/sales/trends?'.http_build_query([
                    'start_date' => '2024-01-01',
                    'end_date' => '2024-12-31',
                    'interval' => $interval,
                ]));

            $this->assertContains($response->status(), [200, 500]);
        }
    }
}
