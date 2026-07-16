<?php

namespace Modules\EventManagement\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration Dependency Service
 *
 * Manages and validates migration dependencies to ensure proper
 * database schema evolution and prevent deployment conflicts.
 */
class MigrationDependencyService
{
    private array $dependencyMatrix = [];

    private array $validationRules = [];

    public function __construct()
    {
        $this->initializeDependencyMatrix();
        $this->initializeValidationRules();
    }

    /**
     * Validate all migration dependencies
     */
    public function validateDependencies(): array
    {
        $results = [
            'status' => 'success',
            'violations' => [],
            'warnings' => [],
            'recommendations' => [],
        ];

        try {
            // Check table dependencies
            $tableViolations = $this->validateTableDependencies();
            if (! empty($tableViolations)) {
                $results['violations'] = array_merge($results['violations'], $tableViolations);
                $results['status'] = 'failed';
            }

            // Check foreign key constraints
            $fkViolations = $this->validateForeignKeyDependencies();
            if (! empty($fkViolations)) {
                $results['violations'] = array_merge($results['violations'], $fkViolations);
                $results['status'] = 'failed';
            }

            // Check feature dependencies
            $featureWarnings = $this->validateFeatureDependencies();
            if (! empty($featureWarnings)) {
                $results['warnings'] = array_merge($results['warnings'], $featureWarnings);
            }

            // Generate recommendations
            $results['recommendations'] = $this->generateRecommendations($results);

        } catch (Exception $e) {
            $results['status'] = 'error';
            $results['violations'][] = [
                'type' => 'system_error',
                'message' => 'Dependency validation failed: '.$e->getMessage(),
            ];
        }

        return $results;
    }

    /**
     * Validate table dependencies
     */
    private function validateTableDependencies(): array
    {
        $violations = [];

        foreach ($this->dependencyMatrix['tables'] as $table => $dependencies) {
            if (! Schema::hasTable($table)) {
                continue; // Skip if table doesn't exist
            }

            foreach ($dependencies as $dependency) {
                if (! Schema::hasTable($dependency)) {
                    $violations[] = [
                        'type' => 'missing_table_dependency',
                        'table' => $table,
                        'missing_dependency' => $dependency,
                        'message' => "Table '{$table}' requires '{$dependency}' but it doesn't exist",
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * Validate foreign key dependencies
     */
    private function validateForeignKeyDependencies(): array
    {
        $violations = [];

        foreach ($this->dependencyMatrix['foreign_keys'] as $table => $foreignKeys) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($foreignKeys as $fk) {
                // Check if referenced table exists
                if (! Schema::hasTable($fk['references_table'])) {
                    $violations[] = [
                        'type' => 'missing_foreign_key_target',
                        'table' => $table,
                        'column' => $fk['column'],
                        'references_table' => $fk['references_table'],
                        'message' => "Foreign key '{$table}.{$fk['column']}' references non-existent table '{$fk['references_table']}'",
                    ];
                }

                // Check if referenced column exists
                if (Schema::hasTable($fk['references_table']) &&
                    ! Schema::hasColumn($fk['references_table'], $fk['references_column'])) {
                    $violations[] = [
                        'type' => 'missing_foreign_key_column',
                        'table' => $table,
                        'column' => $fk['column'],
                        'references_table' => $fk['references_table'],
                        'references_column' => $fk['references_column'],
                        'message' => "Foreign key '{$table}.{$fk['column']}' references non-existent column '{$fk['references_table']}.{$fk['references_column']}'",
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * Validate feature dependencies
     */
    private function validateFeatureDependencies(): array
    {
        $warnings = [];

        foreach ($this->dependencyMatrix['features'] as $feature => $dependencies) {
            $featureEnabled = $this->isFeatureEnabled($feature);

            if ($featureEnabled) {
                foreach ($dependencies as $dependency) {
                    if (! $this->isFeatureEnabled($dependency)) {
                        $warnings[] = [
                            'type' => 'feature_dependency_warning',
                            'feature' => $feature,
                            'missing_dependency' => $dependency,
                            'message' => "Feature '{$feature}' is enabled but depends on disabled feature '{$dependency}'",
                        ];
                    }
                }
            }
        }

        return $warnings;
    }

    /**
     * Check if a feature is enabled
     */
    private function isFeatureEnabled(string $feature): bool
    {
        try {
            if (! Schema::hasTable('tenant_feature_flags')) {
                return false;
            }

            return DB::table('tenant_feature_flags')
                ->where('feature_name', $feature)
                ->where('is_enabled', true)
                ->exists();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Generate recommendations based on validation results
     */
    private function generateRecommendations(array $results): array
    {
        $recommendations = [];

        if (! empty($results['violations'])) {
            $recommendations[] = [
                'priority' => 'high',
                'action' => 'Fix dependency violations before deployment',
                'details' => 'Critical dependencies are missing and will cause system failures',
            ];
        }

        if (! empty($results['warnings'])) {
            $recommendations[] = [
                'priority' => 'medium',
                'action' => 'Review feature flag dependencies',
                'details' => 'Some features may not work correctly due to missing dependencies',
            ];
        }

        if (empty($results['violations']) && empty($results['warnings'])) {
            $recommendations[] = [
                'priority' => 'low',
                'action' => 'Dependencies are healthy',
                'details' => 'All migration dependencies are properly satisfied',
            ];
        }

        return $recommendations;
    }

    /**
     * Initialize dependency matrix
     */
    private function initializeDependencyMatrix(): void
    {
        $this->dependencyMatrix = [
            'tables' => [
                // Phase 3.1 Dependencies
                'booking_facts' => ['events', 'bookings', 'tickets'],
                'analytics_facts' => ['events', 'bookings'],
                'outbox_events' => ['events', 'bookings'],
                'capacity_reservations' => ['events'],
                'pii_vault' => [],
                'pii_access_log' => ['pii_vault'],

                // Phase 3.2 Dependencies
                'kpi_definitions' => ['analytics_facts'],
                'data_health_checks' => ['kpi_definitions'],
                'artist_canonical' => ['artists'],
                'artist_aliases' => ['artist_canonical'],
                'eligibility_decisions' => ['bookings', 'fan_tiers'],
                'loyalty_ledger' => ['bookings', 'loyalty_point_transactions'],
                'risk_scores' => ['bookings'],
                'fraud_timeline' => ['risk_scores', 'outbox_events'],

                // Phase 3.3 Dependencies
                'offline_checkin_queue' => ['tickets', 'events'],
                'staff_overrides' => ['staff_assignments'],
                'ticket_status_log' => ['tickets'],
                'tenant_health_metrics' => [],
                'api_performance_log' => [],
            ],

            'foreign_keys' => [
                'booking_facts' => [
                    ['column' => 'booking_id', 'references_table' => 'bookings', 'references_column' => 'id'],
                    ['column' => 'event_id', 'references_table' => 'events', 'references_column' => 'id'],
                ],
                'outbox_events' => [
                    ['column' => 'aggregate_id', 'references_table' => 'bookings', 'references_column' => 'id'],
                ],
                'capacity_reservations' => [
                    ['column' => 'event_id', 'references_table' => 'events', 'references_column' => 'id'],
                ],
                'eligibility_decisions' => [
                    ['column' => 'booking_id', 'references_table' => 'bookings', 'references_column' => 'id'],
                    ['column' => 'fan_tier_id', 'references_table' => 'fan_tiers', 'references_column' => 'id'],
                ],
                'ticket_status_log' => [
                    ['column' => 'ticket_id', 'references_table' => 'tickets', 'references_column' => 'id'],
                ],
            ],

            'features' => [
                'immutable_facts' => [],
                'outbox_pattern' => [],
                'capacity_management' => ['immutable_facts'],
                'pii_vault' => [],
                'analytics_governance' => ['immutable_facts'],
                'artist_canonicalization' => [],
                'loyalty_auditability' => ['outbox_pattern'],
                'fraud_prevention' => ['outbox_pattern'],
                'operations_hardening' => ['capacity_management'],
                'customer_hub_consistency' => ['pii_vault'],
                'observability' => [],
                'api_governance' => ['observability'],
            ],
        ];
    }

    /**
     * Initialize validation rules
     */
    private function initializeValidationRules(): void
    {
        $this->validationRules = [
            'critical_tables' => [
                'events', 'bookings', 'tickets', 'artists', 'fan_tiers',
            ],
            'required_indexes' => [
                'events' => ['tenant_id', 'status', 'start_date'],
                'bookings' => ['tenant_id', 'event_id', 'status'],
                'tickets' => ['tenant_id', 'booking_id', 'status'],
            ],
            'required_columns' => [
                'events' => ['tenant_id', 'capacity', 'reserved_capacity'],
                'bookings' => ['tenant_id', 'capacity_reservation_id'],
                'tickets' => ['tenant_id', 'status'],
            ],
        ];
    }

    /**
     * Get dependency matrix for external use
     */
    public function getDependencyMatrix(): array
    {
        return $this->dependencyMatrix;
    }

    /**
     * Add custom dependency rule
     */
    public function addDependencyRule(string $type, string $item, array $dependencies): void
    {
        if (! isset($this->dependencyMatrix[$type])) {
            $this->dependencyMatrix[$type] = [];
        }

        $this->dependencyMatrix[$type][$item] = $dependencies;
    }

    /**
     * Validate specific migration before execution
     */
    public function validateMigrationExecution(string $migrationName): array
    {
        $results = [
            'can_execute' => true,
            'blocking_issues' => [],
            'warnings' => [],
        ];

        // Check if migration has unmet dependencies
        foreach ($this->dependencyMatrix['tables'] as $table => $dependencies) {
            if (str_contains($migrationName, $table)) {
                foreach ($dependencies as $dependency) {
                    if (! Schema::hasTable($dependency)) {
                        $results['can_execute'] = false;
                        $results['blocking_issues'][] = "Missing required table: {$dependency}";
                    }
                }
            }
        }

        return $results;
    }
}
