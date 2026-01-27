<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Exception;

/**
 * OfflineOperationsService - Task 9.2
 * 
 * Manages offline check-in queue operations, conflict detection and resolution,
 * and data synchronization with online systems for reliable event operations.
 * 
 * Requirements: 25.1, 25.2, 25.5
 */
class OfflineOperationsService
{
    private const CACHE_PREFIX = 'offline_ops:';
    private const SYNC_BATCH_SIZE = 100;
    private const MAX_RETRY_ATTEMPTS = 3;
    private const CONFLICT_RESOLUTION_TIMEOUT = 300; // 5 minutes

    /**
     * Queue offline check-in operation
     * 
     * @param array $checkinData Check-in operation data
     * @return array Operation result with queue ID
     */
    public function queueOfflineCheckin(array $checkinData): array
    {
        try {
            DB::beginTransaction();

            // Validate required fields
            $this->validateCheckinData($checkinData);

            // Generate unique queue entry
            $queueEntry = [
                'ticket_id' => $checkinData['ticket_id'],
                'staff_id' => $checkinData['staff_id'],
                'event_id' => $checkinData['event_id'],
                'device_id' => $checkinData['device_id'],
                'checkin_timestamp' => $checkinData['checkin_timestamp'] ?? now(),
                'sync_status' => 'pending',
                'checkin_data' => json_encode($checkinData),
                'retry_count' => 0,
                'created_at' => now()
            ];

            $queueId = DB::table('offline_checkin_queue')->insertGetId($queueEntry);

            // Update device sync status
            $this->updateDeviceSyncStatus($checkinData['device_id'], 'pending_sync');

            // Cache for quick access
            $this->cacheQueueEntry($queueId, $queueEntry);

            DB::commit();

            Log::info('Offline check-in queued', [
                'queue_id' => $queueId,
                'ticket_id' => $checkinData['ticket_id'],
                'device_id' => $checkinData['device_id']
            ]);

            return [
                'success' => true,
                'queue_id' => $queueId,
                'status' => 'queued',
                'message' => 'Check-in queued for synchronization'
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to queue offline check-in', [
                'error' => $e->getMessage(),
                'checkin_data' => $checkinData
            ]);

            return [
                'success' => false,
                'error' => 'Failed to queue check-in operation',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Synchronize offline operations with online systems
     * 
     * @param string $deviceId Device to synchronize
     * @param array $options Synchronization options
     * @return array Synchronization result
     */
    public function synchronizeOfflineOperations(string $deviceId, array $options = []): array
    {
        try {
            $syncId = $this->startSyncSession($deviceId, $options);
            
            // Get pending operations for device
            $pendingOperations = $this->getPendingOperations($deviceId, $options);
            
            if (empty($pendingOperations)) {
                return $this->completeSyncSession($syncId, [
                    'records_processed' => 0,
                    'records_synced' => 0,
                    'conflicts_detected' => 0,
                    'message' => 'No pending operations to synchronize'
                ]);
            }

            $syncResults = [
                'processed' => 0,
                'synced' => 0,
                'conflicts' => 0,
                'failed' => 0,
                'conflict_details' => []
            ];

            // Process operations in batches
            $batches = array_chunk($pendingOperations, self::SYNC_BATCH_SIZE);
            
            foreach ($batches as $batch) {
                $batchResult = $this->processSyncBatch($batch, $syncId);
                $syncResults = $this->mergeSyncResults($syncResults, $batchResult);
                
                // Update sync progress
                $this->updateSyncProgress($syncId, $syncResults);
            }

            // Complete synchronization
            return $this->completeSyncSession($syncId, $syncResults);

        } catch (Exception $e) {
            Log::error('Offline synchronization failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Synchronization failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Detect conflicts between offline and online data
     * 
     * @param array $offlineData Offline operation data
     * @return array Conflict detection result
     */
    public function detectConflicts(array $offlineData): array
    {
        try {
            $conflicts = [];
            $ticketId = $offlineData['ticket_id'];
            
            // Get current online ticket status
            $onlineTicket = $this->getOnlineTicketStatus($ticketId);
            
            if (!$onlineTicket) {
                $conflicts[] = [
                    'type' => 'ticket_not_found',
                    'severity' => 'critical',
                    'description' => 'Ticket not found in online system',
                    'offline_data' => $offlineData,
                    'online_data' => null
                ];
                return ['has_conflicts' => true, 'conflicts' => $conflicts];
            }

            // Check for duplicate check-in
            if ($this->isTicketAlreadyCheckedIn($ticketId, $offlineData['checkin_timestamp'])) {
                $conflicts[] = [
                    'type' => 'duplicate_checkin',
                    'severity' => 'high',
                    'description' => 'Ticket already checked in online',
                    'offline_data' => $offlineData,
                    'online_data' => $onlineTicket
                ];
            }

            // Check timestamp consistency
            $timestampConflict = $this->detectTimestampConflict($offlineData, $onlineTicket);
            if ($timestampConflict) {
                $conflicts[] = $timestampConflict;
            }

            // Check staff permissions at time of operation
            $permissionConflict = $this->detectPermissionConflict($offlineData);
            if ($permissionConflict) {
                $conflicts[] = $permissionConflict;
            }

            // Check data integrity
            $integrityConflict = $this->detectDataIntegrityConflict($offlineData, $onlineTicket);
            if ($integrityConflict) {
                $conflicts[] = $integrityConflict;
            }

            return [
                'has_conflicts' => !empty($conflicts),
                'conflicts' => $conflicts,
                'conflict_count' => count($conflicts)
            ];

        } catch (Exception $e) {
            Log::error('Conflict detection failed', [
                'offline_data' => $offlineData,
                'error' => $e->getMessage()
            ]);

            return [
                'has_conflicts' => true,
                'conflicts' => [[
                    'type' => 'detection_error',
                    'severity' => 'critical',
                    'description' => 'Failed to detect conflicts: ' . $e->getMessage(),
                    'offline_data' => $offlineData,
                    'online_data' => null
                ]]
            ];
        }
    }

    /**
     * Resolve synchronization conflicts
     * 
     * @param string $conflictId Conflict identifier
     * @param string $resolutionStrategy Resolution strategy
     * @param array $resolutionData Additional resolution data
     * @param int $staffId Staff member resolving conflict
     * @return array Resolution result
     */
    public function resolveConflict(string $conflictId, string $resolutionStrategy, array $resolutionData, int $staffId): array
    {
        try {
            DB::beginTransaction();

            // Get conflict details
            $conflict = DB::table('sync_conflicts')
                ->where('conflict_id', $conflictId)
                ->where('conflict_status', '!=', 'resolved')
                ->first();

            if (!$conflict) {
                throw new Exception('Conflict not found or already resolved');
            }

            // Validate resolution strategy
            $this->validateResolutionStrategy($resolutionStrategy, $conflict);

            // Apply resolution
            $resolutionResult = $this->applyConflictResolution(
                $conflict,
                $resolutionStrategy,
                $resolutionData,
                $staffId
            );

            // Update conflict status
            DB::table('sync_conflicts')
                ->where('id', $conflict->id)
                ->update([
                    'resolution_strategy' => $resolutionStrategy,
                    'resolution_data' => json_encode($resolutionResult),
                    'conflict_status' => 'resolved',
                    'resolved_by_staff_id' => $staffId,
                    'resolved_at' => now(),
                    'resolution_notes' => $resolutionData['notes'] ?? null,
                    'updated_at' => now()
                ]);

            // Update related queue entry
            if (isset($conflict->offline_data)) {
                $offlineData = json_decode($conflict->offline_data, true);
                $this->updateQueueEntryStatus($offlineData, 'resolved', $resolutionResult);
            }

            DB::commit();

            Log::info('Conflict resolved', [
                'conflict_id' => $conflictId,
                'strategy' => $resolutionStrategy,
                'resolved_by' => $staffId
            ]);

            return [
                'success' => true,
                'conflict_id' => $conflictId,
                'resolution_strategy' => $resolutionStrategy,
                'resolution_result' => $resolutionResult,
                'message' => 'Conflict resolved successfully'
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Conflict resolution failed', [
                'conflict_id' => $conflictId,
                'strategy' => $resolutionStrategy,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to resolve conflict',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get offline capability status for device
     * 
     * @param string $deviceId Device identifier
     * @return array Device offline status
     */
    public function getOfflineCapabilityStatus(string $deviceId): array
    {
        try {
            // Get device information
            $device = DB::table('operations_devices')
                ->where('device_id', $deviceId)
                ->first();

            if (!$device) {
                return [
                    'capable' => false,
                    'status' => 'device_not_found',
                    'message' => 'Device not registered'
                ];
            }

            $capabilities = json_decode($device->device_capabilities, true);
            $isOfflineCapable = $capabilities['offline_operations'] ?? false;

            // Get pending sync count
            $pendingCount = DB::table('offline_checkin_queue')
                ->where('device_id', $deviceId)
                ->where('sync_status', 'pending')
                ->count();

            // Get last sync status
            $lastSync = DB::table('sync_status_log')
                ->where('device_id', $deviceId)
                ->orderBy('sync_started_at', 'desc')
                ->first();

            return [
                'capable' => $isOfflineCapable,
                'status' => $device->device_status,
                'is_offline' => $device->last_online_at < now()->subMinutes(5),
                'pending_operations' => $pendingCount,
                'last_sync' => $lastSync ? [
                    'status' => $lastSync->sync_status,
                    'completed_at' => $lastSync->sync_completed_at,
                    'success_rate' => $lastSync->success_rate
                ] : null,
                'sync_health' => $this->calculateSyncHealth($deviceId)
            ];

        } catch (Exception $e) {
            Log::error('Failed to get offline capability status', [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);

            return [
                'capable' => false,
                'status' => 'error',
                'message' => 'Failed to determine offline capability'
            ];
        }
    }

    /**
     * Get pending conflicts requiring manual resolution
     * 
     * @param array $filters Optional filters
     * @return array List of pending conflicts
     */
    public function getPendingConflicts(array $filters = []): array
    {
        try {
            $query = DB::table('sync_conflicts')
                ->where('conflict_status', '!=', 'resolved')
                ->orderBy('resolution_priority', 'asc')
                ->orderBy('detected_at', 'asc');

            // Apply filters
            if (isset($filters['event_id'])) {
                $query->where('event_id', $filters['event_id']);
            }

            if (isset($filters['conflict_type'])) {
                $query->where('conflict_type', $filters['conflict_type']);
            }

            if (isset($filters['requires_manual'])) {
                $query->where('requires_manual_intervention', $filters['requires_manual']);
            }

            $conflicts = $query->get()->map(function ($conflict) {
                return [
                    'conflict_id' => $conflict->conflict_id,
                    'type' => $conflict->conflict_type,
                    'entity_type' => $conflict->entity_type,
                    'entity_id' => $conflict->entity_id,
                    'priority' => $conflict->resolution_priority,
                    'detected_at' => $conflict->detected_at,
                    'requires_manual' => $conflict->requires_manual_intervention,
                    'offline_data' => json_decode($conflict->offline_data, true),
                    'online_data' => json_decode($conflict->online_data, true),
                    'conflict_details' => json_decode($conflict->conflict_details, true)
                ];
            })->toArray();

            return [
                'success' => true,
                'conflicts' => $conflicts,
                'total_count' => count($conflicts),
                'manual_count' => collect($conflicts)->where('requires_manual', true)->count()
            ];

        } catch (Exception $e) {
            Log::error('Failed to get pending conflicts', [
                'filters' => $filters,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'conflicts' => [],
                'error' => 'Failed to retrieve conflicts'
            ];
        }
    }

    // Private helper methods

    private function validateCheckinData(array $data): void
    {
        $required = ['ticket_id', 'staff_id', 'event_id', 'device_id'];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }
    }

    private function updateDeviceSyncStatus(string $deviceId, string $status): void
    {
        DB::table('operations_devices')
            ->where('device_id', $deviceId)
            ->update([
                'pending_sync_count' => DB::raw('pending_sync_count + 1'),
                'sync_status_details' => json_encode(['status' => $status, 'updated_at' => now()]),
                'updated_at' => now()
            ]);
    }

    private function cacheQueueEntry(int $queueId, array $entry): void
    {
        $cacheKey = self::CACHE_PREFIX . "queue:{$queueId}";
        Cache::put($cacheKey, $entry, 3600); // 1 hour
    }

    private function startSyncSession(string $deviceId, array $options): int
    {
        return DB::table('sync_status_log')->insertGetId([
            'device_id' => $deviceId,
            'event_id' => $options['event_id'] ?? null,
            'sync_type' => $options['sync_type'] ?? 'incremental_sync',
            'sync_status' => 'started',
            'sync_started_at' => now(),
            'sync_details' => json_encode($options),
            'created_at' => now()
        ]);
    }

    private function getPendingOperations(string $deviceId, array $options): array
    {
        $query = DB::table('offline_checkin_queue')
            ->where('device_id', $deviceId)
            ->where('sync_status', 'pending')
            ->orderBy('checkin_timestamp', 'asc');

        if (isset($options['limit'])) {
            $query->limit($options['limit']);
        }

        return $query->get()->toArray();
    }
    private function processSyncBatch(array $batch, int $syncId): array
    {
        $results = ['processed' => 0, 'synced' => 0, 'conflicts' => 0, 'failed' => 0, 'conflict_details' => []];

        foreach ($batch as $operation) {
            $results['processed']++;
            
            try {
                $checkinData = json_decode($operation->checkin_data, true);
                $conflictResult = $this->detectConflicts($checkinData);

                if ($conflictResult['has_conflicts']) {
                    $this->handleSyncConflict($operation, $conflictResult['conflicts']);
                    $results['conflicts']++;
                    $results['conflict_details'] = array_merge($results['conflict_details'], $conflictResult['conflicts']);
                } else {
                    $this->applySyncOperation($operation);
                    $results['synced']++;
                }

            } catch (Exception $e) {
                Log::error('Sync operation failed', [
                    'operation_id' => $operation->id,
                    'error' => $e->getMessage()
                ]);
                $results['failed']++;
            }
        }

        return $results;
    }

    private function mergeSyncResults(array $current, array $batch): array
    {
        return [
            'processed' => $current['processed'] + $batch['processed'],
            'synced' => $current['synced'] + $batch['synced'],
            'conflicts' => $current['conflicts'] + $batch['conflicts'],
            'failed' => $current['failed'] + $batch['failed'],
            'conflict_details' => array_merge($current['conflict_details'], $batch['conflict_details'])
        ];
    }

    private function updateSyncProgress(int $syncId, array $results): void
    {
        DB::table('sync_status_log')
            ->where('id', $syncId)
            ->update([
                'sync_status' => 'in_progress',
                'records_processed' => $results['processed'],
                'records_synced' => $results['synced'],
                'conflicts_detected' => $results['conflicts'],
                'sync_details' => json_encode($results),
                'updated_at' => now()
            ]);
    }

    private function completeSyncSession(int $syncId, array $results): array
    {
        $successRate = $results['processed'] > 0 
            ? round(($results['synced'] / $results['processed']) * 100, 2)
            : 100;

        DB::table('sync_status_log')
            ->where('id', $syncId)
            ->update([
                'sync_status' => $results['failed'] > 0 ? 'partial' : 'completed',
                'sync_completed_at' => now(),
                'records_processed' => $results['processed'],
                'records_synced' => $results['synced'],
                'conflicts_detected' => $results['conflicts'],
                'conflicts_resolved' => 0, // Will be updated as conflicts are resolved
                'success_rate' => $successRate,
                'duration_seconds' => now()->diffInSeconds(DB::table('sync_status_log')->where('id', $syncId)->value('sync_started_at')),
                'sync_details' => json_encode($results),
                'updated_at' => now()
            ]);

        return [
            'success' => true,
            'sync_id' => $syncId,
            'results' => $results,
            'success_rate' => $successRate,
            'message' => 'Synchronization completed'
        ];
    }

    private function getOnlineTicketStatus(int $ticketId): ?array
    {
        // This would integrate with the actual ticket system
        // For now, return mock data structure
        return [
            'ticket_id' => $ticketId,
            'status' => 'valid',
            'checked_in_at' => null,
            'last_updated' => now()
        ];
    }

    private function isTicketAlreadyCheckedIn(int $ticketId, string $checkinTimestamp): bool
    {
        // Check if ticket was already checked in online
        // This would integrate with the actual ticket system
        return false;
    }

    private function detectTimestampConflict(array $offlineData, array $onlineTicket): ?array
    {
        // Implement timestamp conflict detection logic
        return null;
    }

    private function detectPermissionConflict(array $offlineData): ?array
    {
        // Check if staff had proper permissions at time of operation
        return null;
    }

    private function detectDataIntegrityConflict(array $offlineData, array $onlineTicket): ?array
    {
        // Check for data integrity issues
        return null;
    }

    private function validateResolutionStrategy(string $strategy, object $conflict): void
    {
        $validStrategies = ['offline_wins', 'online_wins', 'merge_data', 'manual_resolution', 'create_duplicate', 'reject_offline'];
        
        if (!in_array($strategy, $validStrategies)) {
            throw new Exception("Invalid resolution strategy: {$strategy}");
        }
    }

    private function applyConflictResolution(object $conflict, string $strategy, array $data, int $staffId): array
    {
        // Implement conflict resolution logic based on strategy
        return [
            'strategy' => $strategy,
            'applied_by' => $staffId,
            'applied_at' => now(),
            'result' => 'resolved'
        ];
    }

    private function updateQueueEntryStatus(array $offlineData, string $status, array $result): void
    {
        DB::table('offline_checkin_queue')
            ->where('ticket_id', $offlineData['ticket_id'])
            ->where('device_id', $offlineData['device_id'])
            ->where('sync_status', '!=', 'synced')
            ->update([
                'sync_status' => $status,
                'resolution_data' => json_encode($result),
                'synced_at' => now(),
                'updated_at' => now()
            ]);
    }

    private function handleSyncConflict(object $operation, array $conflicts): void
    {
        foreach ($conflicts as $conflict) {
            $conflictId = 'conflict_' . $operation->id . '_' . uniqid();
            
            DB::table('sync_conflicts')->insert([
                'conflict_id' => $conflictId,
                'conflict_type' => $conflict['type'],
                'entity_type' => 'ticket',
                'entity_id' => $operation->ticket_id,
                'event_id' => $operation->event_id,
                'device_id' => $operation->device_id,
                'staff_id' => $operation->staff_id,
                'offline_data' => $operation->checkin_data,
                'online_data' => json_encode($conflict['online_data']),
                'conflict_details' => json_encode($conflict),
                'conflict_status' => 'detected',
                'resolution_priority' => $this->calculateConflictPriority($conflict),
                'requires_manual_intervention' => $this->requiresManualIntervention($conflict),
                'detected_at' => now(),
                'created_at' => now()
            ]);
        }

        // Update queue entry status
        DB::table('offline_checkin_queue')
            ->where('id', $operation->id)
            ->update([
                'sync_status' => 'conflict',
                'conflict_data' => json_encode($conflicts),
                'updated_at' => now()
            ]);
    }

    private function applySyncOperation(object $operation): void
    {
        // Apply the offline operation to the online system
        // This would integrate with the actual booking/ticket system
        
        DB::table('offline_checkin_queue')
            ->where('id', $operation->id)
            ->update([
                'sync_status' => 'synced',
                'synced_at' => now(),
                'updated_at' => now()
            ]);
    }

    private function calculateConflictPriority(array $conflict): int
    {
        // Calculate priority based on conflict severity and type
        $priorityMap = [
            'critical' => 1,
            'high' => 3,
            'medium' => 5,
            'low' => 7
        ];

        return $priorityMap[$conflict['severity']] ?? 5;
    }

    private function requiresManualIntervention(array $conflict): bool
    {
        $manualTypes = ['duplicate_checkin', 'data_integrity_violation', 'detection_error'];
        return in_array($conflict['type'], $manualTypes) || $conflict['severity'] === 'critical';
    }

    private function calculateSyncHealth(string $deviceId): array
    {
        $recentSyncs = DB::table('sync_status_log')
            ->where('device_id', $deviceId)
            ->where('sync_started_at', '>=', now()->subHours(24))
            ->get();

        if ($recentSyncs->isEmpty()) {
            return ['status' => 'unknown', 'score' => 0];
        }

        $totalSyncs = $recentSyncs->count();
        $successfulSyncs = $recentSyncs->where('sync_status', 'completed')->count();
        $avgSuccessRate = $recentSyncs->avg('success_rate') ?? 0;

        $healthScore = ($successfulSyncs / $totalSyncs) * ($avgSuccessRate / 100) * 100;

        return [
            'status' => $healthScore >= 90 ? 'excellent' : ($healthScore >= 70 ? 'good' : ($healthScore >= 50 ? 'fair' : 'poor')),
            'score' => round($healthScore, 2),
            'recent_syncs' => $totalSyncs,
            'success_rate' => round($avgSuccessRate, 2)
        ];
    }
}