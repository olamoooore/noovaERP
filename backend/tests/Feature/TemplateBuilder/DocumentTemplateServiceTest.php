<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use Illuminate\Support\Str;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Tests\TenantTestCase;

class DocumentTemplateServiceTest extends TenantTestCase
{
    private DocumentTemplateServiceContract $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext(Str::uuid()->toString());
        $this->service = app(DocumentTemplateServiceContract::class);
    }

    public function test_create_template(): void
    {
        $template = $this->service->create([
            'document_type' => 'invoice',
            'name' => 'My Invoice',
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => ['blocks' => [['type' => 'text', 'content' => 'Hi']]],
        ]);

        $this->assertNotNull($template->id);
        $this->assertSame('draft', $template->status);
        $this->assertSame('block', $template->mode);
    }

    public function test_publish_creates_version_and_pins_it(): void
    {
        $template = $this->service->create([
            'document_type' => 'invoice',
            'name' => 'To Publish',
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => ['blocks' => [['type' => 'text', 'content' => 'v1']]],
        ]);

        $published = $this->service->publish($template->id, 'first publish');

        $this->assertSame('published', $published->status);
        $this->assertSame(1, $published->published_version);
        $this->assertCount(1, $published->versions);
        $this->assertTrue($published->versions->first()->is_published);
    }

    public function test_set_default_then_resolve(): void
    {
        $template = $this->service->create([
            'document_type' => 'invoice',
            'name' => 'Defaulted',
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => ['blocks' => []],
        ]);
        $this->service->publish($template->id);
        $this->service->setDefault($template->id);

        $resolved = $this->service->resolveFor('invoice');

        $this->assertNotNull($resolved);
        $this->assertSame($template->id, $resolved->id);
    }

    public function test_seed_defaults_creates_system_templates(): void
    {
        $this->service->seedDefaults();

        $types = DocumentTemplate::where('is_system_default', true)->pluck('document_type')->all();

        foreach (['invoice', 'quotation', 'purchase_order', 'receipt'] as $type) {
            $this->assertContains($type, $types, "Seeded default missing for '{$type}'.");
            // Each should have a tenant-scope default assignment.
            $this->assertGreaterThan(
                0,
                DocumentTemplateAssignment::where('document_type', $type)->count(),
                "No default assignment for '{$type}'.",
            );
        }
    }

    public function test_seed_defaults_is_idempotent(): void
    {
        $this->service->seedDefaults();
        $first = DocumentTemplate::count();

        $this->service->seedDefaults();
        $second = DocumentTemplate::count();

        $this->assertSame($first, $second, 'seedDefaults() should not duplicate existing system templates.');
    }

    public function test_duplicate_creates_a_draft_copy(): void
    {
        $template = $this->service->create([
            'document_type' => 'invoice',
            'name' => 'Original',
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => ['blocks' => [['type' => 'text', 'content' => 'x']]],
        ]);
        $this->service->publish($template->id);

        $copy = $this->service->duplicate($template->id, 'Copy');

        $this->assertNotSame($template->id, $copy->id);
        $this->assertSame('draft', $copy->status);
        $this->assertFalse($copy->is_system_default);
        $this->assertSame('Copy', $copy->name);
    }

    public function test_validate_definition_rejects_block_template_without_blocks(): void
    {
        $result = $this->service->validateDefinition([
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_list_starters_returns_one_per_document_type(): void
    {
        $starters = $this->service->listStarters();
        $types = array_column($starters, 'document_type');

        // One starter per canonical document type — must stay in sync with
        // DocumentTemplateService::activeDefaults() and DefaultTemplates::definitions().
        $this->assertCount(6, $starters);
        foreach (['invoice', 'quotation', 'purchase_order', 'receipt', 'delivery_order', 'customer_statement'] as $type) {
            $this->assertContains($type, $types);
        }

        // No duplicate document types.
        $this->assertCount(6, array_unique($types));
    }

    public function test_create_from_starter_makes_user_owned_draft(): void
    {
        $template = $this->service->createFromStarter('invoice');

        $this->assertSame('invoice', $template->document_type);
        $this->assertSame('draft', $template->status);
        $this->assertFalse($template->is_system_default);
        $this->assertSame(0, $template->published_version);
        $this->assertNotEmpty($template->layout_config['blocks']);
        $this->assertStringContainsString('Copy', $template->name);
    }

    public function test_active_defaults_resolves_seeded_templates(): void
    {
        $this->service->seedDefaults();
        $defaults = $this->service->activeDefaults();

        foreach (['invoice', 'quotation', 'purchase_order', 'receipt'] as $type) {
            $this->assertArrayHasKey($type, $defaults);
            $this->assertNotNull($defaults[$type], "Active default for '{$type}' should resolve after seeding.");
        }
    }

    public function test_preview_returns_html_with_canvas_wrapper(): void
    {
        $this->service->seedDefaults();
        $template = $this->service->resolveFor('invoice');

        $result = $this->service->preview($template);

        // Should contain full HTML document with DOCTYPE, html, head, body
        $this->assertStringContainsString('<!DOCTYPE html>', $result['html']);
        $this->assertStringContainsString('<html', $result['html']);
        $this->assertStringContainsString('<head>', $result['html']);
        $this->assertStringContainsString('<body>', $result['html']);
        // Should contain the canvas CSS (font-family, margin rules)
        $this->assertStringContainsString('font-family', $result['html']);
        $this->assertStringContainsString('@page', $result['html']);
        // Should contain merged sample data
        $this->assertStringContainsString('INVOICE', $result['html']);
    }
}
