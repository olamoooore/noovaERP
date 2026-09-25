<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Services\ExchangeRateService;
use Tests\TenantTestCase;

/**
 * Currency converter 422 ("No exchange rate found for USD to EUR").
 *
 * Rates are stored only against the tenant's base currency (SGD→USD,
 * SGD→MYR, …), so converting between two non-base currencies failed even
 * though both legs existed. ExchangeRateService::getLatestRate() must
 * triangulate via the base currency.
 */
class ExchangeRateCrossConversionTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => 'e3f1a2b4-5c6d-4e7f-8a9b-0c1d2e3f4a5b',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['exchange_rates', 'currencies', 'company_settings']);

        CompanySettings::query()->create(['base_currency' => 'SGD']);

        ExchangeRate::create([
            'from_currency' => 'SGD', 'to_currency' => 'USD', 'rate' => 0.74,
            'effective_date' => '2026-09-25', 'source' => 'api',
        ]);
        ExchangeRate::create([
            'from_currency' => 'SGD', 'to_currency' => 'MYR', 'rate' => 3.45,
            'effective_date' => '2026-09-25', 'source' => 'api',
        ]);
    }

    public function test_direct_and_inverse_rates_still_work(): void
    {
        $service = app(ExchangeRateService::class);

        $this->assertEqualsWithDelta(74.0, $service->convert(100, 'SGD', 'USD')['converted_amount'], 0.01);
        $this->assertEqualsWithDelta(100.0, $service->convert(74, 'USD', 'SGD')['converted_amount'], 0.01);
    }

    public function test_cross_currency_conversion_triangulates_via_base(): void
    {
        $service = app(ExchangeRateService::class);

        // 100 USD -> SGD (1/0.74) -> MYR (×3.45) = 466.22
        $result = $service->convert(100, 'USD', 'MYR');

        $this->assertEqualsWithDelta(466.22, $result['converted_amount'], 0.01);
        $this->assertSame('USD', $result['original_currency']);
        $this->assertSame('MYR', $result['converted_currency']);

        $rate = $service->getLatestRate('USD', 'MYR');
        $this->assertNotNull($rate);
        $this->assertSame('cross_via_SGD', $rate['source']);
    }

    public function test_cross_conversion_returns_nothing_when_a_leg_is_missing(): void
    {
        $service = app(ExchangeRateService::class);

        $this->assertNull($service->getLatestRate('USD', 'EUR'));
    }
}
