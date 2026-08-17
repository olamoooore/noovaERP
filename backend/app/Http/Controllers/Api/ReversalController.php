<?php

namespace App\Http\Controllers\Api;

use App\Accounting\DTOs\ReversalRequest;
use App\Accounting\Services\ReversalCorrectionEngine;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReversalController extends Controller
{
    public function __construct(
        private readonly ReversalCorrectionEngine $engine,
    ) {}

    /**
     * POST /accounting/reversals/full
     * Execute a full reversal of a posted journal entry.
     */
    public function fullReversal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'journal_entry_id' => 'required|integer',
            'reversal_date' => 'required|date',
            'reason' => 'required|string|max:500',
            'reason_code' => 'nullable|string|max:100',
            'approval_user_id' => 'nullable|integer',
        ]);

        $requestDTO = new ReversalRequest(
            journalEntryId: $validated['journal_entry_id'],
            reversalDate: $validated['reversal_date'],
            reason: $validated['reason'],
            reasonCode: $validated['reason_code'] ?? null,
            actorUserId: auth()->id(),
            approvalUserId: $validated['approval_user_id'] ?? null,
            tenantId: auth()->user()?->tenant_id,
        );

        $result = $this->engine->fullReversal($requestDTO);

        if (!$result->success) {
            return response()->json([
                'success' => false,
                'error' => $result->reason,
                'reason_code' => $result->reasonCode?->value,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'reversal_relationship_id' => $result->relationship->id,
            'reversal_journal_entry_id' => $result->journalEntry->id,
            'reversed_amount' => $result->relationship->reversed_amount_summary,
        ]);
    }

    /**
     * POST /accounting/reversals/partial
     * Execute a partial reversal of specific lines.
     */
    public function partialReversal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'journal_entry_id' => 'required|integer',
            'reversal_date' => 'required|date',
            'reason' => 'required|string|max:500',
            'reason_code' => 'nullable|string|max:100',
            'line_amounts' => 'required|array|min:1',
            'line_amounts.*.line_id' => 'required|integer',
            'line_amounts.*.amount' => 'required|numeric|min:0.01',
        ]);

        $requestDTO = new ReversalRequest(
            journalEntryId: $validated['journal_entry_id'],
            reversalDate: $validated['reversal_date'],
            reason: $validated['reason'],
            reasonCode: $validated['reason_code'] ?? null,
            actorUserId: auth()->id(),
            tenantId: auth()->user()?->tenant_id,
        );

        $result = $this->engine->partialReversal($requestDTO, $validated['line_amounts']);

        if (!$result->success) {
            return response()->json([
                'success' => false,
                'error' => $result->reason,
                'reason_code' => $result->reasonCode?->value,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'reversal_relationship_id' => $result->relationship->id,
            'reversal_journal_entry_id' => $result->journalEntry->id,
            'reversed_amount' => $result->relationship->reversed_amount_summary,
            'remaining_reversible' => $result->relationship->remaining_reversible_amount_summary,
        ]);
    }

    /**
     * POST /accounting/reversals/schedule-auto
     * Schedule an auto-reversal for a future date.
     */
    public function scheduleAutoReversal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'journal_entry_id' => 'required|integer',
            'scheduled_date' => 'required|date|after:today',
            'reason_code' => 'required|string|max:100',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $instruction = $this->engine->scheduleAutoReversal(
            journalEntryId: $validated['journal_entry_id'],
            scheduledDate: $validated['scheduled_date'],
            reasonCode: $validated['reason_code'],
            reasonText: $validated['reason_text'] ?? null,
        );

        return response()->json([
            'success' => true,
            'instruction_id' => $instruction->id,
            'execution_state' => $instruction->execution_state,
            'scheduled_date' => $instruction->scheduled_reversal_date->toDateString(),
        ]);
    }

    /**
     * POST /accounting/reversals/execute-due
     * Execute all due auto-reversals.
     */
    public function executeDueAutoReversals(): JsonResponse
    {
        $results = $this->engine->executeDueAutoReversals();

        return response()->json([
            'success' => true,
            'executed' => count($results),
            'results' => $results,
        ]);
    }

    /**
     * GET /accounting/reversals/relationship/{id}
     * Get reversal relationship with component lineage.
     */
    public function showRelationship(int $id): JsonResponse
    {
        $relationship = \App\Models\ReversalRelationship::with('components')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $relationship,
        ]);
    }

    /**
     * GET /accounting/reversals/original/{journalEntryId}
     * Get all reversal relationships for an original entry.
     */
    public function forOriginal(int $journalEntryId): JsonResponse
    {
        $relationships = \App\Models\ReversalRelationship::forOriginal($journalEntryId)
            ->with('components')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $relationships,
            'total_reversed' => $relationships->sum('reversed_amount_summary'),
            'total_remaining' => $relationships->first()?->remaining_reversible_amount_summary ?? 0,
        ]);
    }
}
