<?php

namespace Modules\FinancialReporting\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Modules\FinancialReporting\Models\ReportSchedule;
use Modules\FinancialReporting\Jobs\GenerateScheduledReport;
use Carbon\Carbon;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Report Schedule Controller
 * Manages scheduled report generation
 */
class ReportScheduleController extends Controller
{
    /**
     * Get all report schedules
     */
    public function index(Request $request): JsonResponse
    {
        $query = ReportSchedule::query();

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by report type
        if ($request->has('report_type')) {
            $query->where('report_type', $request->input('report_type'));
        }

        $schedules = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $schedules,
        ]);
    }

    /**
     * Get a single report schedule
     */
    public function show(int $id): JsonResponse
    {
        $schedule = ReportSchedule::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $schedule,
        ]);
    }

    /**
     * Create a new report schedule
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'report_type' => 'required|string|in:profit_loss,balance_sheet,cash_flow,trial_balance,general_ledger,stock_valuation,stock_movement',
            'frequency' => 'required|string|in:daily,weekly,monthly,quarterly,yearly',
            'parameters' => 'nullable|array',
            'recipients' => 'required|array|min:1',
            'recipients.*' => 'email',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $schedule = new ReportSchedule();
        $schedule->name = $request->input('name');
        $schedule->report_type = $request->input('report_type');
        $schedule->frequency = $request->input('frequency');
        $schedule->parameters = $request->input('parameters', []);
        $schedule->recipients = $request->input('recipients');
        $schedule->is_active = $request->input('is_active', true);
        $schedule->created_by = TenantUserHelper::resolveTenantUserId();
        $schedule->next_run_at = $this->calculateNextRunTime($request->input('frequency'));
        $schedule->save();

        return response()->json([
            'success' => true,
            'message' => 'Report schedule created successfully',
            'data' => $schedule,
        ], 201);
    }

    /**
     * Update a report schedule
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $schedule = ReportSchedule::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'report_type' => 'sometimes|required|string|in:profit_loss,balance_sheet,cash_flow,trial_balance,general_ledger,stock_valuation,stock_movement',
            'frequency' => 'sometimes|required|string|in:daily,weekly,monthly,quarterly,yearly',
            'parameters' => 'nullable|array',
            'recipients' => 'sometimes|required|array|min:1',
            'recipients.*' => 'email',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->has('name')) {
            $schedule->name = $request->input('name');
        }

        if ($request->has('report_type')) {
            $schedule->report_type = $request->input('report_type');
        }

        if ($request->has('frequency')) {
            $schedule->frequency = $request->input('frequency');
            $schedule->next_run_at = $this->calculateNextRunTime($request->input('frequency'));
        }

        if ($request->has('parameters')) {
            $schedule->parameters = $request->input('parameters');
        }

        if ($request->has('recipients')) {
            $schedule->recipients = $request->input('recipients');
        }

        if ($request->has('is_active')) {
            $schedule->is_active = $request->input('is_active');
        }

        $schedule->save();

        return response()->json([
            'success' => true,
            'message' => 'Report schedule updated successfully',
            'data' => $schedule,
        ]);
    }

    /**
     * Delete a report schedule
     */
    public function destroy(int $id): JsonResponse
    {
        $schedule = ReportSchedule::findOrFail($id);
        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Report schedule deleted successfully',
        ]);
    }

    /**
     * Run a schedule immediately
     */
    public function runNow(int $id): JsonResponse
    {
        $schedule = ReportSchedule::findOrFail($id);

        // Dispatch the job
        GenerateScheduledReport::dispatch($schedule);

        return response()->json([
            'success' => true,
            'message' => 'Report generation job dispatched successfully',
        ]);
    }

    /**
     * Toggle schedule active status
     */
    public function toggleActive(int $id): JsonResponse
    {
        $schedule = ReportSchedule::findOrFail($id);
        $schedule->is_active = !$schedule->is_active;
        $schedule->save();

        return response()->json([
            'success' => true,
            'message' => 'Schedule status updated successfully',
            'data' => $schedule,
        ]);
    }

    /**
     * Calculate next run time based on frequency
     */
    protected function calculateNextRunTime(string $frequency): Carbon
    {
        $now = Carbon::now();

        return match ($frequency) {
            'daily' => $now->addDay()->setTime(0, 0),
            'weekly' => $now->addWeek()->startOfWeek()->setTime(0, 0),
            'monthly' => $now->addMonth()->startOfMonth()->setTime(0, 0),
            'quarterly' => $now->addQuarter()->startOfQuarter()->setTime(0, 0),
            'yearly' => $now->addYear()->startOfYear()->setTime(0, 0),
            default => $now->addMonth()->startOfMonth()->setTime(0, 0),
        };
    }
}
