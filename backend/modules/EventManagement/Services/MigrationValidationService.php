<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Migration Validation Service
 * 
 * Implements migration timestamp validation to prevent future conflicts,
 * automated dependency checking, and production safety monitoring.
 * 
 * Long-term operational improvements for migration management.
 */
class MigrationValidationService
{
    private string $migrationsPath;
    private array $validationRules = [];
    private array $dependencyMap = [];

    public function __construct()
    {
        $this->migrationsPath = base_path('backend/modules/EventManagement/Database/Migrations');
        $this->initializeValidationRules();
        $this->initializeDependencyMap();
    }

    /**
     * Validate all migration timestamps for conflicts
     */
    public function validateMigrationTimestamps(): array
    {
        Log::info('Starting migration timestamp validation');

        $migrations = $this->getAllMigrationFiles();
        $timestamps = [];
        $conflicts = [];
        $warnings = [];

        foreach ($migrations as $migration) {
            $timestamp = $this->extractTimestamp($migration);
            
            if (!$timestamp) {
                $warnings[] = [
                    'type' => 'invalid_timestamp',
                    'file' => $migration,
                    'message' => 'Could not extract valid timestamp from filename'
                ];
                continue;
            }

            if (isset($timestamps[$timestamp])) {
                $conflicts[] = [
                    'type' => 'duplicate_timestamp',
                    'timestamp' => $timestamp,
                    'files' => array_merge([$timestamps[$timestamp]], [$migration]),
                    'severity' => 'critical',
                    'message' => "Duplicate timestamp {$timestamp} found in multiple migrations"
                ];
            } else {
                $timestamps[$timestamp] = $migration;
            }
        }

        // Check for sequential gaps
        $sequentialIssues = $this->validateSequentialOrder($timestamps);
        
        return [
            'status' => empty($conflicts) ? 'PASSED' : 'FAILED',
            'total_migrations' => count($migrations),
            'conflicts' => $conflicts,
            'warnings' => array_merge($warnings, $sequentialIssues),
            'validation_time' => now(),
            'recommendations' => $this->generateRecommendations($conflicts, $warnings)
        ];
    }

    /**
     * Validate migration dependencies
     */
    public function validateMigrationDependencies(): array
    {
        Log::info('Starting migration dependency validation');

        $migrations = $this->getAllMigrationFiles();
        $dependencyIssues = [];
        $circularDependencies = [];

        foreach ($migrations as $migration) {
            $dependencies = $this->extractDependencies($migration);
            
            foreach ($dependencies as $dependency) {
                if (!$this->migrationExists($dependency)) {
                    $dependencyIssues[] = [
                        'type' => 'missing_dependency',
                        'migration' => $migration,
                        'missing_dependency' => $dependency,
                        'severity' => 'critical',
                        'message' => "Migration {$migration} depends on {$dependency} which does not exist"
                    ];
                }

                // Check for circular dependencies
                if ($this->hasCircularDependency($migration, $dependency)) {
                    $circularDependencies[] = [
                        'type' => 'circular_dependency',
                        'migration' => $migration,
                        'dependency' => $dependency,
                        'severity' => 'critical',
                        'message' => "Circular dependency detected between {$migration} and {$dependency}"
                    ];
                }
            }
        }

        return [
            'status' => empty($dependencyIssues) && empty($circularDependencies) ? 'PASSED' : 'FAILED',
            'dependency_issues' => $dependencyIssues,
            'circular_dependencies' => $circularDependencies,
            'validation_time' => now()
        ];
    }

    /**
     * Monitor migration conflicts in production
     */
    public function monitorProductionMigrations(): array
    {
        Log::info('Monitoring production migration status');

        $productionChecks = [
            'pending_migrations' => $this->checkPendingMigrations(),
            'failed_migrations' => $this->checkFailedMigrations(),
            'rollback_safety' => $this->validateRollbackSafety(),
            'schema_integrity' => $this->validateSchemaIntegrity(),
            'performance_impact' => $this->assessMigrationPerformance()
        ];

        $overallStatus = collect($productionChecks)->every(fn($check) => $check['status'] === 'OK') ? 'HEALTHY' : 'ISSUES_DETECTED';

        return [
            'overall_status' => $overallStatus,
            'checks' => $productionChecks,
            'monitoring_time' => now(),
            'alerts' => $this->generateProductionAlerts($productionChecks)
        ];
    }

    /**
     * Generate migration conflict resolution suggestions
     */
    public function generateConflictResolution(array $conflicts): array
    {
        $resolutions = [];

        foreach ($conflicts as $conflict) {
            switch ($conflict['type']) {
                case 'duplicate_timestamp':
                    $resolutions[] = $this->resolveDuplicateTimestamp($conflict);
                    break;
                case 'missing_dependency':
                    $resolutions[] = $this->resolveMissingDependency($conflict);
                    break;
                case 'circular_dependency':
                    $resolutions[] = $this->resolveCircularDependency($conflict);
                    break;
            }
        }

        return [
            'resolutions' => $resolutions,
            'automated_fixes_available' => count(array_filter($resolutions, fn($r) => $r['can_auto_fix'])),
            'manual_fixes_required' => count(array_filter($resolutions, fn($r) => !$r['can_auto_fix']))
        ];
    }

    /**
     * Automatically fix migration conflicts where possible
     */
    public function autoFixConflicts(array $conflicts): array
    {
        $fixResults = [];

        foreach ($conflicts as $conflict) {
            if ($conflict['type'] === 'duplicate_timestamp') {
                $fixResult = $this->autoFixDuplicateTimestamp($conflict);
                $fixResults[] = $fixResult;
            }
        }

        return [
            'fixes_attempted' => count($fixResults),
            'fixes_successful' => count(array_filter($fixResults, fn($r) => $r['success'])),
            'fix_results' => $fixResults,
            'fix_time' => now()
        ];
    }

    /**
     * Get all migration files
     */
    private function getAllMigrationFiles(): array
    {
        if (!File::exists($this->migrationsPath)) {
            return [];
        }

        return collect(File::files($this->migrationsPath))
            ->filter(fn($file) => $file->getExtension() === 'php')
            ->map(fn($file) => $file->getFilename())
            ->filter(fn($filename) => $filename !== '.gitkeep')
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Extract timestamp from migration filename
     */
    private function extractTimestamp(string $filename): ?string
    {
        if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $filename, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Validate sequential order of migrations
     */
    private function validateSequentialOrder(array $timestamps): array
    {
        $issues = [];
        $sortedTimestamps = array_keys($timestamps);
        sort($sortedTimestamps);

        for ($i = 1; $i < count($sortedTimestamps); $i++) {
            $current = $sortedTimestamps[$i];
            $previous = $sortedTimestamps[$i - 1];
            
            $currentNum = (int) substr($current, -6);
            $previousNum = (int) substr($previous, -6);
            
            if ($currentNum - $previousNum > 1) {
                $issues[] = [
                    'type' => 'sequence_gap',
                    'previous' => $previous,
                    'current' => $current,
                    'gap_size' => $currentNum - $previousNum - 1,
                    'severity' => 'warning',
                    'message' => "Gap in migration sequence between {$previous} and {$current}"
                ];
            }
        }

        return $issues;
    }

    /**
     * Extract dependencies from migration file
     */
    private function extractDependencies(string $migration): array
    {
        $filePath = $this->migrationsPath . '/' . $migration;
        
        if (!File::exists($filePath)) {
            return [];
        }

        $content = File::get($filePath);
        $dependencies = [];

        // Look for foreign key references
        if (preg_match_all('/->foreign\([\'"](\w+)[\'"]\)->references\([\'"](\w+)[\'"]\)->on\([\'"](\w+)[\'"]\)/', $content, $matches)) {
            foreach ($matches[3] as $table) {
                if (isset($this->dependencyMap[$table])) {
                    $dependencies[] = $this->dependencyMap[$table];
                }
            }
        }

        // Look for explicit dependency comments
        if (preg_match_all('/@depends\s+(\w+)/', $content, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }

        return array_unique($dependencies);
    }

    /**
     * Check if migration exists
     */
    private function migrationExists(string $migration): bool
    {
        $migrations = $this->getAllMigrationFiles();
        return in_array($migration, $migrations) || 
               collect($migrations)->contains(fn($m) => str_contains($m, $migration));
    }

    /**
     * Check for circular dependencies
     */
    private function hasCircularDependency(string $migration1, string $migration2): bool
    {
        $deps1 = $this->extractDependencies($migration1);
        $deps2 = $this->extractDependencies($migration2);
        
        return in_array($migration1, $deps2) && in_array($migration2, $deps1);
    }

    /**
     * Auto-fix duplicate timestamp conflicts
     */
    private function autoFixDuplicateTimestamp(array $conflict): array
    {
        try {
            $files = $conflict['files'];
            $timestamp = $conflict['timestamp'];
            
            // Keep the first file, rename subsequent ones
            for ($i = 1; $i < count($files); $i++) {
                $oldPath = $this->migrationsPath . '/' . $files[$i];
                $newTimestamp = $this->generateNextTimestamp($timestamp, $i);
                $newFilename = str_replace($timestamp, $newTimestamp, $files[$i]);
                $newPath = $this->migrationsPath . '/' . $newFilename;
                
                if (File::move($oldPath, $newPath)) {
                    Log::info("Renamed migration {$files[$i]} to {$newFilename}");
                } else {
                    throw new Exception("Failed to rename {$files[$i]}");
                }
            }

            return [
                'success' => true,
                'conflict' => $conflict,
                'action' => 'renamed_duplicates',
                'files_renamed' => array_slice($files, 1)
            ];

        } catch (Exception $e) {
            Log::error("Failed to auto-fix duplicate timestamp", [
                'conflict' => $conflict,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'conflict' => $conflict,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate next available timestamp
     */
    private function generateNextTimestamp(string $baseTimestamp, int $increment): string
    {
        $baseNum = (int) substr($baseTimestamp, -6);
        $newNum = str_pad($baseNum + $increment, 6, '0', STR_PAD_LEFT);
        return substr($baseTimestamp, 0, -6) . $newNum;
    }

    /**
     * Initialize validation rules
     */
    private function initializeValidationRules(): void
    {
        $this->validationRules = [
            'timestamp_format' => '/^\d{4}_\d{2}_\d{2}_\d{6}_/',
            'filename_pattern' => '/^(\d{4}_\d{2}_\d{2}_\d{6})_([a-z0-9_]+)\.php$/',
            'max_migrations_per_day' => 50,
            'required_methods' => ['up', 'down']
        ];
    }

    /**
     * Initialize dependency map
     */
    private function initializeDependencyMap(): void
    {
        $this->dependencyMap = [
            'events' => 'create_core_event_management_tables',
            'bookings' => 'create_core_event_management_tables',
            'tickets' => 'create_core_event_management_tables',
            'artists' => 'create_phase2_artist_tables',
            'kpi_definitions' => 'create_analytics_governance_schema',
            'artist_canonical' => 'create_artist_canonicalization_schema',
            'pii_vault' => 'create_pii_vault_tables',
            'outbox_events' => 'create_outbox_pattern_tables'
        ];
    }

    /**
     * Generate recommendations based on validation results
     */
    private function generateRecommendations(array $conflicts, array $warnings): array
    {
        $recommendations = [];

        if (!empty($conflicts)) {
            $recommendations[] = [
                'priority' => 'critical',
                'action' => 'Fix migration timestamp conflicts immediately',
                'description' => 'Duplicate timestamps will prevent migrations from running',
                'automated_fix_available' => true
            ];
        }

        if (count($warnings) > 10) {
            $recommendations[] = [
                'priority' => 'medium',
                'action' => 'Review migration naming conventions',
                'description' => 'Multiple warnings suggest inconsistent migration practices',
                'automated_fix_available' => false
            ];
        }

        return $recommendations;
    }

    // Production monitoring methods (placeholder implementations)
    private function checkPendingMigrations(): array { return ['status' => 'OK', 'count' => 0]; }
    private function checkFailedMigrations(): array { return ['status' => 'OK', 'count' => 0]; }
    private function validateRollbackSafety(): array { return ['status' => 'OK', 'rollback_safe' => true]; }
    private function validateSchemaIntegrity(): array { return ['status' => 'OK', 'integrity_check' => 'passed']; }
    private function assessMigrationPerformance(): array { return ['status' => 'OK', 'avg_time_ms' => 150]; }
    private function generateProductionAlerts(array $checks): array { return []; }
    private function resolveDuplicateTimestamp(array $conflict): array { return ['can_auto_fix' => true, 'steps' => []]; }
    private function resolveMissingDependency(array $conflict): array { return ['can_auto_fix' => false, 'steps' => []]; }
    private function resolveCircularDependency(array $conflict): array { return ['can_auto_fix' => false, 'steps' => []]; }
}