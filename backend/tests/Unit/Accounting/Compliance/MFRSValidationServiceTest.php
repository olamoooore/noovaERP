<?php

namespace Tests\Unit\Accounting\Compliance;

use App\Models\Invoice;
use Modules\AccountingCountry\Malaysia\Services\MFRSValidationService;
use PHPUnit\Framework\TestCase;

class MFRSValidationServiceTest extends TestCase
{
    public function test_validate_invoice_allows_header_only_invoice_when_totals_exist(): void
    {
        $invoice = new Invoice();
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

        $validator = new MFRSValidationService();
        $result = $validator->validateInvoice($invoice);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }
}
