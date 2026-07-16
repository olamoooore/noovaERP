<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\EventManagement\Http\Middleware\RBACMiddleware;
use Modules\EventManagement\Models\BookingVelocityLog;
use Modules\EventManagement\Models\FraudAlert;
use Modules\EventManagement\Services\AntiAbuseService;
use Modules\EventManagement\Services\FraudDetectionService;

class FraudDetectionController extends Controller
{
    private FraudDetectionService $fraudDetectionService;

    private AntiAbuseService $antiAbuseService;

    public function __construct(
        FraudDetectionService $fraudDetectionService,
        AntiAbuseService $antiAbuseService
    ) {
        $this->fraudDetectionService = $fraudDetectionService;
        $this->antiAbuseService = $antiAbuseService;

        // All fraud detection endpoints require admin access
        $this->middleware([RBACMiddleware::class.':admin']);
    }

    /**
     * Get fraud detection statistics and overview.
     * Requirements: 14.5 - Fraud monitoring and analytics
     */
    public function getStatistics(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');
        $days = $request->query('days', 30);

        try {
            $statistics = $this->antiAbuseService->getFraudStatistics($tenantId, $days);

            return response()->json([
                'success' => true,
                'data' => $statistics,
                'period_days' => $days,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve fraud statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get fraud alerts with filtering and pagination.
     * Requirements: 14.5 - Fraud alert management
     */
    public function getAlerts(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $request->validate([
            'status' => 'nullable|in:open,investigating,resolved,false_positive',
            'severity' => 'nullable|in:low,medium,high,critical',
            'entity_type' => 'nullable|in:customer,ip_address,session',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $query = FraudAlert::forTenant($tenantId)
                ->with(['fraudDetectionRule'])
                ->orderBy('created_at', 'desc');

            // Apply filters
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('severity')) {
                $query->where('severity', $request->severity);
            }

            if ($request->has('entity_type')) {
                $query->where('entity_type', $request->entity_type);
            }

            // Pagination
            $perPage = $request->query('per_page', 20);
            $alerts = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $alerts->items(),
                'pagination' => [
                    'current_page' => $alerts->currentPage(),
                    'per_page' => $alerts->perPage(),
                    'total' => $alerts->total(),
                    'last_page' => $alerts->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve fraud alerts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update fraud alert status.
     * Requirements: 14.5 - Fraud alert management
     */
    public function updateAlertStatus(Request $request, int $alertId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $request->validate([
            'status' => 'required|in:open,investigating,resolved,false_positive',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $alert = FraudAlert::forTenant($tenantId)->findOrFail($alertId);

            $alert->update([
                'status' => $request->status,
                'admin_notes' => $request->notes,
                'resolved_at' => in_array($request->status, ['resolved', 'false_positive']) ? now() : null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Alert status updated successfully',
                'data' => $alert->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update alert status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Analyze booking velocity for a customer.
     * Requirements: 14.1 - Booking velocity monitoring
     */
    public function analyzeCustomerVelocity(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $request->validate([
            'customer_id' => 'required|integer',
            'time_window_minutes' => 'nullable|integer|min:1|max:1440', // Max 24 hours
        ]);

        try {
            $customerId = $request->customer_id;
            $timeWindow = $request->query('time_window_minutes', 60);

            $velocityData = $this->fraudDetectionService->monitorCustomerVelocity(
                $tenantId,
                $customerId,
                $timeWindow
            );

            return response()->json([
                'success' => true,
                'data' => $velocityData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to analyze customer velocity',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Analyze booking velocity for an IP address.
     * Requirements: 14.1 - IP-based monitoring
     */
    public function analyzeIpVelocity(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $request->validate([
            'ip_address' => 'required|ip',
            'time_window_minutes' => 'nullable|integer|min:1|max:1440',
        ]);

        try {
            $ipAddress = $request->ip_address;
            $timeWindow = $request->query('time_window_minutes', 60);

            $velocityData = $this->fraudDetectionService->monitorIpVelocity(
                $tenantId,
                $ipAddress,
                $timeWindow
            );

            return response()->json([
                'success' => true,
                'data' => $velocityData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to analyze IP velocity',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get booking velocity logs with filtering.
     * Requirements: 14.5 - Fraud monitoring and reporting
     */
    public function getVelocityLogs(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $request->validate([
            'customer_id' => 'nullable|integer',
            'ip_address' => 'nullable|ip',
            'event_id' => 'nullable|integer',
            'action_type' => 'nullable|in:booking_attempt,booking_success,booking_failed',
            'hours' => 'nullable|integer|min:1|max:168', // Max 7 days
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $query = BookingVelocityLog::forTenant($tenantId)
                ->orderBy('created_at', 'desc');

            // Apply filters
            if ($request->has('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }

            if ($request->has('ip_address')) {
                $query->where('ip_address', $request->ip_address);
            }

            if ($request->has('event_id')) {
                $query->where('event_id', $request->event_id);
            }

            if ($request->has('action_type')) {
                $query->where('action_type', $request->action_type);
            }

            // Time filter
            $hours = $request->query('hours', 24);
            $query->where('created_at', '>=', now()->subHours($hours));

            // Pagination
            $perPage = $request->query('per_page', 50);
            $logs = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $logs->items(),
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                    'last_page' => $logs->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve velocity logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Detect suspicious patterns for a booking attempt.
     * Requirements: 14.1, 14.2 - Pattern detection
     */
    public function detectPatterns(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        $request->validate([
            'customer_id' => 'nullable|integer',
            'ip_address' => 'nullable|ip',
            'user_agent' => 'nullable|string',
            'event_id' => 'required|integer',
            'tickets_requested' => 'nullable|integer|min:1',
        ]);

        try {
            $bookingData = [
                'customer_id' => $request->customer_id,
                'ip_address' => $request->ip_address ?? '0.0.0.0',
                'user_agent' => $request->user_agent,
                'event_id' => $request->event_id,
                'tickets_requested' => $request->tickets_requested ?? 1,
            ];

            $patterns = $this->fraudDetectionService->detectSuspiciousPatterns($tenantId, $bookingData);

            return response()->json([
                'success' => true,
                'data' => [
                    'patterns_detected' => count($patterns),
                    'patterns' => $patterns,
                    'total_risk_score' => array_sum(array_column($patterns, 'risk_score')),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to detect patterns',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process manual review queue.
     * Requirements: 14.5 - Manual review management
     */
    public function processReviewQueue(Request $request): JsonResponse
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
                'message' => 'Manual review queue processed',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process review queue',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clean up expired fraud detection data.
     * Requirements: 14.5 - Data cleanup and maintenance
     */
    public function cleanupExpiredData(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-ID', 'default');

        try {
            $result = $this->antiAbuseService->cleanupExpiredData($tenantId);

            return response()->json([
                'success' => true,
                'message' => 'Expired data cleaned up successfully',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cleanup expired data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
