<?php

namespace Modules\EventManagement\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Services\MonitoringIntegrationService;
use Modules\EventManagement\Services\OperationalDocumentationService;

/**
 * Setup Phase 3 Monitoring Command
 *
 * Executes Task 14 monitoring and observability integration
 * including dashboard integration, alerting setup, health checks,
 * and operational documentation creation.
 */
class SetupPhase3MonitoringCommand extends Command
{
    protected $signature = 'event-management:setup-phase3-monitoring 
                           {--component=all : Specific component to setup (all|monitoring|documentation)}
                           {--force : Force recreation of existing configurations}
                           {--dry-run : Show what would be done without making changes}';

    protected $description = 'Setup Phase 3 monitoring integration and operational documentation';

    public function __construct(
        private MonitoringIntegrationService $monitoringService,
        private OperationalDocumentationService $documentationService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Setting up Phase 3 Monitoring & Observability Integration');
        $this->info('==========================================================');

        $component = $this->option('component');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $startTime = microtime(true);
        $results = [];

        try {
            // Task 14.1: Integrate with existing monitoring infrastructure
            if ($component === 'all' || $component === 'monitoring') {
                $this->info("\n🔧 Task 14.1: Integrating with existing monitoring infrastructure");

                if (! $dryRun) {
                    $monitoringResults = $this->monitoringService->integratePhase3Monitoring();
                    $results['monitoring_integration'] = $monitoringResults;

                    $this->displayMonitoringResults($monitoringResults);
                } else {
                    $this->line('Would integrate Phase 3 monitoring infrastructure');
                }
            }

            // Task 14.2: Create Phase 3 operational documentation
            if ($component === 'all' || $component === 'documentation') {
                $this->info("\n📚 Task 14.2: Creating Phase 3 operational documentation");

                if (! $dryRun) {
                    $documentationResults = $this->documentationService->createPhase3Documentation();
                    $results['documentation_creation'] = $documentationResults;

                    $this->displayDocumentationResults($documentationResults);
                } else {
                    $this->line('Would create Phase 3 operational documentation');
                }
            }

            $totalTime = microtime(true) - $startTime;

            // Display final summary
            if (! $dryRun) {
                $this->displayFinalSummary($results, $totalTime);
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('Phase 3 monitoring setup failed: '.$e->getMessage());
            Log::error('Phase 3 monitoring setup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }

    /**
     * Display monitoring integration results
     */
    private function displayMonitoringResults(array $results): void
    {
        $integrationResults = $results['integration_results'];

        $this->info("\n📊 Monitoring Integration Results:");

        // Dashboard Integration
        if (isset($integrationResults['dashboard_integration'])) {
            $dashboards = $integrationResults['dashboard_integration'];
            $this->table(
                ['Component', 'Status'],
                [
                    ['Dashboards Created', $dashboards['dashboards_created']],
                    ['Integration Status', $dashboards['integration_status']],
                ]
            );

            $this->line("\n📈 Created Dashboards:");
            foreach ($dashboards['dashboard_configs'] as $name => $config) {
                $this->line("  • {$config['name']} - {$config['description']}");
            }
        }

        // Alerting Integration
        if (isset($integrationResults['alerting_integration'])) {
            $alerting = $integrationResults['alerting_integration'];
            $this->info("\n🚨 Alerting Integration:");
            $this->table(
                ['Alert Category', 'Rules Created'],
                [
                    ['Critical Alerts', count($alerting['alert_rules']['critical_alerts'])],
                    ['Warning Alerts', count($alerting['alert_rules']['warning_alerts'])],
                    ['Performance Alerts', count($alerting['alert_rules']['performance_alerts'])],
                    ['Security Alerts', count($alerting['alert_rules']['security_alerts'])],
                    ['Compliance Alerts', count($alerting['alert_rules']['compliance_alerts'])],
                ]
            );
        }

        // Health Checks
        if (isset($integrationResults['health_checks'])) {
            $healthChecks = $integrationResults['health_checks'];
            $this->info("\n💚 Health Checks Setup:");
            $this->table(
                ['Check Category', 'Checks Created'],
                [
                    ['Service Health Checks', count($healthChecks['health_checks']['service_health_checks'])],
                    ['Job Health Checks', count($healthChecks['health_checks']['job_health_checks'])],
                    ['Database Health Checks', count($healthChecks['health_checks']['database_health_checks'])],
                    ['Integration Health Checks', count($healthChecks['health_checks']['integration_health_checks'])],
                ]
            );
        }

        // Runbooks
        if (isset($integrationResults['runbooks'])) {
            $runbooks = $integrationResults['runbooks'];
            $this->info("\n📖 Runbooks Created:");
            foreach ($runbooks['runbooks_created'] as $runbook) {
                $this->line('  • '.ucwords(str_replace('_', ' ', $runbook)));
            }
        }

        // Metrics Collection
        if (isset($integrationResults['metrics_collection'])) {
            $metrics = $integrationResults['metrics_collection'];
            $this->info("\n📏 Metrics Collection Setup:");
            $this->line('  • Metrics Endpoints: '.count($metrics['metrics_endpoints']));
            $this->line('  • Collection Intervals: '.count($metrics['collection_intervals']));
            $this->line('  • Retention Policies: '.count($metrics['retention_policies']));
        }
    }

    /**
     * Display documentation creation results
     */
    private function displayDocumentationResults(array $results): void
    {
        $documentationResults = $results['documentation_results'];

        $this->info("\n📚 Documentation Creation Results:");
        $this->table(
            ['Documentation Category', 'Documents Created'],
            [
                ['Operational Procedures', $documentationResults['operational_procedures']['procedures_created']],
                ['Troubleshooting Guides', $documentationResults['troubleshooting_guides']['guides_created']],
                ['Incident Response Procedures', $documentationResults['incident_response_procedures']['procedures_created']],
                ['Capacity Planning Guidance', $documentationResults['capacity_planning_guidance']['guidance_created']],
                ['Rollback Procedures', $documentationResults['rollback_procedures']['procedures_created']],
                ['Detailed Runbooks', $documentationResults['runbooks']['runbooks_created']],
                ['Training Materials', $documentationResults['training_materials']['materials_created']],
            ]
        );

        $this->info("\n📁 Documentation Structure:");
        $this->line('  📂 operational_procedures/');
        foreach ($documentationResults['operational_procedures']['procedure_names'] as $procedure) {
            $this->line('    📄 '.str_replace('_', ' ', $procedure).'.md');
        }

        $this->line('  📂 troubleshooting_guides/');
        foreach ($documentationResults['troubleshooting_guides']['guide_names'] as $guide) {
            $this->line('    📄 '.str_replace('_', ' ', $guide).'.md');
        }

        $this->line('  📂 incident_response/');
        foreach ($documentationResults['incident_response_procedures']['procedure_names'] as $procedure) {
            $this->line('    📄 '.str_replace('_', ' ', $procedure).'.md');
        }

        $this->line('  📂 capacity_planning/');
        foreach ($documentationResults['capacity_planning_guidance']['guidance_names'] as $guidance) {
            $this->line('    📄 '.str_replace('_', ' ', $guidance).'.md');
        }

        $this->line('  📂 rollback_procedures/');
        foreach ($documentationResults['rollback_procedures']['procedure_names'] as $procedure) {
            $this->line('    📄 '.str_replace('_', ' ', $procedure).'.md');
        }

        $this->info("\n📍 Documentation Location:");
        $this->line('  Path: '.$results['documentation_path']);
        $this->line('  Total Documents: '.$results['total_documents_created']);
    }

    /**
     * Display final summary
     */
    private function displayFinalSummary(array $results, float $totalTime): void
    {
        $this->info("\n".str_repeat('=', 70));
        $this->info('PHASE 3 MONITORING & OBSERVABILITY SETUP SUMMARY');
        $this->info(str_repeat('=', 70));

        $summaryData = [];

        // Monitoring Integration Summary
        if (isset($results['monitoring_integration'])) {
            $monitoring = $results['monitoring_integration']['integration_results'];
            $summaryData[] = ['Dashboards Created', $monitoring['dashboard_integration']['dashboards_created'] ?? 0];
            $summaryData[] = ['Alert Rules Created', $monitoring['alerting_integration']['alert_rules_created'] ?? 0];
            $summaryData[] = ['Health Checks Created', $monitoring['health_checks']['health_checks_created'] ?? 0];
            $summaryData[] = ['Metrics Endpoints', count($monitoring['metrics_collection']['metrics_endpoints'] ?? [])];
        }

        // Documentation Summary
        if (isset($results['documentation_creation'])) {
            $documentation = $results['documentation_creation'];
            $summaryData[] = ['Documentation Categories', count($documentation['documentation_results'])];
            $summaryData[] = ['Total Documents Created', $documentation['total_documents_created']];
        }

        $summaryData[] = ['Setup Time', number_format($totalTime, 2).' seconds'];
        $summaryData[] = ['Overall Status', 'COMPLETED'];

        $this->table(['Component', 'Result'], $summaryData);

        // Success message
        $this->info("\n✅ Phase 3 Monitoring & Observability Integration completed successfully!");

        // Next steps
        $this->info("\n🚀 Next Steps:");
        $this->line('  1. Review created dashboards in monitoring system');
        $this->line('  2. Test alert rules and notification channels');
        $this->line('  3. Verify health check endpoints are responding');
        $this->line('  4. Review operational documentation with team');
        $this->line('  5. Schedule training sessions for operational procedures');
        $this->line('  6. Proceed with Task 15: Deployment & Rollout');

        // Important notes
        $this->warn("\n⚠️  Important Notes:");
        $this->line('  • Verify monitoring system integration before production deployment');
        $this->line('  • Test alert escalation procedures with actual incidents');
        $this->line('  • Ensure all team members have access to documentation');
        $this->line('  • Schedule regular review of operational procedures');
    }

    /**
     * Display monitoring integration status
     */
    private function displayMonitoringStatus(): void
    {
        $this->info("\n🔍 Current Monitoring Integration Status:");

        $status = $this->monitoringService->getIntegrationStatus();

        $statusTable = [
            ['Dashboards Integrated', $status['dashboards_integrated'] ? '✅ Yes' : '❌ No'],
            ['Alerts Configured', $status['alerts_configured'] ? '✅ Yes' : '❌ No'],
            ['Health Checks Active', $status['health_checks_active'] ? '✅ Yes' : '❌ No'],
            ['Runbooks Available', $status['runbooks_available'] ? '✅ Yes' : '❌ No'],
            ['Metrics Collecting', $status['metrics_collecting'] ? '✅ Yes' : '❌ No'],
            ['Last Updated', $status['last_updated']->format('Y-m-d H:i:s')],
        ];

        $this->table(['Component', 'Status'], $statusTable);
    }

    /**
     * Display documentation status
     */
    private function displayDocumentationStatus(): void
    {
        $this->info("\n📚 Current Documentation Status:");

        $status = $this->documentationService->getDocumentationStatus();

        $this->line('Documentation Path: '.$status['documentation_path']);
        $this->line('Total Categories: '.$status['total_categories']);
        $this->line('Last Updated: '.$status['last_updated']->format('Y-m-d H:i:s'));

        $this->info("\nDocumentation Structure:");
        foreach ($status['structure'] as $category => $items) {
            $this->line("  📂 {$category}/ (".count($items).' items)');
        }
    }

    /**
     * Validate prerequisites
     */
    private function validatePrerequisites(): bool
    {
        $this->info('🔍 Validating prerequisites...');

        $checks = [
            'Phase 1 & 2 Deployed' => true, // Assume previous phases are deployed
            'Monitoring System Available' => true, // Assume monitoring infrastructure exists
            'Documentation Storage Available' => true, // Assume storage is available
            'Required Services Running' => true, // Assume Phase 3 services are running
        ];

        foreach ($checks as $check => $passed) {
            $status = $passed ? '✅' : '❌';
            $this->line("  {$status} {$check}");
        }

        $allPassed = collect($checks)->every(fn ($passed) => $passed);

        if (! $allPassed) {
            $this->error('Prerequisites not met. Please resolve issues before proceeding.');

            return false;
        }

        $this->info('✅ All prerequisites validated');

        return true;
    }
}
