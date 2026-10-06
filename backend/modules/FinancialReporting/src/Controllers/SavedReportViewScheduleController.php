<?php

declare(strict_types=1);

namespace Modules\FinancialReporting\Controllers;

use App\Http\Controllers\Controller;
use App\Support\TenantUserHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\FinancialReporting\Models\ReportSavedView;
use Modules\FinancialReporting\Models\ReportViewSchedule;
use Modules\FinancialReporting\Services\ScheduledSavedViewDeliveryService;

/**
 * Issue #598 (RPT-UX3) — schedules for saved report views.
 *
 * Reads follow the view's visibility (owner or shared); every mutation is
 * owner-only. Reuses the module's reports.view route permission.
 */
class SavedReportViewScheduleController extends Controller
{
    public function index(int $viewId): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $view = ReportSavedView::findOrFail($viewId);

        if (! $view->isOwnedBy($userId) && $view->visibility !== 'shared') {
            return $this->forbiddenResponse('You do not have permission to view this saved view.');
        }

        $schedules = $view->schedules()->orderBy('id')->get()
            ->map(fn (ReportViewSchedule $schedule) => $this->resource($schedule))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $schedules,
        ]);
    }

    public function store(Request $request, int $viewId): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $view = ReportSavedView::findOrFail($viewId);

        if (! $view->isOwnedBy($userId)) {
            return $this->forbiddenResponse('Only the view owner can schedule this saved view.');
        }

        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $attributes = $validator->validated();
        $attributes['view_id'] = $view->id;
        $attributes['created_by'] = $userId;
        $attributes['format'] = $attributes['format'] ?? 'csv';
        $attributes['is_active'] = $attributes['is_active'] ?? true;
        $attributes['time_of_day'] = $attributes['time_of_day'] ?? '08:00:00';

        $schedule = new ReportViewSchedule($attributes);
        $schedule->next_run_at = $schedule->calculateNextRunAt();
        $schedule->save();

        return response()->json([
            'success' => true,
            'message' => 'Saved-view schedule created successfully',
            'data' => $this->resource($schedule),
        ], 201);
    }

    public function update(Request $request, int $viewId, int $scheduleId): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $view = ReportSavedView::findOrFail($viewId);

        if (! $view->isOwnedBy($userId)) {
            return $this->forbiddenResponse('Only the view owner can update this schedule.');
        }

        $schedule = ReportViewSchedule::query()
            ->where('view_id', $view->id)
            ->findOrFail($scheduleId);

        $validator = Validator::make($request->all(), $this->rules(partial: true));

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->toArray());
        }

        $schedulingChanged = false;

        foreach (['frequency', 'day_of_week', 'day_of_month', 'time_of_day'] as $field) {
            if ($request->has($field)) {
                $schedule->{$field} = $request->input($field);
                $schedulingChanged = true;
            }
        }

        if ($request->has('recipients')) {
            $schedule->recipients = array_values(array_map('intval', (array) $request->input('recipients')));
        }

        if ($request->has('format')) {
            $schedule->format = $request->input('format');
        }

        if ($request->has('is_active')) {
            $schedule->is_active = $request->boolean('is_active');
        }

        if ($schedulingChanged) {
            $schedule->next_run_at = $schedule->calculateNextRunAt();
        }

        $schedule->save();

        return response()->json([
            'success' => true,
            'message' => 'Saved-view schedule updated successfully',
            'data' => $this->resource($schedule->refresh()),
        ]);
    }

    public function destroy(int $viewId, int $scheduleId): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $view = ReportSavedView::findOrFail($viewId);

        if (! $view->isOwnedBy($userId)) {
            return $this->forbiddenResponse('Only the view owner can delete this schedule.');
        }

        $schedule = ReportViewSchedule::query()
            ->where('view_id', $view->id)
            ->findOrFail($scheduleId);

        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Saved-view schedule deleted successfully',
        ]);
    }

    public function run(int $viewId, int $scheduleId): JsonResponse
    {
        $userId = $this->currentUserId();

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        $view = ReportSavedView::findOrFail($viewId);

        if (! $view->isOwnedBy($userId)) {
            return $this->forbiddenResponse('Only the view owner can run this schedule.');
        }

        $schedule = ReportViewSchedule::query()
            ->where('view_id', $view->id)
            ->findOrFail($scheduleId);

        $counts = app(ScheduledSavedViewDeliveryService::class)
            ->processSchedule($schedule, (string) (tenant('id') ?? ''));

        return response()->json([
            'success' => true,
            'message' => 'Schedule run completed',
            'data' => $counts,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'frequency' => [$required, 'string', Rule::in(ReportViewSchedule::FREQUENCIES)],
            'day_of_week' => ['nullable', 'integer', 'min:0', 'max:6', 'required_if:frequency,weekly'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31', 'required_if:frequency,monthly'],
            'time_of_day' => ['nullable', 'date_format:H:i,H:i:s'],
            'recipients' => [$required, 'array', 'min:1'],
            'recipients.*' => ['integer', 'exists:users,id'],
            'format' => ['nullable', 'string', Rule::in(ReportViewSchedule::FORMATS)],
            'is_active' => ['boolean'],
        ];
    }

    private function currentUserId(): ?int
    {
        return TenantUserHelper::resolveTenantUserId();
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(ReportViewSchedule $schedule): array
    {
        return [
            'id' => (int) $schedule->id,
            'view_id' => (int) $schedule->view_id,
            'frequency' => (string) $schedule->frequency,
            'day_of_week' => $schedule->day_of_week !== null ? (int) $schedule->day_of_week : null,
            'day_of_month' => $schedule->day_of_month !== null ? (int) $schedule->day_of_month : null,
            'time_of_day' => (string) $schedule->time_of_day,
            'recipients' => array_values(array_map('intval', (array) $schedule->recipients)),
            'format' => (string) $schedule->format,
            'is_active' => (bool) $schedule->is_active,
            'last_run_at' => $schedule->last_run_at?->toISOString(),
            'next_run_at' => $schedule->next_run_at?->toISOString(),
            'run_count' => (int) $schedule->run_count,
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

    private function forbiddenResponse(string $message = 'You do not have permission to manage this schedule.'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }
}
