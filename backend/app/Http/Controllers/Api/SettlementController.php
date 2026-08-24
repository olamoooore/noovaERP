<?php

namespace App\Http\Controllers\Api;

use App\Accounting\Enums\SettlementState;
use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\Settlement;
use App\Models\UnappliedCash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettlementController extends Controller
{
    /**
     * GET /accounting/settlements
     * List settlements with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Settlement::query();

        if ($request->has('state')) {
            $query->where('settlement_state', $request->input('state'));
        }

        if ($request->has('source_type')) {
            $query->where('source_record_type', $request->input('source_type'));
        }

        $settlements = $query->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'success' => true,
            'data' => $settlements,
        ]);
    }

    /**
     * GET /accounting/settlements/{id}
     * Get a settlement with allocations.
     */
    public function show(int $id): JsonResponse
    {
        $settlement = Settlement::with('allocations', 'unappliedCash')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $settlement,
        ]);
    }

    /**
     * POST /accounting/settlements/allocate
     * Allocate a settlement to specific obligations.
     */
    public function allocate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settlement_id' => 'required|integer',
            'allocations' => 'required|array|min:1',
            'allocations.*.source_record_type' => 'required|string',
            'allocations.*.source_record_id' => 'required|integer',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);

        $settlement = Settlement::findOrFail($validated['settlement_id']);
        $totalAllocated = 0;

        foreach ($validated['allocations'] as $allocData) {
            $allocation = Allocation::create([
                'tenant_id' => auth()->user()?->tenant_id,
                'settlement_id' => $settlement->id,
                'source_record_type' => $allocData['source_record_type'],
                'source_record_id' => $allocData['source_record_id'],
                'allocated_amount' => $allocData['amount'],
                'currency_code' => $settlement->currency_code,
            ]);

            $totalAllocated += $allocData['amount'];

            event(new \App\Accounting\Events\SettlementAllocated(
                settlementId: $settlement->id,
                allocationId: $allocation->id,
                allocatedAmount: $allocData['amount'],
                sourceRecordType: $allocData['source_record_type'],
                sourceRecordId: $allocData['source_record_id'],
                tenantId: $settlement->tenant_id,
            ));
        }

        $newAllocated = $settlement->allocated_amount + $totalAllocated;
        $totalAmount = $settlement->allocated_amount + $settlement->unapplied_amount + $totalAllocated;
        $remaining = max(0, $settlement->unapplied_amount - $totalAllocated);

        $newState = $remaining < 0.01
            ? SettlementState::FULLY_ALLOCATED
            : SettlementState::PARTIALLY_ALLOCATED;

        $settlement->update([
            'allocated_amount' => $newAllocated,
            'unapplied_amount' => $remaining,
            'settlement_state' => $newState->value,
        ]);

        return response()->json([
            'success' => true,
            'settlement' => $settlement,
            'total_allocated' => $totalAllocated,
            'remaining_unapplied' => $remaining,
        ]);
    }

    /**
     * POST /accounting/settlements/{id}/reverse
     * Reverse a settlement — creates compensating records instead of hard-deleting
     * allocations (ADR-005 §15, G8 Immutability).
     */
    public function reverse(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $settlement = Settlement::findOrFail($id);

        if ($settlement->settlement_state === SettlementState::REVERSED->value) {
            return response()->json([
                'success' => false,
                'error' => 'Settlement is already reversed.',
            ], 422);
        }

        $reversedAmount = $settlement->allocated_amount;

        // ── Create compensating allocation records (ADR-005 §8 G8) ──────
        // Instead of hard-deleting allocations, mark them as reversed and
        // create compensating records that preserve the audit trail
        $allocations = Allocation::where('settlement_id', $settlement->id)
            ->whereNull('reversed_at')
            ->get();

        foreach ($allocations as $allocation) {
            // Create a compensating allocation with negative amount
            Allocation::create([
                'tenant_id' => $settlement->tenant_id,
                'settlement_id' => $settlement->id,
                'source_record_type' => $allocation->source_record_type,
                'source_record_id' => $allocation->source_record_id,
                'allocated_amount' => -$allocation->allocated_amount,
                'currency_code' => $allocation->currency_code,
                'reversal_of_allocation_id' => $allocation->id,
                'reversal_reason' => $validated['reason'] ?? 'Settlement reversal',
                'reversed_at' => now(),
            ]);

            // Mark original allocation as reversed (soft reference, not hard delete)
            $allocation->update([
                'reversed_at' => now(),
                'reversal_reason' => $validated['reason'] ?? 'Settlement reversal',
            ]);
        }

        $settlement->update([
            'settlement_state' => SettlementState::REVERSED->value,
            'allocated_amount' => 0,
            'reversed_at' => now(),
            'reversal_reason' => $validated['reason'] ?? 'Settlement reversal',
        ]);

        event(new \App\Accounting\Events\SettlementReversed(
            settlementId: $settlement->id,
            reversedAmount: $reversedAmount,
            reason: $validated['reason'],
            tenantId: $settlement->tenant_id,
        ));

        return response()->json([
            'success' => true,
            'data' => $settlement,
            'reversed_amount' => $reversedAmount,
            'compensating_allocations_created' => $allocations->count(),
        ]);
    }

    /**
     * GET /accounting/unapplied-cash
     * List unapplied cash records.
     */
    public function unappliedCash(Request $request): JsonResponse
    {
        $query = UnappliedCash::available();

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        $cash = $query->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'success' => true,
            'data' => $cash,
        ]);
    }
}
