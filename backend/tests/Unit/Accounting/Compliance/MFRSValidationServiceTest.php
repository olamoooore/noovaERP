<?php

namespace Tests\Unit\Accounting\Compliance;

use App\Models\Invoice;
use Modules\AccountingCountry\Malaysia\Services\MFRSValidationService;
use PHPUnit\Framework\TestCase;

class MFRSValidationServiceTest extends TestCase
{
    public function test_validate_invoice_allows_header_only_invoice_when_totals_exist(): void
    {
        $invoice = new Invoice;
        $invoice->setRawAttributes([
            'invoice_number' => 'INV-2026-0014',
            'invoice_date' => '2026-02-03',
            'subtotal' => 27.27,
            'tax_amount' => 2.73,
            'total' => 30.00,
            'tax_rate' => 0,
        ], true);

        $invoice->supplier = (object) [
            'tax_identification_number' => 'IG22308550020',
            'identity_type' => 'NRIC',
            'identity_number' => '840516145713',
        ];

        $invoice->customer = (object) [
            'name' => 'Sham Adren',
            'type' => 'individual',
        ];

        $invoice->lines = collect();

        $validator = new MFRSValidationService;
        $result = $validator->validateInvoice($invoice);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_validate_invoice_uses_supplier_identity_from_tenant_configuration(): void
    {
        $invoice = new Invoice;
        $invoice->setRawAttributes([
            'invoice_number' => 'INV-2026-0015',
            'invoice_date' => '2026-09-14',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'tax_rate' => 0,
        ], true);

        $invoice->customer = (object) [
            'name' => 'Individual Buyer',
            'type' => 'individual',
        ];
        $invoice->lines = collect();

        $result = (new MFRSValidationService)->validateInvoice($invoice, [
            'supplier_tin' => 'C59942841040',
            'supplier_id_type' => 'BRN',
            'supplier_id_number' => '202501010250',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_validate_credentials_reports_every_missing_myinvois_field(): void
    {
        $error = (new MFRSValidationService)->validateCredentials([]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('LHDN API Client ID', $error);
        $this->assertStringContainsString('LHDN API Client Secret', $error);
        $this->assertStringContainsString('Supplier TIN', $error);
        $this->assertStringContainsString('Supplier Name', $error);
        $this->assertStringContainsString('Accounting → MyInvois Settings', $error);
    }

    public function test_validate_credentials_accepts_a_complete_context(): void
    {
        $error = (new MFRSValidationService)->validateCredentials([
            'myinvoice_client_id' => 'id',
            'myinvoice_client_secret' => 'secret',
            'supplier_tin' => 'C1234567890',
            'supplier_name' => 'NoovaERP Sdn Bhd',
        ]);

        $this->assertNull($error);
    }
}
