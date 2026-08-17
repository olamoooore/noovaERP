<?php

namespace App\Http\Controllers\Api;

use App\Accounting\Enums\CorrectionCaseState;
use App\Http\Controllers\Controller;
use App\Models\CorrectionCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorrectionCaseController extends Controller
{
    /**
     * GET /accounting/correction-cases
     * List correction cases.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CorrectionCase::query();

        if ($request->has('state')) {
            $query->where('overall_state', $request->input('state'));
        }

        if ($request->has('type')) {
            $query->where('case_type', $request->input('type'));
        }

        if ($request->has('open')) {
            $query->open();
        }

        $cases = $query->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'success' => true,
            'data' => $cases,
        ]);
    }

    /**
     * GET /accounting/correction-cases/{id}
     * Get a specific correction case.
     */
    public function show(int $id): JsonResponse
    {
        $case = CorrectionCase::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $case,
        ]);
    }

    /**
     * POST /accounting/correction-cases
     * Create a new correction case.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'case_type' => 'required|string|max:50',
            'reason_code' => 'required|string|max:100',
            'reason_text' => 'nullable|string|max:1000',
            'trigger_reference_type' => 'nullable|string|max:100',
            'trigger_reference_id' => 'nullable|integer',
        ]);

        $case = CorrectionCase::create([
            'tenant_id' => auth()->user()?->tenant_id,
            'entity_id' => $request->input('entity_id'),
            'case_type' => $validated['case_type'],
            'reason_code' => $validated['reason_code'],
            'reason_text' => $validated['reason_text'],
            'triggered_by' => (string) auth()->id(),
            'trigger_reference_type' => $validated['trigger_reference_type'],
            'trigger_reference_id' => $validated['trigger_reference_id'],
            'initiated_by' => auth()->id(),
        ]);

        event(new \App\Accounting\Events\CorrectionCaseCreated(
            correctionCaseId: $case->id,
            caseNumber: $case->case_number,
            caseType: $case->case_type,
            reasonCode: $case->reason_code,
            tenantId: $case->tenant_id,
        ));

        return response()->json([
            'success' => true,
            'data' => $case,
        ], 201);
    }

    /**
     * POST /accounting/correction-cases/{id}/advance
     * Advance a child dimension state.
     */
    public function advance(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dimension' => 'required|string|in:document_correction_state,accounting_correction_state,regulatory_correction_state,settlement_correction_state,replacement_issue_state',
            'new_state' => 'required|string',
        ]);

        $case = CorrectionCase::findOrFail($id);
        $previousState = $case->overall_state;

        $case->update([
            $validated['dimension'] => $validated['new_state'],
        ]);

        $case->recalculateState();

        event(new \App\Accounting\Events\CorrectionCaseAdvanced(
            correctionCaseId: $case->id,
            previousState: $previousState,
            newState: $case->overall_state,
            childDimension: $validated['dimension'],
            tenantId: $case->tenant_id,
        ));

        return response()->json([
            'success' => true,
            'data' => $case,
        ]);
    }

    /**
     * POST /accounting/correction-cases/{id}/complete
     * Manually complete a correction case (if all children are done).
     */
    public function complete(int $id): JsonResponse
    {
        $case = CorrectionCase::findOrFail($id);

        if (!$case->canComplete()) {
            return response()->json([
                'success' => false,
                'error' => 'Not all required child steps are completed or explicitly not required.',
            ], 422);
        }

        $case->update([
            'overall_state' => CorrectionCaseState::COMPLETED->value,
            'completed_at' => now(),
        ]);

        event(new \App\Accounting\Events\CorrectionCaseCompleted(
            correctionCaseId: $case->id,
            caseNumber: $case->case_number,
            tenantId: $case->tenant_id,
        ));

        return response()->json([
            'success' => true,
            'data' => $case,
        ]);
    }
}
