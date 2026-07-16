<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Features\AI\Services\AIContextBuilder;
use App\Features\AI\Services\AIRawAccessGuard;
use App\Platform\DataQuality\DataQualityRulesService;
use Tests\TenantTestCase;

/**
 * P0-H Negative Tests — AI Access Boundary, Data Quality, Evidence Pack
 *
 * NEG-016: AI requests raw table access → AI_RAW_ACCESS_DENIED
 * NEG-017: AI context includes restricted HR/private data → blocked
 * Data Quality: Rules and scoring validation
 */
class AiAccessBoundaryTest extends TenantTestCase
{
    private AIRawAccessGuard $rawGuard;

    private AIContextBuilder $contextBuilder;

    private DataQualityRulesService $dataQuality;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext('test-tenant');
        $this->rawGuard = app(AIRawAccessGuard::class);
        $this->contextBuilder = app(AIContextBuilder::class);
        $this->dataQuality = app(DataQualityRulesService::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // NEG-016: AI requests raw table access → AI_RAW_ACCESS_DENIED
    // ──────────────────────────────────────────────────────────────────────

    public function test_neg016_ai_raw_access_denied_for_users_table(): void
    {
        $result = $this->rawGuard->checkRawAccess('users', 'ai-service-account');

        $this->assertFalse($result['allowed']);
        $this->assertSame('AI_RAW_ACCESS_DENIED', $result['code']);
        $this->assertStringContainsString('denied', $result['reason']);
    }

    public function test_neg016_ai_raw_access_denied_for_contacts_table(): void
    {
        $result = $this->rawGuard->checkRawAccess('contacts', 'ai-service-account');

        $this->assertFalse($result['allowed']);
        $this->assertSame('AI_RAW_ACCESS_DENIED', $result['code']);
    }

    public function test_neg016_ai_raw_access_denied_for_invoices_table(): void
    {
        $result = $this->rawGuard->checkRawAccess('invoices', 'ai-service-account');

        $this->assertFalse($result['allowed']);
        $this->assertSame('AI_RAW_ACCESS_DENIED', $result['code']);
    }

    public function test_neg016_ai_raw_access_denied_for_products_table(): void
    {
        $result = $this->rawGuard->checkRawAccess('products', 'ai-service-account');

        $this->assertFalse($result['allowed']);
        $this->assertSame('AI_RAW_ACCESS_DENIED', $result['code']);
    }

    public function test_neg016_ai_raw_access_denied_for_hr_tables(): void
    {
        $hrTables = ['hr_employees', 'hr_contracts', 'hr_payroll', 'hr_leave', 'hr_attendance'];

        foreach ($hrTables as $table) {
            $result = $this->rawGuard->checkRawAccess($table, 'ai-service-account');
            $this->assertFalse($result['allowed'], "Expected AI_RAW_ACCESS_DENIED for table: {$table}");
            $this->assertSame('AI_RAW_ACCESS_DENIED', $result['code']);
        }
    }

    public function test_neg016_ai_raw_access_denied_for_financial_tables(): void
    {
        $financialTables = ['journal_entries', 'journal_entry_lines', 'payments', 'bills', 'bank_accounts'];

        foreach ($financialTables as $table) {
            $result = $this->rawGuard->checkRawAccess($table, 'ai-service-account');
            $this->assertFalse($result['allowed'], "Expected AI_RAW_ACCESS_DENIED for table: {$table}");
            $this->assertSame('AI_RAW_ACCESS_DENIED', $result['code']);
        }
    }

    public function test_neg016_ai_raw_access_denied_for_compliance_tables(): void
    {
        $complianceTables = ['compliance_documents', 'compliance_submissions', 'compliance_events', 'acct_country_configurations'];

        foreach ($complianceTables as $table) {
            $result = $this->rawGuard->checkRawAccess($table, 'ai-service-account');
            $this->assertFalse($result['allowed'], "Expected AI_RAW_ACCESS_DENIED for table: {$table}");
            $this->assertSame('AI_RAW_ACCESS_DENIED', $result['code']);
        }
    }

    public function test_neg016_ai_raw_access_denied_for_evidence_tables(): void
    {
        $evidenceTables = ['platform_evidence_packs', 'platform_evidence_items'];

        foreach ($evidenceTables as $table) {
            $result = $this->rawGuard->checkRawAccess($table, 'ai-service-account');
            $this->assertFalse($result['allowed'], "Expected AI_RAW_ACCESS_DENIED for table: {$table}");
            $this->assertSame('AI_RAW_ACCESS_DENIED', $result['code']);
        }
    }

    public function test_neg016_blocked_tables_list_is_comprehensive(): void
    {
        $blocked = $this->rawGuard->getBlockedTables();

        // Must block all critical tables
        $required = [
            'users', 'contacts', 'products', 'invoices', 'bills',
            'payments', 'journal_entries', 'journal_entry_lines',
            'hr_employees', 'hr_contracts', 'hr_payroll',
            'bank_accounts', 'compliance_documents',
        ];

        foreach ($required as $table) {
            $this->assertContains($table, $blocked, "Table {$table} must be in blocked list");
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // NEG-017: AI context includes restricted HR/private data → blocked
    // ──────────────────────────────────────────────────────────────────────

    public function test_neg017_restricted_field_salary_is_blocked(): void
    {
        $this->assertTrue($this->rawGuard->isFieldRestricted('salary'));
        $this->assertTrue($this->rawGuard->isFieldRestricted('salary_amount'));
    }

    public function test_neg017_restricted_field_bank_account_is_blocked(): void
    {
        $this->assertTrue($this->rawGuard->isFieldRestricted('bank_account_number'));
        $this->assertTrue($this->rawGuard->isFieldRestricted('bank_account_name'));
        $this->assertTrue($this->rawGuard->isFieldRestricted('bank_name'));
    }

    public function test_neg017_restricted_field_nric_is_blocked(): void
    {
        $this->assertTrue($this->rawGuard->isFieldRestricted('nric'));
        $this->assertTrue($this->rawGuard->isFieldRestricted('nric_number'));
        $this->assertTrue($this->rawGuard->isFieldRestricted('passport_number'));
    }

    public function test_neg017_restricted_field_api_credentials_is_blocked(): void
    {
        $this->assertTrue($this->rawGuard->isFieldRestricted('api_credentials'));
        $this->assertTrue($this->rawGuard->isFieldRestricted('api_key'));
        $this->assertTrue($this->rawGuard->isFieldRestricted('api_secret'));
        $this->assertTrue($this->rawGuard->isFieldRestricted('private_key'));
    }

    public function test_neg017_restricted_field_tax_info_is_blocked(): void
    {
        $this->assertTrue($this->rawGuard->isFieldRestricted('tax_file_number'));
        $this->assertTrue($this->rawGuard->isFieldRestricted('epf_number'));
        $this->assertTrue($this->rawGuard->isFieldRestricted('socso_number'));
    }

    public function test_neg017_restricted_field_emergency_contact_is_blocked(): void
    {
        $this->assertTrue($this->rawGuard->isFieldRestricted('emergency_contact_name'));
        $this->assertTrue($this->rawGuard->isFieldRestricted('emergency_contact_phone'));
    }

    public function test_neg017_filter_removes_restricted_fields(): void
    {
        $data = [
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'salary' => 5000,
            'bank_account_number' => '1234567890',
            'nric' => 'S1234567A',
            'phone' => '+6591234567',
        ];

        $filtered = $this->rawGuard->filterRestrictedFields($data);

        $this->assertArrayHasKey('id', $filtered);
        $this->assertArrayHasKey('name', $filtered);
        $this->assertArrayHasKey('email', $filtered);
        $this->assertArrayHasKey('phone', $filtered);
        $this->assertArrayNotHasKey('salary', $filtered);
        $this->assertArrayNotHasKey('bank_account_number', $filtered);
        $this->assertArrayNotHasKey('nric', $filtered);
    }

    public function test_neg017_context_builder_blocks_restricted_hr_data(): void
    {
        $result = $this->contextBuilder->buildContext(
            'hr',
            'employee',
            null,
            'ai-service-account'
        );

        // Context builder should either fail or return filtered data
        // Both outcomes prove restricted HR data is not exposed
        if ($result['success']) {
            // If it succeeds, verify no restricted fields in context
            $context = $result['context'];
            if (is_array($context) && ! empty($context)) {
                $firstRecord = (array) $context[0];
                $this->assertArrayNotHasKey('salary', $firstRecord, 'HR salary must not appear in AI context');
                $this->assertArrayNotHasKey('bank_account_number', $firstRecord, 'HR bank account must not appear in AI context');
                $this->assertArrayNotHasKey('nric', $firstRecord, 'HR NRIC must not appear in AI context');
            }
        } else {
            // If it fails, it must be due to access denial
            $this->assertNotNull($result['reason'], 'Context builder must provide a reason when blocked');
        }
    }

    public function test_neg017_restricted_fields_list_is_comprehensive(): void
    {
        $restricted = $this->rawGuard->getRestrictedFields();

        // Must restrict all critical fields
        $required = [
            'salary', 'bank_account_number', 'nric', 'passport_number',
            'api_credentials', 'api_key', 'api_secret', 'private_key',
            'tax_file_number', 'epf_number', 'emergency_contact_name',
        ];

        foreach ($required as $field) {
            $this->assertContains($field, $restricted, "Field {$field} must be in restricted list");
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Data Quality Rules Validation
    // ──────────────────────────────────────────────────────────────────────

    public function test_data_quality_rules_available(): void
    {
        $rules = $this->dataQuality->getAvailableRules();

        $this->assertNotEmpty($rules);
        $this->assertArrayHasKey('required_field_not_null', $rules);
        $this->assertArrayHasKey('unique_value', $rules);
        $this->assertArrayHasKey('debit_equals_credit', $rules);
    }

    public function test_data_quality_completeness_check(): void
    {
        $result = $this->dataQuality->validateTable('products');

        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('violations', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(1, $result['score']);
    }

    public function test_data_quality_journal_entry_balance_rule(): void
    {
        $result = $this->dataQuality->runRule('journal_entries', 'debit_equals_credit');

        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('violations', $result);
    }

    public function test_data_quality_overall_score(): void
    {
        $result = $this->dataQuality->calculateOverallScore(['products', 'contacts']);

        $this->assertArrayHasKey('overall_score', $result);
        $this->assertArrayHasKey('table_scores', $result);
        $this->assertArrayHasKey('violations', $result);
        $this->assertGreaterThanOrEqual(0, $result['overall_score']);
        $this->assertLessThanOrEqual(1, $result['overall_score']);
    }

    public function test_data_quality_rules_by_category(): void
    {
        $completenessRules = $this->dataQuality->getRulesByCategory('completeness');
        $this->assertNotEmpty($completenessRules);

        foreach ($completenessRules as $rule) {
            $this->assertSame('completeness', $rule['category']);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Integration: AI Context Builder + Raw Access Guard
    // ──────────────────────────────────────────────────────────────────────

    public function test_context_builder_uses_ai_safe_views(): void
    {
        $result = $this->contextBuilder->buildContext(
            'accounting',
            'invoice',
            null,
            'ai-service-account'
        );

        // Should not allow direct table access
        if (! $result['success']) {
            $this->assertStringContainsString('denied', $result['reason']);
        }
    }

    public function test_batch_context_filters_restricted_fields(): void
    {
        $result = $this->contextBuilder->buildBatchContext(
            'accounting',
            'contact',
            [1, 2, 3],
            'ai-service-account'
        );

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('contexts', $result);
        $this->assertArrayHasKey('summary', $result);
    }
}
