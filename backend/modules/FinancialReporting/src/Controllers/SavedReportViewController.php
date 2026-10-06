<?php

declare(strict_types=1);

namespace Modules\FinancialReporting\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TenantUserHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\FinancialReporting\Models\ReportDashboardWidget;
use Modules\FinancialReporting\Models\ReportSavedView;
use Modules\FinancialReporting\Support\SavedViewPresenter;

/**
 * Issue #598 (RPT-UX3) — CRUD for saved sales-report views.
 *
 * Reuses the module's existing reports.view route permission (no new
 * permission is introduced). Personal views are visible only to their owner;
 * shared views are visible to every tenant user with reports.view but can
 * only be changed/deleted/defaulted/favourited by their owner.
 *
 * Issue #603 adds the owner-only `is_favourite` flag (favourites list before
 * the default) and one-click toggling; dashboard pinning lives in
 * ReportDashboardWidgetController against the same storage.
 */
class SavedReportViewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $pinnedViewIds = ReportDashboardWidget::query()
            ->forUser($userId)
            ->pluck('view_id')
            ->map(fn ($viewId) => (int) $viewId)
            ->all();

        $views = ReportSavedView::query()
            ->where('report_key', 'sales-analytics')
            ->visibleTo($userId)
            ->orderByDesc('is_favourite')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (ReportSavedView $view) => $this->resource($view, $userId, in_array((int) $view->id, $pinnedViewIds, true)))
            ->values();

        $defaultViewId = ReportSavedView::query()
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->value('id');

        return response()->json([
            'success' => true,
            'data' => [
                'views' => $views,
                'default_view_id' => $defaultViewId,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $validator = Validator::make($request->all(), $this->rules($userId));

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $view = DB::transaction(function () use ($request, $userId) {
            $isDefault = $request->boolean('is_default');

            if ($isDefault) {
                ReportSavedView::query()->where('user_id', $userId)->update(['is_default' => false]);
            }

            return ReportSavedView::create([
                'user_id' => $userId,
                'name' => $request->input('name'),
                'report_key' => $request->input('report_key', 'sales-analytics'),
                'tab' => $request->input('tab'),
                'filters' => $request->input('filters', []),
                'grouping' => $request->input('grouping'),
                'visibility' => $request->input('visibility', 'personal'),
                'is_default' => $isDefault,
                'is_favourite' => $request->boolean('is_favourite'),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Saved view created successfully',
            'data' => $this->resource($view, $userId),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $view = ReportSavedView::findOrFail($id);

        if (! $view->isOwnedBy($userId)) {
            return $this->forbiddenResponse('Only the view owner can update this saved view.');
        }

        $validator = Validator::make($request->all(), $this->rules($userId, $id, partial: true));

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        DB::transaction(function () use ($request, $view, $userId) {
            if ($request->has('is_default') && $request->boolean('is_default')) {
                ReportSavedView::query()
                    ->where('user_id', $userId)
                    ->where('id', '!=', $view->id)
                    ->update(['is_default' => false]);
            }

            foreach (['name', 'tab', 'filters', 'grouping', 'visibility'] as $field) {
                if ($request->has($field)) {
                    $view->{$field} = $request->input($field);
                }
            }

            if ($request->has('is_default')) {
                $view->is_default = $request->boolean('is_default');
            }

            if ($request->has('is_favourite')) {
                $view->is_favourite = $request->boolean('is_favourite');
            }

            $view->save();
        });

        return response()->json([
            'success' => true,
            'message' => 'Saved view updated successfully',
            'data' => $this->resource($view->refresh(), $userId),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $view = ReportSavedView::findOrFail($id);

        if (! $view->isOwnedBy($userId)) {
            return $this->forbiddenResponse('Only the view owner can delete this saved view.');
        }

        $view->delete();

        return response()->json([
            'success' => true,
            'message' => 'Saved view deleted successfully',
        ]);
    }

    public function setDefault(int $id): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $view = ReportSavedView::findOrFail($id);

        if (! $view->isOwnedBy($userId)) {
            return $this->forbiddenResponse('Only the view owner can set this saved view as their default.');
        }

        DB::transaction(function () use ($view, $userId) {
            ReportSavedView::query()->where('user_id', $userId)->update(['is_default' => false]);
            $view->forceFill(['is_default' => true])->save();
        });

        return response()->json([
            'success' => true,
            'message' => 'Default view updated successfully',
            'data' => $this->resource($view->refresh(), $userId),
        ]);
    }

    /**
     * Issue #603 — one-click favourite toggle (owner-only, like every other
     * saved-view mutation).
     */
    public function setFavourite(int $id): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $view = ReportSavedView::findOrFail($id);

        if (! $view->isOwnedBy($userId)) {
            return $this->forbiddenResponse('Only the view owner can favourite this saved view.');
        }

        $view->is_favourite = ! (bool) $view->is_favourite;
        $view->save();

        return response()->json([
            'success' => true,
            'message' => $view->is_favourite
                ? 'Saved view added to favourites'
                : 'Saved view removed from favourites',
            'data' => $this->resource($view->refresh(), $userId),
        ]);
    }

    /**
     * Active tenant users who can be chosen as schedule recipients.
     */
    public function recipients(): JsonResponse
    {
        if ($this->currentUserId() === null) {
            return $this->forbiddenResponse();
        }

        $users = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ]);

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(int $userId, ?int $ignoreId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [
                $required,
                'string',
                'max:120',
                Rule::unique('report_saved_views', 'name')
                    ->where('user_id', $userId)
                    ->ignore($ignoreId),
            ],
            'report_key' => 'nullable|string|max:50|in:sales-analytics',
            'tab' => [$required, 'string', Rule::in(ReportSavedView::TABS)],
            'filters' => [$partial ? 'sometimes' : 'present', 'array'],
            'grouping' => 'nullable|array',
            'visibility' => ['nullable', 'string', Rule::in(ReportSavedView::VISIBILITIES)],
            'is_default' => 'boolean',
            'is_favourite' => 'boolean',
        ];
    }

    private function currentUserId(): ?int
    {
        return TenantUserHelper::resolveTenantUserId();
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(ReportSavedView $view, int $userId, ?bool $isPinned = null): array
    {
        $pinned = $isPinned ?? ReportDashboardWidget::query()
            ->forUser($userId)
            ->where('view_id', $view->id)
            ->exists();

        return SavedViewPresenter::toArray($view, $userId, $pinned);
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

    private function forbiddenResponse(string $message = 'You do not have permission to manage this saved view.'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }
}
