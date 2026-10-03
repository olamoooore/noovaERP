<?php

namespace App\Services;

use App\Http\Middleware\RequirePermission;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Row-level visibility for approval requests (issue #651 [SALES-APR] AP7).
 *
 * GET /approvals and GET /approvals/{id} used to return every pending request
 * with its subject document loaded — any authenticated tenant user could read
 * amounts, customers and metadata across document types and legal entities.
 *
 * A request row is visible to a user only when ALL of the following hold:
 *
 *   1. Entity scope — when the user has user_entity_access grants, a subject
 *      document carrying a legal_entity_id must live in one of their
 *      accessible entities. A user with no grants at all is not entity-scoped
 *      (single-entity tenants / pre-entity-assignment data keep working), but
 *      any grant confines every role, Owner/Admin included.
 *   2. AND at least one of:
 *      a. the user requested it (`requested_by`);
 *      b. the user may decide it — ApprovalService::userCanApprove(), i.e.
 *         Owner/Admin (implicit super-role bypass), a configured sales
 *         reviewer (SalesApprovalGate::resolveReviewers), or the POS
 *         entitlement holder for the request type and amount;
 *      c. the user may view the subject document — the same module view
 *         permission that gates reading the record itself, taken from
 *         chatter's `subject_permissions` map (issue #74) and checked through
 *         TenantRoleService, exactly like RequirePermission/ChatterController
 *         do. A subject type with no mapping fails closed.
 *
 * No new permission strings and no route middleware are introduced: this is
 * pure row-level scoping on top of the existing auth:sanctum chain.
 */
class ApprovalVisibilityService
{
    /**
     * @var array<int, int|null> user id => central user id
     */
    private array $centralUserIds = [];

    /**
     * @var array<int, array<int, int>|null> user id => accessible entity ids (null = not entity-scoped)
     */
    private array $accessibleEntities = [];

    /**
     * @var array<string, bool> "<user id>|<subject_type>" => may view
     */
    private array $viewableSubjectTypes = [];

    /**
     * @var array<string, bool> "<user id>|<request type>|<amount key>" => may decide
     */
    private array $decidable = [];

    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly TenantRoleService $roleService,
        private readonly EntityAccessService $entityAccessService,
    ) {}

    /**
     * Keep only the requests the user may see.
     *
     * @param  Collection<int, ApprovalRequest>  $requests
     * @return Collection<int, ApprovalRequest>
     */
    public function visible(Collection $requests, ?User $user): Collection
    {
        if ($user === null) {
            return $requests->take(0);
        }

        return $requests
            ->filter(fn (ApprovalRequest $request) => $this->canView($request, $user))
            ->values();
    }

    /**
     * Whether the user may see this request (list and show share the rule).
     */
    public function canView(ApprovalRequest $request, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        // Platform super-admins bypass row-level scoping, matching
        // RequirePermission, ChatterSubjectAuthorization and
        // DocumentTraceService — read-only visibility; approving still goes
        // through ApprovalService::userCanApprove().
        if (($user->is_super_admin ?? false) || ($user->role ?? null) === 'platform_super_admin') {
            return true;
        }

        if (! $this->inAccessibleEntity($request, $user)) {
            return false;
        }

        if ((int) $request->requested_by === (int) $user->id) {
            return true;
        }

        if ($this->canDecide($request, $user)) {
            return true;
        }

        return $this->canViewSubjectDocument($request, $user);
    }

    /**
     * Entity gate — see rule 1 above.
     */
    private function inAccessibleEntity(ApprovalRequest $request, User $user): bool
    {
        $accessible = $this->accessibleEntityIds($user);

        // The user has no entity grants at all: nothing to scope against
        // (single-entity tenants and legacy data keep working).
        if ($accessible === null) {
            return true;
        }

        $document = $this->resolveSubject($request);

        // No document to scope (unresolvable subject type, dangling id or a
        // subject type without the column) — the row itself is not
        // entity-scoped; the decide/view rules still apply.
        if ($document === null) {
            return true;
        }

        $attributes = $document->getAttributes();

        if (! array_key_exists('legal_entity_id', $attributes)) {
            return true;
        }

        $entityId = $attributes['legal_entity_id'];

        if ($entityId === null) {
            return true;
        }

        return in_array((int) $entityId, $accessible, true);
    }

    /**
     * Whether the user may action this request (rule 2b).
     *
     * Sales request types are reviewer-driven and amount-independent; the POS
     * entitlement types branch on the supervisor amount threshold, so their
     * cache key keeps the amount.
     */
    private function canDecide(ApprovalRequest $request, User $user): bool
    {
        $requestType = (string) $request->request_type;
        $amountKey = SalesApprovalGate::isSalesRequestType($requestType)
            ? ''
            : '|'.(string) $request->amount;
        $key = $user->id.'|'.$requestType.$amountKey;

        if (! array_key_exists($key, $this->decidable)) {
            $this->decidable[$key] = $this->approvalService->userCanApprove(
                $user,
                $requestType,
                (float) $request->amount,
            );
        }

        return $this->decidable[$key];
    }

    /**
     * Whether the user may read the subject document (rule 2c).
     *
     * Reuses chatter's `subject_permissions` map — the platform's "which
     * permission gates reading this record" table (issue #74) — so approvals
     * and chatter can never disagree about who may read a document. A subject
     * type without a mapping (e.g. the POS 'PosOrder' alias) fails closed and
     * stays visible only to its requester and to whoever may decide it.
     */
    private function canViewSubjectDocument(ApprovalRequest $request, User $user): bool
    {
        $subjectType = (string) $request->subject_type;
        $key = $user->id.'|'.$subjectType;

        if (array_key_exists($key, $this->viewableSubjectTypes)) {
            return $this->viewableSubjectTypes[$key];
        }

        $permission = config('chatter.subject_permissions')[$subjectType] ?? null;

        if ($permission === null || ! function_exists('tenant') || ! tenant()) {
            return $this->viewableSubjectTypes[$key] = false;
        }

        try {
            return $this->viewableSubjectTypes[$key] = $this->roleService->hasPermission(
                $this->centralUserId($user),
                (string) tenant('id'),
                $permission,
            );
        } catch (\Throwable $e) {
            Log::warning('ApprovalVisibilityService: subject view check failed', [
                'user_id' => $user->id,
                'subject_type' => $subjectType,
                'error' => $e->getMessage(),
            ]);

            return $this->viewableSubjectTypes[$key] = false;
        }
    }

    /**
     * @return array<int, int>|null null when the user has no entity grants
     */
    private function accessibleEntityIds(User $user): ?array
    {
        if (array_key_exists($user->id, $this->accessibleEntities)) {
            return $this->accessibleEntities[$user->id];
        }

        $hasGrants = DB::table('user_entity_access')
            ->where('user_id', $user->id)
            ->exists();

        return $this->accessibleEntities[$user->id] = $hasGrants
            ? $this->entityAccessService->accessibleEntityIds($user->id)
            : null;
    }

    /**
     * The subject document, preferring the relation loadSubjects() already
     * attached so the entity check costs no extra query on list/show.
     */
    private function resolveSubject(ApprovalRequest $request): ?Model
    {
        if ($request->relationLoaded('subject')) {
            $relation = $request->getRelation('subject');

            return $relation instanceof Model ? $relation : null;
        }

        $class = ApprovalRequest::subjectClass((string) $request->subject_type);

        if ($class === null) {
            return null;
        }

        return $class::query()->find($request->subject_id);
    }

    /**
     * Central user id for the central-role → Spatie bridge (same resolution
     * the RequirePermission middleware and ApprovalService use).
     */
    private function centralUserId(User $user): int
    {
        if (! array_key_exists($user->id, $this->centralUserIds)) {
            $this->centralUserIds[$user->id] = RequirePermission::resolveCentralUserId($user);
        }

        return $this->centralUserIds[$user->id];
    }
}
