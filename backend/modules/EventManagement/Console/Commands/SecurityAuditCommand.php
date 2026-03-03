<?php

namespace Modules\EventManagement\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Modules\EventManagement\Services\InputSanitizationService;
use Modules\EventManagement\Services\SqlInjectionPreventionService;

/**
 * Security Audit Command
 * 
 * Performs comprehensive security audit of the EventManagement module
 * to identify potential vulnerabilities and security issues.
 * 
 * Requirements: 8.5 - Input sanitization validation
 */
class SecurityAuditCommand extends Command
{
    protected $signature = 'eventmanagement:security-audit {--fix : Attempt to fix issues automatically}';
    protected $description = 'Perform security audit of EventManagement module';

    private InputSanitizationService $sanitizationService;
    private SqlInjectionPreventionService $sqlService;

    public function __construct(
        InputSanitizationService $sanitizationService,
        SqlInjectionPreventionService $sqlService
    ) {
        parent::__construct();
        $this->sanitizationService = $sanitizationService;
        $this->sqlService = $sqlService;
    }

    public function handle(): int
    {
        $this->info('Starting EventManagement Security Audit...');
        
        $issues = [];
        
        // Audit file upload security
        $issues['file_uploads'] = $this->auditFileUploads();
        
        // Audit SQL injection prevention
        $issues['sql_injection'] = $this->auditSqlInjection();
        
        // Audit XSS prevention
        $issues['xss_prevention'] = $this->auditXssPrevention();
        
        // Audit input validation
        $issues['input_validation'] = $this->auditInputValidation();
        
        // Audit authentication and authorization
        $issues['auth'] = $this->auditAuthentication();
        
        // Generate report
        $this->generateSecurityReport($issues);
        
        return 0;
    }

    private function auditFileUploads(): array
    {
        $this->info('Auditing file upload security...');
        
        $issues = [];
        $controllers = $this->getPhpFiles('Http/Controllers');
        
        foreach ($controllers as $file) {
            $content = File::get($file);
            
            // Check for file upload handling
            if (preg_match_all('/\$request->file\(/', $content, $matches)) {
                // Check if proper validation is used
                if (!preg_match('/validateFileUpload|InputSanitizationService/', $content)) {
                    $issues[] = [
                        'file' => $file,
                        'type' => 'missing_file_validation',
                        'description' => 'File upload without proper validation'
                    ];
                }
            }
            
            // Check for direct file storage without validation
            if (preg_match('/Storage::.*put.*file_get_contents/', $content)) {
                if (!preg_match('/validateFileUpload/', $content)) {
                    $issues[] = [
                        'file' => $file,
                        'type' => 'unsafe_file_storage',
                        'description' => 'File storage without security validation'
                    ];
                }
            }
        }
        
        return $issues;
    }

    private function auditSqlInjection(): array
    {
        $this->info('Auditing SQL injection prevention...');
        
        $issues = [];
        $phpFiles = $this->getPhpFiles();
        
        foreach ($phpFiles as $file) {
            $content = File::get($file);
            
            // Check for raw SQL usage
            if (preg_match_all('/DB::raw\s*\(\s*["\']([^"\']*)["\']/', $content, $matches)) {
                foreach ($matches[1] as $rawSql) {
                    $violations = $this->sqlService->validateInput($rawSql);
                    if (!empty($violations)) {
                        $issues[] = [
                            'file' => $file,
                            'type' => 'unsafe_raw_sql',
                            'description' => 'Potentially unsafe DB::raw usage',
                            'sql' => $rawSql,
                            'violations' => $violations
                        ];
                    }
                }
            }
            
            // Check for string concatenation in queries
            if (preg_match('/\$\w+\s*\.\s*["\'].*["\']|\$\{/', $content)) {
                $issues[] = [
                    'file' => $file,
                    'type' => 'sql_concatenation',
                    'description' => 'Potential SQL injection via string concatenation'
                ];
            }
            
            // Check for whereRaw usage
            if (preg_match_all('/whereRaw\s*\(\s*["\']([^"\']*)["\']/', $content, $matches)) {
                foreach ($matches[1] as $whereRaw) {
                    $violations = $this->sqlService->validateInput($whereRaw);
                    if (!empty($violations)) {
                        $issues[] = [
                            'file' => $file,
                            'type' => 'unsafe_where_raw',
                            'description' => 'Potentially unsafe whereRaw usage',
                            'sql' => $whereRaw
                        ];
                    }
                }
            }
        }
        
        return $issues;
    }

    private function auditXssPrevention(): array
    {
        $this->info('Auditing XSS prevention...');
        
        $issues = [];
        
        // Check PHP files for unescaped output
        $phpFiles = $this->getPhpFiles();
        foreach ($phpFiles as $file) {
            $content = File::get($file);
            
            // Check for direct echo/print without escaping
            if (preg_match('/echo\s+\$|print\s+\$/', $content)) {
                $issues[] = [
                    'file' => $file,
                    'type' => 'unescaped_output',
                    'description' => 'Potential unescaped output'
                ];
            }
        }
        
        // Check frontend files for XSS vulnerabilities
        $frontendPath = base_path('../frontend/src');
        if (File::exists($frontendPath)) {
            $tsxFiles = File::allFiles($frontendPath);
            
            foreach ($tsxFiles as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'tsx') {
                    $content = File::get($file);
                    
                    // Check for dangerouslySetInnerHTML without sanitization
                    if (preg_match('/dangerouslySetInnerHTML/', $content)) {
                        if (!preg_match('/sanitizeHtml|sanitize/', $content)) {
                            $issues[] = [
                                'file' => $file->getPathname(),
                                'type' => 'unsafe_inner_html',
                                'description' => 'dangerouslySetInnerHTML without sanitization'
                            ];
                        }
                    }
                }
            }
        }
        
        return $issues;
    }

    private function auditInputValidation(): array
    {
        $this->info('Auditing input validation...');
        
        $issues = [];
        $controllers = $this->getPhpFiles('Http/Controllers');
        
        foreach ($controllers as $file) {
            $content = File::get($file);
            
            // Check for request handling without validation
            if (preg_match_all('/function\s+(\w+)\s*\([^)]*Request[^)]*\)/', $content, $matches)) {
                foreach ($matches[1] as $method) {
                    // Check if method validates input
                    $methodPattern = '/function\s+' . preg_quote($method) . '\s*\([^{]*\{([^}]*(?:\{[^}]*\}[^}]*)*)\}/s';
                    if (preg_match($methodPattern, $content, $methodMatch)) {
                        $methodBody = $methodMatch[1];
                        
                        if (!preg_match('/validate\(|validated\(|sanitize/', $methodBody)) {
                            $issues[] = [
                                'file' => $file,
                                'type' => 'missing_input_validation',
                                'description' => "Method {$method} may lack input validation",
                                'method' => $method
                            ];
                        }
                    }
                }
            }
        }
        
        return $issues;
    }

    private function auditAuthentication(): array
    {
        $this->info('Auditing authentication and authorization...');
        
        $issues = [];
        $controllers = $this->getPhpFiles('Http/Controllers');
        
        foreach ($controllers as $file) {
            $content = File::get($file);
            
            // Check for missing authentication middleware
            if (!preg_match('/middleware.*auth|__construct.*auth/', $content)) {
                // Check if controller has public methods that might need auth
                if (preg_match('/public\s+function\s+(store|update|destroy|create|edit)/', $content)) {
                    $issues[] = [
                        'file' => $file,
                        'type' => 'missing_authentication',
                        'description' => 'Controller with sensitive methods may lack authentication'
                    ];
                }
            }
            
            // Check for missing authorization checks
            if (preg_match('/function\s+(update|destroy|show)/', $content)) {
                if (!preg_match('/authorize|can\(|Gate::/', $content)) {
                    $issues[] = [
                        'file' => $file,
                        'type' => 'missing_authorization',
                        'description' => 'Sensitive methods may lack authorization checks'
                    ];
                }
            }
        }
        
        return $issues;
    }

    private function getPhpFiles(string $subPath = ''): array
    {
        $basePath = app_path('Modules/EventManagement');
        $searchPath = $subPath ? $basePath . '/' . $subPath : $basePath;
        
        if (!File::exists($searchPath)) {
            return [];
        }
        
        $files = File::allFiles($searchPath);
        
        return array_filter(
            array_map(fn($file) => $file->getPathname(), $files),
            fn($file) => pathinfo($file, PATHINFO_EXTENSION) === 'php'
        );
    }

    private function generateSecurityReport(array $issues): void
    {
        $this->info('Generating security report...');
        
        $totalIssues = 0;
        $criticalIssues = 0;
        
        foreach ($issues as $category => $categoryIssues) {
            $count = count($categoryIssues);
            $totalIssues += $count;
            
            if (in_array($category, ['sql_injection', 'xss_prevention', 'unsafe_file_storage'])) {
                $criticalIssues += $count;
            }
            
            if ($count > 0) {
                $this->warn("Found {$count} issues in {$category}:");
                
                foreach ($categoryIssues as $issue) {
                    $this->line("  - {$issue['description']} in {$issue['file']}");
                }
                
                $this->line('');
            }
        }
        
        // Summary
        if ($totalIssues === 0) {
            $this->info('✅ No security issues found!');
        } else {
            $this->warn("⚠️  Found {$totalIssues} total security issues ({$criticalIssues} critical)");
            
            if ($criticalIssues > 0) {
                $this->error('🚨 Critical security issues require immediate attention!');
            }
        }
        
        // Save detailed report
        $reportPath = storage_path('logs/security-audit-' . date('Y-m-d-H-i-s') . '.json');
        File::put($reportPath, json_encode($issues, JSON_PRETTY_PRINT));
        $this->info("Detailed report saved to: {$reportPath}");
    }
}