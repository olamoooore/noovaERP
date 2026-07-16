<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\Log;

/**
 * SQL Injection Prevention Service
 *
 * Provides utilities to prevent SQL injection attacks
 * and validate database queries for security.
 *
 * Requirements: 8.5 - Input sanitization validation
 */
class SqlInjectionPreventionService
{
    /**
     * Dangerous SQL keywords that should be monitored
     */
    private const DANGEROUS_KEYWORDS = [
        'union', 'select', 'insert', 'update', 'delete', 'drop', 'create', 'alter',
        'exec', 'execute', 'sp_', 'xp_', 'declare', 'cast', 'convert', 'char',
        'nchar', 'varchar', 'nvarchar', 'ascii', 'substring', 'len', 'concat',
    ];

    /**
     * SQL injection patterns to detect
     */
    private const INJECTION_PATTERNS = [
        '/(\s*(union|select|insert|update|delete|drop|create|alter)\s+)/i',
        '/(\s*(or|and)\s+\d+\s*=\s*\d+)/i',
        '/(\s*;\s*)/i',
        '/(\s*--\s*)/i',
        '/(\s*\/\*.*?\*\/\s*)/i',
        '/(\s*\'\s*(or|and)\s*\'\s*=\s*\')/i',
        '/(\s*"\s*(or|and)\s*"\s*=\s*")/i',
    ];

    /**
     * Validate input for SQL injection patterns
     */
    public function validateInput(string $input): array
    {
        $violations = [];

        // Check for dangerous keywords
        foreach (self::DANGEROUS_KEYWORDS as $keyword) {
            if (stripos($input, $keyword) !== false) {
                $violations[] = [
                    'type' => 'dangerous_keyword',
                    'keyword' => $keyword,
                    'position' => stripos($input, $keyword),
                ];
            }
        }

        // Check for injection patterns
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $input, $matches)) {
                $violations[] = [
                    'type' => 'injection_pattern',
                    'pattern' => $pattern,
                    'match' => $matches[0],
                ];
            }
        }

        return $violations;
    }

    /**
     * Sanitize input for safe database usage
     */
    public function sanitizeForDatabase(string $input): string
    {
        // Remove null bytes
        $input = str_replace("\0", '', $input);

        // Remove or escape dangerous characters
        $input = str_replace(['--', '/*', '*/', ';'], '', $input);

        // Escape single quotes
        $input = str_replace("'", "''", $input);

        // Remove excessive whitespace
        $input = preg_replace('/\s+/', ' ', $input);

        return trim($input);
    }

    /**
     * Validate parameterized query for safety
     */
    public function validateParameterizedQuery(string $query, array $bindings = []): array
    {
        $issues = [];

        // Check if query uses proper parameter binding
        $parameterCount = substr_count($query, '?');
        $namedParameterCount = preg_match_all('/:\w+/', $query);
        $bindingCount = count($bindings);

        if ($parameterCount > 0 && $parameterCount !== $bindingCount) {
            $issues[] = 'Parameter count mismatch: expected '.$parameterCount.', got '.$bindingCount;
        }

        // Check for raw SQL concatenation
        if (preg_match('/\$\w+|\{\w+\}|"\s*\.\s*\$|\'\s*\.\s*\$/', $query)) {
            $issues[] = 'Potential SQL concatenation detected';
        }

        // Check for dangerous raw SQL usage
        if (stripos($query, 'DB::raw') !== false) {
            $issues[] = 'DB::raw usage detected - ensure proper sanitization';
        }

        return $issues;
    }

    /**
     * Create safe LIKE query parameter
     */
    public function createSafeLikeParameter(string $search): string
    {
        // Escape LIKE wildcards
        $search = str_replace(['%', '_'], ['\%', '\_'], $search);

        // Sanitize the search term
        $search = $this->sanitizeForDatabase($search);

        return '%'.$search.'%';
    }

    /**
     * Validate ORDER BY clause for safety
     */
    public function validateOrderByClause(string $orderBy, array $allowedColumns): bool
    {
        // Remove whitespace and convert to lowercase
        $orderBy = strtolower(trim($orderBy));

        // Extract column name (remove ASC/DESC)
        $column = preg_replace('/\s+(asc|desc)$/', '', $orderBy);

        // Check if column is in allowed list
        return in_array($column, array_map('strtolower', $allowedColumns));
    }

    /**
     * Create safe dynamic WHERE clause
     */
    public function createSafeWhereClause(array $conditions, array $allowedColumns): array
    {
        $safeConditions = [];
        $bindings = [];

        foreach ($conditions as $column => $value) {
            // Validate column name
            if (! in_array($column, $allowedColumns)) {
                continue;
            }

            // Sanitize column name (should only contain alphanumeric and underscore)
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
                continue;
            }

            $safeConditions[] = $column.' = ?';
            $bindings[] = $value;
        }

        return [
            'where_clause' => implode(' AND ', $safeConditions),
            'bindings' => $bindings,
        ];
    }

    /**
     * Log potential SQL injection attempt
     */
    public function logInjectionAttempt(string $input, array $violations, array $context = []): void
    {
        Log::warning('Potential SQL injection attempt detected', [
            'input' => substr($input, 0, 200),
            'violations' => $violations,
            'context' => $context,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Validate table name for dynamic queries
     */
    public function validateTableName(string $tableName, array $allowedTables): bool
    {
        // Check if table name is in allowed list
        if (! in_array($tableName, $allowedTables)) {
            return false;
        }

        // Validate table name format
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
            return false;
        }

        return true;
    }

    /**
     * Create safe pagination parameters
     */
    public function createSafePaginationParams(int $page, int $perPage): array
    {
        // Ensure positive values
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage)); // Limit to 100 items per page

        $offset = ($page - 1) * $perPage;

        return [
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * Validate and sanitize search query
     */
    public function sanitizeSearchQuery(string $query): string
    {
        // Remove SQL injection patterns
        foreach (self::INJECTION_PATTERNS as $pattern) {
            $query = preg_replace($pattern, '', $query);
        }

        // Remove dangerous keywords
        foreach (self::DANGEROUS_KEYWORDS as $keyword) {
            $query = str_ireplace($keyword, '', $query);
        }

        // Clean up whitespace
        $query = preg_replace('/\s+/', ' ', $query);

        return trim($query);
    }

    /**
     * Check if query uses safe practices
     */
    public function auditQuery(string $query, array $bindings = []): array
    {
        $audit = [
            'safe' => true,
            'warnings' => [],
            'recommendations' => [],
        ];

        // Check for parameter binding
        if (preg_match('/\$\w+|\{\w+\}/', $query)) {
            $audit['safe'] = false;
            $audit['warnings'][] = 'Direct variable interpolation detected';
            $audit['recommendations'][] = 'Use parameter binding instead of string concatenation';
        }

        // Check for raw SQL usage
        if (stripos($query, 'DB::raw') !== false) {
            $audit['warnings'][] = 'Raw SQL usage detected';
            $audit['recommendations'][] = 'Ensure raw SQL is properly sanitized';
        }

        // Check for proper parameter usage
        $paramCount = substr_count($query, '?');
        if ($paramCount !== count($bindings)) {
            $audit['safe'] = false;
            $audit['warnings'][] = 'Parameter binding count mismatch';
        }

        return $audit;
    }
}
