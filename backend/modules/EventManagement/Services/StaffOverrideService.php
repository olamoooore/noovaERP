<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Exception;

/**
 * StaffOverrideService - Task 9.3
 * 
 * Manages mandatory override reason tracking, supervisor approval workflows,
 * override audit trails and pattern detection for operations console hardening.
 * 
 * Requirements: 25.3, 25.4
 */
class StaffOverrideService
{
    private const CACHE_PREFIX = 'staff_override:';
    private const PATTERN_DETECTION_WINDOW = 24; // hours
    private const MAX_OVERRIDES_PER_HOUR = 10;
    private const EMERGENCY_OVERRIDE_TIMEOUT = 300; // 5 minutes

    /**
     * Create staff override with mandatory reason tracking
     * 
     * @param array $overrideData Override operation data
     * @return array Override creation result
     */
    public function createOverride(array $overrideData): array
    {
        try {
            DB::beginTransaction();

            // Validate override data
            $this->validateOverrideData($overrideData);

            // Check staff permissions
            $permissionCheck = $this->checkStaffOverridePermissions($overrideData['staff_id'], $overrideData);
            if (!$permissionCheck['allowed']) {
                throw new Exception($permissionCheck['reason']);
            }

            // Determine if supervisor approval is required
            $requiresApproval = $this->requiresSupervisorApproval($overrideData);

            // Create override record
            $overrideEntry = [
                'staff_id' => $overrideData['staff_id'],
                'event_id' => $overrideData['event_id'] ?? null,
                'override_type' => $overrideData['override_type'],
                'target_entity_type' => $overrideData['target_entity_type'],
                'target_entity_id' => $overrideData['target_entity_id'],
                'override_reason' => $overrideData['override_reason'],
                'override_category' => $overrideData['override_category'],
                'override_data' => json_encode($overrideData['override_data']),
                'context_data' => json_encode($overrideData['context_data'] ?? []),
                'supervisor_approval_required' => $requiresApproval,
                'approval_status' => $requiresApproval ? 'pending' : 'auto_approved',
                'device_id' => $overrideData['device_id'] ?? null,
                'ip_address' => $overrideData['ip_address'] ?? request()->ip(),
                'override_timestamp' => now(),
                'is_emergency_override' => $overrideData['is_emergency'] ?? false,
                'audit_metadata' => json_encode($this->generateAuditMetadata($overrideData)),
                'created_at' => now()
            ];

            $overrideId = DB::table('staff_overrides')->insertGetId($overrideEntry);

            // Update pattern detection
            $this->updateOverridePatterns($overrideData['staff_id'], $overrideData);

            // Cache override for quick access
            $this->cacheOverride($overrideId, $overrideEntry);

            DB::commit();

            Log::info('Staff override created', [
                'override_id' => $overrideId,
                'staff_id' => $overrideData['staff_id'],
                'override_type' => $overrideData['override_type'],
                'requires_approval' => $requiresApproval
            ]);

            return [
                'success' => true,
                'override_id' => $overrideId,
                'requires_approval' => $requiresApproval,
                'approval_status' => $overrideEntry['approval_status'],
                'message' => $requiresApproval ? 'Override created, awaiting supervisor approval' : 'Override approved automatically'
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create staff override', [
                'error' => $e->getMessage(),
                'override_data' => $overrideData
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create override',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Process supervisor approval for override
     * 
     * @param int $overrideId Override identifier
     * @param int $supervisorId Supervisor staff ID
     * @param string $decision Approval decision (approved/rejected)
     * @param string $notes Approval notes
     * @return array Approval result
     */
    public function processSupervisorApproval(int $overrideId, int $supervisorId, string $decision, string $notes = ''): array
    {
        try {
            DB::beginTransaction();

            // Get override details
            $override = DB::table('staff_overrides')
                ->where('id', $overrideId)
                ->where('approval_status', 'pending')
                ->first();

            if (!$override) {
                throw new Exception('Override not found or not pending approval');
            }

            // Validate supervisor permissions
            $supervisorCheck = $this->validateSupervisorPermissions($supervisorId, $override);
            if (!$supervisorCheck['valid']) {
                throw new Exception($supervisorCheck['reason']);
            }

            // Validate decision
            if (!in_array($decision, ['approved', 'rejected'])) {
                throw new Exception('Invalid approval decision');
            }

            // Update override record
            DB::table('staff_overrides')
                ->where('id', $overrideId)
                ->update([
                    'approved_by_staff_id' => $supervisorId,
                    'approved_at' => now(),
                    'approval_notes' => $notes,
                    'approval_status' => $decision,
                    'updated_at' => now()
                ]);

            // Apply override if approved
            $applicationResult = null;
            if ($decision === 'approved') {
                $applicationResult = $this->applyApprovedOverride($override);
            }

            // Update pattern tracking
            $this->updateApprovalPatterns($supervisorId, $decision, $override);

            DB::commit();

            Log::info('Override approval processed', [
                'override_id' => $overrideId,
                'supervisor_id' => $supervisorId,
                'decision' => $decision
            ]);

            return [
                'success' => true,
                'override_id' => $overrideId,
                'decision' => $decision,
                'application_result' => $applicationResult,
                'message' => "Override {$decision} successfully"
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to process supervisor approval', [
                'override_id' => $overrideId,
                'supervisor_id' => $supervisorId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to process approval',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get override audit trail for entity
     * 
     * @param string $entityType Entity type
     * @param int $entityId Entity ID
     * @param array $options Query options
     * @return array Audit trail data
     */
    public function getOverrideAuditTrail(string $entityType, int $entityId, array $options = []): array
    {
        try {
            $query = DB::table('staff_overrides')
                ->where('target_entity_type', $entityType)
                ->where('target_entity_id', $entityId)
                ->orderBy('override_timestamp', 'desc');

            // Apply filters
            if (isset($options['staff_id'])) {
                $query->where('staff_id', $options['staff_id']);
            }

            if (isset($options['override_type'])) {
                $query->where('override_type', $options['override_type']);
            }

            if (isset($options['date_from'])) {
                $query->where('override_timestamp', '>=', $options['date_from']);
            }

            if (isset($options['date_to'])) {
                $query->where('override_timestamp', '<=', $options['date_to']);
            }

            if (isset($options['limit'])) {
                $query->limit($options['limit']);
            }

            $overrides = $query->get()->map(function ($override) {
                return [
                    'override_id' => $override->id,
                    'staff_id' => $override->staff_id,
                    'override_type' => $override->override_type,
                    'override_reason' => $override->override_reason,
                    'override_category' => $override->override_category,
                    'override_timestamp' => $override->override_timestamp,
                    'approval_status' => $override->approval_status,
                    'approved_by' => $override->approved_by_staff_id,
                    'approved_at' => $override->approved_at,
                    'approval_notes' => $override->approval_notes,
                    'is_emergency' => $override->is_emergency_override,
                    'override_data' => json_decode($override->override_data, true),
                    'context_data' => json_decode($override->context_data, true)
                ];
            })->toArray();

            return [
                'success' => true,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'overrides' => $overrides,
                'total_count' => count($overrides)
            ];

        } catch (Exception $e) {
            Log::error('Failed to get override audit trail', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'overrides' => [],
                'error' => 'Failed to retrieve audit trail'
            ];
        }
    }

    /**
     * Detect override patterns and generate alerts
     * 
     * @param int $staffId Staff member ID
     * @param array $options Detection options
     * @return array Pattern detection results
     */
    public function detectOverridePatterns(int $staffId, array $options = []): array
    {
        try {
            $timeWindow = $options['time_window_hours'] ?? self::PATTERN_DETECTION_WINDOW;
            $patterns = [];

            // Get recent overrides for staff
            $recentOverrides = DB::table('staff_overrides')
                ->where('staff_id', $staffId)
                ->where('override_timestamp', '>=', now()->subHours($timeWindow))
                ->orderBy('override_timestamp', 'desc')
                ->get();

            // Frequency pattern detection
            $frequencyPattern = $this->detectFrequencyPattern($recentOverrides, $timeWindow);
            if ($frequencyPattern['alert']) {
                $patterns[] = $frequencyPattern;
            }

            // Time-based pattern detection
            $timePattern = $this->detectTimeBasedPattern($recentOverrides);
            if ($timePattern['alert']) {
                $patterns[] = $timePattern;
            }

            // Category clustering pattern
            $categoryPattern = $this->detectCategoryPattern($recentOverrides);
            if ($categoryPattern['alert']) {
                $patterns[] = $categoryPattern;
            }

            // Emergency override pattern
            $emergencyPattern = $this->detectEmergencyPattern($recentOverrides);
            if ($emergencyPattern['alert']) {
                $patterns[] = $emergencyPattern;
            }

            // Generate alerts for detected patterns
            foreach ($patterns as $pattern) {
                $this->generatePatternAlert($staffId, $pattern);
            }

            return [
                'success' => true,
                'staff_id' => $staffId,
                'patterns_detected' => count($patterns),
                'patterns' => $patterns,
                'alert_level' => $this->calculateOverallAlertLevel($patterns)
            ];

        } catch (Exception $e) {
            Log::error('Failed to detect override patterns', [
                'staff_id' => $staffId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'patterns' => [],
                'error' => 'Failed to detect patterns'
            ];
        }
    }
    /**
     * Get pending approvals for supervisor
     * 
     * @param int $supervisorId Supervisor staff ID
     * @param array $filters Optional filters
     * @return array Pending approvals list
     */
    public function getPendingApprovals(int $supervisorId, array $filters = []): array
    {
        try {
            // Validate supervisor permissions
            $supervisorCheck = $this->validateSupervisorRole($supervisorId);
            if (!$supervisorCheck['valid']) {
                return [
                    'success' => false,
                    'error' => 'Insufficient supervisor permissions',
                    'pending_approvals' => []
                ];
            }

            $query = DB::table('staff_overrides')
                ->where('approval_status', 'pending')
                ->where('supervisor_approval_required', true)
                ->orderBy('override_timestamp', 'asc');

            // Apply filters
            if (isset($filters['event_id'])) {
                $query->where('event_id', $filters['event_id']);
            }

            if (isset($filters['override_type'])) {
                $query->where('override_type', $filters['override_type']);
            }

            if (isset($filters['priority'])) {
                // Filter by emergency overrides if high priority requested
                if ($filters['priority'] === 'high') {
                    $query->where('is_emergency_override', true);
                }
            }

            $pendingApprovals = $query->get()->map(function ($override) {
                return [
                    'override_id' => $override->id,
                    'staff_id' => $override->staff_id,
                    'event_id' => $override->event_id,
                    'override_type' => $override->override_type,
                    'target_entity_type' => $override->target_entity_type,
                    'target_entity_id' => $override->target_entity_id,
                    'override_reason' => $override->override_reason,
                    'override_category' => $override->override_category,
                    'override_timestamp' => $override->override_timestamp,
                    'is_emergency' => $override->is_emergency_override,
                    'time_pending' => now()->diffInMinutes($override->override_timestamp),
                    'override_data' => json_decode($override->override_data, true),
                    'context_data' => json_decode($override->context_data, true)
                ];
            })->toArray();

            return [
                'success' => true,
                'supervisor_id' => $supervisorId,
                'pending_approvals' => $pendingApprovals,
                'total_count' => count($pendingApprovals),
                'emergency_count' => collect($pendingApprovals)->where('is_emergency', true)->count()
            ];

        } catch (Exception $e) {
            Log::error('Failed to get pending approvals', [
                'supervisor_id' => $supervisorId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'pending_approvals' => [],
                'error' => 'Failed to retrieve pending approvals'
            ];
        }
    }

    /**
     * Generate override analytics and reporting
     * 
     * @param array $filters Analytics filters
     * @return array Override analytics data
     */
    public function generateOverrideAnalytics(array $filters = []): array
    {
        try {
            $dateFrom = $filters['date_from'] ?? now()->subDays(30);
            $dateTo = $filters['date_to'] ?? now();

            $query = DB::table('staff_overrides')
                ->whereBetween('override_timestamp', [$dateFrom, $dateTo]);

            // Apply additional filters
            if (isset($filters['event_id'])) {
                $query->where('event_id', $filters['event_id']);
            }

            if (isset($filters['staff_id'])) {
                $query->where('staff_id', $filters['staff_id']);
            }

            $overrides = $query->get();

            // Calculate analytics
            $analytics = [
                'total_overrides' => $overrides->count(),
                'approved_overrides' => $overrides->where('approval_status', 'approved')->count(),
                'rejected_overrides' => $overrides->where('approval_status', 'rejected')->count(),
                'pending_overrides' => $overrides->where('approval_status', 'pending')->count(),
                'emergency_overrides' => $overrides->where('is_emergency_override', true)->count(),
                'approval_rate' => $overrides->count() > 0 
                    ? round(($overrides->where('approval_status', 'approved')->count() / $overrides->count()) * 100, 2)
                    : 0,
                'avg_approval_time' => $this->calculateAverageApprovalTime($overrides),
                'overrides_by_type' => $this->groupOverridesByType($overrides),
                'overrides_by_category' => $this->groupOverridesByCategory($overrides),
                'overrides_by_staff' => $this->groupOverridesByStaff($overrides),
                'overrides_by_hour' => $this->groupOverridesByHour($overrides),
                'pattern_alerts' => $this->getPatternAlerts($dateFrom, $dateTo)
            ];

            return [
                'success' => true,
                'period' => [
                    'from' => $dateFrom,
                    'to' => $dateTo
                ],
                'analytics' => $analytics
            ];

        } catch (Exception $e) {
            Log::error('Failed to generate override analytics', [
                'filters' => $filters,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'analytics' => [],
                'error' => 'Failed to generate analytics'
            ];
        }
    }

    // Private helper methods

    private function validateOverrideData(array $data): void
    {
        $required = ['staff_id', 'override_type', 'target_entity_type', 'target_entity_id', 'override_reason', 'override_category'];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }

        // Validate override category
        $validCategories = ['technical_issue', 'customer_service', 'emergency_situation', 'system_error', 'policy_exception', 'security_concern', 'other'];
        if (!in_array($data['override_category'], $validCategories)) {
            throw new Exception("Invalid override category: {$data['override_category']}");
        }
    }

    private function checkStaffOverridePermissions(int $staffId, array $overrideData): array
    {
        // Check if staff has override permissions
        $staffAssignment = DB::table('staff_assignments')
            ->where('staff_id', $staffId)
            ->where('assignment_status', 'active')
            ->where('can_override', true)
            ->first();

        if (!$staffAssignment) {
            return [
                'allowed' => false,
                'reason' => 'Staff member does not have override permissions'
            ];
        }

        // Check rate limiting
        $recentOverrides = DB::table('staff_overrides')
            ->where('staff_id', $staffId)
            ->where('override_timestamp', '>=', now()->subHour())
            ->count();

        if ($recentOverrides >= self::MAX_OVERRIDES_PER_HOUR) {
            return [
                'allowed' => false,
                'reason' => 'Override rate limit exceeded'
            ];
        }

        return ['allowed' => true];
    }

    private function requiresSupervisorApproval(array $overrideData): bool
    {
        // Emergency overrides require approval
        if ($overrideData['is_emergency'] ?? false) {
            return true;
        }

        // High-risk override types require approval
        $highRiskTypes = ['capacity_override', 'security_override', 'financial_adjustment'];
        if (in_array($overrideData['override_type'], $highRiskTypes)) {
            return true;
        }

        // Policy exceptions require approval
        if ($overrideData['override_category'] === 'policy_exception') {
            return true;
        }

        return false;
    }

    private function generateAuditMetadata(array $overrideData): array
    {
        return [
            'user_agent' => request()->header('User-Agent'),
            'session_id' => session()->getId(),
            'request_id' => request()->header('X-Request-ID'),
            'timestamp' => now()->toISOString(),
            'source' => 'operations_console'
        ];
    }

    private function updateOverridePatterns(int $staffId, array $overrideData): void
    {
        // Update pattern tracking in cache
        $patternKey = self::CACHE_PREFIX . "patterns:{$staffId}";
        $patterns = Cache::get($patternKey, []);
        
        $patterns[] = [
            'timestamp' => now(),
            'type' => $overrideData['override_type'],
            'category' => $overrideData['override_category']
        ];

        // Keep only last 24 hours of patterns
        $patterns = array_filter($patterns, function ($pattern) {
            return Carbon::parse($pattern['timestamp'])->isAfter(now()->subHours(24));
        });

        Cache::put($patternKey, $patterns, 1440); // 24 hours
    }

    private function cacheOverride(int $overrideId, array $overrideData): void
    {
        $cacheKey = self::CACHE_PREFIX . "override:{$overrideId}";
        Cache::put($cacheKey, $overrideData, 3600); // 1 hour
    }

    private function validateSupervisorPermissions(int $supervisorId, object $override): array
    {
        $supervisor = DB::table('staff_assignments')
            ->where('staff_id', $supervisorId)
            ->where('assignment_status', 'active')
            ->where('can_approve_overrides', true)
            ->first();

        if (!$supervisor) {
            return [
                'valid' => false,
                'reason' => 'Staff member does not have supervisor approval permissions'
            ];
        }

        // Check if supervisor can approve for this event
        if ($override->event_id && $supervisor->event_id !== $override->event_id) {
            return [
                'valid' => false,
                'reason' => 'Supervisor not assigned to this event'
            ];
        }

        return ['valid' => true];
    }

    private function validateSupervisorRole(int $supervisorId): array
    {
        $supervisor = DB::table('staff_assignments')
            ->where('staff_id', $supervisorId)
            ->where('assignment_status', 'active')
            ->where('is_supervisor', true)
            ->first();

        return [
            'valid' => $supervisor !== null,
            'reason' => $supervisor ? null : 'Staff member is not a supervisor'
        ];
    }

    private function applyApprovedOverride(object $override): array
    {
        // Apply the approved override to the target entity
        // This would integrate with the specific system being overridden
        
        Log::info('Override applied', [
            'override_id' => $override->id,
            'target_entity_type' => $override->target_entity_type,
            'target_entity_id' => $override->target_entity_id
        ]);

        return [
            'applied' => true,
            'timestamp' => now(),
            'result' => 'Override successfully applied'
        ];
    }

    private function updateApprovalPatterns(int $supervisorId, string $decision, object $override): void
    {
        $patternKey = self::CACHE_PREFIX . "approval_patterns:{$supervisorId}";
        $patterns = Cache::get($patternKey, []);
        
        $patterns[] = [
            'timestamp' => now(),
            'decision' => $decision,
            'override_type' => $override->override_type,
            'staff_id' => $override->staff_id
        ];

        Cache::put($patternKey, $patterns, 1440); // 24 hours
    }

    private function detectFrequencyPattern(object $overrides, int $timeWindow): array
    {
        $count = $overrides->count();
        $threshold = self::MAX_OVERRIDES_PER_HOUR * ($timeWindow / 1); // Scale threshold by time window
        
        return [
            'type' => 'frequency',
            'alert' => $count > $threshold,
            'severity' => $count > $threshold * 2 ? 'critical' : 'warning',
            'description' => "Staff member has {$count} overrides in {$timeWindow} hours (threshold: {$threshold})",
            'count' => $count,
            'threshold' => $threshold
        ];
    }

    private function detectTimeBasedPattern(object $overrides): array
    {
        $hourCounts = [];
        foreach ($overrides as $override) {
            $hour = Carbon::parse($override->override_timestamp)->hour;
            $hourCounts[$hour] = ($hourCounts[$hour] ?? 0) + 1;
        }

        $maxHourCount = max($hourCounts ?: [0]);
        $alert = $maxHourCount > 5; // More than 5 overrides in a single hour

        return [
            'type' => 'time_based',
            'alert' => $alert,
            'severity' => $maxHourCount > 10 ? 'critical' : 'warning',
            'description' => "Peak hour has {$maxHourCount} overrides",
            'hour_distribution' => $hourCounts,
            'peak_count' => $maxHourCount
        ];
    }

    private function detectCategoryPattern(object $overrides): array
    {
        $categories = $overrides->pluck('override_category')->countBy();
        $dominantCategory = $categories->keys()->first();
        $dominantCount = $categories->first();
        $totalCount = $overrides->count();
        
        $dominancePercentage = $totalCount > 0 ? ($dominantCount / $totalCount) * 100 : 0;
        $alert = $dominancePercentage > 80; // More than 80% of overrides in one category

        return [
            'type' => 'category_clustering',
            'alert' => $alert,
            'severity' => $dominancePercentage > 90 ? 'critical' : 'warning',
            'description' => "Category '{$dominantCategory}' represents {$dominancePercentage}% of overrides",
            'dominant_category' => $dominantCategory,
            'dominance_percentage' => round($dominancePercentage, 2),
            'category_distribution' => $categories->toArray()
        ];
    }

    private function detectEmergencyPattern(object $overrides): array
    {
        $emergencyCount = $overrides->where('is_emergency_override', true)->count();
        $totalCount = $overrides->count();
        
        $emergencyPercentage = $totalCount > 0 ? ($emergencyCount / $totalCount) * 100 : 0;
        $alert = $emergencyPercentage > 30; // More than 30% emergency overrides

        return [
            'type' => 'emergency_frequency',
            'alert' => $alert,
            'severity' => $emergencyPercentage > 50 ? 'critical' : 'warning',
            'description' => "Emergency overrides represent {$emergencyPercentage}% of total overrides",
            'emergency_count' => $emergencyCount,
            'emergency_percentage' => round($emergencyPercentage, 2)
        ];
    }

    private function generatePatternAlert(int $staffId, array $pattern): void
    {
        // Store pattern alert for monitoring
        DB::table('override_patterns')->updateOrInsert(
            [
                'pattern_name' => "staff_{$staffId}_{$pattern['type']}",
                'pattern_type' => $pattern['type']
            ],
            [
                'pattern_description' => $pattern['description'],
                'pattern_rules' => json_encode($pattern),
                'pattern_thresholds' => json_encode(['alert_threshold' => true]),
                'detection_count' => DB::raw('detection_count + 1'),
                'last_detected_at' => now(),
                'alert_level' => $pattern['severity'],
                'alert_configuration' => json_encode(['notify_supervisors' => true]),
                'updated_at' => now()
            ]
        );
    }

    private function calculateOverallAlertLevel(array $patterns): string
    {
        if (empty($patterns)) {
            return 'info';
        }

        $severities = collect($patterns)->pluck('severity');
        
        if ($severities->contains('critical')) {
            return 'critical';
        } elseif ($severities->contains('warning')) {
            return 'warning';
        }

        return 'info';
    }

    private function calculateAverageApprovalTime(object $overrides): float
    {
        $approvedOverrides = $overrides->whereNotNull('approved_at');
        
        if ($approvedOverrides->isEmpty()) {
            return 0;
        }

        $totalMinutes = $approvedOverrides->sum(function ($override) {
            return Carbon::parse($override->approved_at)->diffInMinutes($override->override_timestamp);
        });

        return round($totalMinutes / $approvedOverrides->count(), 2);
    }

    private function groupOverridesByType(object $overrides): array
    {
        return $overrides->groupBy('override_type')->map->count()->toArray();
    }

    private function groupOverridesByCategory(object $overrides): array
    {
        return $overrides->groupBy('override_category')->map->count()->toArray();
    }

    private function groupOverridesByStaff(object $overrides): array
    {
        return $overrides->groupBy('staff_id')->map->count()->toArray();
    }

    private function groupOverridesByHour(object $overrides): array
    {
        return $overrides->groupBy(function ($override) {
            return Carbon::parse($override->override_timestamp)->hour;
        })->map->count()->toArray();
    }

    private function getPatternAlerts(string $dateFrom, string $dateTo): array
    {
        return DB::table('override_patterns')
            ->whereBetween('last_detected_at', [$dateFrom, $dateTo])
            ->where('is_active', true)
            ->get()
            ->map(function ($pattern) {
                return [
                    'pattern_name' => $pattern->pattern_name,
                    'pattern_type' => $pattern->pattern_type,
                    'description' => $pattern->pattern_description,
                    'detection_count' => $pattern->detection_count,
                    'last_detected' => $pattern->last_detected_at,
                    'alert_level' => $pattern->alert_level
                ];
            })
            ->toArray();
    }
}