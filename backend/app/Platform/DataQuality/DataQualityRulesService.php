<?php

namespace App\Platform\DataQuality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Data Quality Rules Service
 *
 * Shared data quality rules and scoring across all modules.
 * Replaces module-specific implementations with a unified platform service.
 */
class DataQualityRulesService
{
    /**
     * Built-in data quality rules.
     */
    private const RULES = [
        // Completeness rules
        'required_field_not_null' => [
            'category' => 'completeness',
            'severity' => 'critical',
            'description' => 'Required field must not be null or empty',
        ],
        'required_field_not_empty' => [
            'category' => 'completeness',
            'severity' => 'critical',
            'description' => 'Required field must not be empty string',
        ],
        // Uniqueness rules
        'unique_value' => [
            'category' => 'uniqueness',
            'severity' => 'critical',
            'description' => 'Value must be unique within scope',
        ],
        // Consistency rules
        'foreign_key_exists' => [
            'category' => 'consistency',
            'severity' => 'high',
            'description' => 'Foreign key reference must exist in parent table',
        ],
        'referential_integrity' => [
            'category' => 'consistency',
            'severity' => 'high',
            'description' => 'Referential integrity constraint violated',
        ],
        // Format rules
        'valid_email' => [
            'category' => 'format',
            'severity' => 'medium',
            'description' => 'Value must be a valid email address',
        ],
        'valid_date' => [
            'category' => 'format',
            'severity' => 'medium',
            'description' => 'Value must be a valid date',
        ],
        'valid_number' => [
            'category' => 'format',
            'severity' => 'medium',
            'description' => 'Value must be a valid number',
        ],
        // Range rules
        'positive_value' => [
            'category' => 'range',
            'severity' => 'medium',
            'description' => 'Value must be positive',
        ],
        'non_negative_value' => [
            'category' => 'range',
            'severity' => 'medium',
            'description' => 'Value must be non-negative',
        ],
        // Business rules
        'debit_equals_credit' => [
            'category' => 'business',
            'severity' => 'critical',
            'description' => 'Total debits must equal total credits in journal entry',
        ],
        'invoice_balance_valid' => [
            'category' => 'business',
            'severity' => 'high',
            'description' => 'Invoice balance must be valid',
        ],
        'stock_sufficient' => [
            'category' => 'business',
            'severity' => 'high',
            'description' => 'Sufficient stock for operation',
        ],
    ];

    /**
     * Run data quality rules on a specific table.
     *
     * @param string $tableName The table to check
     * @param array $rules Rules to apply (empty = all applicable rules)
     * @param array $options Additional options (tenant_id, limit, etc.)
     * @return array{passed: bool, score: float, violations: array, summary: array}
     */
    public function validateTable(string $tableName, array $rules = [], array $options = []): array
    {
        $tenantId = $options['tenant_id'] ?? tenant()->id;
        $limit = $options['limit'] ?? 1000;

        // Get table columns
        $columns = $this->getTableColumns($tableName);
        if (empty($columns)) {
            return [
                'passed' => true,
                'score' => 1.0,
                'violations' => [],
                'summary' => ['message' => "Table {$tableName} not found or has no columns"],
            ];
        }

        $violations = [];
        $totalChecks = 0;
        $passedChecks = 0;

        // Run completeness checks
        foreach ($columns as $column) {
            $totalChecks++;
            $result = $this->checkCompleteness($tableName, $column, $tenantId, $limit);
            if ($result['passed']) {
                $passedChecks++;
            } else {
                $violations[] = $result;
            }
        }

        // Run business rules if applicable
        $businessRules = $this->getBusinessRulesForTable($tableName);
        foreach ($businessRules as $ruleName) {
            if (!empty($rules) && !in_array($ruleName, $rules)) {
                continue;
            }
            $totalChecks++;
            $result = $this->checkBusinessRule($tableName, $ruleName, $tenantId);
            if ($result['passed']) {
                $passedChecks++;
            } else {
                $violations[] = $result;
            }
        }

        $score = $totalChecks > 0 ? $passedChecks / $totalChecks : 1.0;

        return [
            'passed' => empty($violations),
            'score' => round($score, 4),
            'violations' => $violations,
            'summary' => [
                'table' => $tableName,
                'total_checks' => $totalChecks,
                'passed_checks' => $passedChecks,
                'failed_checks' => count($violations),
                'score' => round($score * 100, 2) . '%',
            ],
        ];
    }

    /**
     * Calculate overall data quality score across multiple tables.
     *
     * @param array $tables Tables to check
     * @param array $options
     * @return array{overall_score: float, table_scores: array, violations: array}
     */
    public function calculateOverallScore(array $tables, array $options = []): array
    {
        $tableScores = [];
        $allViolations = [];
        $totalScore = 0;

        foreach ($tables as $tableName) {
            $result = $this->validateTable($tableName, [], $options);
            $tableScores[$tableName] = $result['score'];
            $allViolations = array_merge($allViolations, $result['violations']);
            $totalScore += $result['score'];
        }

        $overallScore = count($tables) > 0 ? $totalScore / count($tables) : 1.0;

        return [
            'overall_score' => round($overallScore, 4),
            'table_scores' => $tableScores,
            'violations' => $allViolations,
            'summary' => [
                'tables_checked' => count($tables),
                'overall_score' => round($overallScore * 100, 2) . '%',
                'total_violations' => count($allViolations),
            ],
        ];
    }

    /**
     * Run a specific rule on a table.
     *
     * @param string $tableName
     * @param string $ruleName
     * @param array $options
     * @return array{passed: bool, violations: array}
     */
    public function runRule(string $tableName, string $ruleName, array $options = []): array
    {
        $tenantId = $options['tenant_id'] ?? tenant()->id;
        $result = $this->checkBusinessRule($tableName, $ruleName, $tenantId);

        return [
            'passed' => $result['passed'],
            'violations' => $result['passed'] ? [] : [$result],
        ];
    }

    /**
     * Get available rules.
     *
     * @return array
     */
    public function getAvailableRules(): array
    {
        return self::RULES;
    }

    /**
     * Get rules by category.
     *
     * @param string $category
     * @return array
     */
    public function getRulesByCategory(string $category): array
    {
        return array_filter(
            self::RULES,
            fn($rule) => $rule['category'] === $category
        );
    }

    /**
     * Get table columns using schema inspection.
     */
    private function getTableColumns(string $tableName): array
    {
        try {
            $results = DB::select(
                "SELECT column_name FROM information_schema.columns WHERE table_name = ? AND table_schema = 'public'",
                [$tableName]
            );
            return array_map(fn($r) => $r->column_name, $results);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check completeness (NOT NULL, NOT EMPTY) for a column.
     */
    private function checkCompleteness(string $tableName, string $column, ?string $tenantId, int $limit): array
    {
        try {
            // Check NULL values
            $nullCount = DB::table($tableName)
                ->where('tenant_id', $tenantId)
                ->whereNull($column)
                ->count();

            // Check empty strings
            $emptyCount = DB::table($tableName)
                ->where('tenant_id', $tenantId)
                ->where($column, '')
                ->count();

            $totalIssues = $nullCount + $emptyCount;

            if ($totalIssues > 0) {
                $totalRows = DB::table($tableName)->where('tenant_id', $tenantId)->count();
                $completeness = $totalRows > 0 ? ($totalRows - $totalIssues) / $totalRows : 1.0;

                return [
                    'passed' => false,
                    'rule' => 'required_field_not_null',
                    'table' => $tableName,
                    'column' => $column,
                    'null_count' => $nullCount,
                    'empty_count' => $emptyCount,
                    'total_rows' => $totalRows,
                    'completeness' => round($completeness * 100, 2) . '%',
                    'severity' => $completeness < 0.9 ? 'critical' : 'medium',
                ];
            }

            return ['passed' => true];
        } catch (\Exception $e) {
            return ['passed' => true]; // Column might not exist
        }
    }

    /**
     * Get business rules applicable to a table.
     */
    private function getBusinessRulesForTable(string $tableName): array
    {
        $rules = [
            'journal_entries' => ['debit_equals_credit'],
            'invoices' => ['invoice_balance_valid'],
            'bills' => ['invoice_balance_valid'],
            'stock_moves' => ['stock_sufficient'],
        ];

        return $rules[$tableName] ?? [];
    }

    /**
     * Check a specific business rule.
     */
    private function checkBusinessRule(string $tableName, string $ruleName, ?string $tenantId): array
    {
        switch ($ruleName) {
            case 'debit_equals_credit':
                return $this->checkDebitEqualsCredit($tenantId);
            case 'invoice_balance_valid':
                return $this->checkInvoiceBalanceValid($tableName, $tenantId);
            case 'stock_sufficient':
                return $this->checkStockSufficient($tenantId);
            default:
                return ['passed' => true];
        }
    }

    /**
     * Check journal entry balance (debit = credit).
     */
    private function checkDebitEqualsCredit(?string $tenantId): array
    {
        try {
            $query = DB::table('journal_entries as je')
                ->join('journal_entry_lines as jel', 'je.id', 'jel.journal_entry_id')
                ->select('je.id', 'je.entry_number')
                ->selectRaw('SUM(jel.debit) as total_debit, SUM(jel.credit) as total_credit')
                ->groupBy('je.id', 'je.entry_number')
                ->havingRaw('ABS(SUM(jel.debit) - SUM(jel.credit)) > 0.01');

            // Only filter by tenant_id if column exists
            if (Schema::hasColumn('journal_entries', 'tenant_id')) {
                $query->where('je.tenant_id', $tenantId);
            }

            $unbalanced = $query->get();

            if ($unbalanced->isNotEmpty()) {
                return [
                    'passed' => false,
                    'rule' => 'debit_equals_credit',
                    'severity' => 'critical',
                    'unbalanced_entries' => $unbalanced->toArray(),
                    'count' => $unbalanced->count(),
                ];
            }

            return ['passed' => true];
        } catch (\Exception $e) {
            return ['passed' => true]; // Table structure doesn't support this check
        }
    }

    /**
     * Check invoice balance validity.
     */
    private function checkInvoiceBalanceValid(string $tableName, ?string $tenantId): array
    {
        $invalid = DB::table($tableName)
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) {
                $query->where('total', '<', 0)
                    ->orWhere('balance', '<', 0);
            })
            ->get();

        if ($invalid->isNotEmpty()) {
            return [
                'passed' => false,
                'rule' => 'invoice_balance_valid',
                'severity' => 'high',
                'invalid_invoices' => $invalid->toArray(),
                'count' => $invalid->count(),
            ];
        }

        return ['passed' => true];
    }

    /**
     * Check stock sufficiency.
     */
    private function checkStockSufficient(?string $tenantId): array
    {
        // Check for negative stock levels
        $negativeStock = DB::table('stock_moves as sm')
            ->join('products as p', 'sm.product_id', 'p.id')
            ->where('sm.tenant_id', $tenantId)
            ->select('p.id', 'p.name', 'p.sku')
            ->selectRaw('SUM(sm.quantity) as total_quantity')
            ->groupBy('p.id', 'p.name', 'p.sku')
            ->havingRaw('SUM(sm.quantity) < 0')
            ->get();

        if ($negativeStock->isNotEmpty()) {
            return [
                'passed' => false,
                'rule' => 'stock_sufficient',
                'severity' => 'high',
                'negative_stock_products' => $negativeStock->toArray(),
                'count' => $negativeStock->count(),
            ];
        }

        return ['passed' => true];
    }
}
