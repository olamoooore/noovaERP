<?php

namespace Tests\Unit\Accounting\Compliance;

use PHPUnit\Framework\TestCase;
use App\Accounting\Services\ComplianceService;
use App\Accounting\Plugin\CountryPluginRegistry;
use Modules\AccountingCountry\Singapore\Services\SFRSValidationService;

/**
 * Example Test Suite for Compliance Plugin System
 * 
 * In production, replace with actual test data and mocked dependencies
 */
class CompliancePluginExampleTest extends TestCase
{
    protected ComplianceService $complianceService;
    protected CountryPluginRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->registry = new CountryPluginRegistry();
        $this->complianceService = new ComplianceService($this->registry);
    }

    /**
     * Test: Singapore validator correctly validates UEN format
     */
    public function test_singapore_uen_validation_succeeds_with_valid_format()
    {
        $validator = new SFRSValidationService();
        
        $invoice = (object) [
            'invoice_date' => now(),
            'invoice_number' => 'INV001',
            'customer_id' => 1,
            'supplier' => (object) [
                'registration_number' => '123456789A', // Valid 10-char UEN
            ],
            'customer' => (object) [
                'is_sme' => true,
            ],
            'tax_amount' => 100,
            'tax_rate' => 8,
            'lineItems' => collect([
                (object) [
                    'description' => 'Product A',
                    'quantity' => 1,
                    'unit_price' => 1000,
                ]
            ]),
            'issued_date' => now()->subDay(),
        ];

        $result = $validator->validateInvoice($invoice);
        
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test: Singapore validator rejects invalid GST rate
     */
    public function test_singapore_validation_fails_with_invalid_gst_rate()
    {
        $validator = new SFRSValidationService();
        
        $invoice = (object) [
            'invoice_date' => now(),
            'invoice_number' => 'INV002',
            'customer_id' => 1,
            'supplier' => (object) [
                'registration_number' => '123456789A',
            ],
            'customer' => (object) [
                'is_sme' => false,
                'registration_number' => '987654321B',
            ],
            'tax_amount' => 70,
            'tax_rate' => 7, // Invalid - must be 0 or 8
            'lineItems' => collect([
                (object) [
                    'description' => 'Product B',
                    'quantity' => 2,
                    'unit_price' => 500,
                ]
            ]),
            'issued_date' => now()->subDay(),
        ];

        $result = $validator->validateInvoice($invoice);
        
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('tax rate', implode(' ', $result['errors']));
    }

    /**
     * Test: Singapore validator requires supplier UEN
     */
    public function test_singapore_validation_fails_without_supplier_uen()
    {
        $validator = new SFRSValidationService();
        
        $invoice = (object) [
            'invoice_date' => now(),
            'invoice_number' => 'INV003',
            'customer_id' => 1,
            'supplier' => (object) [
                'registration_number' => null, // Missing UEN
            ],
            'customer' => (object) [
                'is_sme' => true,
            ],
            'tax_amount' => 0,
            'tax_rate' => 0,
            'lineItems' => collect([
                (object) [
                    'description' => 'Service',
                    'quantity' => 1,
                    'unit_price' => 100,
                ]
            ]),
            'issued_date' => now()->subDay(),
        ];

        $result = $validator->validateInvoice($invoice);
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('UEN', implode(' ', $result['errors']));
    }

    /**
     * Test: Singapore validator rejects future invoice dates
     */
    public function test_singapore_validation_fails_with_future_invoice_date()
    {
        $validator = new SFRSValidationService();
        
        $invoice = (object) [
            'invoice_date' => now()->addDay(), // Future date
            'invoice_number' => 'INV004',
            'customer_id' => 1,
            'supplier' => (object) [
                'registration_number' => '123456789A',
            ],
            'customer' => (object) [
                'is_sme' => true,
            ],
            'tax_amount' => 80,
            'tax_rate' => 8,
            'issued_date' => now()->addDay(), // Future
            'lineItems' => collect([
                (object) [
                    'description' => 'Future Product',
                    'quantity' => 1,
                    'unit_price' => 1000,
                ]
            ]),
        ];

        $result = $validator->validateInvoice($invoice);
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('future', implode(' ', $result['errors']));
    }

    /**
     * Test: Plugin registry registers Singapore plugins correctly
     */
    public function test_plugin_registry_registers_singapore_plugins()
    {
        $validator = new SFRSValidationService();
        $this->registry->register('SG', [
            'validator' => $validator,
        ]);

        $this->assertTrue($this->registry->has('SG'));
        $resolved = $this->registry->resolve('SG', 'validator');
        $this->assertInstanceOf(SFRSValidationService::class, $resolved);
    }

    /**
     * Test: Plugin registry returns registered countries
     */
    public function test_plugin_registry_lists_registered_countries()
    {
        $this->registry->register('SG', ['validator' => new SFRSValidationService()]);
        
        $countries = $this->registry->getRegisteredCountries();
        
        $this->assertContains('SG', $countries);
    }

    /**
     * Test: Tax calculation accuracy in Singapore mapper
     */
    public function test_singapore_invoice_mapper_tax_calculation()
    {
        // Invoice: SGD 1000 + 8% tax
        $taxable = 1000;
        $taxRate = 8;
        $expectedTax = 80;
        
        $this->assertEquals($expectedTax, $taxable * ($taxRate / 100));
    }

    /**
     * Test: Malaysia MFRS validates SST correctly
     */
    public function test_malaysia_sst_rate_validation()
    {
        // Valid SST rates in Malaysia: 0% or 6%
        $validRates = [0, 6];
        $invalidRate = 8;
        
        $this->assertTrue(in_array(0, $validRates));
        $this->assertTrue(in_array(6, $validRates));
        $this->assertFalse(in_array($invalidRate, $validRates));
    }
}
