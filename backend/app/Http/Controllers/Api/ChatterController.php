<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ChatterAttachment;
use App\Models\CustomerContact;
use App\Models\DocumentFollower;
use App\Services\Chatter\ChatterService;
use App\Services\Chatter\DocumentFollowerService;
use App\Services\TenantRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Services\CommunicationEngine;

class ChatterController extends Controller
{
    public function __construct(
        private readonly ChatterService $chatter,
        private readonly TenantRoleService $roleService,
        private readonly CommunicationEngine $communication,
        private readonly DocumentFollowerService $followers,
    ) {}

    public function show(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId);

        $bundle = $this->chatter->bundle($subjectType, $subjectId);

        // Issue #81: merge canonical Communication messages with any legacy
        // chatter_messages rows that pre-date the cutover, sorted by created_at
        // so the chronological timeline is preserved. CommunicationMessage is
        // now the source of truth for new posts.
        $tenantId = (string) tenant()?->id;
        $communicationMessages = CommunicationMessage::query()
            ->where('model', $subjectType)
            ->where('record_id', (int) $subjectId)
            ->when($tenantId !== '', fn ($q) => $q->where('tenant_id', $tenantId))
            ->with('author')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (CommunicationMessage $m) {
                $author = $m->author;

                return [
                    'id' => $m->id,
                    'body' => $m->body,
                    'mentions' => [],
                    'is_internal_note' => $m->message_type === CommunicationMessage::TYPE_INTERNAL_NOTE,
                    'created_at' => optional($m->created_at)->toIso8601String(),
                    'source' => 'communication',
                    'actor' => $author ? [
                        'id' => $author->id,
                        'name' => $author->name,
                        'email' => $author->email,
                        'avatar' => $author->avatar ?? null,
                    ] : null,
                ];
            })
            ->all();

        $legacyMessages = $bundle['messages'] ?? [];
        $mergedMessages = collect($communicationMessages)
            ->concat(array_map(
                fn ($m) => array_merge($m, ['source' => 'chatter']),
                $legacyMessages,
            ))
            ->sortByDesc(fn ($m) => $m['created_at'] ?? '')
            ->values()
            ->all();

        $bundle['messages'] = $mergedMessages;

        return response()->json(['data' => $bundle]);
    }

    /**
     * Issue #87: lightweight polling endpoint for the chatter panel.
     *
     * The platform does not yet deploy a WebSocket server, so the frontend
     * polls this endpoint every ~10s while the chatter tab is open. Returns
     * only the messages + activities + attachments newer than the
     * `since` ISO-8601 timestamp so the payload stays small.
     *
     * When a WebSocket / Laravel Echo server is wired up, the same channel
     * (`chatter.{tenant}.{subjectType}.{subjectId}`) can replace this polling
     * with no frontend change beyond a transport switch.
     */
    public function since(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        // Accept any parseable datetime; ISO-8601 with timezone is the
        // canonical wire format from the frontend's \`toIso8601String\`.
        $since = null;
        if ($raw = $request->query('since')) {
            try {
                $since = \Carbon\Carbon::parse($raw)->toDateTimeString();
            } catch (\Throwable) {
                return response()->json([
                    'message' => 'The since field must be a valid date.',
                ], 422);
            }
        }

        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId);

        // Accept any parseable datetime; ISO-8601 with timezone is the
        // canonical wire format from the frontend's \`toIso8601String\`.
        $since = null;
        if ($raw = $request->query('since')) {
            try {
                $since = \Carbon\Carbon::parse($raw)->toDateTimeString();
            } catch (\Throwable) {
                return response()->json([
                    'message' => 'The since field must be a valid date.',
                ], 422);
            }
        }

        $tenantId = (string) tenant()?->id;
        $query = CommunicationMessage::query()
            ->where('model', $subjectType)
            ->where('record_id', (int) $subjectId)
            ->when($tenantId !== '', fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($since, fn ($q) => $q->where('created_at', '>', $since))
            ->with('author')
            ->orderBy('created_at');

        $messages = $query->get()
            ->map(function (CommunicationMessage $m) {
                $author = $m->author;

                return [
                    'id' => $m->id,
                    'body' => $m->body,
                    'mentions' => [],
                    'is_internal_note' => $m->message_type === CommunicationMessage::TYPE_INTERNAL_NOTE,
                    'created_at' => optional($m->created_at)->toIso8601String(),
                    'source' => 'communication',
                    'actor' => $author ? [
                        'id' => $author->id,
                        'name' => $author->name,
                        'email' => $author->email,
                        'avatar' => $author->avatar ?? null,
                    ] : null,
                ];
            })
            ->all();

        return response()->json([
            'data' => [
                'messages' => $messages,
                'since' => $messages ? end($messages)['created_at'] : $since,
            ],
        ]);
    }

    // ─── Issue #639: document followers ─────────────────────────────────────

    /**
     * List the followers of a document.
     */
    public function followers(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId);

        $followers = $this->followers->followers($subjectType, (int) $subjectId);

        // Issue #639: the picker needs the customer's contacts; return them as
        // meta so no document-specific page edit is required.
        $record = $subjectType::find($subjectId);
        $partnerContacts = [];

        if ($record !== null && $record->customer_id !== null) {
            $partnerContacts = CustomerContact::query()
                ->where('customer_id', (int) $record->customer_id)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (CustomerContact $contact) => [
                    'id' => (int) $contact->id,
                    'name' => $contact->name,
                    'email' => $contact->email,
                ])
                ->all();
        }

        return response()->json([
            'data' => $followers
                ->map(fn (DocumentFollower $follower) => $this->serializeFollower($follower))
                ->all(),
            'meta' => [
                'partner_contacts' => $partnerContacts,
            ],
        ]);
    }

    /**
     * Follow a document (idempotent upsert), as the authenticated user
     * (internal follower) or as a customer contact (partner follower).
     */
    public function follow(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        // Followers are readable/writable by anyone who may read the record —
        // the same view permission that gates the chatter endpoint itself.
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId);

        $data = $request->validate([
            'contact_id' => 'nullable|integer|exists:customer_contacts,id',
            'subscribe_messages' => 'nullable|boolean',
            'subscribe_activities' => 'nullable|boolean',
            'subscribe_stage_changes' => 'nullable|boolean',
        ]);

        if (isset($data['contact_id'])) {
            $contact = CustomerContact::find($data['contact_id']);

            if (! $contact) {
                abort(422, 'The selected contact does not exist.');
            }

            $record = $subjectType::find($subjectId);
            if (! $record || $record->customer_id === null || (int) $record->customer_id !== (int) $contact->customer_id) {
                abort(422, 'The contact does not belong to this document\'s customer.');
            }

            $follower = $this->followers->followContact(
                $subjectType,
                (int) $subjectId,
                $contact,
                $this->subscriptions($data),
                DocumentFollower::SOURCE_CONTACT,
            );
        } else {
            $follower = $this->followers->followUser(
                $subjectType,
                (int) $subjectId,
                (int) $request->user()->id,
                $this->subscriptions($data),
                DocumentFollower::SOURCE_MANUAL,
            );
        }

        return response()->json(['data' => $this->serializeFollower($follower)], 201);
    }

    /**
     * Unfollow a document. A user may remove themselves; managers may remove
     * anyone; partner contacts may be removed by anyone with record access.
     */
    public function unfollow(Request $request, string $subjectType, int|string $subjectId, int $followerId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId);

        $follower = DocumentFollower::query()
            ->forDocument($subjectType, (int) $subjectId)
            ->whereKey($followerId)
            ->firstOrFail();

        $this->authorizeFollowerRemoval($request, $follower);

        $this->followers->remove($follower);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool>
     */
    private function subscriptions(array $data): array
    {
        $subscriptions = [];

        foreach (['subscribe_messages', 'subscribe_activities', 'subscribe_stage_changes'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $subscriptions[$flag] = (bool) $data[$flag];
            }
        }

        return $subscriptions;
    }

    private function serializeFollower(DocumentFollower $follower): array
    {
        return [
            'id' => $follower->id,
            'follower_type' => $follower->follower_type,
            'follow_source' => $follower->follow_source,
            'subscribe_messages' => (bool) $follower->subscribe_messages,
            'subscribe_activities' => (bool) $follower->subscribe_activities,
            'subscribe_stage_changes' => (bool) $follower->subscribe_stage_changes,
            'created_at' => optional($follower->created_at)->toIso8601String(),
            'user' => $follower->user ? [
                'id' => $follower->user->id,
                'name' => $follower->user->name,
                'email' => $follower->user->email,
                'avatar' => $follower->user->avatar ?? null,
            ] : null,
            'contact' => $follower->contact ? [
                'id' => $follower->contact->id,
                'name' => $follower->contact->name,
                'email' => $follower->contact->email,
            ] : null,
        ];
    }

    private function authorizeFollowerRemoval(Request $request, DocumentFollower $follower): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        // The follower may always remove themselves.
        if ($follower->user_id !== null && (int) $follower->user_id === (int) $user->id) {
            return;
        }

        // Partner contacts have no login; anyone who may read the record may
        // remove their subscription.
        if ($follower->follower_type === DocumentFollower::TYPE_PARTNER) {
            return;
        }

        $isSuperAdmin = ($user->is_super_admin ?? false) || ($user->role ?? null) === 'platform_super_admin';
        if ($isSuperAdmin) {
            return;
        }

        $tenantId = (function_exists('tenant') && tenant())
            ? (string) tenant('id')
            : (string) $request->header('X-Tenant-ID', '');
        if ($tenantId === '') {
            abort(403, 'Tenant context required.');
        }

        $centralUserId = (int) (DB::table('public.users')->where('email', $user->email)->value('id') ?? $user->id);
        $role = $this->roleService->getRole($centralUserId, $tenantId);

        if (! $role || ! in_array($role, [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER], true)) {
            abort(403, 'Only the follower or a manager may remove this follower.');
        }
    }

    /**
     * Issue #639: follower fan-out is a side channel — a notification or
     * mirror failure must never roll back the message/activity that was
     * already stored. Failures are reported with context.
     *
     * @param  array<string, mixed>  $context
     */
    private function notifyFollowerSideEffect(string $event, string $subjectType, int $subjectId, ?int $actorId, array $context): void
    {
        try {
            $this->followers->notifyFollowers($event, $subjectType, $subjectId, $actorId, $context);
        } catch (\Throwable $e) {
            Log::error('Chatter: follower notification failed', [
                'event' => $event,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function addMessage(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId, 'write');
        $data = $request->validate([
            'body' => 'required|string|min:1|max:10000',
        ]);

        // Issue #81: write through the canonical Communication engine so the
        // two competing chatter implementations reconcile. The legacy
        // ChatterService is retained for activity scheduling (different
        // semantics) and for reading any historical chatter_messages rows
        // that pre-date the cutover.
        try {
            $message = $this->communication->postComment(
                model: $subjectType,
                recordId: (int) $subjectId,
                body: $data['body'],
                authorId: (int) $request->user()->id,
                tenantId: (string) tenant()?->id,
            );
        } catch (\Throwable $e) {
            // Fallback: if the Communication subscriber throws (queue down,
            // tenant binding edge case), fall back to the legacy store so
            // the operator never loses a customer note.
            Log::warning('Chatter: Communication post failed, falling back to legacy', [
                'error' => $e->getMessage(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
            ]);
            $message = $this->chatter->addMessage($subjectType, $subjectId, $data['body']);
        }

        $author = $message->author ?? null;

        // Issue #639: notify followers subscribed to messages (the author is
        // excluded inside the service).
        $this->notifyFollowerSideEffect(
            DocumentFollowerService::EVENT_MESSAGE,
            $subjectType,
            (int) $subjectId,
            (int) $request->user()->id,
            ['body' => $data['body']],
        );

        return response()->json(['data' => [
            'id' => $message->id,
            'body' => $message->body,
            'mentions' => $message->mentions ?? [],
            'created_at' => optional($message->created_at)->toIso8601String(),
            'actor' => $author ? [
                'id' => $author->id,
                'name' => $author->name,
                'email' => $author->email,
                'avatar' => $author->avatar ?? null,
            ] : null,
        ]], 201);
    }

    public function addActivity(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId, 'write');
        $data = $request->validate([
            'activity_type' => ['required', Rule::in(['follow_up', 'call', 'meeting', 'todo', 'reminder'])],
            'summary' => 'required|string|min:1|max:255',
            'note' => 'nullable|string|max:10000',
            'due_date' => 'nullable|date',
            // Issue #83: assigning to SOMEONE ELSE is a supervisory action
            // (spam/harassment guard); self-assignment stays open to everyone.
            'assigned_to_id' => 'nullable|integer|exists:users,id',
        ]);

        $assigneeId = isset($data['assigned_to_id']) ? (int) $data['assigned_to_id'] : null;
        $actorId = (int) $request->user()->id;

        if ($assigneeId !== null && $assigneeId !== $actorId) {
            $role = $this->roleService->getRole($actorId, (string) tenant()->id);

            if (! $role || ! in_array($role, [\App\Enums\UserRole::OWNER, \App\Enums\UserRole::ADMIN, \App\Enums\UserRole::MANAGER], true)) {
                return response()->json([
                    'message' => 'You do not have permission to assign activities to other users.',
                ], 403);
            }
        }

        $activity = $this->chatter->addActivity($subjectType, $subjectId, $data);

        // Issue #639: notify followers subscribed to activities.
        $this->notifyFollowerSideEffect(
            DocumentFollowerService::EVENT_ACTIVITY,
            $subjectType,
            (int) $subjectId,
            $actorId,
            [
                'activity_type' => $data['activity_type'],
                'summary' => $data['summary'],
            ],
        );

        // Issue #83 priority 2: audit who assigned what to whom.
        if ($assigneeId !== null && $assigneeId !== $actorId) {
            \App\Models\AuditLog::create([
                'operation' => 'activity_assigned',
                'reference_type' => 'ChatterActivity',
                'reference_id' => $activity->id,
                'actor_id' => $actorId,
                'source_route' => 'chatter.activities.store',
                'metadata' => ['assigned_to_user_id' => $assigneeId],
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json(['data' => $this->serializeActivity($activity)], 201);
    }

    public function markActivityDone(Request $request, string $subjectType, int|string $subjectId, int $activityId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId, 'write');

        $activity = $this->chatter->markActivityDone($subjectType, $subjectId, $activityId);

        return response()->json(['data' => $this->serializeActivity($activity)]);
    }

    public function uploadAttachment(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId, 'write');
        $request->validate([
            'file' => 'required|file|max:25600', // 25 MB
        ]);

        $attachment = $this->chatter->uploadAttachment($subjectType, $subjectId, $request->file('file'));

        return response()->json(['data' => [
            'id' => $attachment->id,
            'filename' => $attachment->filename,
            'mime_type' => $attachment->mime_type,
            'size' => (int) $attachment->size,
            'url' => $attachment->url,
            'uploaded_at' => optional($attachment->created_at)->toIso8601String(),
            'uploaded_by' => $attachment->uploadedBy ? [
                'id' => $attachment->uploadedBy->id,
                'name' => $attachment->uploadedBy->name,
                'email' => $attachment->uploadedBy->email,
            ] : null,
        ]], 201);
    }

    /**
     * Issue #78: download an attachment via the authenticated API. The storage
     * path is never exposed to clients.
     */
    public function downloadAttachment(Request $request, string $subjectType, int|string $subjectId, int $attachmentId)
    {
        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId);

        return $this->chatter->downloadAttachment($subjectType, $subjectId, $attachmentId);
    }

    public function deleteAttachment(Request $request, string $subjectType, int|string $subjectId, int $attachmentId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId, 'write');

        // Issue #629: deleting is not just "write on the subject" — it must be
        // your own upload (or a manager's decision), and it must never destroy
        // a file an evidence record still points at.
        $attachment = $this->chatter->findAttachment($subjectType, $subjectId, $attachmentId);
        $this->authorizeAttachmentDeletion($request, $attachment);

        $this->chatter->deleteAttachment($subjectType, $subjectId, $attachmentId);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Validate the polymorphic chatter subject.
     *
     * Issue #75 (CRITICAL, CWE-94 code injection): the previous check was only
     * `class_exists($subjectType)`, which EVERY autoloadable class satisfies —
     * including framework/vendor classes whose constructors have side effects
     * (mailers, cache managers, jobs). An attacker could pass an arbitrary FQCN
     * as subject_type and attempt to trigger instantiation via the polymorphic
     * relation.
     *
     * Defence in depth, in order:
     *  1. Whitelist: subject_type must be one of config('chatter.allowed_models')
     *     (strict comparison — no URL-decode or case bypass).
     *  2. The class must actually exist.
     *  3. It must be an Eloquent Model subclass (not an arbitrary object).
     *  4. It must NOT be a framework/vendor class (Illuminate\, Symfony\,
     *     Composer\) — belt-and-braces in case the whitelist is ever mis-edited.
     *  5. subject_id must be numeric.
     */
    private function validateSubject(string $subjectType, int|string $subjectId): void
    {
        $allowed = config('chatter.allowed_models', []);

        // 1. Strict whitelist check (rejects URL-encoded / arbitrary FQCNs).
        if (! in_array($subjectType, $allowed, true)) {
            abort(422, 'Invalid subject type.');
        }

        // 2. Class must exist.
        if (! class_exists($subjectType)) {
            abort(422, 'Invalid subject type.');
        }

        // 3. Must be an Eloquent Model subclass.
        if (! is_subclass_of($subjectType, \Illuminate\Database\Eloquent\Model::class)) {
            abort(422, 'Subject type must be an Eloquent model.');
        }

        // 4. Reject framework/vendor namespaces even if whitelisted by mistake.
        foreach (['Illuminate\\', 'Symfony\\', 'Composer\\'] as $forbiddenPrefix) {
            if (str_starts_with($subjectType, $forbiddenPrefix)) {
                abort(422, 'Framework classes are not allowed as chatter subjects.');
            }
        }

        // 5. Numeric subject id.
        if (! is_numeric($subjectId)) {
            abort(422, 'Invalid subject id.');
        }
    }

    /**
     * Issue #74 (CRITICAL, CWE-639 broken access control): record-level access
     * control for chatter. validateSubject() only proves the subject_type is a
     * whitelisted model class — it does NOT prove the record exists or that the
     * caller may access it. Without this, any authenticated user could read the
     * chatter (messages/activities/attachments) of ANY record by guessing the
     * model + id.
     *
     * Enforcement, in order:
     *  1. Resolve the permission that gates this record for the requested
     *     ability: `subject_permissions` (view) for reads, or
     *     `subject_write_permissions` (issue #629) for mutations. No mapping
     *     => fail closed 403.
     *  2. Check the caller holds that permission via TenantRoleService (the same
     *     central-role -> Spatie mechanism RequirePermission uses). Super-admins
     *     bypass, matching the platform convention.
     *  3. Resolve the subject record — 404 if it does not exist (also stops
     *     blind id enumeration returning empty-but-200 bundles).
     *
     * NOTE: this is record-TYPE-level authorization. Row-level legal-entity
     * scoping is not possible on these models yet (no legal_entity_id column) —
     * that residual gap is tracked separately in #97/#140.
     */
    private function authorizeSubjectAccess(Request $request, string $subjectType, int|string $subjectId, string $ability = 'view'): void
    {
        // 1. Resolve the permission that gates this record type for the ability;
        //    fail closed if the subject has no mapping (defence in depth
        //    alongside the #75 whitelist).
        $permission = $this->subjectPermission($subjectType, $ability);
        if (! $permission) {
            abort(403, $ability === 'write'
                ? 'Chatter cannot be modified for this subject type.'
                : 'Chatter access is not permitted for this subject type.');
        }

        // 2. Check the caller's permission BEFORE resolving the record, so an
        //    unauthorized caller gets 403 regardless of whether the id exists —
        //    they cannot enumerate which record ids are real. Super-admins
        //    bypass, matching RequirePermission / TenantRoleService.
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        $isSuperAdmin = ($user->is_super_admin ?? false) || ($user->role ?? null) === 'platform_super_admin';
        if (! $isSuperAdmin) {
            $tenantId = (function_exists('tenant') && tenant())
                ? (string) tenant('id')
                : (string) $request->header('X-Tenant-ID', '');
            if ($tenantId === '') {
                abort(403, 'Tenant context required.');
            }

            $centralUserId = (int) (DB::table('public.users')->where('email', $user->email)->value('id') ?? $user->id);

            if (! $this->roleService->hasPermission($centralUserId, $tenantId, $permission)) {
                abort(403, $ability === 'write'
                    ? 'You do not have permission to modify this record\'s chatter.'
                    : 'You do not have permission to view this record\'s chatter.');
            }
        }

        // 3. The record must actually exist (only reached once authorized).
        $record = $subjectType::find($subjectId);
        if (! $record) {
            abort(404, 'Subject record not found.');
        }
    }

    /**
     * Resolve the permission that gates a chatter subject for an ability.
     *
     * `subject_permissions` (existing view map) is kept untouched for backward
     * compatibility; writes use the separate `subject_write_permissions` map
     * added in issue #629. A subject without a write mapping fails closed.
     */
    private function subjectPermission(string $subjectType, string $ability): ?string
    {
        $map = $ability === 'write'
            ? config('chatter.subject_write_permissions', [])
            : config('chatter.subject_permissions', []);

        return $map[$subjectType] ?? null;
    }

    /**
     * Issue #629: chatter attachment deletion is destructive and evidence may
     * point at the same stored file. Refuse evidence-linked files outright,
     * then require the caller to be the uploader or a manager.
     */
    private function authorizeAttachmentDeletion(Request $request, ChatterAttachment $attachment): void
    {
        // Issue #629/#630: evidence-linked files are never deletable through
        // chatter. Name the evidence so the operator knows where to manage it.
        if ($description = $this->chatter->evidenceLinkDescription($attachment)) {
            abort(403, "This file is evidence for {$description} and cannot be deleted through chatter.");
        }

        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        // The uploader may remove their own attachment.
        if ($attachment->uploaded_by_id !== null && (int) $attachment->uploaded_by_id === (int) $user->id) {
            return;
        }

        $isSuperAdmin = ($user->is_super_admin ?? false) || ($user->role ?? null) === 'platform_super_admin';
        if ($isSuperAdmin) {
            return;
        }

        // Managers (and the implicit super roles) may moderate any attachment.
        $tenantId = (function_exists('tenant') && tenant())
            ? (string) tenant('id')
            : (string) $request->header('X-Tenant-ID', '');
        if ($tenantId === '') {
            abort(403, 'Tenant context required.');
        }

        $centralUserId = (int) (DB::table('public.users')->where('email', $user->email)->value('id') ?? $user->id);
        $role = $this->roleService->getRole($centralUserId, $tenantId);

        if (! $role || ! in_array($role, [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER], true)) {
            abort(403, 'Only the uploader or a manager may delete this chatter attachment.');
        }
    }

    private function serializeActivity($activity): array
    {
        return [
            'id' => $activity->id,
            'activity_type' => $activity->activity_type,
            'summary' => $activity->summary,
            'note' => $activity->note,
            'due_date' => optional($activity->due_date)->toIso8601String(),
            'status' => $activity->status,
            'completed_at' => optional($activity->completed_at)->toIso8601String(),
            'created_at' => optional($activity->created_at)->toIso8601String(),
            'assigned_to' => $activity->assignee ? [
                'id' => $activity->assignee->id,
                'name' => $activity->assignee->name,
                'email' => $activity->assignee->email,
                'avatar' => $activity->assignee->avatar ?? null,
            ] : null,
            'actor' => $activity->createdBy ? [
                'id' => $activity->createdBy->id,
                'name' => $activity->createdBy->name,
                'email' => $activity->createdBy->email,
            ] : null,
        ];
    }
}
