<?php

namespace Modules\FinancialReporting\Tests\Feature;

use Tests\TenantTestCase;
use Illuminate\Support\Facades\Artisan;
use Modules\FinancialReporting\Models\ReportTemplate;
use App\Models\User;

class ReportTemplateTest extends TenantTestCase
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

        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_list_all_templates()
    {
        ReportTemplate::factory()->count(3)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/templates');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'report_type',
                        'configuration',
                        'is_system',
                    ]
                ]
            ]);
    }

    /** @test */
    public function it_can_create_a_template()
    {
        $data = [
            'name' => 'Executive P&L Template',
            'description' => 'Simplified P&L for executives',
            'report_type' => 'profit_loss',
            'configuration' => [
                'columns' => ['account_name', 'amount'],
                'filters' => ['compare_period' => true],
                'grouping' => ['account_type'],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/templates', $data);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'name', 'report_type']
            ]);

        $this->assertDatabaseHas('report_templates', [
            'name' => 'Executive P&L Template',
            'report_type' => 'profit_loss',
        ]);
    }

    /** @test */
    public function it_validates_required_fields_when_creating_template()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/templates', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'report_type', 'configuration']);
    }

    /** @test */
    public function it_validates_report_type_for_templates()
    {
        $data = [
            'name' => 'Test Template',
            'report_type' => 'invalid_type',
            'configuration' => ['columns' => []],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/templates', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['report_type']);
    }

    /** @test */
    public function it_can_update_a_template()
    {
        $template = ReportTemplate::factory()->create([
            'created_by' => $this->user->id,
            'is_system' => false,
        ]);

        $data = [
            'name' => 'Updated Template Name',
            'description' => 'Updated description',
        ];

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/reports/templates/{$template->id}", $data);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Updated Template Name',
                ]
            ]);

        $this->assertDatabaseHas('report_templates', [
            'id' => $template->id,
            'name' => 'Updated Template Name',
        ]);
    }

    /** @test */
    public function it_prevents_updating_system_templates()
    {
        $template = ReportTemplate::factory()->create([
            'is_system' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/reports/templates/{$template->id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'System templates cannot be modified',
            ]);
    }

    /** @test */
    public function it_prevents_updating_templates_not_owned_by_user()
    {
        $otherUser = User::factory()->create();
        $template = ReportTemplate::factory()->create([
            'created_by' => $otherUser->id,
            'is_system' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/reports/templates/{$template->id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'You do not have permission to update this template',
            ]);
    }

    /** @test */
    public function it_can_delete_a_template()
    {
        $template = ReportTemplate::factory()->create([
            'created_by' => $this->user->id,
            'is_system' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/reports/templates/{$template->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSoftDeleted('report_templates', [
            'id' => $template->id,
        ]);
    }

    /** @test */
    public function it_prevents_deleting_system_templates()
    {
        $template = ReportTemplate::factory()->create([
            'is_system' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/reports/templates/{$template->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'System templates cannot be deleted',
            ]);
    }

    /** @test */
    public function it_can_duplicate_a_template()
    {
        $template = ReportTemplate::factory()->create([
            'name' => 'Original Template',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/reports/templates/{$template->id}/duplicate");

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Template duplicated successfully',
            ]);

        $this->assertDatabaseHas('report_templates', [
            'name' => 'Original Template (Copy)',
            'report_type' => $template->report_type,
            'created_by' => $this->user->id,
        ]);
    }

    /** @test */
    public function it_can_share_a_template()
    {
        $template = ReportTemplate::factory()->create([
            'created_by' => $this->user->id,
            'configuration' => ['columns' => []],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/reports/templates/{$template->id}/share");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $template->refresh();
        $this->assertTrue($template->configuration['shared'] ?? false);
    }

    /** @test */
    public function it_can_unshare_a_template()
    {
        $template = ReportTemplate::factory()->create([
            'created_by' => $this->user->id,
            'configuration' => [
                'columns' => [],
                'shared' => true,
                'shared_by' => $this->user->id,
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/reports/templates/{$template->id}/unshare");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $template->refresh();
        $this->assertFalse($template->configuration['shared'] ?? false);
    }

    /** @test */
    public function it_can_apply_a_template()
    {
        $template = ReportTemplate::factory()->create([
            'configuration' => [
                'columns' => ['account_name', 'amount'],
                'filters' => ['department_id' => 5],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/reports/templates/{$template->id}/apply", [
                'filters' => ['start_date' => '2024-01-01'],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'report_type' => $template->report_type,
                    'filters' => [
                        'department_id' => 5,
                        'start_date' => '2024-01-01',
                    ],
                ],
            ]);
    }

    /** @test */
    public function it_can_filter_templates_by_report_type()
    {
        ReportTemplate::factory()->create(['report_type' => 'profit_loss']);
        ReportTemplate::factory()->create(['report_type' => 'balance_sheet']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/templates?report_type=profit_loss');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        $this->assertCount(1, $data);
        $this->assertEquals('profit_loss', $data[0]['report_type']);
    }

    /** @test */
    public function it_can_filter_system_templates()
    {
        ReportTemplate::factory()->create(['is_system' => true]);
        ReportTemplate::factory()->create(['is_system' => false]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/templates?is_system=1');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        $this->assertCount(1, $data);
        $this->assertTrue($data[0]['is_system']);
    }

    /** @test */
    public function it_requires_authentication()
    {
        $response = $this->getJson('/api/v1/reports/templates');
        $response->assertStatus(401);
    }
}
