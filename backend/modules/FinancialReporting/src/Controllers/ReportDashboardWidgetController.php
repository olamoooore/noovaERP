<?php

declare(strict_types=1);

namespace Modules\FinancialReporting\Controllers;

use App\Http\Controllers\Controller;
use App\Services\EntityAccessService;
use App\Support\TenantUserHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\FinancialReporting\Models\ReportDashboardWidget;
use Modules\FinancialReporting\Models\ReportSavedView;
use Modules\FinancialReporting\Services\SavedViewReportRenderer;
use Modules\FinancialReporting\Support\SavedViewPresenter;

/**
 * Issue #603 (RPT-UX3 companion) — pin saved report views (#598) onto the
 * Sales cockpit as per-user dashboard widgets.
 *
 * Reuses the reporting route group's existing reports.view permission (no new
 * permission is introduced). A pin is private to its user; the pinned view may
 * be the user's own or any shared view they can see. Every render
 * re-authorises the view's visibility and its saved legal-entity filter, so a
 * widget cannot leak figures the viewer is no longer allowed to read.
 */
class ReportDashboardWidgetController extends Controller
{
    public function __construct(
        private readonly SavedViewReportRenderer $renderer,
        private readonly EntityAccessService $entityAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        return response()->json([
            'success' => true,
            'data' => $this->widgetsFor($userId),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $validator = Validator::make($request->all(), [
            'view_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        // The view must be visible to this user (own personal view or any
        // shared view). An invisible view 404s rather than leaking its name.
        $view = ReportSavedView::query()
            ->visibleTo($userId)
            ->find((int) $request->input('view_id'));

        if ($view === null) {
            return response()->json([
                'success' => false,
                'message' => 'The saved view was not found.',
            ], 404);
        }

        $widget = DB::transaction(function () use ($userId, $view) {
            $existing = ReportDashboardWidget::query()
                ->forUser($userId)
                ->where('view_id', $view->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $nextPosition = ((int) ReportDashboardWidget::query()
                ->forUser($userId)
                ->max('position')) + 1;

            return ReportDashboardWidget::create([
                'user_id' => $userId,
                'view_id' => $view->id,
                'position' => $nextPosition,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Saved view added to the dashboard',
            'data' => $this->widgetResource($widget->loadMissing('view'), $userId),
        ], $widget->wasRecentlyCreated ? 201 : 200);
    }

    public function reorder(Request $request): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $validator = Validator::make($request->all(), [
            'widget_ids' => ['required', 'array'],
            'widget_ids.*' => ['integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $ids = array_values(array_unique(array_map('intval', (array) $request->input('widget_ids'))));

        $ownedIds = ReportDashboardWidget::query()
            ->forUser($userId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Every id must be the caller's own widget — a user must not probe or
        // reorder someone else's dashboard.
        if (count($ownedIds) !== count($ids)) {
            return $this->forbiddenResponse('One or more dashboard widgets do not belong to you.');
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                ReportDashboardWidget::query()
                    ->where('id', $id)
                    ->update(['position' => $position]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Dashboard widget order updated',
            'data' => $this->widgetsFor($userId),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $widget = ReportDashboardWidget::query()->forUser($userId)->findOrFail($id);

        $widget->delete();

        return response()->json([
            'success' => true,
            'message' => 'Saved view removed from the dashboard',
        ]);
    }

    public function data(Request $request, int $id): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $widget = ReportDashboardWidget::query()->forUser($userId)->with('view')->findOrFail($id);

        /** @var ReportSavedView|null $view */
        $view = $widget->view;

        if ($view === null) {
            return response()->json([
                'success' => false,
                'message' => 'The saved view no longer exists.',
            ], 404);
        }

        // Re-authorise on every render: a view that has since become personal
        // to someone else must not render for this user.
        if (! $view->isOwnedBy($userId) && $view->visibility !== 'shared') {
            return $this->forbiddenResponse('You do not have access to this saved view.');
        }

        $entityId = is_array($view->filters) ? ($view->filters['legal_entity_id'] ?? null) : null;
        if ($entityId !== null && $entityId !== '') {
            $accessible = $this->entityAccess->accessibleEntityIds($userId);

            // Empty grants mean "no per-entity restriction" (the platform's
            // entity-scope convention); a non-empty grant list that excludes
            // the view's entity is a real scope violation.
            if ($accessible !== [] && ! in_array((int) $entityId, $accessible, true)) {
                return $this->forbiddenResponse('You do not have access to the saved view\'s legal entity.');
            }
        }

        return response()->json([
            'success' => true,
            'data' => array_merge(
                [
                    'widget_id' => (int) $widget->id,
                    'view' => SavedViewPresenter::toArray($view, $userId, true),
                ],
                $this->renderer->renderWidgetPayload($view, $request->integer('limit', 5)),
            ),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function widgetsFor(int $userId): array
    {
        return ReportDashboardWidget::query()
            ->forUser($userId)
            ->with('view')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (ReportDashboardWidget $widget) => $widget->view !== null)
            ->map(fn (ReportDashboardWidget $widget) => $this->widgetResource($widget, $userId))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function widgetResource(ReportDashboardWidget $widget, int $userId): array
    {
        /** @var ReportSavedView $view */
        $view = $widget->view;

        return [
            'id' => (int) $widget->id,
            'view_id' => (int) $widget->view_id,
            'position' => (int) $widget->position,
            'view' => SavedViewPresenter::toArray($view, $userId, true),
        ];
    }

    private function currentUserId(): ?int
    {
        return TenantUserHelper::resolveTenantUserId();
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function validationFailed(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $errors,
        ], 422);
    }

    private function forbiddenResponse(string $message = 'You do not have permission to manage this dashboard widget.'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }
}
