<?php

namespace App\Features\AI\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI Raw Access Guard
 *
 * Prevents AI service accounts from directly querying raw database tables.
 * All AI data access must go through the AI Context Builder.
 *
 * NEG-016: AI requests raw table access → AI_RAW_ACCESS_DENIED
 */
class AIRawAccessGuard
{
    /**
     * Tables that AI is NEVER allowed to query directly.
     */
    private const BLOCKED_TABLES = [
        'users',
        'password_resets',
        'personal_access_tokens',
        'tenants',
        'contacts',
        'contact_addresses',
        'contact_people',
        'contact_notes',
        'products',
        'product_variants',
        'product_lots',
        'invoices',
        'invoice_lines',
        'bills',
        'bill_lines',
        'payments',
        'journal_entries',
        'journal_entry_lines',
        'accounts',
        'taxes',
        'tax_rates',
        'warehouses',
        'stock_moves',
        'stock_move_lines',
        'purchase_orders',
        'purchase_order_lines',
        'sales_orders',
        'sales_order_lines',
        'delivery_orders',
        'delivery_order_lines',
        'quotations',
        'quotation_lines',
        'credit_notes',
        'credit_note_lines',
        'debit_notes',
        'debit_note_lines',
        'bank_accounts',
        'bank_transactions',
        'reconciliation_rules',
        'fiscal_years',
        'lock_dates',
        'currencies',
        'exchange_rates',
        'company_settings',
        'default_accounts',
        'chart_of_accounts',
        'audit_log',
        'ai_reasoning_records',
        'ai_approval_requests',
        'ai_accounting_audit_logs',
        'platform_evidence_packs',
        'platform_evidence_items',
        'data_quality_rules',
        'data_quality_scores',
        'data_quality_alerts',
        'hr_employees',
        'hr_contracts',
        'hr_payroll',
        'hr_leave',
        'hr_attendance',
        'compliance_documents',
        'compliance_submissions',
        'compliance_events',
        'acct_country_configurations',
    ];

    /**
     * Restricted fields that must never appear in AI context.
     * These are HR, financial, or personally identifiable fields.
     */
    private const RESTRICTED_FIELDS = [
        // HR data
        'salary',
        'salary_amount',
        'bank_account_number',
        'bank_account_name',
        'bank_name',
        'bank_routing_number',
        'nric',
        'nric_number',
        'passport_number',
        'tax_file_number',
        'epf_number',
        'socso_number',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'medical_conditions',
        'performance_reviews',
        'disciplinary_actions',
        // Financial secrets
        'api_credentials',
        'api_key',
        'api_secret',
        'private_key',
        'certificate',
        'signature',
        'password',
        'secret',
        'token',
        // Personal data
        'date_of_birth',
        'date_of_birth_encrypted',
        'personal_email',
        'personal_phone',
        'home_address',
        'ic_number',
    ];

    /**
     * Check if a raw table query is allowed.
     *
     * @param string $tableName The table being queried
     * @param string $userId The user/AI service account making the request
     * @param array $context Additional context (module, action, etc.)
     * @return array{allowed: bool, reason: string|null, code: string|null}
     */
    public function checkRawAccess(string $tableName, string $userId, array $context = []): array
    {
        $normalizedTable = strtolower($tableName);

        // Check if table is in blocked list
        if (in_array($normalizedTable, self::BLOCKED_TABLES)) {
            Log::warning('AI_RAW_ACCESS_DENIED', [
                'table' => $tableName,
                'user_id' => $userId,
                'module' => $context['module'] ?? null,
                'action' => $context['action'] ?? null,
                'timestamp' => now()->toIso8601String(),
            ]);

            return [
                'allowed' => false,
                'reason' => "Direct access to table '{$tableName}' is denied for AI accounts. Use AI Context Builder instead.",
                'code' => 'AI_RAW_ACCESS_DENIED',
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'code' => null,
        ];
    }

    /**
     * Check if a field is restricted and should be excluded from AI context.
     *
     * @param string $fieldName The field being accessed
     * @return bool True if the field is restricted
     */
    public function isFieldRestricted(string $fieldName): bool
    {
        $normalizedField = strtolower($fieldName);

        foreach (self::RESTRICTED_FIELDS as $restricted) {
            if ($normalizedField === strtolower($restricted) || Str::contains($normalizedField, $restricted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter an array of data to remove restricted fields.
     *
     * @param array $data The raw data
     * @return array Filtered data with restricted fields removed
     */
    public function filterRestrictedFields(array $data): array
    {
        $filtered = [];
        foreach ($data as $key => $value) {
            if (!$this->isFieldRestricted($key)) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    /**
     * Get list of all blocked tables.
     *
     * @return array
     */
    public function getBlockedTables(): array
    {
        return self::BLOCKED_TABLES;
    }

    /**
     * Get list of all restricted fields.
     *
     * @return array
     */
    public function getRestrictedFields(): array
    {
        return self::RESTRICTED_FIELDS;
    }
}
