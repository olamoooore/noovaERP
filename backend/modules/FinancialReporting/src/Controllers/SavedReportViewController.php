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
use Modules\FinancialReporting\Models\ReportSavedView;

/**
 * Issue #598 (RPT-UX3) — CRUD for saved sales-report views.
 *
 * Reuses the module's existing reports.view route permission (no new
 * permission is introduced). Personal views are visible only to their owner;
 * shared views are visible to every tenant user with reports.view but can
 * only be changed/deleted/defaulted by their owner.
 */
class SavedReportViewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $views = ReportSavedView::query()
            ->where('report_key', 'sales-analytics')
            ->visibleTo($userId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (ReportSavedView $view) => $this->resource($view, $userId))
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
        ];
    }

    private function currentUserId(): ?int
    {
        return TenantUserHelper::resolveTenantUserId();
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(ReportSavedView $view, int $userId): array
    {
        return [
            'id' => (int) $view->id,
            'user_id' => (int) $view->user_id,
            'name' => (string) $view->name,
            'report_key' => (string) $view->report_key,
            'tab' => (string) $view->tab,
            'filters' => $view->filters ?? [],
            'grouping' => $view->grouping,
            'visibility' => (string) $view->visibility,
            'is_default' => (bool) $view->is_default,
            'is_owner' => $view->isOwnedBy($userId),
            'created_at' => $view->created_at?->toISOString(),
            'updated_at' => $view->updated_at?->toISOString(),
        ];
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
