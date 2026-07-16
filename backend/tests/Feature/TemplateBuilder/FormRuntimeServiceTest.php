<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use Illuminate\Support\Str;
use Modules\TemplateBuilder\Exceptions\TemplatePlatformException;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Platform\InteractionType;
use Modules\TemplateBuilder\Services\FormRuntimeService;
use Tests\TenantTestCase;

class FormRuntimeServiceTest extends TenantTestCase
{
    private FormRuntimeService $forms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext(Str::uuid()->toString());
        $this->forms = app(FormRuntimeService::class);
    }

    public function test_valid_submission_is_recorded(): void
    {
        $template = $this->publishedForm();

        $submission = $this->forms->submit($template, ['name' => 'Jane', 'email' => 'jane@example.com'], [
            'submitter_email' => 'jane@example.com',
        ]);

        $this->assertSame('submitted', $submission->status);
        $this->assertSame('jane@example.com', $submission->answers['email']);
    }

    public function test_validation_rejects_missing_required_field(): void
    {
        $template = $this->publishedForm();

        $this->expectException(TemplatePlatformException::class);

        $this->forms->submit($template, ['email' => 'x@y.z']);
    }

    public function test_validation_rejects_invalid_email_type(): void
    {
        $template = $this->publishedForm();

        $this->expectException(TemplatePlatformException::class);

        $this->forms->submit($template, ['name' => 'Jane', 'email' => 'not-an-email']);
    }

    public function test_idempotency_replay_returns_same_submission(): void
    {
        $template = $this->publishedForm();
        $ctx = ['submitter_email' => 'dup@example.com', 'idempotency_key' => 'key-1'];

        $first = $this->forms->submit($template, ['name' => 'Jane', 'email' => 'dup@example.com'], $ctx);
        $second = $this->forms->submit($template, ['name' => 'Jane', 'email' => 'dup@example.com'], $ctx);

        $this->assertSame($first->id, $second->id);
    }

    public function test_rejects_submission_to_non_form_template(): void
    {
        $template = DocumentTemplate::create([
            'document_type' => 'invoice',
            'name' => 'Doc',
            'interaction_type' => InteractionType::BusinessDocument->value,
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => ['blocks' => []],
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'published_version' => 1,
        ]);

        $this->expectException(TemplatePlatformException::class);

        $this->forms->submit($template, []);
    }

    public function test_conditional_requirement_when_other_field_matches(): void
    {
        $template = DocumentTemplate::create([
            'document_type' => 'lead_form',
            'name' => 'Conditional',
            'interaction_type' => InteractionType::DataEntryForm->value,
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => ['fields' => [
                ['key' => 'contact_method', 'type' => 'text'],
                ['key' => 'phone', 'type' => 'text', 'required_when' => ['field' => 'contact_method', 'equals' => 'phone']],
            ]],
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'published_version' => 1,
        ]);

        // phone not required when contact_method != phone
        $ok = $this->forms->submit($template, ['contact_method' => 'email'], ['submitter_email' => 'a@b.c']);
        $this->assertSame('submitted', $ok->status);

        // phone required when contact_method == phone
        $this->expectException(TemplatePlatformException::class);
        $this->forms->submit($template->fresh(), ['contact_method' => 'phone'], ['submitter_email' => 'a2@b.c']);
    }

    private function publishedForm(): DocumentTemplate
    {
        return DocumentTemplate::create([
            'document_type' => 'lead_capture',
            'name' => 'Lead Form',
            'interaction_type' => InteractionType::DataEntryForm->value,
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => ['fields' => [
                ['key' => 'name', 'type' => 'text', 'required' => true],
                ['key' => 'email', 'type' => 'email', 'required' => true],
            ]],
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'published_version' => 1,
        ]);
    }
}
