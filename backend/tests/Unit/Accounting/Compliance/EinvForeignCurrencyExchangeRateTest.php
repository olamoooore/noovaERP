<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Compliance;

use Modules\AccountingCountry\Malaysia\Services\MyInvoiceSubmissionProvider;
use PHPUnit\Framework\TestCase;

/**
 * #820 EP9: a non-MYR MyInvois document declares MYR as its tax currency and
 * carries the exchange rate from the invoice's own snapshot (never today's);
 * MYR documents are unchanged.
 */
class EinvForeignCurrencyExchangeRateTest extends TestCase
{
    private function payload(string $currency, ?float $rate): array
    {
        return [
            'document_type' => 'invoice',
            'invoice_id' => 1,
            'invoice_number' => 'INV-FX-001',
            'invoice_date' => '2026-03-25',
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'supplier' => ['name' => 'NoovaERP Sdn Bhd', 'tin' => 'C2584563200', 'company_registration' => '202401234567'],
            'buyer' => ['name' => 'Foreign Customer Inc'],
            'line_items' => [[
                'item_id' => '1', 'description' => 'Consulting', 'quantity' => 1,
                'unit_price' => 100.00, 'line_amount' => 100.00,
            ]],
            'totals' => ['gross_amount' => 100.00],
        ];
    }

    private function build(array $payload): array
    {
        $provider = new MyInvoiceSubmissionProvider(null, 'https://sandbox.myinvoice.test');
        $method = (new \ReflectionClass($provider))->getMethod('buildUblDocumentFromMappedPayload');
        $method->setAccessible(true);

        return $method->invoke($provider, $payload)['Invoice'][0];
    }

    public function test_a_usd_document_carries_the_exchange_rate_block_and_myr_tax_currency(): void
    {
        $invoice = $this->build($this->payload('USD', 4.70));

        $this->assertSame('USD', $invoice['DocumentCurrencyCode'][0]['_']);
        $this->assertSame('MYR', $invoice['TaxCurrencyCode'][0]['_']);

        $rate = $invoice['TaxExchangeRate'][0];
        $this->assertSame('USD', $rate['SourceCurrencyCode'][0]['_']);
        $this->assertSame('MYR', $rate['TargetCurrencyCode'][0]['_']);
        $this->assertSame(4.70, (float) $rate['CalculationRate'][0]['_']);
    }

    public function test_a_myr_document_is_unchanged(): void
    {
        $invoice = $this->build($this->payload('MYR', null));

        $this->assertSame('MYR', $invoice['DocumentCurrencyCode'][0]['_']);
        $this->assertSame('MYR', $invoice['TaxCurrencyCode'][0]['_']);
        $this->assertArrayNotHasKey('TaxExchangeRate', $invoice);
    }

    public function test_a_foreign_document_without_a_rate_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/EINVOICE_EXCHANGE_RATE_REQUIRED/');

        $this->build($this->payload('USD', null));
    }
}
