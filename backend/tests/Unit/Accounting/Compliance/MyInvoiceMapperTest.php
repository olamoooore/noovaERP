<?php

namespace Tests\Unit\Accounting\Compliance;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Support\Collection;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;
use Tests\TestCase;

// Extends Laravel TestCase (not bare PHPUnit) because MyInvoiceMapper calls Log::info()
// which requires a booted container to resolve the Log facade.
class MyInvoiceMapperTest extends TestCase
{
    public function test_map_invoice_uses_tax_exclusive_line_amounts_and_consistent_totals(): void
    {
        $invoice = new Invoice;
        $invoice->setRawAttributes([
            'id' => 16,
            'invoice_number' => 'INV-2026-0014',
            'invoice_date' => '2026-04-06',
            'due_date' => '2026-04-06',
            'currency_code' => 'MYR',
            'subtotal' => 157.50,
            'tax_amount' => 7.50,
            'discount_amount' => 0,
            'total' => 165.00,
            'paid_amount' => 0,
        ], true);

        $invoice->supplier = (object) [
            'name' => 'NoovaERP Sdn Bhd',
            'tax_identification_number' => 'IG22308550020',
            'company_registration' => null,
            'identity_type' => 'NRIC',
            'identity_number' => '840516145713',
            'country_code' => 'MY',
            'is_individual' => true,
        ];

        $invoice->setRelation('customer', (object) [
            'name' => 'Customer',
            'phone' => '07037597163',
            'email' => 'hello@draxel.com',
            'is_individual' => false,
            'country_code' => 'MY',
        ]);

        $line = new InvoiceLine;
        $line->setRawAttributes([
            'id' => 15,
            'description' => 'testing',
            'quantity' => 1,
            'unit_price' => 150.00,
            'tax_rate' => 5.00,
            'tax_amount' => 7.50,
            'discount_amount' => 0,
            'line_total' => 157.50,
        ], true);

        $invoice->setRelation('lines', new Collection([$line]));

        $mapper = new MyInvoiceMapper;
        $payload = $mapper->mapInvoice($invoice);

        $this->assertSame(150.00, $payload['line_items'][0]['line_amount']);
        $this->assertSame(7.50, $payload['line_items'][0]['sst_amount']);
        $this->assertSame(150.00, $payload['totals']['subtotal']);
        $this->assertSame(7.50, $payload['totals']['sst_amount']);
        $this->assertSame(157.50, $payload['totals']['gross_amount']);
        $this->assertSame(157.50, $payload['totals']['amount_due']);
    }

    public function test_map_invoice_persists_myinvois_exemption_fields(): void
    {
        // MRD R5.5: exemption columns were referenced by the mapper but never
        // existed; now they must flow end-to-end into the mapped payload.
        $invoice = new Invoice;
        $invoice->setRawAttributes([
            'id' => 18,
            'invoice_number' => 'INV-2026-0018',
            'invoice_date' => '2026-04-06',
            'currency_code' => 'MYR',
            'subtotal' => 500.00,
            'tax_amount' => 0.00,
            'discount_amount' => 0,
            'total' => 500.00,
            'paid_amount' => 0,
            'tax_exemption_code' => 'A024',
            'tax_exemption_reason' => 'Export of goods (zero-rated)',
        ], true);

        $invoice->supplier = (object) [
            'name' => 'NoovaERP Sdn Bhd',
            'tax_identification_number' => 'IG22308550020',
            'company_registration' => '202401234567',
            'country_code' => 'MY',
        ];

        $invoice->setRelation('customer', (object) [
            'name' => 'Exempt Buyer',
            'email' => 'buyer@example.com',
            'is_individual' => false,
            'country_code' => 'MY',
        ]);

        $line = new InvoiceLine;
        $line->setRawAttributes([
            'id' => 18,
            'description' => 'Export batch',
            'quantity' => 1,
            'unit_price' => 500.00,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'line_total' => 500.00,
            'tax_type' => 'Z',
            'tax_exemption_reason' => 'International supply under SST Rules',
        ], true);
        $invoice->setRelation('lines', new Collection([$line]));

        $payload = (new MyInvoiceMapper)->mapInvoice($invoice);

        $this->assertSame('Z', $payload['line_items'][0]['tax_type']);
        $this->assertSame('International supply under SST Rules', $payload['line_items'][0]['tax_exemption_reason']);
        $this->assertSame('A024', $payload['tax_summary']['sst_exemption_code']);
    }

    public function test_map_invoice_uses_legacy_customer_shipping_address_and_mobile(): void
    {
        $invoice = new Invoice;
        $invoice->setRawAttributes([
            'id' => 17,
            'invoice_number' => 'INV-2026-0015',
            'invoice_date' => '2026-04-06',
            'currency_code' => 'MYR',
            'subtotal' => 27.27,
            'tax_amount' => 2.73,
            'discount_amount' => 0,
            'total' => 30.00,
            'paid_amount' => 0,
        ], true);

        $invoice->supplier = (object) [
            'name' => 'NoovaERP Sdn Bhd',
            'tax_identification_number' => 'IG22308550020',
            'company_registration' => '202401234567',
            'country_code' => 'MY',
        ];

        $invoice->setRelation('customer', (object) [
            'name' => 'Sham Adren',
            'email' => 'hello@draxel.com',
            'phone' => null,
            'mobile' => '+2347037597163',
            'shipping_address' => json_encode([
                'address' => '10, paper mill road, jebba, Kwara state',
                'city' => 'Jebba',
                'postal_code' => '241101',
                'country' => 'Singapore',
            ], JSON_THROW_ON_ERROR),
            'is_individual' => true,
        ]);

        $line = new InvoiceLine;
        $line->setRawAttributes([
            'id' => 17,
            'description' => 'POS item',
            'quantity' => 1,
            'unit_price' => 27.27,
            'tax_rate' => 10.01,
            'tax_amount' => 2.73,
            'discount_amount' => 0,
            'line_total' => 30.00,
        ], true);

        $invoice->setRelation('lines', new Collection([$line]));

        $mapper = new MyInvoiceMapper;
        $payload = $mapper->mapInvoice($invoice);

        $this->assertSame('10, paper mill road, jebba, Kwara state', $payload['buyer']['address']['street']);
        $this->assertSame('Jebba', $payload['buyer']['address']['city']);
        $this->assertSame('241101', $payload['buyer']['address']['postal_code']);
        $this->assertSame('SGP', $payload['buyer']['address']['country']);
        $this->assertSame('+2347037597163', $payload['buyer']['contact']['phone']);
    }

    public function test_map_invoice_builds_fallback_line_when_invoice_has_no_lines(): void
    {
        $invoice = new Invoice;
        $invoice->setRawAttributes([
            'id' => 16,
            'invoice_number' => 'INV-2026-0014',
            'invoice_date' => '2026-02-03',
            'currency_code' => 'MYR',
            'subtotal' => 27.27,
            'tax_amount' => 2.73,
            'discount_amount' => 0,
            'total' => 30.00,
            'paid_amount' => 30.00,
            'notes' => 'Generated from POS Order: POS-20260203224851-OMZP',
        ], true);

        $invoice->supplier = (object) [
            'name' => 'NoovaERP Sdn Bhd',
            'tax_identification_number' => 'IG22308550020',
            'company_registration' => '202401234567',
            'country_code' => 'MY',
        ];

        $invoice->setRelation('customer', (object) [
            'name' => 'Sham Adren',
            'phone' => '07037597163',
            'email' => 'hello@draxel.com',
        ]);

        $invoice->setRelation('lines', new Collection);

        $mapper = new MyInvoiceMapper;
        $payload = $mapper->mapInvoice($invoice);

        $this->assertCount(1, $payload['line_items']);
        $this->assertSame(27.27, $payload['line_items'][0]['line_amount']);
        $this->assertSame(2.73, $payload['line_items'][0]['sst_amount']);
        $this->assertSame('Generated from POS Order: POS-20260203224851-OMZP', $payload['line_items'][0]['description']);
    }

    /**
     * Regression: mapParty() must NOT inject a TIN from .env when the supplier
     * object has null identity fields. The null must pass through so that
     * applyProviderPayloadDefaults() (DB context) fills it from tenant config.
     */
    public function test_supplier_tin_is_null_when_supplier_has_no_tin(): void
    {
        $invoice = new Invoice;
        $invoice->setRawAttributes([
            'id' => 99,
            'invoice_number' => 'INV-NOSUP-001',
            'invoice_date' => '2026-07-01',
            'currency_code' => 'MYR',
            'subtotal' => 100.00,
            'tax_amount' => 6.00,
            'discount_amount' => 0,
            'total' => 106.00,
            'paid_amount' => 0,
        ], true);

        // Supplier with null identity fields — simulates what resolveSupplier() now returns
        // when no invoice supplier is present (DB context fills these later, not .env).
        $invoice->supplier = (object) [
            'name' => 'Company Name',
            'tax_identification_number' => null,
            'company_registration' => null,
            'identity_type' => 'BRN',
            'identity_number' => null,
            'country_code' => 'MY',
            'is_individual' => false,
        ];

        $invoice->setRelation('customer', (object) [
            'name' => 'Test Customer',
            'tin' => 'C1234567890',
        ]);
        $invoice->setRelation('lines', new Collection([
            tap(new InvoiceLine, fn ($l) => $l->setRawAttributes([
                'id' => 1, 'description' => 'Item', 'quantity' => 1,
                'unit_price' => 100.00, 'tax_rate' => 6.0, 'tax_amount' => 6.00,
                'discount_amount' => 0, 'line_total' => 106.00,
            ], true)),
        ]));

        $mapper = new MyInvoiceMapper;
        $payload = $mapper->mapInvoice($invoice);

        $this->assertNull($payload['supplier']['tin'],
            'mapParty() must pass null TIN through — it must NOT inject .env supplier TIN');
        $this->assertNull($payload['supplier']['company_registration'],
            'mapParty() must pass null BRN through — DB context fills it later');
    }

    /**
     * Regression: buyer with no TIN should receive the LHDN general-public constant
     * (EI00000000010), not a value read from .env config.
     */
    public function test_buyer_without_tin_receives_lhdn_general_public_tin(): void
    {
        $invoice = new Invoice;
        $invoice->setRawAttributes([
            'id' => 100,
            'invoice_number' => 'INV-NOTINBUYER-001',
            'invoice_date' => '2026-07-01',
            'currency_code' => 'MYR',
            'subtotal' => 50.00,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 50.00,
            'paid_amount' => 0,
        ], true);

        $invoice->supplier = (object) [
            'name' => 'Supplier Co',
            'tax_identification_number' => 'C9999999990',
            'company_registration' => '202400000001',
            'country_code' => 'MY',
        ];

        $invoice->setRelation('customer', (object) [
            'name' => 'Walk-in Customer',
            // No tin / tax_number / tax_id set
        ]);

        $invoice->setRelation('lines', new Collection([
            tap(new InvoiceLine, fn ($l) => $l->setRawAttributes([
                'id' => 2, 'description' => 'Walk-in sale', 'quantity' => 1,
                'unit_price' => 50.00, 'tax_rate' => 0, 'tax_amount' => 0,
                'discount_amount' => 0, 'line_total' => 50.00,
            ], true)),
        ]));

        $mapper = new MyInvoiceMapper;
        $payload = $mapper->mapInvoice($invoice);

        $this->assertSame('EI00000000010', $payload['buyer']['tin'],
            'Buyer with no TIN must receive the LHDN general-public constant (EI00000000010)');
    }

    /**
     * Regression: listVersionID in the UBL must always be "1.0" for the v1.0 API.
     * This prevents ERR237 ("Invalid or inactive document type/version") from recurring.
     *
     * We verify via the provider's UBL builder so the constant is exercised end-to-end,
     * not just declared.
     */
    public function test_list_version_id_is_always_1_0_in_ubl(): void
    {
        // The mapper itself does not know about listVersionID — that lives in the provider.
        // This test verifies the provider constant via the validateMyInvoicePayload path:
        // when the payload is valid, the provider must NOT reject it with a version error.
        // The provider's buildSubmissionBody hardcodes listVersionID = '1.0'.
        // We verify this by reflection on the private constant / builder.

        $provider = new \Modules\AccountingCountry\Malaysia\Services\MyInvoiceSubmissionProvider(
            new \GuzzleHttp\Client,
            'https://sandbox.myinvois.hasil.gov.my'
        );

        $ref = new \ReflectionClass($provider);
        $method = $ref->getMethod('buildUblDocumentFromMappedPayload');
        $method->setAccessible(true);

        $ubl = $method->invoke($provider, [
            'document_type' => 'invoice',
            'invoice_id' => 1,
            'invoice_number' => 'INV-VERS-001',
            'invoice_date' => '2026-07-01',
            'currency_code' => 'MYR',
            'supplier' => [
                'name' => 'Supplier', 'tin' => 'C0000000000',
                'company_registration' => '202400000001',
                'identity_type' => 'BRN', 'identity_number' => '202400000001',
                'address' => ['street' => 'Jalan Test', 'city' => 'KL', 'state' => '14', 'country' => 'MYS', 'postal_code' => '50000'],
                'contact' => ['phone' => '+60123456789', 'email' => 'supplier@test.com'],
                'msic_code' => '46510', 'business_activity_description' => 'Wholesale',
            ],
            'buyer' => [
                'name' => 'Buyer Co', 'tin' => 'C1111111110',
                'identity_type' => 'BRN', 'identity_number' => '202400000002',
                'address' => ['country' => 'MYS'],
                'contact' => ['phone' => '+60198765432', 'email' => 'buyer@test.com'],
            ],
            'line_items' => [[
                'line_number' => 1, 'description' => 'Service', 'quantity' => 1,
                'unit_price' => 100.0, 'line_amount' => 100.0, 'sst_amount' => 0.0,
                'classification' => '022', 'unit_code' => 'C62',
            ]],
            'totals' => [
                'subtotal' => 100.0, 'sst_amount' => 0.0,
                'gross_amount' => 100.0, 'amount_due' => 100.0,
            ],
        ]);

        $invoiceNode = $ubl['Invoice'][0] ?? [];
        $typeCodeNode = $invoiceNode['InvoiceTypeCode'][0] ?? [];

        $this->assertSame('1.0', $typeCodeNode['listVersionID'] ?? null,
            'listVersionID must be "1.0" — "1.1" causes ERR237 from LHDN (regression guard)');
    }

    // -------------------------------------------------------------------------
    // Issue #664 — buyer type / general-public TIN rules
    // -------------------------------------------------------------------------

    public function test_business_customer_without_tin_is_not_given_the_general_public_tin(): void
    {
        $invoice = $this->makeInvoiceForBuyer((object) [
            'name' => 'ABC Sdn Bhd',
            'type' => 'company',
            'company_registration_number' => '202401234567',
        ]);

        $mapper = new MyInvoiceMapper;
        $payload = $mapper->mapInvoice($invoice);

        $this->assertSame('business', $payload['buyer']['buyer_type']);
        $this->assertTrue($payload['buyer']['is_business']);
        $this->assertNull(
            $payload['buyer']['tin'],
            'A business buyer without a TIN must not be defaulted to the LHDN general-public TIN (issue #664).'
        );
        $this->assertFalse(
            $payload['is_consolidated'],
            'A sales invoice to a business buyer must be explicitly non-consolidated.'
        );
        $this->assertFalse(
            $mapper->validatePayload($payload),
            'A business buyer without a TIN must fail mapped-payload validation.'
        );
    }

    public function test_individual_customer_without_tin_keeps_general_public_tin_and_is_consolidated(): void
    {
        $invoice = $this->makeInvoiceForBuyer((object) [
            'name' => 'Walk-in Customer',
            'type' => 'individual',
        ]);

        $mapper = new MyInvoiceMapper;
        $payload = $mapper->mapInvoice($invoice);

        $this->assertSame('individual', $payload['buyer']['buyer_type']);
        $this->assertSame('EI00000000010', $payload['buyer']['tin']);
        $this->assertTrue($payload['is_consolidated']);
        $this->assertTrue($mapper->validatePayload($payload));
    }

    public function test_explicit_buyer_type_government_requires_a_real_tin(): void
    {
        $invoice = $this->makeInvoiceForBuyer((object) [
            'name' => 'Jabatan Kerajaan',
            'buyer_type' => 'government',
        ]);

        $mapper = new MyInvoiceMapper;
        $payload = $mapper->mapInvoice($invoice);

        $this->assertSame('government', $payload['buyer']['buyer_type']);
        $this->assertNull($payload['buyer']['tin']);
        $this->assertFalse($payload['is_consolidated']);
        $this->assertFalse($mapper->validatePayload($payload));
    }

    public function test_explicit_buyer_type_foreign_with_tin_is_non_consolidated(): void
    {
        $invoice = $this->makeInvoiceForBuyer((object) [
            'name' => 'Overseas Pte Ltd',
            'buyer_type' => 'foreign',
            'tax_number' => 'FOREIGN-TIN-123',
        ]);

        $mapper = new MyInvoiceMapper;
        $payload = $mapper->mapInvoice($invoice);

        $this->assertSame('foreign', $payload['buyer']['buyer_type']);
        $this->assertSame('FOREIGN-TIN-123', $payload['buyer']['tin']);
        $this->assertFalse($payload['is_consolidated']);
        $this->assertTrue($mapper->validatePayload($payload));
    }

    private function makeInvoiceForBuyer(object $buyer): Invoice
    {
        $invoice = new Invoice;
        $invoice->setRawAttributes([
            'id' => 664,
            'invoice_number' => 'INV-664-0001',
            'invoice_date' => '2026-10-02',
            'currency_code' => 'MYR',
            'subtotal' => 100.00,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100.00,
            'paid_amount' => 0,
        ], true);

        $invoice->supplier = (object) [
            'name' => 'NoovaERP Sdn Bhd',
            'tax_identification_number' => 'C9999999990',
            'company_registration' => '202400000001',
            'country_code' => 'MY',
        ];

        $invoice->setRelation('customer', $buyer);
        $invoice->setRelation('lines', new Collection([
            tap(new InvoiceLine, fn ($l) => $l->setRawAttributes([
                'id' => 664, 'description' => 'Item', 'quantity' => 1,
                'unit_price' => 100.00, 'tax_rate' => 0, 'tax_amount' => 0,
                'discount_amount' => 0, 'line_total' => 100.00,
            ], true)),
        ]));

        return $invoice;
    }
}
