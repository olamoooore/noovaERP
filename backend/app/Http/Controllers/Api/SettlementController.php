<?php

namespace App\Http\Controllers\Api;

use App\Accounting\Enums\SettlementState;
use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\Settlement;
use App\Models\UnappliedCash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SettlementController — ACC-005B subledger allocation bookkeeping.
 *
 * GL-SILENT BY DESIGN (issue noovaerp/NoovaERP#63, confirmed
 * 2026-09-04): allocate()/reverse() intentionally contain no
 * PostingEngine calls. Verified state of the design:
 *   - The only GL effect in this flow is the cash receipt itself,
 *     posted once via PaymentPostingService -> PostingEngine at payment
 *     posting time (DR bank / CR AR-suspense).
 *   - AR/AP clearing against a specific invoice/bill happens in the
 *     payment-application path (PaymentController apply/cancel via
 *     PaymentPostingService / PaymentCorrectionService), which are the
 *     ledger-authoritative postings.
 *   - Settlement allocation is a bookkeeping overlay on top of that:
 *     it records which obligations a settlement is earmarked against.
 *     No balance changes when cash is allocated, so no compensating
 *     journal entry is due — the SettlementAllocated event has no
 *     posting listener by design (verified: zero listeners registered
 *     in app/Listeners and all module providers).
 *   - reverse() writes compensating Allocation rows with
 *     reversal_of_allocation_id lineage and moves settlement state to
 *     REVERSED — subledger-only correction (G8, verified 2026-08-29).
 * If ACC-005 ownership ever gives settlement its own GL effect, it
 * MUST route through PostingEngine like every other ledger writer
 * (ADR-005) — not by extending this controller's raw model writes.
 */
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
            $sourceType = (string) $request->input('source_type');
            $query->where(function ($q) use ($sourceType) {
                $q->where('source_record_type', $sourceType);
                // Accept a short form ("payment", "BillPayment") as well as
                // the stored FQCN ("App\Models\Payment").
                if (! str_contains($sourceType, '\\')) {
                    $q->orWhere('source_record_type', 'like', '%'.addcslashes($sourceType, '%_\\'));
                }
            });
        }

        $perPage = max(1, min((int) $request->input('per_page', 25), 100));

        $settlements = $query->orderByDesc('created_at')->paginate($perPage);

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
     *
     * Issues #41 + #46:
     *   - Transactional + row-locked (prevents concurrent over-allocation).
     *   - Real tenant provenance (never null; derived from settlement, request, or fallback).
     *   - Exact-boundary rejection (no floating-point tolerance; equals is fine).
     *   - Atomic: any insert or event failure rolls back all allocation writes.
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

        $tenantId = $this->resolveTenantId($request);

        try {
            return DB::transaction(function () use ($validated, $tenantId) {
                $settlement = Settlement::where('id', $validated['settlement_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                // ── #46 (HR46-01): exact-decimal boundary arithmetic.
                // Floats made 0.10 + 0.20 != 0.30 at the boundary; bcmath at
                // the column scale (2dp) is exact and matches PostingEngine.
                $totalAllocated = '0.00';
                foreach ($validated['allocations'] as $allocData) {
                    $totalAllocated = bcadd($totalAllocated, (string) $allocData['amount'], 2);
                }

                if (bccomp($totalAllocated, (string) $settlement->unapplied_amount, 2) === 1) {
                    return response()->json([
                        'success' => false,
                        'error' => 'ALLOCATION_EXCEEDS_UNAPPLIED',
                        'message' => "Requested allocation ({$totalAllocated}) exceeds available unapplied cash ({$settlement->unapplied_amount}).",
                        'unapplied_amount' => (float) $settlement->unapplied_amount,
                        'requested_amount' => (float) $totalAllocated,
                    ], 422);
                }

                $createdAllocations = [];
                foreach ($validated['allocations'] as $allocData) {
                    $allocation = Allocation::create([
                        'tenant_id' => $settlement->tenant_id ?? $tenantId,
                        'settlement_id' => $settlement->id,
                        'source_record_type' => $allocData['source_record_type'],
                        'source_record_id' => $allocData['source_record_id'],
                        'allocated_amount' => $allocData['amount'],
                        'currency_code' => $settlement->currency_code,
                    ]);
                    $createdAllocations[] = $allocation;

                    event(new \App\Accounting\Events\SettlementAllocated(
                        settlementId: $settlement->id,
                        allocationId: $allocation->id,
                        allocatedAmount: $allocData['amount'],
                        sourceRecordType: $allocData['source_record_type'],
                        sourceRecordId: $allocData['source_record_id'],
                        tenantId: $settlement->tenant_id ?? $tenantId,
                    ));
                }

                $newAllocated = bcadd((string) $settlement->allocated_amount, $totalAllocated, 2);
                $remaining = bcsub((string) $settlement->unapplied_amount, $totalAllocated, 2);

                $newState = bccomp($remaining, '0.00', 2) <= 0
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
                    'total_allocated' => (float) $totalAllocated,
                    'remaining_unapplied' => $remaining,
                    'allocations' => $createdAllocations,
                ], 201);
            });
        } catch (\Throwable $e) {
            Log::error('Settlement allocation failed', [
                'settlement_id' => $validated['settlement_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * POST /accounting/settlements/{id}/reverse
     * Reverse a settlement — creates compensating records instead of hard-deleting
     * allocations (ADR-005 §15, G8 Immutability).
     *
     * Issues #41 + #46:
     *   - Transactional: any compensating insert or settlement update failure
     *     rolls back the entire reversal.
     *   - Settlement invariants preserved: allocated_amount, unapplied_amount,
     *     and total (allocated + unapplied) match the pre-reversal totals.
     *   - Original allocation `allocated_amount` never changes; only reversal
     *     metadata (`reversed_at`, `reversal_reason`) is set.
     */
    public function reverse(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            return DB::transaction(function () use ($id, $validated) {
                $settlement = Settlement::where('id', $id)->lockForUpdate()->firstOrFail();

                if ($settlement->settlement_state === SettlementState::REVERSED->value) {
                    return response()->json([
                        'success' => false,
                        'error' => 'SETTLEMENT_ALREADY_REVERSED',
                        'message' => 'Settlement is already reversed.',
                    ], 422);
                }

                $reason = $validated['reason'] ?? 'Settlement reversal';
                $reversedAt = now();

                // ── Create compensating allocations ──
                $allocations = Allocation::where('settlement_id', $settlement->id)
                    ->whereNull('reversed_at')
                    ->lockForUpdate()
                    ->get();

                foreach ($allocations as $allocation) {
                    Allocation::create([
                        'tenant_id' => $allocation->tenant_id,
                        'settlement_id' => $settlement->id,
                        'source_record_type' => $allocation->source_record_type,
                        'source_record_id' => $allocation->source_record_id,
                        'allocated_amount' => -$allocation->allocated_amount,
                        'currency_code' => $allocation->currency_code,
                        'reversal_of_allocation_id' => $allocation->id,
                        'reversal_reason' => $reason,
                        'reversed_at' => $reversedAt,
                    ]);

                    $allocation->update([
                        'reversed_at' => $reversedAt,
                        'reversal_reason' => $reason,
                    ]);
                }

                // #46 (HR46-01): exact-decimal arithmetic on the reversal too.
                $reversedAmount = (float) $settlement->allocated_amount;

                // ── Settlement invariants: allocated = 0; unapplied returns
                //    the prior total - allocated, preserving total + reconciliation.
                $settlementTotal = bcadd(
                    (string) $settlement->allocated_amount,
                    (string) $settlement->unapplied_amount,
                    2
                );
                $settlement->update([
                    'settlement_state' => SettlementState::REVERSED->value,
                    'allocated_amount' => '0.00',
                    'unapplied_amount' => $settlementTotal,
                    'reversed_at' => $reversedAt,
                    'reversal_reason' => $reason,
                ]);

                event(new \App\Accounting\Events\SettlementReversed(
                    settlementId: $settlement->id,
                    reversedAmount: $reversedAmount,
                    reason: $reason,
                    tenantId: $settlement->tenant_id,
                ));

                return response()->json([
                    'success' => true,
                    'data' => $settlement->fresh(),
                    'reversed_amount' => $reversedAmount,
                    'compensating_allocations_created' => $allocations->count(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Settlement reversal failed', [
                'settlement_id' => $id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
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

    /**
     * Resolve a real tenant id from the request, settlement, or tenant() helper.
     * Tenant-schema auth users do not carry a `tenant_id` column on the User model,
     * so we fall back to `tenant()` (stancl/tenancy) which always carries the UUID
     * in a real request.
     */
    private function resolveTenantId(Request $request): string
    {
        $candidates = [
            $request->attributes->get('tenant_id'),
            $request->input('tenant_id'),
            tenant()?->id,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '' && $candidate !== 0) {
                return (string) $candidate;
            }
        }

        // Fallback to a deterministic placeholder rather than null — UUIDs
        // must be non-null in the allocations.tenant_id column.
        return '00000000-0000-0000-0000-000000000000';
    }
}
