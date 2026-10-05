<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Accounting\Events\ApprovalDecisionEvent;
use App\Models\DocumentFollower;
use App\Services\Chatter\DocumentFollowerService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Issue #639 (CHATTER-EPIC #628) — approval auto-follow rule.
 *
 * Whoever approves a document joins its follower list (`auto:approver`), so
 * they keep receiving the document's chatter after the approval. Runs for
 * every approval-request subject that is a real Eloquent model; a subject
 * stored under a registry alias is skipped (no model to follow).
 */
class DocumentFollowerApprovalListener
{
    public function __construct(
        private readonly DocumentFollowerService $followers,
    ) {}

    public function handle(ApprovalDecisionEvent $event): void
    {
        if ($event->action !== ApprovalDecisionEvent::ACTION_APPROVED) {
            return;
        }

        $actor = $event->actor;
        $subjectType = (string) $event->approvalRequest->subject_type;
        $subjectId = (int) $event->approvalRequest->subject_id;

        if ($actor === null || $subjectId <= 0) {
            return;
        }

        if (! class_exists($subjectType) || ! is_subclass_of($subjectType, Model::class)) {
            return;
        }

        try {
            $this->followers->autoFollowActor(
                $subjectType,
                $subjectId,
                (int) $actor->id,
                DocumentFollower::SOURCE_APPROVER,
            );
        } catch (\Throwable $e) {
            // The approval itself is already committed; a follower write
            // failure must not turn a successful approval into an error, but
            // it must be visible in the log.
            Log::error('DocumentFollowerApprovalListener: failed to auto-follow approver', [
                'approval_request_id' => $event->approvalRequest->id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'user_id' => $actor->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
