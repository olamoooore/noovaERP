<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePermission;
use App\Models\ChatterActivity;
use App\Services\Chatter\AssignedActivityService;
use App\Services\Chatter\ChatterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issue #637 — "My Activities".
 *
 * Chatter activities exist per document, but there was no cross-document list:
 * a follow-up assigned to you was only discoverable by opening each record.
 * This controller exposes the caller's own open activities across quotations,
 * sales orders, delivery orders, invoices and customer credit notes, grouped
 * overdue / today / upcoming, plus mark-done and snooze actions.
 *
 * Issue #410: the listing and the actions follow the document's legal entity
 * too (AssignedActivityService, shared with the cockpit tile and the due
 * reminders) — an activity on a document outside the caller's entities is
 * neither listed nor actionable (404).
 *
 * Security rules (asserted in Issue637MyActivitiesTest):
 *  - the assignee filter is the whole point: `assigned_to_id` must be the
 *    caller, so another user's tasks are never listed or mutated (an activity
 *    that is not yours is a 404, never a 403 that would confirm it exists);
 *  - a row only appears when the caller holds the SAME view permission that
 *    opens its document (config/chatter.php `subject_permissions`), so the
 *    page cannot leak a document the caller cannot open.
 */
class MyActivityController extends Controller
{
    public function __construct(
        private readonly ChatterService $chatter,
        private readonly AssignedActivityService $assigned,
    ) {}

    public function mine(Request $request): JsonResponse
    {
        // Issue #410: the assignee/permission/legal-entity rule is shared with
        // the cockpit tile and the due reminders (AssignedActivityService).
        $activities = $this->assigned->openFor($request->user(), $this->allowedSubjectTypes($request))
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $labels = $this->batchSubjectLabels($activities);

        $startOfToday = now()->startOfDay();
        $startOfTomorrow = $startOfToday->copy()->addDay();

        $grouped = [
            'overdue' => [],
            'today' => [],
            'upcoming' => [],
        ];

        foreach ($activities as $activity) {
            if ($activity->due_date !== null && $activity->due_date->lt($startOfToday)) {
                $bucket = 'overdue';
            } elseif ($activity->due_date !== null && $activity->due_date->lt($startOfTomorrow)) {
                $bucket = 'today';
            } else {
                // Future dates and activities without a due date (least urgent)
                // are upcoming.
                $bucket = 'upcoming';
            }

            $grouped[$bucket][] = $this->serializeActivity($activity, $labels);
        }

        return response()->json(['data' => [
            'overdue' => $grouped['overdue'],
            'today' => $grouped['today'],
            'upcoming' => $grouped['upcoming'],
            'total' => count($grouped['overdue']) + count($grouped['today']) + count($grouped['upcoming']),
        ]]);
    }

    public function complete(Request $request, int $activityId): JsonResponse
    {
        $activity = $this->findOwnActivity($request, $activityId);
        $this->authorizeSubjectAccess($request, $activity);

        $done = $this->chatter->markActivityDone(
            (string) $activity->subject_type,
            (int) $activity->subject_id,
            (int) $activity->id,
        );

        return response()->json(['data' => $this->serializeActivity($done)]);
    }

    public function snooze(Request $request, int $activityId): JsonResponse
    {
        $activity = $this->findOwnActivity($request, $activityId);
        $this->authorizeSubjectAccess($request, $activity);

        $validated = $request->validate([
            'due_date' => ['required', 'date', 'after:now'],
        ]);

        $snoozed = $this->chatter->snoozeActivity(
            (string) $activity->subject_type,
            (int) $activity->subject_id,
            (int) $activity->id,
            $validated['due_date'],
        );

        return response()->json(['data' => $this->serializeActivity($snoozed)]);
    }

    /**
     * Resolve the activity by id, restricted to the caller's own assignments.
     * Another user's activity is a 404 — a 403 would confirm its existence.
     */
    private function findOwnActivity(Request $request, int $activityId): ChatterActivity
    {
        $activity = ChatterActivity::query()
            ->where('id', $activityId)
            ->where('assigned_to_id', $request->user()->id)
            ->firstOrFail();

        // Issue #410: an activity on a document outside the caller's legal
        // entities is not theirs to act on — 404 like any unlisted activity.
        $inScope = ! isset(AssignedActivityService::SUBJECTS[(string) $activity->subject_type])
            || $this->assigned->assignedTo($request->user(), [(string) $activity->subject_type])
                ->where('chatter_activities.id', $activity->id)
                ->exists();

        if (! $inScope) {
            abort(404);
        }

        return $activity;
    }

    /**
     * The document types to include: only those this caller may open, using
     * the same permission map that gates chatter. A subject without a mapping
     * is never exposed.
     *
     * @return string[]
     */
    private function allowedSubjectTypes(Request $request): array
    {
        return $this->assigned->viewableSubjectTypes(
            fn (string $permission) => RequirePermission::allows($request, $permission),
        );
    }

    private function authorizeSubjectAccess(Request $request, ChatterActivity $activity): void
    {
        $subjectType = (string) $activity->subject_type;
        $permission = config('chatter.subject_permissions', [])[$subjectType] ?? null;

        if ($permission === null || ! RequirePermission::allows($request, $permission)) {
            abort(403, 'You do not have permission to access this activity\'s document.');
        }
    }

    /**
     * Batch-load document numbers so the list renders real document links
     * without an N+1 query per row.
     *
     * @param  \Illuminate\Support\Collection<int, ChatterActivity>  $activities
     * @return array<string, array<int, string>>
     */
    private function batchSubjectLabels($activities): array
    {
        $labels = [];

        foreach ($activities->groupBy('subject_type') as $subjectType => $rows) {
            $column = AssignedActivityService::SUBJECTS[$subjectType][0] ?? null;
            if ($column === null) {
                continue;
            }

            $ids = $rows->pluck('subject_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

            $labels[$subjectType] = $subjectType::query()
                ->whereIn('id', $ids)
                ->pluck($column, 'id')
                ->all();
        }

        return $labels;
    }

    private function serializeActivity(ChatterActivity $activity, array $labels = []): array
    {
        $subjectType = (string) $activity->subject_type;
        $subjectId = (int) $activity->subject_id;
        $label = $labels[$subjectType][$subjectId] ?? null;

        if ($label === null && isset(AssignedActivityService::SUBJECTS[$subjectType])) {
            $column = AssignedActivityService::SUBJECTS[$subjectType][0];
            $label = $subjectType::query()->whereKey($subjectId)->value($column);
        }

        return [
            'id' => $activity->id,
            'activity_type' => $activity->activity_type,
            'summary' => $activity->summary,
            'note' => $activity->note,
            'due_date' => optional($activity->due_date)->toIso8601String(),
            'status' => $activity->status,
            'completed_at' => optional($activity->completed_at)->toIso8601String(),
            'created_at' => optional($activity->created_at)->toIso8601String(),
            'subject' => [
                'type' => $subjectType,
                'id' => $subjectId,
                'label' => $label !== null ? (string) $label : null,
            ],
        ];
    }
}
