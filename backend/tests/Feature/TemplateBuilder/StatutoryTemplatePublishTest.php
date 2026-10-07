<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Tests\TenantTestCase;

/**
 * #627 — the country pack's required statutory fields are validated when a
 * tax-document template is published. Requiredness comes from the pack data
 * (#614), never from this test.
 */
class StatutoryTemplatePublishTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Statutory Publish Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTemplate(array $blocks, string $type = 'invoice'): DocumentTemplate
    {
        return app(DocumentTemplateServiceContract::class)->create([
            'document_type' => $type,
            'name' => 'Statutory '.$type,
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => ['blocks' => $blocks],
        ]);
    }

    public function test_sg_pack_blocks_publishing_an_invoice_template_without_the_uen_token(): void
    {
        Setting::setValue('company_country', 'SG');
        $template = $this->createTemplate([
            ['type' => 'text', 'content' => 'No statutory tokens here'],
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/template-builder/templates/{$template->id}/publish");

        $response->assertStatus(422);
        $response->assertJsonPath('missing_fields', ['Unique Entity Number (UEN)']);
        $this->assertStringContainsString('Unique Entity Number (UEN)', (string) $response->json('message'));

        // Nothing was published.
        $this->assertSame('draft', $template->fresh()->status);
        $this->assertSame(0, $template->versions()->count());
    }

    public function test_sg_pack_publishes_once_the_uen_token_is_present(): void
    {
        Setting::setValue('company_country', 'SG');
        $template = $this->createTemplate([
            ['type' => 'text', 'content' => 'UEN: {{ company.tin }}'],
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/template-builder/templates/{$template->id}/publish")
            ->assertOk();

        $this->assertSame('published', $template->fresh()->status);
    }

    public function test_generic_pack_requires_no_statutory_tokens(): void
    {
        Setting::setValue('company_country', 'US');
        $template = $this->createTemplate([
            ['type' => 'text', 'content' => 'Plain template'],
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/template-builder/templates/{$template->id}/publish")
            ->assertOk();
    }

    public function test_non_tax_document_types_are_not_blocked(): void
    {
        Setting::setValue('company_country', 'SG');
        $template = $this->createTemplate(
            [['type' => 'text', 'content' => 'Quote with no UEN']],
            'quotation',
        );

        $this->actingAs($this->admin)
            ->postJson("/api/v1/template-builder/templates/{$template->id}/publish")
            ->assertOk();
    }
}
