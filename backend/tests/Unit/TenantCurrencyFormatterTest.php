<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TenantCurrencyFormatter;
use Tests\TestCase;

class TenantCurrencyFormatterTest extends TestCase
{
    public function test_formats_singapore_dollars_using_tenant_symbol_preference(): void
    {
        $formatter = new TenantCurrencyFormatter(['code' => 'SGD', 'display_format' => 'symbol']);
        $this->assertSame('S$1,234.50', $formatter->format(1234.5));
    }

    public function test_formats_singapore_dollars_using_tenant_narrow_symbol_preference(): void
    {
        $formatter = new TenantCurrencyFormatter(['code' => 'SGD', 'display_format' => 'narrow_symbol']);
        $this->assertSame('-$1,234.50', $formatter->format(-1234.5));
    }

    public function test_formats_using_tenant_currency_code_preference(): void
    {
        $formatter = new TenantCurrencyFormatter(['code' => 'SGD', 'display_format' => 'code']);
        $this->assertSame('SGD 1,234.50', $formatter->format(1234.5));
    }

    public function test_document_currency_overrides_code_but_keeps_tenant_display_preference(): void
    {
        $formatter = new TenantCurrencyFormatter(['code' => 'SGD', 'display_format' => 'symbol']);
        $this->assertSame('$1,234.50', $formatter->format(1234.5, 'USD'));
    }
}
