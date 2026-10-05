<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Models\LegalEntity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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

        if ($request->has('legal_entity_id')) {
            $query->forEntity((int) $request->legal_entity_id);
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
            'code' => [
                'required', 'string', 'max:50',
                // Issue #768: codes are unique per legal entity, not tenant-wide.
                Rule::unique('fiscal_periods', 'code')->where(
                    fn ($q) => $request->filled('legal_entity_id')
                        ? $q->where('legal_entity_id', (int) $request->legal_entity_id)
                        : $q->whereNull('legal_entity_id')
                ),
            ],
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'period_type' => 'required|in:monthly,quarterly,yearly',
            'fiscal_year' => 'required|integer|min:2000|max:2100',
            'legal_entity_id' => 'nullable|integer|exists:legal_entities,id',
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
     *
     * Issue #768: the calendar comes from the legal entity's
     * fiscal_year_start (single source of truth). Without a legal entity the
     * legacy January calendar is kept. `period_start`/`period_end` override
     * the window for a first financial year of up to 18 months or a change
     * of year end.
     */
    public function generateYear(Request $request)
    {
        $validated = $request->validate([
            'fiscal_year' => 'required|integer|min:2000|max:2100',
            'legal_entity_id' => 'nullable|integer|exists:legal_entities,id',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after:period_start',
        ]);

        $entity = isset($validated['legal_entity_id'])
            ? LegalEntity::find($validated['legal_entity_id'])
            : LegalEntity::where('status', 'active')->orderBy('id')->first();

        $count = FiscalPeriod::createMonthlyPeriodsForYear(
            (int) $validated['fiscal_year'],
            $entity,
            isset($validated['period_start']) ? Carbon::parse($validated['period_start']) : null,
            isset($validated['period_end']) ? Carbon::parse($validated['period_end']) : null,
        );

        return response()->json([
            'message' => "{$count} fiscal period(s) created for {$validated['fiscal_year']}",
            'periods_created' => $count,
            'legal_entity_id' => $entity?->id,
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
