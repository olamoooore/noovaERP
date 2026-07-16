<?php

namespace Modules\EventManagement\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DataHealthCheckService
{
    private const CACHE_PREFIX = 'data_health_check:';

    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Run automated data consistency validation
     */
    public function runDataHealthChecks(): array
    {
        $results = [];

        try {
            // Run all registered health checks
            $results['booking_analytics_reconciliation'] = $this->checkBookingAnalyticsReconciliation();
            $results['capacity_consistency'] = $this->checkCapacityConsistency();
            $results['pii_vault_integrity'] = $this->checkPIIVaultIntegrity();
            $results['outbox_processing_health'] = $this->checkOutboxProcessingHealth();
            $results['loyalty_points_integrity'] = $this->checkLoyaltyPointsIntegrity();
            $results['artist_relationship_consistency'] = $this->checkArtistRelationshipConsistency();

            // Store results in database
            $this->storeHealthCheckResults($results);

            // Generate alerts for failed checks
            $this->generateHealthCheckAlerts($results);

            return $results;

        } catch (Exception $e) {
            Log::error('Data health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Check reconciliation between booking data and analytics
     */
    private function checkBookingAnalyticsReconciliation(): array
    {
        $checkName = 'booking_analytics_reconciliation';

        try {
            // Compare booking counts between source and analytics
            $sourceCount = DB::table('bookings')
                ->where('status', 'confirmed')
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->count();

            $analyticsCount = DB::table('analytics_facts')
                ->where('fact_type', 'booking_created')
                ->where('fact_timestamp', '>=', Carbon::now()->subDays(7))
                ->count();

            $discrepancy = abs($sourceCount - $analyticsCount);
            $discrepancyPercentage = $sourceCount > 0 ? ($discrepancy / $sourceCount) * 100 : 0;

            // Check revenue reconciliation
            $sourceRevenue = DB::table('bookings')
                ->join('booking_items', 'bookings.id', '=', 'booking_items.booking_id')
                ->where('bookings.status', 'confirmed')
                ->where('bookings.created_at', '>=', Carbon::now()->subDays(7))
                ->sum(DB::raw('booking_items.quantity * booking_items.unit_price'));

            $analyticsRevenue = DB::table('analytics_facts')
                ->where('fact_type', 'booking_created')
                ->where('fact_timestamp', '>=', Carbon::now()->subDays(7))
                ->sum(DB::raw("JSON_EXTRACT(calculated_metrics, '$.revenue')"));

            $revenueDifference = abs($sourceRevenue - $analyticsRevenue);
            $revenueDiscrepancyPercentage = $sourceRevenue > 0 ? ($revenueDifference / $sourceRevenue) * 100 : 0;

            $status = ($discrepancyPercentage <= 1.0 && $revenueDiscrepancyPercentage <= 1.0) ? 'passed' :
                (($discrepancyPercentage <= 5.0 && $revenueDiscrepancyPercentage <= 5.0) ? 'warning' : 'failed');

            $result = [
                'check_name' => $checkName,
                'check_type' => 'reconciliation',
                'status' => $status,
                'expected_result' => [
                    'booking_count' => $sourceCount,
                    'revenue_total' => $sourceRevenue,
                ],
                'actual_result' => [
                    'analytics_count' => $analyticsCount,
                    'analytics_revenue' => $analyticsRevenue,
                    'count_discrepancy_percentage' => $discrepancyPercentage,
                    'revenue_discrepancy_percentage' => $revenueDiscrepancyPercentage,
                ],
                'checked_at' => Carbon::now(),
            ];

            return $result;

        } catch (Exception $e) {
            return [
                'check_name' => $checkName,
                'check_type' => 'reconciliation',
                'status' => 'failed',
                'error' => $e->getMessage(),
                'checked_at' => Carbon::now(),
            ];
        }
    }

    /**
     * Check capacity consistency across events and reservations
     */
    private function checkCapacityConsistency(): array
    {
        $checkName = 'capacity_consistency';

        try {
            $inconsistentEvents = DB::select("
                SELECT 
                    e.id,
                    e.max_capacity,
                    e.reserved_capacity,
                    COALESCE(SUM(CASE WHEN cr.status = 'active' THEN cr.quantity ELSE 0 END), 0) as active_reservations,
                    COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN bi.quantity ELSE 0 END), 0) as confirmed_bookings
                FROM events e
                LEFT JOIN capacity_reservations cr ON e.id = cr.event_id
                LEFT JOIN bookings b ON e.id = b.event_id
                LEFT JOIN booking_items bi ON b.id = bi.booking_id
                WHERE e.status = 'active'
                GROUP BY e.id, e.max_capacity, e.reserved_capacity
                HAVING 
                    (confirmed_bookings + active_reservations) > e.max_capacity
                    OR e.reserved_capacity != active_reservations
            ");

            $status = empty($inconsistentEvents) ? 'passed' : 'failed';

            return [
                'check_name' => $checkName,
                'check_type' => 'consistency',
                'status' => $status,
                'expected_result' => ['inconsistent_events' => 0],
                'actual_result' => [
                    'inconsistent_events' => count($inconsistentEvents),
                    'details' => $inconsistentEvents,
                ],
                'checked_at' => Carbon::now(),
            ];

        } catch (Exception $e) {
            return [
                'check_name' => $checkName,
                'check_type' => 'consistency',
                'status' => 'failed',
                'error' => $e->getMessage(),
                'checked_at' => Carbon::now(),
            ];
        }
    }

    /**
     * Check PII vault integrity and access logging
     */
    private function checkPIIVaultIntegrity(): array
    {
        $checkName = 'pii_vault_integrity';

        try {
            // Check for unencrypted PII data
            $unencryptedPII = DB::table('pii_vault')
                ->where('encrypted_value', 'LIKE', '%{%')
                ->orWhere('encrypted_value', 'NOT LIKE', '%:%')
                ->count();

            // Check for missing access logs for recent access
            $recentAccessWithoutLogs = DB::select('
                SELECT COUNT(*) as missing_logs
                FROM pii_vault pv
                WHERE pv.created_at >= ?
                AND NOT EXISTS (
                    SELECT 1 FROM pii_access_log pal 
                    WHERE pal.vault_id = pv.id
                )
            ', [Carbon::now()->subHours(24)]);

            $missingLogs = $recentAccessWithoutLogs[0]->missing_logs ?? 0;

            // Check for expired data that should be anonymized
            $expiredUnanonymized = DB::table('pii_vault')
                ->where('expires_at', '<', Carbon::now())
                ->whereNull('anonymized_at')
                ->count();

            $status = ($unencryptedPII === 0 && $missingLogs === 0 && $expiredUnanonymized === 0) ? 'passed' :
                (($unencryptedPII === 0 && $missingLogs < 10 && $expiredUnanonymized < 5) ? 'warning' : 'failed');

            return [
                'check_name' => $checkName,
                'check_type' => 'consistency',
                'status' => $status,
                'expected_result' => [
                    'unencrypted_pii' => 0,
                    'missing_access_logs' => 0,
                    'expired_unanonymized' => 0,
                ],
                'actual_result' => [
                    'unencrypted_pii' => $unencryptedPII,
                    'missing_access_logs' => $missingLogs,
                    'expired_unanonymized' => $expiredUnanonymized,
                ],
                'checked_at' => Carbon::now(),
            ];

        } catch (Exception $e) {
            return [
                'check_name' => $checkName,
                'check_type' => 'consistency',
                'status' => 'failed',
                'error' => $e->getMessage(),
                'checked_at' => Carbon::now(),
            ];
        }
    }

    /**
     * Check outbox event processing health
     */
    private function checkOutboxProcessingHealth(): array
    {
        $checkName = 'outbox_processing_health';

        try {
            // Check for stuck events
            $stuckEvents = DB::table('outbox_events')
                ->where('status', 'processing')
                ->where('created_at', '<', Carbon::now()->subMinutes(30))
                ->count();

            // Check for failed events exceeding retry limit
            $failedEvents = DB::table('outbox_events')
                ->where('status', 'failed')
                ->where('retry_count', '>=', DB::raw('max_retries'))
                ->count();

            // Check processing lag
            $oldestPending = DB::table('outbox_events')
                ->where('status', 'pending')
                ->min('created_at');

            $processingLagMinutes = $oldestPending ?
                Carbon::parse($oldestPending)->diffInMinutes(Carbon::now()) : 0;

            $status = ($stuckEvents === 0 && $failedEvents === 0 && $processingLagMinutes < 5) ? 'passed' :
                (($stuckEvents < 5 && $failedEvents < 10 && $processingLagMinutes < 15) ? 'warning' : 'failed');

            return [
                'check_name' => $checkName,
                'check_type' => 'completeness',
                'status' => $status,
                'expected_result' => [
                    'stuck_events' => 0,
                    'failed_events' => 0,
                    'processing_lag_minutes' => 0,
                ],
                'actual_result' => [
                    'stuck_events' => $stuckEvents,
                    'failed_events' => $failedEvents,
                    'processing_lag_minutes' => $processingLagMinutes,
                ],
                'checked_at' => Carbon::now(),
            ];

        } catch (Exception $e) {
            return [
                'check_name' => $checkName,
                'check_type' => 'completeness',
                'status' => 'failed',
                'error' => $e->getMessage(),
                'checked_at' => Carbon::now(),
            ];
        }
    }

    /**
     * Check loyalty points ledger integrity
     */
    private function checkLoyaltyPointsIntegrity(): array
    {
        $checkName = 'loyalty_points_integrity';

        try {
            // Check for balance inconsistencies
            $inconsistentBalances = DB::select('
                SELECT 
                    ct.customer_id,
                    ct.current_points,
                    COALESCE(SUM(ll.points_delta), 0) as ledger_balance
                FROM customer_tiers ct
                LEFT JOIN loyalty_ledger ll ON ct.customer_id = ll.customer_id
                GROUP BY ct.customer_id, ct.current_points
                HAVING ct.current_points != ledger_balance
                LIMIT 100
            ');

            // Check for duplicate transactions
            $duplicateTransactions = DB::table('loyalty_ledger')
                ->select('idempotency_key')
                ->groupBy('idempotency_key')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            $status = (empty($inconsistentBalances) && $duplicateTransactions === 0) ? 'passed' :
                ((count($inconsistentBalances) < 10 && $duplicateTransactions < 5) ? 'warning' : 'failed');

            return [
                'check_name' => $checkName,
                'check_type' => 'accuracy',
                'status' => $status,
                'expected_result' => [
                    'inconsistent_balances' => 0,
                    'duplicate_transactions' => 0,
                ],
                'actual_result' => [
                    'inconsistent_balances' => count($inconsistentBalances),
                    'duplicate_transactions' => $duplicateTransactions,
                    'balance_details' => array_slice($inconsistentBalances, 0, 10),
                ],
                'checked_at' => Carbon::now(),
            ];

        } catch (Exception $e) {
            return [
                'check_name' => $checkName,
                'check_type' => 'accuracy',
                'status' => 'failed',
                'error' => $e->getMessage(),
                'checked_at' => Carbon::now(),
            ];
        }
    }

    /**
     * Check artist relationship consistency
     */
    private function checkArtistRelationshipConsistency(): array
    {
        $checkName = 'artist_relationship_consistency';

        try {
            // Check for orphaned artist events
            $orphanedRelations = DB::table('artist_events')
                ->leftJoin('artists', 'artist_events.artist_id', '=', 'artists.id')
                ->leftJoin('events', 'artist_events.event_id', '=', 'events.id')
                ->whereNull('artists.id')
                ->orWhereNull('events.id')
                ->count();

            // Check for missing canonical references
            $missingCanonical = DB::table('artists')
                ->leftJoin('artist_canonical', 'artists.id', '=', 'artist_canonical.master_record_id')
                ->whereNull('artist_canonical.id')
                ->count();

            $status = ($orphanedRelations === 0 && $missingCanonical === 0) ? 'passed' :
                (($orphanedRelations < 5 && $missingCanonical < 10) ? 'warning' : 'failed');

            return [
                'check_name' => $checkName,
                'check_type' => 'consistency',
                'status' => $status,
                'expected_result' => [
                    'orphaned_relations' => 0,
                    'missing_canonical' => 0,
                ],
                'actual_result' => [
                    'orphaned_relations' => $orphanedRelations,
                    'missing_canonical' => $missingCanonical,
                ],
                'checked_at' => Carbon::now(),
            ];

        } catch (Exception $e) {
            return [
                'check_name' => $checkName,
                'check_type' => 'consistency',
                'status' => 'failed',
                'error' => $e->getMessage(),
                'checked_at' => Carbon::now(),
            ];
        }
    }

    /**
     * Store health check results in database
     */
    private function storeHealthCheckResults(array $results): void
    {
        foreach ($results as $result) {
            DB::table('data_health_checks')->insert([
                'check_name' => $result['check_name'],
                'check_type' => $result['check_type'],
                'target_table' => $this->getTargetTableForCheck($result['check_name']),
                'check_query' => $this->getCheckQueryForCheck($result['check_name']),
                'expected_result' => json_encode($result['expected_result'] ?? null),
                'actual_result' => json_encode($result['actual_result'] ?? null),
                'status' => $result['status'],
                'checked_at' => $result['checked_at'],
            ]);
        }
    }

    /**
     * Generate alerts for failed health checks
     */
    private function generateHealthCheckAlerts(array $results): void
    {
        foreach ($results as $result) {
            if ($result['status'] === 'failed') {
                Log::error('Data health check failed', [
                    'check_name' => $result['check_name'],
                    'check_type' => $result['check_type'],
                    'expected' => $result['expected_result'] ?? null,
                    'actual' => $result['actual_result'] ?? null,
                    'error' => $result['error'] ?? null,
                ]);

                // TODO: Integrate with alerting system (Slack, email, etc.)
            } elseif ($result['status'] === 'warning') {
                Log::warning('Data health check warning', [
                    'check_name' => $result['check_name'],
                    'check_type' => $result['check_type'],
                    'expected' => $result['expected_result'] ?? null,
                    'actual' => $result['actual_result'] ?? null,
                ]);
            }
        }
    }

    /**
     * Get target table for a specific check
     */
    private function getTargetTableForCheck(string $checkName): string
    {
        $mapping = [
            'booking_analytics_reconciliation' => 'bookings,analytics_facts',
            'capacity_consistency' => 'events,capacity_reservations',
            'pii_vault_integrity' => 'pii_vault',
            'outbox_processing_health' => 'outbox_events',
            'loyalty_points_integrity' => 'customer_tiers,loyalty_ledger',
            'artist_relationship_consistency' => 'artists,artist_events',
        ];

        return $mapping[$checkName] ?? 'unknown';
    }

    /**
     * Get check query description for a specific check
     */
    private function getCheckQueryForCheck(string $checkName): string
    {
        $mapping = [
            'booking_analytics_reconciliation' => 'Compare booking counts and revenue between source and analytics tables',
            'capacity_consistency' => 'Verify capacity constraints and reservation consistency',
            'pii_vault_integrity' => 'Check PII encryption, access logging, and retention compliance',
            'outbox_processing_health' => 'Monitor outbox event processing status and lag',
            'loyalty_points_integrity' => 'Validate loyalty points balance consistency with ledger',
            'artist_relationship_consistency' => 'Check artist-event relationship integrity and canonical references',
        ];

        return $mapping[$checkName] ?? 'Unknown check query';
    }

    /**
     * Get KPI definition registry with version management
     */
    public function getKPIDefinitions(): array
    {
        return Cache::remember(self::CACHE_PREFIX.'kpi_definitions', self::CACHE_TTL, function () {
            return DB::table('kpi_definitions')
                ->where('is_active', true)
                ->orderBy('kpi_name')
                ->get()
                ->toArray();
        });
    }

    /**
     * Register a new KPI definition
     */
    public function registerKPIDefinition(array $kpiData): int
    {
        $kpiId = DB::table('kpi_definitions')->insertGetId([
            'kpi_name' => $kpiData['kpi_name'],
            'kpi_description' => $kpiData['kpi_description'],
            'calculation_logic' => json_encode($kpiData['calculation_logic']),
            'data_sources' => json_encode($kpiData['data_sources']),
            'refresh_frequency' => $kpiData['refresh_frequency'],
            'version' => $kpiData['version'] ?? 1,
            'is_active' => $kpiData['is_active'] ?? true,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Clear cache
        Cache::forget(self::CACHE_PREFIX.'kpi_definitions');

        return $kpiId;
    }

    /**
     * Get recent health check results
     */
    public function getRecentHealthCheckResults(int $hours = 24): array
    {
        return DB::table('data_health_checks')
            ->where('checked_at', '>=', Carbon::now()->subHours($hours))
            ->orderBy('checked_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get health check summary
     */
    public function getHealthCheckSummary(): array
    {
        $recent = $this->getRecentHealthCheckResults(24);

        $summary = [
            'total_checks' => count($recent),
            'passed' => 0,
            'warnings' => 0,
            'failed' => 0,
            'last_run' => null,
        ];

        foreach ($recent as $check) {
            switch ($check->status) {
                case 'passed':
                    $summary['passed']++;
                    break;
                case 'warning':
                    $summary['warnings']++;
                    break;
                case 'failed':
                    $summary['failed']++;
                    break;
            }

            if (! $summary['last_run'] || $check->checked_at > $summary['last_run']) {
                $summary['last_run'] = $check->checked_at;
            }
        }

        return $summary;
    }
}
