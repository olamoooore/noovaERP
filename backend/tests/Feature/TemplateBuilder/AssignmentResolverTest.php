<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use Illuminate\Support\Str;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Modules\TemplateBuilder\Platform\AssignmentOutcome;
use Modules\TemplateBuilder\Platform\ResolutionContext;
use Modules\TemplateBuilder\Services\AssignmentResolver;
use Tests\TenantTestCase;

class AssignmentResolverTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext(Str::uuid()->toString());
    }

    public function test_resolves_single_matching_assignment(): void
    {
        [$template] = $this->publishedTemplate('invoice');
        $this->assign($template, 'invoice', priority: 100);

        $result = $this->resolver()->resolve(ResolutionContext::forDocument('invoice'));

        $this->assertSame(AssignmentOutcome::Resolved, $result->outcome);
        $this->assertSame($template->id, $result->template?->id);
        $this->assertNotEmpty($result->explanation);
    }

    public function test_equal_priority_tie_is_rejected_as_ambiguous(): void
    {
        [$t1] = $this->publishedTemplate('invoice', name: 'T1');
        [$t2] = $this->publishedTemplate('invoice', name: 'T2');
        $this->assign($t1, 'invoice', priority: 100);
        $this->assign($t2, 'invoice', priority: 100);

        $result = $this->resolver()->resolve(ResolutionContext::forDocument('invoice'));

        $this->assertSame(AssignmentOutcome::Ambiguous, $result->outcome);
        $this->assertNull($result->template);
    }

    public function test_no_match_with_fallback_disabled_returns_not_assigned(): void
    {
        $resolver = new AssignmentResolver(allowDefaultFallback: false);

        $result = $resolver->resolve(ResolutionContext::forDocument('invoice'));

        $this->assertSame(AssignmentOutcome::NotAssigned, $result->outcome);
    }

    public function test_no_match_falls_back_to_system_default(): void
    {
        [$default] = $this->publishedTemplate('invoice', systemDefault: true);

        $result = $this->resolver()->resolve(ResolutionContext::forDocument('invoice'));

        $this->assertSame(AssignmentOutcome::DefaultApplied, $result->outcome);
        $this->assertSame($default->id, $result->template?->id);
    }

    public function test_higher_priority_wins_over_lower(): void
    {
        [$low] = $this->publishedTemplate('invoice', name: 'Low');
        [$high] = $this->publishedTemplate('invoice', name: 'High');
        $this->assign($low, 'invoice', priority: 50);
        $this->assign($high, 'invoice', priority: 100);

        $result = $this->resolver()->resolve(ResolutionContext::forDocument('invoice'));

        $this->assertSame(AssignmentOutcome::Resolved, $result->outcome);
        $this->assertSame($high->id, $result->template?->id);
    }

    public function test_context_dimension_filters_assignments(): void
    {
        [$en] = $this->publishedTemplate('invoice', name: 'EN');
        [$fr] = $this->publishedTemplate('invoice', name: 'FR');
        $this->assign($en, 'invoice', priority: 100, attributes: ['language' => 'en']);
        $this->assign($fr, 'invoice', priority: 100, attributes: ['language' => 'fr']);

        $frResult = $this->resolver()->resolve(ResolutionContext::forDocument('invoice', ['language' => 'fr']));
        $enResult = $this->resolver()->resolve(ResolutionContext::forDocument('invoice', ['language' => 'en']));

        $this->assertSame($fr->id, $frResult->template?->id);
        $this->assertSame($en->id, $enResult->template?->id);
    }

    public function test_excludes_non_published_templates(): void
    {
        $template = DocumentTemplate::create([
            'document_type' => 'invoice',
            'name' => 'Still Draft',
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => ['blocks' => []],
            'status' => DocumentTemplate::STATUS_DRAFT,
        ]);
        $this->assign($template, 'invoice', priority: 100);

        $result = $this->resolver()->resolve(ResolutionContext::forDocument('invoice'));

        $this->assertNotSame(AssignmentOutcome::Resolved, $result->outcome);
    }

    private function resolver(): AssignmentResolver
    {
        return new AssignmentResolver(allowDefaultFallback: true);
    }

    private function publishedTemplate(string $type, string $name = 'T', bool $systemDefault = false): array
    {
        $template = DocumentTemplate::create([
            'document_type' => $type,
            'name' => $name,
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => ['blocks' => []],
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'published_version' => 1,
            'is_system_default' => $systemDefault,
        ]);

        $template->versions()->create([
            'template_id' => $template->id,
            'version' => 1,
            'layout_config' => ['blocks' => []],
            'is_published' => true,
        ]);

        return [$template];
    }

    private function assign(DocumentTemplate $template, string $type, int $priority, array $attributes = []): DocumentTemplateAssignment
    {
        return DocumentTemplateAssignment::create(array_merge([
            'document_type' => $type,
            'template_id' => $template->id,
            'scope_type' => 'tenant',
            'priority' => $priority,
            'is_active' => true,
        ], $attributes));
    }
}
