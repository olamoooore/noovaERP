<?php

namespace Modules\EventManagement\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Services\ComprehensiveTestRunnerService;
use Modules\EventManagement\Services\LoadTestingService;
use Modules\EventManagement\Services\SecurityComplianceValidationService;
use Modules\EventManagement\Services\DisasterRecoveryTestingService;

/**
 * Run Comprehensive Testing Command
 * 
 * Executes all Task 13 comprehensive testing and validation suites
 * including property-based tests, load testing, security validation,
 * and disaster recovery testing.
 */
class RunComprehensiveTestingCommand extends Command
{
    protected $signature = 'event-management:run-comprehensive-testing 
                           {--iterations=100 : Number of iterations for property-based tests}
                           {--suite=all : Test suite to run (all|property|load|security|disaster)}
                           {--tenant= : Specific tenant to test}
                           {--report-format=json : Report format (json|html|csv)}';

    protected $description = 'Run comprehensive Phase 3 testing and validation suite';

    public function __construct(
        private ComprehensiveTestRunnerService $testRunnerService,
        private LoadTestingService $loadTestingService,
        private SecurityComplianceValidationService $securityValidationService,
        private DisasterRecoveryTestingService $disasterRecoveryService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting Phase 3 Comprehensive Testing and Validation');
        $this->info('================================================');

        $iterations = (int) $this->option('iterations');
        $suite = $this->option('suite');
        $tenant = $this->option('tenant');
        $reportFormat = $this->option('report-format');

        $startTime = microtime(true);
        $overallResults = [];

        try {
            // Task 13.1: Run all Phase 3 property-based tests
            if ($suite === 'all' || $suite === 'property') {
                $this->info("\n🧪 Running Property-Based Tests (Task 13.1)");
                $this->line("Iterations: {$iterations}");
                
                $propertyResults = $this->testRunnerService->runAllPhase3Tests($iterations);
                $overallResults['property_tests'] = $propertyResults;
                
                $this->displayPropertyTestResults($propertyResults);
            }

            // Task 13.2: Load testing and performance validation
            if ($suite === 'all' || $suite === 'load') {
                $this->info("\n⚡ Running Load Testing and Performance Validation (Task 13.2)");
                
                $loadResults = $this->loadTestingService->runLoadTestingSuite();
                $overallResults['load_tests'] = $loadResults;
                
                $this->displayLoadTestResults($loadResults);
            }

            // Task 13.3: Security and compliance validation
            if ($suite === 'all' || $suite === 'security') {
                $this->info("\n🔒 Running Security and Compliance Validation (Task 13.3)");
                
                $securityResults = $this->securityValidationService->runSecurityComplianceValidation();
                $overallResults['security_tests'] = $securityResults;
                
                $this->displaySecurityTestResults($securityResults);
            }

            // Task 13.4: Disaster recovery and failover testing
            if ($suite === 'all' || $suite === 'disaster') {
                $this->info("\n🚨 Running Disaster Recovery and Failover Testing (Task 13.4)");
                
                $disasterResults = $this->disasterRecoveryService->runDisasterRecoveryTesting();
                $overallResults['disaster_recovery_tests'] = $disasterResults;
                
                $this->displayDisasterRecoveryResults($disasterResults);
            }

            $totalTime = microtime(true) - $startTime;

            // Generate comprehensive report
            $this->generateComprehensiveReport($overallResults, $totalTime, $reportFormat);

            // Display final summary
            $this->displayFinalSummary($overallResults, $totalTime);

            return $this->determineExitCode($overallResults);

        } catch (\Exception $e) {
            $this->error("Comprehensive testing failed: " . $e->getMessage());
            Log::error('Comprehensive testing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return 1;
        }
    }

    /**
     * Display property test results
     */
    private function displayPropertyTestResults(array $results): void
    {
        $summary = $results['summary'];
        
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Tests', $summary['total_tests']],
                ['Passed', $summary['passed']],
                ['Failed', $summary['failed']],
                ['Errors', $summary['errors']],
                ['Success Rate', number_format($summary['success_rate'], 2) . '%'],
                ['Overall Status', $summary['overall_status']]
            ]
        );

        // Display failed tests if any
        if ($summary['failed'] > 0 || $summary['errors'] > 0) {
            $this->warn("\n❌ Failed/Error Tests:");
            foreach ($results['test_results'] as $testName => $result) {
                if ($result['status'] !== 'PASSED') {
                    $this->line("  • {$testName}: {$result['status']}");
                    if (isset($result['error'])) {
                        $this->line("    Error: {$result['error']}");
                    }
                }
            }
        }

        // Display recommendations
        if (!empty($results['recommendations'])) {
            $this->warn("\n💡 Recommendations:");
            foreach ($results['recommendations'] as $recommendation) {
                $this->line("  • [{$recommendation['type']}] {$recommendation['message']}");
            }
        }
    }

    /**
     * Display load test results
     */
    private function displayLoadTestResults(array $results): void
    {
        $summary = $results['summary'];
        
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Load Tests', $summary['total_load_tests']],
                ['Passed', $summary['passed']],
                ['Failed', $summary['failed']],
                ['Errors', $summary['errors']],
                ['Success Rate', number_format($summary['success_rate'], 2) . '%'],
                ['Overall Status', $summary['overall_status']]
            ]
        );

        // Display performance highlights
        $this->info("\n📊 Performance Highlights:");
        foreach ($results['load_test_results'] as $testName => $result) {
            if ($result['status'] === 'PASSED') {
                $this->line("  ✅ {$testName}");
                if (isset($result['throughput_per_second'])) {
                    $this->line("     Throughput: " . number_format($result['throughput_per_second'], 2) . " ops/sec");
                }
                if (isset($result['success_rate'])) {
                    $this->line("     Success Rate: " . number_format($result['success_rate'], 2) . "%");
                }
            } else {
                $this->line("  ❌ {$testName}: {$result['status']}");
            }
        }
    }

    /**
     * Display security test results
     */
    private function displaySecurityTestResults(array $results): void
    {
        $summary = $results['summary'];
        
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Validations', $summary['total_validations']],
                ['Passed', $summary['passed']],
                ['Failed', $summary['failed']],
                ['Errors', $summary['errors']],
                ['Compliance Rate', number_format($summary['compliance_rate'], 2) . '%'],
                ['Overall Status', $summary['overall_status']]
            ]
        );

        // Display security validation details
        $this->info("\n🔐 Security Validation Details:");
        foreach ($results['validation_results'] as $validationName => $result) {
            $status = $result['status'] === 'PASSED' ? '✅' : '❌';
            $this->line("  {$status} {$validationName}: {$result['status']}");
        }
    }

    /**
     * Display disaster recovery results
     */
    private function displayDisasterRecoveryResults(array $results): void
    {
        $summary = $results['summary'];
        
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Recovery Tests', $summary['total_recovery_tests']],
                ['Passed', $summary['passed']],
                ['Failed', $summary['failed']],
                ['Errors', $summary['errors']],
                ['Resilience Rate', number_format($summary['resilience_rate'], 2) . '%'],
                ['Overall Status', $summary['overall_status']]
            ]
        );

        // Display resilience details
        $this->info("\n🛡️ Resilience Test Details:");
        foreach ($results['recovery_test_results'] as $testName => $result) {
            $status = $result['status'] === 'PASSED' ? '✅' : '❌';
            $this->line("  {$status} {$testName}: {$result['status']}");
        }
    }

    /**
     * Generate comprehensive report
     */
    private function generateComprehensiveReport(array $results, float $totalTime, string $format): void
    {
        $reportData = [
            'execution_summary' => [
                'start_time' => now()->subSeconds($totalTime),
                'end_time' => now(),
                'total_duration' => $totalTime,
                'test_suites_executed' => array_keys($results)
            ],
            'results' => $results,
            'overall_assessment' => $this->generateOverallAssessment($results)
        ];

        $filename = 'comprehensive_testing_report_' . now()->format('Y-m-d_H-i-s');

        switch ($format) {
            case 'json':
                $this->saveJsonReport($reportData, $filename);
                break;
            case 'html':
                $this->saveHtmlReport($reportData, $filename);
                break;
            case 'csv':
                $this->saveCsvReport($reportData, $filename);
                break;
        }

        $this->info("\n📄 Report saved: storage/app/reports/{$filename}.{$format}");
    }

    /**
     * Save JSON report
     */
    private function saveJsonReport(array $data, string $filename): void
    {
        $path = storage_path("app/reports/{$filename}.json");
        
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Save HTML report
     */
    private function saveHtmlReport(array $data, string $filename): void
    {
        $html = $this->generateHtmlReport($data);
        $path = storage_path("app/reports/{$filename}.html");
        
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        
        file_put_contents($path, $html);
    }

    /**
     * Save CSV report
     */
    private function saveCsvReport(array $data, string $filename): void
    {
        $csv = $this->generateCsvReport($data);
        $path = storage_path("app/reports/{$filename}.csv");
        
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        
        file_put_contents($path, $csv);
    }

    /**
     * Generate HTML report
     */
    private function generateHtmlReport(array $data): string
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <title>Phase 3 Comprehensive Testing Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background: #f4f4f4; padding: 20px; border-radius: 5px; }
        .summary { margin: 20px 0; }
        .test-suite { margin: 20px 0; border: 1px solid #ddd; padding: 15px; }
        .passed { color: green; }
        .failed { color: red; }
        .error { color: orange; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Phase 3 Comprehensive Testing Report</h1>
        <p>Generated: ' . now()->format('Y-m-d H:i:s') . '</p>
        <p>Duration: ' . number_format($data['execution_summary']['total_duration'], 2) . ' seconds</p>
    </div>';

        foreach ($data['results'] as $suiteName => $suiteResults) {
            $html .= '<div class="test-suite">';
            $html .= '<h2>' . ucwords(str_replace('_', ' ', $suiteName)) . '</h2>';
            
            if (isset($suiteResults['summary'])) {
                $summary = $suiteResults['summary'];
                $html .= '<table>';
                $html .= '<tr><th>Metric</th><th>Value</th></tr>';
                foreach ($summary as $key => $value) {
                    $html .= '<tr><td>' . ucwords(str_replace('_', ' ', $key)) . '</td><td>' . $value . '</td></tr>';
                }
                $html .= '</table>';
            }
            
            $html .= '</div>';
        }

        $html .= '</body></html>';
        
        return $html;
    }

    /**
     * Generate CSV report
     */
    private function generateCsvReport(array $data): string
    {
        $csv = "Test Suite,Test Name,Status,Details\n";
        
        foreach ($data['results'] as $suiteName => $suiteResults) {
            if (isset($suiteResults['test_results'])) {
                foreach ($suiteResults['test_results'] as $testName => $result) {
                    $status = $result['status'] ?? 'UNKNOWN';
                    $details = isset($result['error']) ? $result['error'] : 'Success';
                    $csv .= "\"{$suiteName}\",\"{$testName}\",\"{$status}\",\"{$details}\"\n";
                }
            }
        }
        
        return $csv;
    }

    /**
     * Generate overall assessment
     */
    private function generateOverallAssessment(array $results): array
    {
        $totalTests = 0;
        $totalPassed = 0;
        $totalFailed = 0;
        $totalErrors = 0;
        $criticalIssues = [];

        foreach ($results as $suiteName => $suiteResults) {
            if (isset($suiteResults['summary'])) {
                $summary = $suiteResults['summary'];
                $totalTests += $summary['total_tests'] ?? $summary['total_load_tests'] ?? $summary['total_validations'] ?? $summary['total_recovery_tests'] ?? 0;
                $totalPassed += $summary['passed'] ?? 0;
                $totalFailed += $summary['failed'] ?? 0;
                $totalErrors += $summary['errors'] ?? 0;

                // Check for critical issues
                if (($summary['failed'] ?? 0) > 0 || ($summary['errors'] ?? 0) > 0) {
                    $criticalIssues[] = $suiteName;
                }
            }
        }

        $overallSuccessRate = $totalTests > 0 ? ($totalPassed / $totalTests) * 100 : 0;
        $deploymentReady = empty($criticalIssues) && $overallSuccessRate >= 95;

        return [
            'total_tests' => $totalTests,
            'total_passed' => $totalPassed,
            'total_failed' => $totalFailed,
            'total_errors' => $totalErrors,
            'overall_success_rate' => $overallSuccessRate,
            'deployment_ready' => $deploymentReady,
            'critical_issues' => $criticalIssues,
            'recommendation' => $deploymentReady ? 
                'System is ready for deployment' : 
                'Critical issues must be resolved before deployment'
        ];
    }

    /**
     * Display final summary
     */
    private function displayFinalSummary(array $results, float $totalTime): void
    {
        $assessment = $this->generateOverallAssessment($results);

        $this->info("\n" . str_repeat('=', 60));
        $this->info('COMPREHENSIVE TESTING SUMMARY');
        $this->info(str_repeat('=', 60));

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Tests Executed', $assessment['total_tests']],
                ['Total Passed', $assessment['total_passed']],
                ['Total Failed', $assessment['total_failed']],
                ['Total Errors', $assessment['total_errors']],
                ['Overall Success Rate', number_format($assessment['overall_success_rate'], 2) . '%'],
                ['Execution Time', number_format($totalTime, 2) . ' seconds'],
                ['Deployment Ready', $assessment['deployment_ready'] ? 'YES' : 'NO']
            ]
        );

        if ($assessment['deployment_ready']) {
            $this->info("\n✅ " . $assessment['recommendation']);
        } else {
            $this->warn("\n❌ " . $assessment['recommendation']);
            if (!empty($assessment['critical_issues'])) {
                $this->warn("Critical issues in: " . implode(', ', $assessment['critical_issues']));
            }
        }
    }

    /**
     * Determine exit code based on results
     */
    private function determineExitCode(array $results): int
    {
        $assessment = $this->generateOverallAssessment($results);
        
        // Return 0 (success) only if deployment ready
        return $assessment['deployment_ready'] ? 0 : 1;
    }
}