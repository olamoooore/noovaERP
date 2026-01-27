<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\EventManagement\Models\ManualReviewQueue;
use Modules\EventManagement\Services\AntiAbuseService;
use Modules\EventManagement\Http\Middleware\RBACMiddleware;

class ManualReviewController extends Controller
{
    private AntiAbuseService $antiAbuseService;

    public function __construct(AntiAbuseService $antiAbuseService)
    {
        $this->antiAbuseService = $antiAbuseService;

        // All manual review endpoints require admin access
        $this->middleware([RBACMiddleware::class . ':admin']);
    }

    /**
     * Get manual review queue with filtering and pagination.
     * Requirements: 14.5 - Manual review queue management
     */
    public function getReviewQueue(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $request->validate([
            'status' => 'nullable|in:pending,approved,rejected,escalated',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'review_reason' => 'nullable|in:velocity_exceeded,suspicious_pattern,high_risk_customer,manual_flag',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $query = ManualReviewQueue::forTenant($tenantId)
                ->with(['booking', 'booking.event'])
                ->orderBy('priority', 'desc')
                ->orderBy('created_at', 'asc'); // FIFO within priority

            // Apply filters
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('priority')) {
                $query->where('priority', $request->priority);
            }

            if ($request->has('review_reason')) {
                $query->where('review_reason', $request->review_reason);
            }

            // Pagination
            $perPage = $request->query('per_page', 20);
            $reviews = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $reviews->items(),
                'pagination' => [
                    'current_page' => $reviews->currentPage(),
                    'per_page' => $reviews->perPage(),
                    'total' => $reviews->total(),
                    'last_page' => $reviews->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve review queue',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get details for a specific review item.
     * Requirements: 14.5 - Manual review details
     */
    public function getReviewDetails(Request $request, int $reviewId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        try {
            $review = ManualReviewQueue::forTenant($tenantId)
                ->with(['booking', 'booking.event', 'booking.attendees'])
                ->findOrFail($reviewId);

            return response()->json([
                'success' => true,
                'data' => $review,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve review details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve a manual review item.
     * Requirements: 14.5 - Manual review approval
     */
    public function approveReview(Request $request, int $reviewId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $review = ManualReviewQueue::forTenant($tenantId)->findOrFail($reviewId);

            if ($review->status !== ManualReviewQueue::STATUS_PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review item is not in pending status',
                ], 400);
            }

            $review->approve($request->notes ?? 'Approved by admin');

            return response()->json([
                'success' => true,
                'message' => 'Review item approved successfully',
                'data' => $review->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve review item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject a manual review item.
     * Requirements: 14.5 - Manual review rejection
     */
    public function rejectReview(Request $request, int $reviewId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        try {
            $review = ManualReviewQueue::forTenant($tenantId)->findOrFail($reviewId);

            if ($review->status !== ManualReviewQueue::STATUS_PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review item is not in pending status',
                ], 400);
            }

            $review->reject($request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Review item rejected successfully',
                'data' => $review->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject review item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Escalate a manual review item.
     * Requirements: 14.5 - Manual review escalation
     */
    public function escalateReview(Request $request, int $reviewId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $request->validate([
            'escalation_reason' => 'required|string|max:1000',
        ]);

        try {
            $review = ManualReviewQueue::forTenant($tenantId)->findOrFail($reviewId);

            if ($review->status !== ManualReviewQueue::STATUS_PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review item is not in pending status',
                ], 400);
            }

            $review->escalate($request->escalation_reason);

            return response()->json([
                'success' => true,
                'message' => 'Review item escalated successfully',
                'data' => $review->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to escalate review item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk process review items.
     * Requirements: 14.5 - Bulk review processing
     */
    public function bulkProcessReviews(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $request->validate([
            'review_ids' => 'required|array|min:1|max:50',
            'review_ids.*' => 'integer',
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|string|max:1000',
            'reason' => 'required_if:action,reject|string|max:1000',
        ]);

        try {
            $reviewIds = $request->review_ids;
            $action = $request->action;
            $notes = $request->notes ?? '';
            $reason = $request->reason ?? '';

            $reviews = ManualReviewQueue::forTenant($tenantId)
                ->whereIn('id', $reviewIds)
                ->where('status', ManualReviewQueue::STATUS_PENDING)
                ->get();

            $processed = [];
            $errors = [];

            foreach ($reviews as $review) {
                try {
                    if ($action === 'approve') {
                        $review->approve($notes);
                    } else {
                        $review->reject($reason);
                    }
                    $processed[] = $review->id;
                } catch (\Exception $e) {
                    $errors[] = [
                        'review_id' => $review->id,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Bulk processing completed',
                'data' => [
                    'processed_count' => count($processed),
                    'processed_ids' => $processed,
                    'error_count' => count($errors),
                    'errors' => $errors,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to bulk process reviews',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get review queue statistics.
     * Requirements: 14.5 - Review queue analytics
     */
    public function getQueueStatistics(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        try {
            $statistics = [
                'pending_count' => ManualReviewQueue::forTenant($tenantId)
                    ->where('status', ManualReviewQueue::STATUS_PENDING)
                    ->count(),
                'high_priority_count' => ManualReviewQueue::forTenant($tenantId)
                    ->where('status', ManualReviewQueue::STATUS_PENDING)
                    ->whereIn('priority', [ManualReviewQueue::PRIORITY_HIGH, ManualReviewQueue::PRIORITY_URGENT])
                    ->count(),
                'approved_today' => ManualReviewQueue::forTenant($tenantId)
                    ->where('status', ManualReviewQueue::STATUS_APPROVED)
                    ->whereDate('reviewed_at', today())
                    ->count(),
                'rejected_today' => ManualReviewQueue::forTenant($tenantId)
                    ->where('status', ManualReviewQueue::STATUS_REJECTED)
                    ->whereDate('reviewed_at', today())
                    ->count(),
                'average_review_time_minutes' => ManualReviewQueue::forTenant($tenantId)
                    ->whereNotNull('reviewed_at')
                    ->whereDate('reviewed_at', '>=', now()->subDays(7))
                    ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, reviewed_at)) as avg_time')
                    ->value('avg_time') ?? 0,
                'oldest_pending_hours' => ManualReviewQueue::forTenant($tenantId)
                    ->where('status', ManualReviewQueue::STATUS_PENDING)
                    ->orderBy('created_at', 'asc')
                    ->selectRaw('TIMESTAMPDIFF(HOUR, created_at, NOW()) as hours_old')
                    ->value('hours_old') ?? 0,
            ];

            return response()->json([
                'success' => true,
                'data' => $statistics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve queue statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Auto-process low-risk items in the queue.
     * Requirements: 14.5 - Automated review processing
     */
    public function autoProcessQueue(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $limit = $request->query('limit', 50);
            $result = $this->antiAbuseService->processManualReviewQueue($tenantId, $limit);

            return response()->json([
                'success' => true,
                'message' => 'Auto-processing completed',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to auto-process queue',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}