<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TaxEngineService;
use PHPUnit\Framework\TestCase;

class TaxEngineCalculationTest extends TestCase
{
    public function test_exclusive_tax_calculation_is_rounded_at_line_level(): void
    {
        $result = (new TaxEngineService())->calculate(100.00, 9.0, false);

        $this->assertSame(['net' => 100.0, 'tax' => 9.0, 'gross' => 109.0], $result);
    }

    public function test_inclusive_tax_is_extracted_from_gross(): void
    {
        $result = (new TaxEngineService())->calculate(109.00, 9.0, true);

        $this->assertSame(100.0, $result['net']);
        $this->assertSame(9.0, $result['tax']);
        $this->assertSame(109.0, $result['gross']);
    }

    public function test_country_defaults_expose_sg_gst_and_my_sst_rates(): void
    {
        $engine = new TaxEngineService();

        $this->assertSame(9.0, $engine->countryDefaults('SG')['rates']['gst']);
        $this->assertSame(10.0, $engine->countryDefaults('MY')['rates']['sales']);
        $this->assertSame(8.0, $engine->countryDefaults('MY')['rates']['service']);
    }
}
