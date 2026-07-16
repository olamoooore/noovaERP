<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * FiscalPeriodsController
 *
 * CRUD + lifecycle actions (lock / unlock / close / reopen) for fiscal periods.
 * Status transitions go through the model's dedicated methods to ensure
 * the is_locked boolean and the status enum stay in sync.
 */
class FiscalPeriodsController extends Controller
{
    /**
     * List all fiscal periods, optionally filtered by year or status.
     */
    public function index(Request $request)
    {
        $query = FiscalPeriod::query();

        if ($request->has('fiscal_year')) {
            $query->forYear((int) $request->fiscal_year);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $periods = $query->orderBy('period_start')->get();

        return response()->json(['data' => $periods]);
    }

    /**
     * Create a new fiscal period.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50|unique:fiscal_periods,code',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'period_type' => 'required|in:monthly,quarterly,yearly',
            'fiscal_year' => 'required|integer|min:2000|max:2100',
        ]);

        $validated['is_locked'] = false;
        $validated['status'] = 'open';
        $validated['created_by'] = TenantUserHelper::resolveTenantUserId();

        $period = FiscalPeriod::create($validated);

        return response()->json([
            'message' => 'Fiscal period created successfully',
            'data' => $period,
        ], 201);
    }

    /**
     * Create monthly periods for an entire fiscal year at once.
     */
    public function generateYear(Request $request)
    {
        $validated = $request->validate([
            'fiscal_year' => 'required|integer|min:2000|max:2100',
        ]);

        $count = FiscalPeriod::createMonthlyPeriodsForYear($validated['fiscal_year']);

        return response()->json([
            'message' => "{$count} fiscal period(s) created for {$validated['fiscal_year']}",
            'periods_created' => $count,
        ]);
    }

    /**
     * Show a single fiscal period.
     */
    public function show($id)
    {
        $period = FiscalPeriod::findOrFail($id);

        return response()->json(['data' => $period]);
    }

    /**
     * Update an open fiscal period (name, dates, type).
     * Locked or closed periods cannot be edited directly.
     */
    public function update(Request $request, $id)
    {
        $period = FiscalPeriod::findOrFail($id);

        if ($period->isLocked()) {
            return response()->json([
                'message' => 'Cannot edit a locked fiscal period. Unlock it first.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:50|unique:fiscal_periods,code,'.$id,
            'period_start' => 'sometimes|date',
            'period_end' => 'sometimes|date|after:period_start',
            'period_type' => 'sometimes|in:monthly,quarterly,yearly',
            'fiscal_year' => 'sometimes|integer|min:2000|max:2100',
        ]);

        $period->update($validated);

        return response()->json([
            'message' => 'Fiscal period updated successfully',
            'data' => $period->fresh(),
        ]);
    }

    /**
     * Lock a fiscal period — prevents any transactions from being posted to it.
     * Requires accounting.lock permission (or admin/super-admin).
     */
    public function lock($id)
    {
        $period = FiscalPeriod::findOrFail($id);

        try {
            $period->lock();
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Log::info('Fiscal period locked', [
            'period_id' => $period->id,
            'period_code' => $period->code,
            'locked_by' => TenantUserHelper::resolveTenantUserId(),
        ]);

        return response()->json([
            'message' => "Fiscal period '{$period->name}' has been locked.",
            'data' => $period->fresh(),
        ]);
    }

    /**
     * Unlock a locked fiscal period.
     * This is a sensitive operation — requires accounting.lock permission.
     */
    public function unlock($id)
    {
        $period = FiscalPeriod::findOrFail($id);

        try {
            $period->unlock();
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Log::warning('Fiscal period unlocked', [
            'period_id' => $period->id,
            'period_code' => $period->code,
            'unlocked_by' => TenantUserHelper::resolveTenantUserId(),
        ]);

        return response()->json([
            'message' => "Fiscal period '{$period->name}' has been unlocked.",
            'data' => $period->fresh(),
        ]);
    }

    /**
     * Close a fiscal period (soft-close — still readable, no new transactions).
     */
    public function close($id)
    {
        $period = FiscalPeriod::findOrFail($id);

        try {
            $period->close();
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Fiscal period '{$period->name}' has been closed.",
            'data' => $period->fresh(),
        ]);
    }

    /**
     * Reopen a closed (but not locked) fiscal period.
     */
    public function reopen($id)
    {
        $period = FiscalPeriod::findOrFail($id);

        try {
            $period->reopen();
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Fiscal period '{$period->name}' has been reopened.",
            'data' => $period->fresh(),
        ]);
    }

    /**
     * Delete a period — only allowed when it's open and has no posted transactions.
     */
    public function destroy($id)
    {
        $period = FiscalPeriod::findOrFail($id);

        if ($period->isLocked() || $period->isClosed()) {
            return response()->json([
                'message' => "Cannot delete a '{$period->status}' fiscal period.",
            ], 422);
        }

        $period->delete();

        return response()->json(['message' => 'Fiscal period deleted successfully.']);
    }
}
