<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReconciliationException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * ReconciliationExceptionController
 * 
 * API endpoints for reconciliation exception management.
 */
class ReconciliationExceptionController extends Controller
{
    /**
     * List exceptions with filters
     * 
     * GET /api/v1/reconciliation/exceptions
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => 'nullable|string|in:open,in_progress,pending_approval,resolved,escalated',
                'exception_type' => 'nullable|string',
                'aging_bucket' => 'nullable|string|in:current,1_30_days,31_60_days,61_90_days,90_plus_days',
                'priority' => 'nullable|string|in:low,medium,high,critical',
                'assigned_to' => 'nullable|integer|exists:users,id',
                'bank_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'limit' => 'nullable|integer|min:1|max:100|default:50',
            ]);

            $query = ReconciliationException::with(['bankAccount', 'assignedUser']);

            // Apply filters
            if (!empty($validated['status'])) {
                $query->where('status', $validated['status']);
            } else {
                $query->open(); // Default to open exceptions
            }

            if (!empty($validated['exception_type'])) {
                $query->where('exception_type', $validated['exception_type']);
            }

            if (!empty($validated['aging_bucket'])) {
                $query->inBucket($validated['aging_bucket']);
            }

            if (!empty($validated['priority'])) {
                $query->where('priority', $validated['priority']);
            }

            if (!empty($validated['assigned_to'])) {
                $query->assignedTo($validated['assigned_to']);
            }

            if (!empty($validated['bank_account_id'])) {
                $query->where('bank_account_id', $validated['bank_account_id']);
            }

            if (!empty($validated['date_from'])) {
                $query->where('transaction_date', '>=', $validated['date_from']);
            }

            if (!empty($validated['date_to'])) {
                $query->where('transaction_date', '<=', $validated['date_to']);
            }

            $exceptions = $query->orderByDesc('created_at')
                ->paginate($validated['limit']);

            return response()->json([
                'success' => true,
                'data' => $exceptions,
            ]);

        } catch (\Exception $e) {
            Log::error('Exception list failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve exceptions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create new exception
     * 
     * POST /api/v1/reconciliation/exceptions
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'exception_type' => 'required|string|in:unmatched_transaction,bank_error,timing_difference,missing_receipt,duplicate',
                'bank_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
                'amount' => 'nullable|numeric',
                'transaction_date' => 'nullable|date',
                'reference' => 'nullable|string|max:100',
                'description' => 'nullable|string',
                'priority' => 'nullable|string|in:low,medium,high,critical|default:medium',
                'assigned_to' => 'nullable|integer|exists:users,id',
                'notes' => 'nullable|string',
            ]);

            $validated['status'] = ReconciliationException::STATUS_OPEN;
            $validated['aging_bucket'] = ReconciliationException::BUCKET_CURRENT;
            $validated['days_open'] = 0;

            $exception = ReconciliationException::create($validated);

            return response()->json([
                'success' => true,
                'data' => $exception,
                'message' => 'Exception created successfully',
            ], 201);

        } catch (\Exception $e) {
            Log::error('Exception creation failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create exception',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single exception
     * 
     * GET /api/v1/reconciliation/exceptions/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $exception = ReconciliationException::with(['bankAccount', 'assignedUser', 'resolvedByUser'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $exception,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exception not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Update exception
     * 
     * PUT /api/v1/reconciliation/exceptions/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $exception = ReconciliationException::findOrFail($id);

            $validated = $request->validate([
                'status' => 'nullable|string|in:open,in_progress,pending_approval,resolved,escalated',
                'priority' => 'nullable|string|in:low,medium,high,critical',
                'assigned_to' => 'nullable|integer|exists:users,id',
                'notes' => 'nullable|string',
            ]);

            $exception->update($validated);

            return response()->json([
                'success' => true,
                'data' => $exception,
                'message' => 'Exception updated successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Exception update failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update exception',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve exception
     * 
     * POST /api/v1/reconciliation/exceptions/{id}/resolve
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        try {
            $exception = ReconciliationException::findOrFail($id);

            $validated = $request->validate([
                'resolution_notes' => 'nullable|string',
            ]);

            $exception->resolve(auth()->id(), $validated['resolution_notes'] ?? null);

            return response()->json([
                'success' => true,
                'data' => $exception,
                'message' => 'Exception resolved successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Exception resolution failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to resolve exception',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Escalate exception
     * 
     * POST /api/v1/reconciliation/exceptions/{id}/escalate
     */
    public function escalate(Request $request, int $id): JsonResponse
    {
        try {
            $exception = ReconciliationException::findOrFail($id);

            $validated = $request->validate([
                'reason' => 'required|string',
            ]);

            $exception->escalate($validated['reason']);

            return response()->json([
                'success' => true,
                'data' => $exception,
                'message' => 'Exception escalated successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Exception escalation failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to escalate exception',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get aging statistics
     * 
     * GET /api/v1/reconciliation/exceptions/aging
     */
    public function agingStatistics(): JsonResponse
    {
        try {
            $statistics = ReconciliationException::getAgingStatistics();

            // Add summary counts
            $summary = [
                'total_open' => ReconciliationException::open()->count(),
                'high_priority' => ReconciliationException::open()->highPriority()->count(),
                'aging_90_plus' => ReconciliationException::open()->inBucket('90_plus_days')->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => $statistics,
                    'summary' => $summary,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Aging statistics failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk update aging for all open exceptions
     * 
     * POST /api/v1/reconciliation/exceptions/age-all
     */
    public function ageAll(): JsonResponse
    {
        try {
            $exceptions = ReconciliationException::open()->get();
            
            foreach ($exceptions as $exception) {
                $exception->updateAging();
            }

            return response()->json([
                'success' => true,
                'message' => "Updated aging for {$exceptions->count()} exceptions",
                'data' => [
                    'updated_count' => $exceptions->count(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk aging update failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update aging',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}