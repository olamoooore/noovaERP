<?php

declare(strict_types=1);

namespace App\Services\Chatter;

use App\Models\CustomerContact;
use App\Models\DocumentFollower;
use App\Models\User;
use App\Platform\Notifications\NotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Communication\Models\CommunicationSubscriber;

/**
 * Issue #639 (CHATTER-EPIC #628) — per-document followers.
 *
 * One service owns every follower rule so the HTTP endpoints, the chatter
 * notification fan-out and the document-lifecycle auto-follow hooks cannot
 * drift apart:
 *
 *   - follow / unfollow / list internal users and partner contacts;
 *   - auto-follow the creator, the resolved salesperson, the approver and
 *     whoever moves a document's stage;
 *   - notify internal followers through the platform NotificationService,
 *     honouring each follower's per-subtype subscription flag and never
 *     notifying the actor about their own action;
 *   - mirror partner contacts into the Communication engine's subscriber
 *     list so the existing outbox delivers the customer-facing email.
 */
class DocumentFollowerService
{
    public const EVENT_MESSAGE = 'chatter.message';

    public const EVENT_ACTIVITY = 'chatter.activity';

    /** Issue #635: direct notification to the user an activity was assigned to. */
    public const EVENT_ACTIVITY_ASSIGNED = 'chatter.activity.assigned';

    public const EVENT_STAGE_CHANGE = 'chatter.stage_change';

    /** Lifecycle chatter events that trigger the stage-change rule. */
    public const STAGE_EVENTS = ['status_changed', 'cancelled'];

    /** Lifecycle chatter event that triggers the creation rule. */
    public const CREATED_EVENT = 'created';

    private const SUBSCRIPTION_FLAGS = [
        'subscribe_messages',
        'subscribe_activities',
        'subscribe_stage_changes',
    ];

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @return Collection<int, DocumentFollower>
     */
    public function followers(string $documentType, int $documentId): Collection
    {
        return DocumentFollower::query()
            ->forDocument($documentType, $documentId)
            ->with(['user', 'contact'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Follow (or update the subscriptions of) an internal user.
     *
     * Idempotent: a second follow updates the supplied flags and never
     * duplicates the row. The original `follow_source` (e.g. auto:creator) is
     * preserved — a later manual follow does not rewrite why the user was
     * added, and an automatic rule does not overwrite a manual follow.
     *
     * @param  array<string, bool|null>  $subscriptions
     */
    public function followUser(
        string $documentType,
        int $documentId,
        int $userId,
        array $subscriptions = [],
        string $source = DocumentFollower::SOURCE_MANUAL,
    ): DocumentFollower {
        $existing = DocumentFollower::query()
            ->forDocument($documentType, $documentId)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            $updates = $this->subscriptionAttributes($subscriptions);
            if ($updates !== []) {
                $existing->update($updates);
            }

            return $existing->fresh(['user', 'contact']);
        }

        $follower = DocumentFollower::create(array_merge([
            'tenant_id' => $this->tenantId(),
            'document_type' => $documentType,
            'document_id' => $documentId,
            'user_id' => $userId,
            'contact_id' => null,
            'follower_type' => DocumentFollower::TYPE_INTERNAL,
            'follow_source' => $source,
        ], $this->subscriptionAttributes($subscriptions, true)));

        return $follower->fresh(['user', 'contact']);
    }

    /**
     * Follow (or update the subscriptions of) a customer-contact partner.
     *
     * The contact is mirrored into `communication_subscribers` with the email
     * channel and `is_active` tied to the message subscription, so the shared
     * Communication engine's outbox is what actually delivers the email.
     *
     * @param  array<string, bool|null>  $subscriptions
     */
    public function followContact(
        string $documentType,
        int $documentId,
        CustomerContact $contact,
        array $subscriptions = [],
        string $source = DocumentFollower::SOURCE_CONTACT,
    ): DocumentFollower {
        $existing = DocumentFollower::query()
            ->forDocument($documentType, $documentId)
            ->where('contact_id', (int) $contact->id)
            ->first();

        if ($existing) {
            $updates = $this->subscriptionAttributes($subscriptions);
            if ($updates !== []) {
                $existing->update($updates);
            }
            $follower = $existing->fresh(['user', 'contact']);
        } else {
            $follower = DocumentFollower::create(array_merge([
                'tenant_id' => $this->tenantId(),
                'document_type' => $documentType,
                'document_id' => $documentId,
                'user_id' => null,
                'contact_id' => (int) $contact->id,
                'follower_type' => DocumentFollower::TYPE_PARTNER,
                'follow_source' => $source,
            ], $this->subscriptionAttributes($subscriptions, true)))->fresh(['user', 'contact']);
        }

        $this->syncPartnerSubscriber($follower);

        return $follower;
    }

    /**
     * Remove a follower row. A partner's Communication subscriber is
     * deactivated in the same step so no further email goes out.
     */
    public function remove(DocumentFollower $follower): void
    {
        if ($follower->follower_type === DocumentFollower::TYPE_PARTNER) {
            $this->deactivatePartnerSubscriber($follower);
        }

        $follower->delete();
    }

    /**
     * Apply the creation-time auto-follow rules: the actor (creator) and the
     * free-text salesperson resolved to a tenant user.
     *
     * Never overwrites an existing follower's source/flags; it only adds.
     */
    public function autoFollowOnCreate(Model $document, ?int $actorId = null): void
    {
        $documentType = $document::class;
        $documentId = (int) $document->getKey();
        if ($documentId <= 0) {
            return;
        }

        $creatorId = $actorId ?? (int) ($document->getAttribute('created_by') ?? 0);
        if ($creatorId > 0 && User::query()->whereKey($creatorId)->exists()) {
            $this->followUser($documentType, $documentId, $creatorId, [], DocumentFollower::SOURCE_CREATOR);
        }

        $salesperson = $document->getAttribute('salesperson');
        $salespersonUser = $this->resolveUserByName(is_string($salesperson) ? $salesperson : null);

        if ($salespersonUser !== null && (int) $salespersonUser->id !== $creatorId) {
            $this->followUser($documentType, $documentId, (int) $salespersonUser->id, [], DocumentFollower::SOURCE_SALESPERSON);
        }
    }

    /**
     * Auto-follow the user behind a lifecycle action (approver, stage mover).
     */
    public function autoFollowActor(string $documentType, int $documentId, ?int $userId, string $source): ?DocumentFollower
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        if (! User::query()->whereKey($userId)->exists()) {
            return null;
        }

        return $this->followUser($documentType, $documentId, $userId, [], $source);
    }

    /**
     * Fan a chatter event out to the followers subscribed to it.
     *
     * Internal followers get a platform notification row (in-app + mail);
     * partner followers are handled by the Communication engine subscription
     * mirror created at follow time. The actor is never notified about their
     * own action.
     *
     * @param  array<string, mixed>  $context
     */
    public function notifyFollowers(
        string $event,
        string $documentType,
        int $documentId,
        ?int $actorId = null,
        array $context = [],
    ): void {
        $flag = $this->flagForEvent($event);
        if ($flag === null) {
            return;
        }

        foreach ($this->followers($documentType, $documentId) as $follower) {
            if (! $follower->{$flag}) {
                continue;
            }

            // Partner contacts are delivered by the Communication outbox.
            if ($follower->user_id === null) {
                continue;
            }

            if ($actorId !== null && (int) $follower->user_id === $actorId) {
                continue;
            }

            $user = $follower->user;
            if (! $user) {
                continue;
            }

            try {
                $this->notifications->dispatch($event, $user, $this->notificationData(
                    $event,
                    $documentType,
                    $documentId,
                    $actorId,
                    $context,
                ));
            } catch (\Throwable $e) {
                // A notification failure must not roll back the document
                // action that triggered it; surface it (with context) rather
                // than hide it, matching SalesApprovalDecisionListener.
                Log::error('DocumentFollowerService: follower notification failed', [
                    'event' => $event,
                    'document_type' => $documentType,
                    'document_id' => $documentId,
                    'user_id' => $follower->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Issue #635: an activity assigned to a specific user notifies that
     * assignee directly. Follower fan-out only reaches users who already
     * follow the document with the activity subscription on, so without this
     * an assignee who is not a follower heard nothing. The actor never
     * notifies themselves (self-assignment is silent).
     *
     * Best-effort side effect, like notifyFollowers: the activity row is
     * already committed and a notification failure is surfaced, not hidden.
     *
     * @param  array<string, mixed>  $context
     */
    public function notifyAssignee(
        string $documentType,
        int $documentId,
        User $assignee,
        int $actorId,
        array $context = [],
    ): void {
        if ((int) $assignee->id === $actorId) {
            return;
        }

        try {
            $this->notifications->dispatch(
                self::EVENT_ACTIVITY_ASSIGNED,
                $assignee,
                $this->notificationData(self::EVENT_ACTIVITY_ASSIGNED, $documentType, $documentId, $actorId, $context),
            );
        } catch (\Throwable $e) {
            Log::error('DocumentFollowerService: assignee notification failed', [
                'event' => self::EVENT_ACTIVITY_ASSIGNED,
                'document_type' => $documentType,
                'document_id' => $documentId,
                'user_id' => $assignee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, bool|null>  $subscriptions
     * @return array<string, bool>
     */
    private function subscriptionAttributes(array $subscriptions, bool $defaults = false): array
    {
        $attributes = [];

        foreach (self::SUBSCRIPTION_FLAGS as $flag) {
            if (array_key_exists($flag, $subscriptions) && $subscriptions[$flag] !== null) {
                $attributes[$flag] = (bool) $subscriptions[$flag];
            } elseif ($defaults) {
                $attributes[$flag] = true;
            }
        }

        return $attributes;
    }

    private function flagForEvent(string $event): ?string
    {
        return match ($event) {
            self::EVENT_MESSAGE => 'subscribe_messages',
            self::EVENT_ACTIVITY => 'subscribe_activities',
            self::EVENT_STAGE_CHANGE => 'subscribe_stage_changes',
            default => null,
        };
    }

    /**
     * Create/update the Communication subscriber that delivers partner emails
     * through the existing outbox. `is_active` honours the message flag.
     */
    private function syncPartnerSubscriber(DocumentFollower $follower): void
    {
        $contact = $follower->contact;
        $email = $contact?->email;

        if (! is_string($email) || trim($email) === '') {
            return;
        }

        CommunicationSubscriber::updateOrCreate(
            [
                'tenant_id' => $follower->tenant_id,
                'model' => $follower->document_type,
                'record_id' => (int) $follower->document_id,
                'email' => $email,
            ],
            [
                // Email only: internal followers carry the platform
                // notification; the partner is the customer-facing channel.
                'channels' => ['email'],
                'subtypes' => null,
                'is_active' => (bool) $follower->subscribe_messages,
            ],
        );
    }

    private function deactivatePartnerSubscriber(DocumentFollower $follower): void
    {
        $email = $follower->contact?->email;

        if (! is_string($email) || trim($email) === '') {
            return;
        }

        CommunicationSubscriber::query()
            ->where('tenant_id', $follower->tenant_id)
            ->where('model', $follower->document_type)
            ->where('record_id', (int) $follower->document_id)
            ->where('email', $email)
            ->update(['is_active' => false]);
    }

    private function resolveUserByName(?string $name): ?User
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function notificationData(
        string $event,
        string $documentType,
        int $documentId,
        ?int $actorId,
        array $context,
    ): array {
        $label = class_basename($documentType);
        $actorName = $actorId !== null ? (User::query()->whereKey($actorId)->value('name') ?? 'A teammate') : 'Someone';

        $body = match ($event) {
            self::EVENT_MESSAGE => sprintf(
                '%s posted a message on %s #%d: %s',
                $actorName,
                $label,
                $documentId,
                $this->excerpt((string) ($context['body'] ?? '')),
            ),
            self::EVENT_ACTIVITY => sprintf(
                '%s scheduled a %s on %s #%d: %s',
                $actorName,
                (string) ($context['activity_type'] ?? 'activity'),
                $label,
                $documentId,
                (string) ($context['summary'] ?? ''),
            ),
            self::EVENT_ACTIVITY_ASSIGNED => sprintf(
                '%s assigned you a %s on %s #%d: %s',
                $actorName,
                (string) ($context['activity_type'] ?? 'activity'),
                $label,
                $documentId,
                (string) ($context['summary'] ?? ''),
            ),
            self::EVENT_STAGE_CHANGE => sprintf(
                '%s moved %s #%d from %s to %s.',
                $actorName,
                $label,
                $documentId,
                (string) ($context['from_state'] ?? 'the previous stage'),
                (string) ($context['to_state'] ?? 'a new stage'),
            ),
            default => sprintf('%s #%d was updated by %s.', $label, $documentId, $actorName),
        };

        return [
            'subject' => match ($event) {
                self::EVENT_MESSAGE => "New message on {$label} #{$documentId}",
                self::EVENT_ACTIVITY => "New activity on {$label} #{$documentId}",
                self::EVENT_ACTIVITY_ASSIGNED => "Activity assigned to you: {$label} #{$documentId}",
                self::EVENT_STAGE_CHANGE => "Stage change: {$label} #{$documentId}",
                default => "{$label} #{$documentId} updated",
            },
            'body' => $body,
            // Consumers can deep-link back to the document from these two.
            'document_type' => $documentType,
            'document_id' => $documentId,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
        ];
    }

    private function excerpt(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        return mb_strlen($body) > 140 ? mb_substr($body, 0, 137).'…' : $body;
    }

    private function tenantId(): ?string
    {
        try {
            return function_exists('tenant') && tenant() ? (string) tenant('id') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
