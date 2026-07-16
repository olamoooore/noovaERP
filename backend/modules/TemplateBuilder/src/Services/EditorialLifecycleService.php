<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Illuminate\Support\Facades\DB;
use Modules\TemplateBuilder\Exceptions\TemplatePlatformException;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateVersion;
use Modules\TemplateBuilder\Platform\EditorialState;
use Modules\TemplateBuilder\Platform\TemplateErrorCode;

/**
 * ADR-004 §9 — Editorial lifecycle manager.
 *
 * Enforces the 5-state lifecycle (Draft → In Review → Published → Superseded
 * → Archived) with a controlled review gate. Supersession is explicit: a new
 * publication for the same registration/type can supersede the prior published
 * version, which is retained for history but excluded from new resolution.
 */
final class EditorialLifecycleService
{
    public function __construct(
        private readonly AuditWriter $audit,
        private readonly ?CommunicationTemplateBridge $communicationBridge = null,
    ) {}

    /**
     * Submit a draft for review (ADR-004 §9 "In Review").
     */
    public function submitForReview(DocumentTemplate $template, ?int $reviewerId = null): DocumentTemplate
    {
        $this->transition($template, EditorialState::InReview);

        $template->update([
            'review_status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_decision' => null,
            'review_notes' => null,
        ]);

        $this->audit->record('template.review.requested', $template, ['reviewer' => $reviewerId]);

        return $template->fresh();
    }

    /**
     * Approve a template under review and publish it (ADR-004 §9).
     */
    public function approve(DocumentTemplate $template, int $reviewerId, ?string $changeNote = null, ?string $notes = null): DocumentTemplate
    {
        if ($template->status !== DocumentTemplate::STATUS_IN_REVIEW) {
            throw TemplatePlatformException::fromCode(
                TemplateErrorCode::ValidationFailed,
                'Only templates in review can be approved.',
                ['current_status' => $template->status],
            );
        }

        return DB::transaction(function () use ($template, $reviewerId, $changeNote, $notes) {
            $template->update([
                'review_status' => 'approved',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_decision' => 'approved',
                'review_notes' => $notes,
            ]);

            $this->audit->record('template.review.approved', $template, ['reviewer' => $reviewerId]);

            return $this->publish($template, $changeNote, $reviewerId);
        });
    }

    /**
     * Reject a template under review, returning it to draft (ADR-004 §9).
     */
    public function reject(DocumentTemplate $template, int $reviewerId, ?string $notes = null): DocumentTemplate
    {
        if ($template->status !== DocumentTemplate::STATUS_IN_REVIEW) {
            throw TemplatePlatformException::fromCode(
                TemplateErrorCode::ValidationFailed,
                'Only templates in review can be rejected.',
                ['current_status' => $template->status],
            );
        }

        $template->update([
            'review_status' => 'rejected',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'review_decision' => 'rejected',
            'review_notes' => $notes,
        ]);

        $this->transition($template, EditorialState::Draft);

        $this->audit->record('template.review.rejected', $template, ['reviewer' => $reviewerId, 'notes' => $notes]);

        return $template->fresh();
    }

    /**
     * Publish a template immutably (ADR-004 §9). Optionally supersedes the
     * previously-published template for the same registration/type.
     */
    public function publish(DocumentTemplate $template, ?string $changeNote = null, ?int $actorId = null): DocumentTemplate
    {
        return DB::transaction(function () use ($template, $changeNote, $actorId) {
            $next = ($template->versions()->max('version') ?? 0) + 1;

            $template->versions()->update(['is_published' => false]);

            DocumentTemplateVersion::create([
                'template_id' => $template->id,
                'version' => $next,
                'layout_config' => $template->layout_config,
                'html' => $template->html,
                'css' => $template->css,
                'change_note' => $changeNote,
                'is_published' => true,
                'created_by' => $actorId ?? auth()->id(),
            ]);

            // Supersede prior published siblings for the same registration/type.
            $this->supersedeSiblings($template);

            $template->update([
                'status' => DocumentTemplate::STATUS_PUBLISHED,
                'published_version' => $next,
            ]);

            // ADR-004 §5 (#56-57): fold communication templates into the
            // Communication engine on publish (no second authoring studio).
            $this->communicationBridge?->publishToCommunicationEngine($template);

            $this->audit->record('template.published', $template, ['version' => $next, 'actor' => $actorId]);

            return $template->fresh();
        });
    }

    /**
     * Archive a template (ADR-004 §9). Archived templates are unavailable for
     * new use; history is retained under policy.
     */
    public function archive(DocumentTemplate $template, ?int $actorId = null): DocumentTemplate
    {
        $this->transition($template, EditorialState::Archived);

        $this->audit->record('template.archived', $template, ['actor' => $actorId]);

        return $template->fresh();
    }

    /**
     * Restore an archived template to draft (ADR-004 §14 "restore" capability).
     */
    public function restore(DocumentTemplate $template, ?int $actorId = null): DocumentTemplate
    {
        if ($template->status !== DocumentTemplate::STATUS_ARCHIVED) {
            throw TemplatePlatformException::fromCode(
                TemplateErrorCode::ValidationFailed,
                'Only archived templates can be restored.',
                ['current_status' => $template->status],
            );
        }

        $template->update(['status' => DocumentTemplate::STATUS_DRAFT]);

        $this->audit->record('template.restored', $template, ['actor' => $actorId]);

        return $template->fresh();
    }

    private function transition(DocumentTemplate $template, EditorialState $target): void
    {
        $current = $template->editorialState();

        if (! $current->canTransitionTo($target)) {
            throw TemplatePlatformException::fromCode(
                TemplateErrorCode::ValidationFailed,
                "Editorial transition {$current->value} → {$target->value} is not permitted.",
                ['from' => $current->value, 'to' => $target->value],
            );
        }

        $template->update(['status' => $target->value]);
    }

    /**
     * Mark any other PUBLISHED template bound to the same registration/type as
     * SUPERSEDED (ADR-004 §9). History retained; excluded from resolution.
     */
    private function supersedeSiblings(DocumentTemplate $published): void
    {
        $siblings = DocumentTemplate::query()
            ->whereKeyNot($published->id)
            ->where('status', DocumentTemplate::STATUS_PUBLISHED)
            ->where(function ($q) use ($published) {
                $q->where(function ($inner) use ($published) {
                    $inner->where('document_type', $published->document_type);
                    if ($published->registration_id) {
                        $inner->where('registration_id', $published->registration_id);
                    }
                });
                if ($published->interaction_type) {
                    $q->where('interaction_type', $published->interaction_type);
                }
            })
            ->get();

        foreach ($siblings as $sibling) {
            $sibling->update([
                'status' => DocumentTemplate::STATUS_SUPERSEDED,
                'superseded_by' => $published->id,
            ]);
            $this->audit->record('template.superseded', $sibling, ['by' => $published->id]);
        }
    }
}
