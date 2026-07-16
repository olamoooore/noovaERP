<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Exceptions\TemplatePlatformException;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Platform\EditorialState;
use Modules\TemplateBuilder\Services\EditorialLifecycleService;
use Tests\TenantTestCase;

class EditorialLifecycleServiceTest extends TenantTestCase
{
    private EditorialLifecycleService $lifecycle;

    private int $reviewerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext(Str::uuid()->toString());
        $this->lifecycle = app(EditorialLifecycleService::class);
        // Reviewer must exist so created_by FK on versions holds.
        $this->reviewerId = User::create([
            'name' => 'Reviewer',
            'email' => 'rev'.Str::uuid()->toString().'@test.local',
            'password' => 'x',
        ])->id;
    }

    public function test_submit_for_review_moves_to_in_review(): void
    {
        $template = $this->draft();

        $result = $this->lifecycle->submitForReview($template);

        $this->assertSame(DocumentTemplate::STATUS_IN_REVIEW, $result->status);
        $this->assertSame('pending', $result->review_status);
    }

    public function test_approve_publishes_and_pins_version(): void
    {
        $template = $this->draft();
        $this->lifecycle->submitForReview($template);

        $published = $this->lifecycle->approve($template->fresh(), reviewerId: $this->reviewerId, changeNote: 'ok');

        $this->assertSame(DocumentTemplate::STATUS_PUBLISHED, $published->status);
        $this->assertSame(1, $published->published_version);
        $this->assertSame('approved', $published->review_decision);
        $this->assertNotNull($published->reviewed_at);
    }

    public function test_reject_returns_to_draft(): void
    {
        $template = $this->draft();
        $this->lifecycle->submitForReview($template);

        $rejected = $this->lifecycle->reject($template->fresh(), reviewerId: $this->reviewerId, notes: 'nope');

        $this->assertSame(DocumentTemplate::STATUS_DRAFT, $rejected->status);
        $this->assertSame('rejected', $rejected->review_decision);
    }

    public function test_cannot_approve_a_non_review_template(): void
    {
        $this->expectException(TemplatePlatformException::class);

        $template = $this->draft();

        $this->lifecycle->approve($template, reviewerId: $this->reviewerId);
    }

    public function test_publish_supersedes_prior_published_sibling(): void
    {
        $first = $this->draft();
        $this->lifecycle->submitForReview($first);
        $first = $this->lifecycle->approve($first->fresh(), reviewerId: $this->reviewerId);

        $second = $this->draft();
        $this->lifecycle->submitForReview($second);
        $second = $this->lifecycle->approve($second->fresh(), reviewerId: $this->reviewerId);

        $this->assertSame(DocumentTemplate::STATUS_SUPERSEDED, $first->fresh()->status);
        $this->assertSame($second->id, $first->fresh()->superseded_by);
    }

    public function test_archive_then_cannot_be_resolved(): void
    {
        $template = $this->draft();
        $this->lifecycle->submitForReview($template);
        $published = $this->lifecycle->approve($template->fresh(), reviewerId: $this->reviewerId);

        $archived = $this->lifecycle->archive($published);

        $this->assertSame(DocumentTemplate::STATUS_ARCHIVED, $archived->status);
        $this->assertFalse($archived->isResolvable());
    }

    public function test_restore_brings_archived_back_to_draft(): void
    {
        $template = $this->draft();
        $this->lifecycle->submitForReview($template);
        $published = $this->lifecycle->approve($template->fresh(), reviewerId: $this->reviewerId);
        $archived = $this->lifecycle->archive($published);

        $restored = $this->lifecycle->restore($archived);

        $this->assertSame(DocumentTemplate::STATUS_DRAFT, $restored->status);
    }

    public function test_editorial_state_transitions_are_enforced(): void
    {
        $draft = EditorialState::Draft;

        $this->assertContains(EditorialState::InReview, $draft->permittedTransitions());
        $this->assertNotContains(EditorialState::Published, $draft->permittedTransitions());
        $this->assertFalse($draft->canTransitionTo(EditorialState::Published));
    }

    private function draft(): DocumentTemplate
    {
        return DocumentTemplate::create([
            'document_type' => 'invoice',
            'name' => 'Lifecycle Test',
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => ['blocks' => [['type' => 'text', 'content' => 'x']]],
            'status' => DocumentTemplate::STATUS_DRAFT,
        ]);
    }
}
