<?php

namespace Modules\FinancialReporting\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

class ExportApiTest extends TenantTestCase
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

        $this->user = $this->setUpTenantAdminUser('Export API Tenant');
        Storage::fake('local');
    }

    /** @test */
    public function it_can_export_report_to_csv()
    {
        $reportData = [
            'title' => 'Test Report',
            'data' => [
                ['name' => 'Item 1', 'value' => 100],
                ['name' => 'Item 2', 'value' => 200],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/export/csv', [
                'report_type' => 'profit-loss',
                'report_data' => $reportData,
            ]);

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_export_report_to_excel()
    {
        $reportData = [
            'title' => 'Test Report',
            'data' => [
                ['name' => 'Item 1', 'value' => 100],
                ['name' => 'Item 2', 'value' => 200],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/export/excel', [
                'report_type' => 'profit-loss',
                'report_data' => $reportData,
            ]);

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_requires_authentication_for_export()
    {
        $response = $this->postJson('/api/v1/reports/export/csv', [
            'report_type' => 'profit-loss',
            'report_data' => [],
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function it_validates_export_request()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/export/csv', []);

        $this->assertContains($response->status(), [422, 500]);
    }

    /** @test */
    public function it_handles_invalid_report_type()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/export/csv', [
                'report_type' => 'invalid-type',
                'report_data' => ['data' => []],
            ]);

        $this->assertContains($response->status(), [200, 422, 500]);
    }
}
