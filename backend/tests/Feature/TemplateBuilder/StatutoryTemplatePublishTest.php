<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use App\Models\LegalEntity;
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

    /**
     * The validator reads the issuing country from the tenant's first legal
     * entity (#921: no undeclared company_country / business_country keys).
     */
    private function setIssuingCountry(string $country): void
    {
        $first = LegalEntity::query()->orderBy('id')->first();

        if ($first !== null) {
            $first->update(['country_code' => $country]);

            return;
        }

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Statutory Publish Group',
            'code' => 'SPG-'.Str::upper(Str::random(4)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Statutory Publish Entity',
            'code' => 'SPE-'.Str::upper(Str::random(4)),
            'status' => 'active',
            'country_code' => $country,
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
        $this->setIssuingCountry('SG');
        $template = $this->createTemplate([
            // #1019: the SG pack also requires the GST registration number;
            // it is present so this case isolates the missing UEN.
            ['type' => 'text', 'content' => 'No UEN token here. GST Reg No.: {{ company.gst_number }}'],
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
        $this->setIssuingCountry('SG');
        // #1019: the SG pack now also requires the GST registration number.
        $template = $this->createTemplate([
            ['type' => 'text', 'content' => 'UEN: {{ company.tin }} GST Reg No.: {{ company.gst_number }}'],
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/template-builder/templates/{$template->id}/publish")
            ->assertOk();

        $this->assertSame('published', $template->fresh()->status);
    }

    /**
     * #1019: IRAS lists the supplier's "GST registration number" among the
     * mandatory tax-invoice fields, so the SG pack requires its token too.
     */
    public function test_sg_pack_blocks_publishing_an_invoice_template_with_uen_but_no_gst_number_token(): void
    {
        $this->setIssuingCountry('SG');
        $template = $this->createTemplate([
            ['type' => 'text', 'content' => 'UEN: {{ company.tin }}'],
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/template-builder/templates/{$template->id}/publish");

        $response->assertStatus(422);
        $response->assertJsonPath('missing_fields', ['GST Registration No.']);
        $this->assertStringContainsString('GST Registration No.', (string) $response->json('message'));
        $this->assertSame('draft', $template->fresh()->status);
    }

    public function test_generic_pack_requires_no_statutory_tokens(): void
    {
        $this->setIssuingCountry('US');
        $template = $this->createTemplate([
            ['type' => 'text', 'content' => 'Plain template'],
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/template-builder/templates/{$template->id}/publish")
            ->assertOk();
    }

    public function test_non_tax_document_types_are_not_blocked(): void
    {
        $this->setIssuingCountry('SG');
        $template = $this->createTemplate(
            [['type' => 'text', 'content' => 'Quote with no UEN']],
            'quotation',
        );

        $this->actingAs($this->admin)
            ->postJson("/api/v1/template-builder/templates/{$template->id}/publish")
            ->assertOk();
    }
}
