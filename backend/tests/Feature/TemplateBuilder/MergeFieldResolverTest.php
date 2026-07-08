<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use Modules\TemplateBuilder\Services\MergeFieldResolver;
use Tests\TestCase;

class MergeFieldResolverTest extends TestCase
{
    private function resolver(): MergeFieldResolver
    {
        return (new MergeFieldResolver)->with([
            'invoice' => ['number' => 'INV-2026-0001', 'issue_date' => '2026-07-08'],
            'customer' => ['name' => 'Globex Corporation'],
            'totals' => ['total' => 8056.00, 'discount' => 0],
            'meta' => ['currency_symbol' => 'RM ', 'number_format' => '1,234.56'],
        ]);
    }

    public function test_resolves_a_dot_path_token(): void
    {
        $this->assertSame('Globex Corporation', $this->resolver()->resolveToken('customer.name'));
    }

    public function test_resolves_string_with_multiple_tokens(): void
    {
        $out = $this->resolver()->resolveString('Invoice {{invoice.number}} dated {{invoice.issue_date}}');
        $this->assertSame('Invoice INV-2026-0001 dated 2026-07-08', $out);
    }

    public function test_money_formatter_uses_currency_symbol(): void
    {
        $value = $this->resolver()->resolveToken('totals.total | money');
        $this->assertSame('RM 8,056.00', $value);
    }

    public function test_date_formatter_uses_provided_format(): void
    {
        $value = $this->resolver()->resolveToken('invoice.issue_date | date:d/m/Y');
        $this->assertSame('08/07/2026', $value);
    }

    public function test_missing_token_resolves_to_empty_string_in_strings(): void
    {
        $out = $this->resolver()->resolveString('Hello {{customer.missing}}!');
        $this->assertSame('Hello !', $out);
    }

    public function test_unknown_formatter_records_warning(): void
    {
        $resolver = $this->resolver();
        $resolver->resolveToken('totals.total | bogus');
        $this->assertNotEmpty($resolver->warnings());
    }

    public function test_chained_formatters(): void
    {
        $resolver = (new MergeFieldResolver)->with([
            'customer' => ['name' => 'globex corporation'],
            'meta' => ['currency_symbol' => '', 'number_format' => '1,234.56'],
        ]);
        $this->assertSame('GLOBEX CORPORATION', $resolver->resolveToken('customer.name | upper'));
    }
}
