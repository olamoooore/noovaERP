<?php

namespace Tests\Unit\Accounting\Compliance;

use Modules\AccountingCountry\Singapore\Services\SFRSTaxonomyProfile;
use Modules\AccountingCountry\Singapore\Services\SFRSValidationService;
use Modules\AccountingCountry\Singapore\Services\XBRLReportExporter;
use PHPUnit\Framework\TestCase;

class SingaporeXbrlExporterTest extends TestCase
{
    public function test_sfrs_profile_maps_core_balance_sheet_and_profit_loss_accounts(): void
    {
        $profile = new SFRSTaxonomyProfile;

        $cash = $profile->mapAccount([
            'code' => '1010',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'name' => 'Cash',
        ]);

        $sales = $profile->mapAccount([
            'code' => '4100',
            'type' => 'revenue',
            'subtype' => 'operating_revenue',
            'name' => 'Sales Revenue',
        ]);

        $opex = $profile->mapAccount([
            'code' => '5200',
            'type' => 'expense',
            'subtype' => 'operating_expense',
            'name' => 'Operating Expenses',
        ]);

        $this->assertSame('CashAndCashEquivalents', $cash['element']);
        $this->assertSame('balance_sheet', $cash['statement']);
        $this->assertSame('Revenue', $sales['element']);
        $this->assertSame('income_statement', $sales['statement']);
        $this->assertSame('AdministrativeAndOperatingExpenses', $opex['element']);
    }

    public function test_sfrs_validator_rejects_account_code_grouping_mismatch(): void
    {
        $validator = new SFRSValidationService(new SFRSTaxonomyProfile);

        $this->assertFalse($validator->validateAccountMapping('4100', 'asset'));
        $this->assertNotEmpty($validator->getErrors());
        $this->assertStringContainsString('outside the expected asset range', implode(' ', $validator->getErrors()));
    }

    public function test_exporter_generates_structured_xbrl_xml_from_fixture_report_data(): void
    {
        $exporter = new XBRLReportExporter(new SFRSTaxonomyProfile);

        $xml = $exporter->exportXBRL([
            'type' => 'trial_balance',
            'taxonomy_version' => 'ACRA-SFRS-2024',
            'as_of_date' => '2026-03-31',
            'include_source_data' => false,
            'accounts' => [
                [
                    'account_code' => '1010',
                    'account_name' => 'Cash',
                    'taxonomy_element' => 'CashAndCashEquivalents',
                    'debit' => 1500.00,
                    'credit' => 0.00,
                ],
                [
                    'account_code' => '4100',
                    'account_name' => 'Sales Revenue',
                    'taxonomy_element' => 'Revenue',
                    'debit' => 0.00,
                    'credit' => 1500.00,
                ],
            ],
        ]);

        $this->assertStringContainsString('<xbrli:xbrl', $xml);
        $this->assertStringContainsString('acra-sfrs-2024-entrypoint.xsd', $xml);
        $this->assertStringContainsString('<sg-sfrs:TaxonomyVersion>ACRA-SFRS-2024</sg-sfrs:TaxonomyVersion>', $xml);
        $this->assertStringContainsString('<sg-sfrs:AccountCode>1010</sg-sfrs:AccountCode>', $xml);
        $this->assertStringContainsString('<sg-sfrs:TaxonomyElement>CashAndCashEquivalents</sg-sfrs:TaxonomyElement>', $xml);
        $this->assertStringContainsString('<sg-sfrs:CreditAmount>1500.00</sg-sfrs:CreditAmount>', $xml);
    }

    public function test_exporter_exposes_taxonomy_validation_state_when_xsd_not_installed(): void
    {
        putenv('COMPLIANCE_SG_TAXONOMY_ENTRY_XSD=definitely-not-present.xsd');

        $exporter = new XBRLReportExporter(new SFRSTaxonomyProfile);

        $xml = $exporter->exportXBRL([
            'type' => 'trial_balance',
            'taxonomy_version' => 'ACRA-SFRS-2024',
            'as_of_date' => '2026-03-31',
            'include_source_data' => false,
            'accounts' => [
                [
                    'account_code' => '1010',
                    'account_name' => 'Cash',
                    'taxonomy_element' => 'CashAndCashEquivalents',
                    'debit' => 100.00,
                    'credit' => 0.00,
                ],
                [
                    'account_code' => '3000',
                    'account_name' => 'Share Capital',
                    'taxonomy_element' => 'ShareCapital',
                    'debit' => 0.00,
                    'credit' => 100.00,
                ],
            ],
        ]);

        $this->assertNotEmpty($xml);

        $validation = $exporter->getLastXbrlValidation();
        $this->assertArrayHasKey('validated', $validation);
        $this->assertArrayHasKey('valid', $validation);
        $this->assertArrayHasKey('errors', $validation);
        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['errors']);

        putenv('COMPLIANCE_SG_TAXONOMY_ENTRY_XSD');
    }
}
