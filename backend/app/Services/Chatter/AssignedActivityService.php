<?php

declare(strict_types=1);

namespace App\Services\Chatter;

use App\Http\Middleware\RequirePermission;
use App\Models\ChatterActivity;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use App\Platform\Notifications\NotificationService;
use App\Services\EntityAccessService;
use App\Services\SalesDocumentEntityScope;
use App\Services\TenantRoleService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;

/**
 * Issue #410 — scheduled activities on sales documents, seen from the
 * assignee's side.
 *
 * Activities themselves are the chatter's (ChatterActivity, scheduled through
 * ChatterController). This service owns the ONE rule for "which of my open
 * activities may I see", shared by every surface so they cannot drift:
 *
 *   - "My activities" (#637, MyActivityController);
 *   - the Sales cockpit activities tile (#365, SalesActionQueueService);
 *   - the due-activity reminders (sales:send-activity-reminders).
 *
 * The rule: the activity is assigned to the user, still planned, on a
 * document type the user holds the document's own view permission for
 * (config/chatter.php `subject_permissions`, the map that gates the chatter),
 * and — when the user has legal-entity grants — on a document inside those
 * entities (SalesDocumentEntityScope, the #365 rule).
 */
class AssignedActivityService
{
    /** Notification event: an activity assigned to you has fallen due. */
    public const EVENT_DUE = 'chatter.activity.due';

    /**
     * Document types with an assignee-facing surface, mapped to
     * [number column, human label, frontend detail path prefix].
     *
     * @var array<class-string<Model>, array{0: string, 1: string, 2: string}>
     */
    public const SUBJECTS = [
        Invoice::class => ['invoice_number', 'Invoice', '/invoices/'],
        Quotation::class => ['quotation_number', 'Quotation', '/sales/quotes/'],
        SalesOrder::class => ['sales_order_number', 'Sales order', '/sales/orders/'],
        DeliveryOrder::class => ['delivery_order_number', 'Delivery order', '/delivery-orders/'],
        CustomerCreditNote::class => ['credit_note_number', 'Credit note', '/accounting/customers/credit-notes/'],
    ];

    public function __construct(
        private readonly SalesDocumentEntityScope $entityScope,
        private readonly EntityAccessService $entityAccess,
        private readonly NotificationService $notifications,
        private readonly TenantRoleService $roles,
    ) {}

    /**
     * The document types whose view permission the caller holds. A subject
     * without a permission mapping is never exposed.
     *
     * @param  Closure(string): bool  $allows  permission check for the user
     * @return list<string>
     */
    public function viewableSubjectTypes(Closure $allows): array
    {
        $viewPermissions = config('chatter.subject_permissions', []);
        $allowed = [];

        foreach (array_keys(self::SUBJECTS) as $subjectType) {
            $permission = $viewPermissions[$subjectType] ?? null;
            if ($permission !== null && $allows($permission)) {
                $allowed[] = $subjectType;
            }
        }

        return $allowed;
    }

    /**
     * Planned activities assigned to $user on documents the user may see.
     *
     * @param  list<string>  $subjectTypes  from viewableSubjectTypes()
     */
    public function openFor(User $user, array $subjectTypes): Builder
    {
        return $this->assignedTo($user, $subjectTypes)
            ->where('chatter_activities.status', 'planned');
    }

    /**
     * Every activity (any status) assigned to $user on documents the user may
     * see — the base of openFor(), and the guard for acting on one activity.
     *
     * @param  list<string>  $subjectTypes
     */
    public function assignedTo(User $user, array $subjectTypes): Builder
    {
        $query = ChatterActivity::query()
            ->where('chatter_activities.assigned_to_id', $user->id)
            ->whereIn('chatter_activities.subject_type', $subjectTypes);

        $this->scopeToAccessibleDocuments($query, $user, $subjectTypes);

        return $query;
    }

    /**
     * Same-entity rule as the document lists: an activity is only visible when
     * its document is inside the user's legal entities. Unscoped users (no
     * grants) are not narrowed — the SalesDocumentEntityScope default.
     *
     * @param  list<string>  $subjectTypes
     */
    private function scopeToAccessibleDocuments(Builder $query, User $user, array $subjectTypes): void
    {
        if ($this->entityAccess->accessibleEntityIds((int) $user->id) === []) {
            return; // no entity grants → unscoped
        }

        // Only the sales documents have an entity rule; any other type is
        // excluded for an entity-scoped user rather than guessed at.
        $documentTypes = array_values(array_intersect($subjectTypes, array_keys(self::SUBJECTS)));

        if ($documentTypes === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $any) use ($user, $documentTypes) {
            foreach ($documentTypes as $subjectType) {
                /** @var Builder $documents */
                $documents = $subjectType::query();
                $table = $documents->getModel()->getTable();
                // chatter_activities.subject_id is a string column.
                $documents->select(DB::raw("CAST({$table}.id AS VARCHAR)"));
                $this->entityScope->apply($documents, $user);

                $any->orWhere(fn (Builder $one) => $one
                    ->where('chatter_activities.subject_type', $subjectType)
                    ->whereIn('chatter_activities.subject_id', $documents->toBase()));
            }
        });
    }

    /**
     * Document number, customer name and link per activity, batch-loaded per
     * document type (no N+1).
     *
     * @param  Collection<int, ChatterActivity>  $activities
     * @return array<string, array<int, array{number: ?string, customer: ?string}>>
     */
    public function subjectDetails(Collection $activities): array
    {
        $details = [];

        foreach ($activities->groupBy('subject_type') as $subjectType => $rows) {
            if (! isset(self::SUBJECTS[$subjectType])) {
                continue;
            }

            [$column] = self::SUBJECTS[$subjectType];
            $ids = $rows->pluck('subject_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

            $details[$subjectType] = $subjectType::query()
                ->with('customer:id,name')
                ->whereIn('id', $ids)
                ->get()
                ->mapWithKeys(fn (Model $document) => [(int) $document->getKey() => [
                    'number' => $document->{$column} !== null ? (string) $document->{$column} : null,
                    'customer' => $document->customer?->name,
                ]])
                ->all();
        }

        return $details;
    }

    public static function documentLink(string $subjectType, int|string $subjectId): ?string
    {
        return isset(self::SUBJECTS[$subjectType]) ? self::SUBJECTS[$subjectType][2].(int) $subjectId : null;
    }

    /**
     * Sales cockpit tile (#365): the user's activities that are due — overdue
     * or due today — most overdue first.
     *
     * @param  Closure(string): bool  $allows
     * @return array{label: string, count: int, overdue: int, link: string, items: list<array<string, mixed>>}
     */
    public function dueSummary(?User $user, Closure $allows, int $limit): array
    {
        $summary = ['label' => 'My activities due', 'count' => 0, 'overdue' => 0, 'link' => '/activities', 'items' => []];

        if ($user === null) {
            return $summary;
        }

        $startOfToday = now()->startOfDay();
        $due = $this->openFor($user, $this->viewableSubjectTypes($allows))
            ->whereNotNull('chatter_activities.due_date')
            ->where('chatter_activities.due_date', '<', $startOfToday->copy()->addDay());

        $summary['count'] = (clone $due)->count();
        $summary['overdue'] = (clone $due)->where('chatter_activities.due_date', '<', $startOfToday)->count();

        $rows = $due->orderBy('chatter_activities.due_date')->orderBy('chatter_activities.id')->limit($limit)->get();
        $details = $this->subjectDetails($rows);

        $summary['items'] = $rows->map(function (ChatterActivity $activity) use ($details) {
            $subjectType = (string) $activity->subject_type;
            $detail = $details[$subjectType][(int) $activity->subject_id] ?? ['number' => null, 'customer' => null];

            return [
                'id' => (int) $activity->id,
                'number' => $detail['number'] ?? self::SUBJECTS[$subjectType][1].' #'.(int) $activity->subject_id,
                'summary' => $activity->summary,
                'activity_type' => $activity->activity_type,
                'customer' => $detail['customer'],
                'date' => $activity->due_date?->toDateString(),
                'amount' => null,
                'currency_code' => null,
                'link' => self::documentLink($subjectType, $activity->subject_id),
            ];
        })->values()->all();

        return $summary;
    }

    /**
     * Reminder sweep for the current tenant: every planned activity whose due
     * date has been reached and that was not reminded yet notifies its
     * assignee once, through the platform NotificationService (in-app log +
     * mail, honouring the user's opt-out and document mutes). The assignee
     * must still be able to open the document (view permission + legal
     * entity); otherwise the activity is left un-reminded and counted.
     *
     * A failed dispatch leaves `reminded_at` empty so the next run retries,
     * and is returned as `failed` for the command to report and fail on.
     *
     * @return array{sent: int, skipped: int, failed: list<array{activity_id: int, error: string}>}
     */
    public function sendDueReminders(string $tenantId): array
    {
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => []];

        $due = ChatterActivity::query()
            ->where('status', 'planned')
            ->whereNotNull('assigned_to_id')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', now())
            ->whereNull('reminded_at')
            ->whereIn('subject_type', array_keys(self::SUBJECTS))
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        foreach ($due->groupBy('assigned_to_id') as $assigneeId => $activities) {
            $assignee = User::query()->find((int) $assigneeId);

            if ($assignee === null) {
                $result['skipped'] += $activities->count();

                continue;
            }

            $visibleIds = $this->openFor($assignee, $this->viewableSubjectTypes($this->permissionCheck($assignee, $tenantId)))
                ->whereIn('chatter_activities.id', $activities->pluck('id')->all())
                ->pluck('chatter_activities.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $visible = $activities->filter(fn (ChatterActivity $activity) => in_array((int) $activity->id, $visibleIds, true))->values();
            $result['skipped'] += $activities->count() - $visible->count();

            $details = $this->subjectDetails($visible);

            foreach ($visible as $activity) {
                try {
                    $this->notifications->dispatch(self::EVENT_DUE, $assignee, $this->reminderData($activity, $details));
                    $activity->forceFill(['reminded_at' => now()])->save();
                    $result['sent']++;
                } catch (\Throwable $e) {
                    Log::error('AssignedActivityService: due-activity reminder failed', [
                        'activity_id' => $activity->id,
                        'user_id' => $assignee->id,
                        'error' => $e->getMessage(),
                    ]);
                    $result['failed'][] = ['activity_id' => (int) $activity->id, 'error' => $e->getMessage()];
                }
            }
        }

        return $result;
    }

    /**
     * Permission check for a user outside a request (the scheduler): the same
     * resolution RequirePermission::allows() makes for a request user.
     *
     * @return Closure(string): bool
     */
    private function permissionCheck(User $user, string $tenantId): Closure
    {
        if (($user->is_super_admin ?? false) || ($user->role ?? null) === 'platform_super_admin') {
            return fn (string $permission) => true;
        }

        $centralUserId = RequirePermission::resolveCentralUserId($user);

        return fn (string $permission) => $this->roles->hasPermission($centralUserId, $tenantId, $permission);
    }

    /**
     * @param  array<string, array<int, array{number: ?string, customer: ?string}>>  $details
     * @return array<string, mixed>
     */
    private function reminderData(ChatterActivity $activity, array $details): array
    {
        $subjectType = (string) $activity->subject_type;
        $subjectId = (int) $activity->subject_id;
        $label = self::SUBJECTS[$subjectType][1];
        $detail = $details[$subjectType][$subjectId] ?? ['number' => null, 'customer' => null];
        $document = trim($label.' '.($detail['number'] ?? '#'.$subjectId));
        $dueText = $activity->due_date?->toDayDateTimeString() ?? '';

        return [
            'subject' => "Activity due: {$document}",
            'body' => sprintf(
                'Your %s "%s" on %s%s is due (%s).',
                str_replace('_', ' ', (string) $activity->activity_type),
                (string) $activity->summary,
                $document,
                $detail['customer'] ? " ({$detail['customer']})" : '',
                $dueText,
            ),
            'document_type' => $subjectType,
            'document_id' => $subjectId,
            'activity_id' => (int) $activity->id,
            'due_date' => $activity->due_date?->toIso8601String(),
            'link' => self::documentLink($subjectType, $subjectId),
        ];
    }
}
