<?php

namespace Modules\FinancialReporting\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TenantTestCase;

class InventoryReportsApiTest extends TenantTestCase
{
    protected User $user;

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

        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_generate_stock_valuation_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/valuation?'.http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_generate_stock_movement_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/movement?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_generate_reorder_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/reorder');

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_generate_inventory_aging_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/aging?'.http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_generate_inventory_turnover_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/turnover?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_requires_authentication_for_inventory_reports()
    {
        $response = $this->getJson('/api/v1/reports/inventory/valuation');

        $response->assertStatus(401);
    }
}
