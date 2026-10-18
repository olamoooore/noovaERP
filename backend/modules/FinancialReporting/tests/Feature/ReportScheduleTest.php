<?php

namespace Modules\FinancialReporting\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Modules\FinancialReporting\Jobs\GenerateScheduledReport;
use Modules\FinancialReporting\Models\ReportSchedule;
use Tests\TenantTestCase;

class ReportScheduleTest extends TenantTestCase
{
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

        $this->user = $this->setUpTenantAdminUser('Report Schedule Tenant');
    }

    /** @test */
    public function it_can_list_all_schedules()
    {
        ReportSchedule::factory()->count(3)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/schedules');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'report_type',
                        'frequency',
                        'recipients',
                        'is_active',
                    ],
                ],
            ]);
    }

    /** @test */
    public function it_can_create_a_schedule()
    {
        $data = [
            'name' => 'Monthly P&L Report',
            'report_type' => 'profit_loss',
            'frequency' => 'monthly',
            'recipients' => ['test@example.com'],
            'parameters' => ['export_format' => 'pdf'],
            'is_active' => true,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/schedules', $data);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'name',
                    'report_type',
                    'frequency',
                ],
            ]);

        $this->assertDatabaseHas('report_schedules', [
            'name' => 'Monthly P&L Report',
            'report_type' => 'profit_loss',
            'frequency' => 'monthly',
        ]);
    }

    /** @test */
    public function it_validates_required_fields_when_creating_schedule()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/schedules', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'report_type', 'frequency', 'recipients']);
    }

    /** @test */
    public function it_validates_report_type()
    {
        $data = [
            'name' => 'Test Schedule',
            'report_type' => 'invalid_type',
            'frequency' => 'monthly',
            'recipients' => ['test@example.com'],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/schedules', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['report_type']);
    }

    /** @test */
    public function it_validates_frequency()
    {
        $data = [
            'name' => 'Test Schedule',
            'report_type' => 'profit_loss',
            'frequency' => 'invalid_frequency',
            'recipients' => ['test@example.com'],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/schedules', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['frequency']);
    }

    /** @test */
    public function it_validates_email_recipients()
    {
        $data = [
            'name' => 'Test Schedule',
            'report_type' => 'profit_loss',
            'frequency' => 'monthly',
            'recipients' => ['invalid-email'],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/schedules', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recipients.0']);
    }

    /** @test */
    public function it_can_update_a_schedule()
    {
        $schedule = ReportSchedule::factory()->create([
            'created_by' => $this->user->id,
        ]);

        $data = [
            'name' => 'Updated Schedule Name',
            'frequency' => 'weekly',
        ];

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/reports/schedules/{$schedule->id}", $data);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Updated Schedule Name',
                    'frequency' => 'weekly',
                ],
            ]);

        $this->assertDatabaseHas('report_schedules', [
            'id' => $schedule->id,
            'name' => 'Updated Schedule Name',
            'frequency' => 'weekly',
        ]);
    }

    /** @test */
    public function it_can_delete_a_schedule()
    {
        $schedule = ReportSchedule::factory()->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/reports/schedules/{$schedule->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSoftDeleted('report_schedules', [
            'id' => $schedule->id,
        ]);
    }

    /** @test */
    public function it_can_toggle_schedule_active_status()
    {
        $schedule = ReportSchedule::factory()->create([
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/reports/schedules/{$schedule->id}/toggle");

        $response->assertStatus(200);

        $this->assertDatabaseHas('report_schedules', [
            'id' => $schedule->id,
            'is_active' => false,
        ]);
    }

    /** @test */
    public function it_can_run_schedule_immediately()
    {
        Bus::fake([GenerateScheduledReport::class]);

        $schedule = ReportSchedule::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/reports/schedules/{$schedule->id}/run");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Report generation job dispatched successfully',
            ]);

        Bus::assertDispatched(GenerateScheduledReport::class);
    }

    /** @test */
    public function it_requires_authentication()
    {
        $response = $this->getJson('/api/v1/reports/schedules');
        $response->assertStatus(401);
    }

    /** @test */
    public function it_can_filter_schedules_by_active_status()
    {
        ReportSchedule::factory()->create(['is_active' => true]);
        ReportSchedule::factory()->create(['is_active' => false]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/schedules?is_active=1');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertTrue($data[0]['is_active']);
    }

    /** @test */
    public function it_can_filter_schedules_by_report_type()
    {
        ReportSchedule::factory()->create(['report_type' => 'profit_loss']);
        ReportSchedule::factory()->create(['report_type' => 'balance_sheet']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/schedules?report_type=profit_loss');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('profit_loss', $data[0]['report_type']);
    }
}
