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
}
