<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Chatter\ChatterService;
use App\Services\TenantRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChatterController extends Controller
{
    public function __construct(
        private readonly ChatterService $chatter,
        private readonly TenantRoleService $roleService,
    ) {}

    public function show(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId);

        $bundle = $this->chatter->bundle($subjectType, $subjectId);

        return response()->json(['data' => $bundle]);
    }

    public function addMessage(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId);
        $data = $request->validate([
            'body' => 'required|string|min:1|max:10000',
        ]);

        $message = $this->chatter->addMessage($subjectType, $subjectId, $data['body']);

        return response()->json(['data' => [
            'id' => $message->id,
            'body' => $message->body,
            'mentions' => $message->mentions ?? [],
            'created_at' => optional($message->created_at)->toIso8601String(),
            'actor' => $message->author ? [
                'id' => $message->author->id,
                'name' => $message->author->name,
                'email' => $message->author->email,
                'avatar' => $message->author->avatar ?? null,
            ] : null,
        ]], 201);
    }

    public function addActivity(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId);
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
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId);

        $activity = $this->chatter->markActivityDone($subjectType, $subjectId, $activityId);

        return response()->json(['data' => $this->serializeActivity($activity)]);
    }

    public function uploadAttachment(Request $request, string $subjectType, int|string $subjectId): JsonResponse
    {
        $this->validateSubject($subjectType, $subjectId);
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId);
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
        $this->authorizeSubjectAccess($request, $subjectType, $subjectId);

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
     *  1. Resolve the subject record — 404 if it does not exist (also stops
     *     blind id enumeration returning empty-but-200 bundles).
     *  2. Look up the module view-permission that gates the record itself
     *     (config/chatter.php subject_permissions). No mapping => fail closed 403.
     *  3. Check the caller holds that permission via TenantRoleService (the same
     *     central-role -> Spatie mechanism RequirePermission uses). Super-admins
     *     bypass, matching the platform convention.
     *
     * NOTE: this is record-TYPE-level authorization. Row-level legal-entity
     * scoping is not possible on these models yet (no legal_entity_id column) —
     * that residual gap is tracked separately in #97/#140.
     */
    private function authorizeSubjectAccess(Request $request, string $subjectType, int|string $subjectId): void
    {
        // 1. Resolve the permission that gates this record type; fail closed if
        //    the subject has no mapping (defence in depth alongside the #75
        //    whitelist).
        $permission = config('chatter.subject_permissions')[$subjectType] ?? null;
        if (! $permission) {
            abort(403, 'Chatter access is not permitted for this subject type.');
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
                abort(403, 'You do not have permission to view this record\'s chatter.');
            }
        }

        // 3. The record must actually exist (only reached once authorized).
        $record = $subjectType::find($subjectId);
        if (! $record) {
            abort(404, 'Subject record not found.');
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
