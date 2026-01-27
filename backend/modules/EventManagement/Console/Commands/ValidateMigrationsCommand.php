<?php

namespace Modules\EventManagement\Console\Commands;

use Illuminate\Console\Command;
use Modules\EventManagement\Services\MigrationValidationService;

/**
 * Validate Migrations Command
 * 
 * CLI command for validating migration timestamps, dependencies,
 * and production safety. Implements long-term operational improvements.
 */
class ValidateMigrationsCommand extends Command
{
    protected $signature = 'event-management:validate-migrations 
                           {--fix : Automatically fix conflicts where possible}
                           {--production : Run production safety checks}
                           {--dependencies : Validate migration dependencies}
                           {--timestamps : Validate timestamp conflicts only}';

    protected $description = 'Validate migration timestamps, dependencies, and production safety';

    public function __construct(
        private MigrationValidationService $validationService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🔍 ERP Event Management Migration Validation');
        $this->info('============================================');

        $fix = $this->option('fix');
        $production = $this->option('production');
        $dependencies = $this->option('dependencies');
        $timestamps = $this->option('timestamps');

        $startTime = microtime(true);
        $allPassed = true;

        // Validate timestamps
        if (!$dependencies && !$production) {
            $this->info("\n📅 Validating Migration Timestamps...");
            $timestampResults = $this->validateTimestamps($fix);
            $allPassed = $allPassed && $timestampResults;
        }

        // Validate dependencies
        if ($dependencies || (!$timestamps && !$production)) {
            $this->info("\n🔗 Validating Migration Dependencies...");
            $dependencyResults = $this->validateDependencies();
            $allPassed = $allPassed && $dependencyResults;
        }

        // Production monitoring
        if ($production) {
            $this->info("\n🏭 Running Production Safety Checks...");
            $productionResults = $this->runProductionChecks();
            $allPassed = $allPassed && $productionResults;
        }

        $totalTime = microtime(true) - $startTime;
        $this->displaySummary($allPassed, $totalTime);

        return $allPassed ? 0 : 1;
    }

    /**
     * Validate migration timestamps
     */
    private function validateTimestamps(bool $autoFix): bool
    {
        $results = $this->validationService->validateMigrationTimestamps();

        $this->line("Total migrations: {$results['total_migrations']}");

        if (empty($results['conflicts'])) {
            $this->info('✅ No timestamp conflicts found');
        } else {
            $this->error("❌ Found {count($results (['conflicts'])} timestamp conflicts:");

            foreach ($results['conflicts'] as $conflict) {
                $this->warn("  • {$conflict['message']}");
                foreach ($conflict['files'] as $file) {
                    $this->line("    - {$file}");
                }
            }

            if ($autoFix) {
                $this->info("\n🔧 Attempting to auto-fix conflicts...");
                $fixResults = $this->validationService->autoFixConflicts($results['conflicts']);

                if ($fixResults['fixes_successful'] > 0) {
                    $this->info("✅ Successfully fixed {$fixResults['fixes_successful']} conflicts");
                } else {
                    $this->error("❌ Failed to fix conflicts automatically");
                }
            }
        }

        if (!empty($results['warnings'])) {
            $this->warn("\n⚠️  Found {count($results(['warnings'])} warnings:");
            foreach ($results['warnings'] as $warning) {
                $this->line("  • {$warning['message']}");
            }
        }

        if (!empty($results['recommendations'])) {
            $this->info("\n💡 Recommendations:");
            foreach ($results['recommendations'] as $rec) {
                $priority = $rec['priority'] === 'critical' ? '🚨' : '📋';
                $this->line("  {$priority} {$rec['action']}");
                $this->line("     {$rec['description']}");
            }
        }

        return $results['status'] === 'PASSED';
    }

    /**
     * Validate migration dependencies
     */
    private function validateDependencies(): bool
    {
        $results = $this->validationService->validateMigrationDependencies();

        if (empty($results['dependency_issues']) && empty($results['circular_dependencies'])) {
            $this->info('✅ No dependency issues found');
        } else {
            if (!empty($results['dependency_issues'])) {
                $this->error("❌ Found {count($results(['dependency_issues'])} dependency issues:");
                foreach ($results['dependency_issues'] as $issue) {
                    $this->warn("  • {$issue['message']}");
                }
            }

            if (!empty($results['circular_dependencies'])) {
                $this->error("❌ Found {count($results(['circular_dependencies'])} circular dependencies:");
                foreach ($results['circular_dependencies'] as $circular) {
                    $this->warn("  • {$circular['message']}");
                }
            }
        }

        return $results['status'] === 'PASSED';
    }

    /**
     * Run production safety checks
     */
    private function runProductionChecks(): bool
    {
        $results = $this->validationService->monitorProductionMigrations();

        $this->line("Overall Status: " . ($results['overall_status'] === 'HEALTHY' ? '✅ HEALTHY' : '⚠️  ISSUES DETECTED'));

        foreach ($results['checks'] as $checkName => $check) {
            $status = $check['status'] === 'OK' ? '✅' : '❌';
            $this->line("  {$status} " . ucwords(str_replace('_', ' ', $checkName)));
        }

        if (!empty($results['alerts'])) {
            $this->warn("\n🚨 Production Alerts:");
            foreach ($results['alerts'] as $alert) {
                $this->line("  • {$alert['message']}");
            }
        }

        return $results['overall_status'] === 'HEALTHY';
    }

    /**
     * Display validation summary
     */
    private function displaySummary(bool $allPassed, float $totalTime): void
    {
        $this->info("\n" . str_repeat('=', 50));
        $this->info('MIGRATION VALIDATION SUMMARY');
        $this->info(str_repeat('=', 50));

        if ($allPassed) {
            $this->info('🎉 All validations passed successfully!');
        } else {
            $this->error('❌ Some validations failed - review issues above');
        }

        $this->line("Validation completed in " . number_format($totalTime, 2) . " seconds");

        if (!$allPassed) {
            $this->warn("\n⚠️  Next Steps:");
            $this->line("  1. Fix critical issues immediately");
            $this->line("  2. Run with --fix to auto-resolve conflicts");
            $this->line("  3. Review warnings and recommendations");
            $this->line("  4. Re-run validation after fixes");
        }
    }
}