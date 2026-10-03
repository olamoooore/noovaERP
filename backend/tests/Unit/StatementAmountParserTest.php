<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BankStatements\StatementAmountParser;
use App\Services\BankStatements\UnparsableAmountException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #834 (BK2): unit tests for every statement amount pattern the
 * parser must handle — the old (float) str_replace() mis-signed trailing
 * minus / DR / parentheses and turned "RM 1,234.56" into a silent 0.
 */
class StatementAmountParserTest extends TestCase
{
    private StatementAmountParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new StatementAmountParser;
    }

    public function test_plain_amounts_and_thousands_separators(): void
    {
        $this->assertSame(1234.56, $this->parser->parse('1,234.56', 'amount'));
        $this->assertSame(1234.56, $this->parser->parse('1 234.56', 'amount'));
        $this->assertSame(1234.56, $this->parser->parse('1,234.56', 'amount'));
        $this->assertSame(0.0, $this->parser->parse('0.00', 'amount'));
    }

    public function test_leading_and_trailing_signs(): void
    {
        $this->assertSame(-1234.56, $this->parser->parse('-1,234.56', 'amount'));
        $this->assertSame(1234.56, $this->parser->parse('+1,234.56', 'amount'));
        $this->assertSame(-1234.56, $this->parser->parse('1,234.56-', 'amount'));
        $this->assertSame(-1234.56, $this->parser->parse('1,234.56 -', 'amount'));
    }

    public function test_dr_and_cr_markers_in_either_position(): void
    {
        $this->assertSame(-1234.56, $this->parser->parse('1,234.56 DR', 'amount'));
        $this->assertSame(-1234.56, $this->parser->parse('1,234.56DR', 'amount'));
        $this->assertSame(-1234.56, $this->parser->parse('1,234.56 dr', 'amount'));
        $this->assertSame(1234.56, $this->parser->parse('1,234.56 CR', 'amount'));
        $this->assertSame(1234.56, $this->parser->parse('1,234.56cr', 'amount'));
        $this->assertSame(-300.0, $this->parser->parse('DR 300', 'amount'));
        $this->assertSame(300.0, $this->parser->parse('CR 300', 'amount'));
    }

    public function test_parentheses_are_accounting_negatives(): void
    {
        $this->assertSame(-1234.56, $this->parser->parse('(1,234.56)', 'amount'));
        $this->assertSame(-500.0, $this->parser->parse('(500.00)', 'amount'));
        $this->assertSame(-50.0, $this->parser->parse('(RM50)', 'amount'));
    }

    public function test_currency_codes_and_symbols_are_stripped(): void
    {
        $this->assertSame(1234.56, $this->parser->parse('RM 1,234.56', 'amount'));
        $this->assertSame(1234.56, $this->parser->parse('RM1,234.56', 'amount'));
        $this->assertSame(1234.56, $this->parser->parse('SGD1,234.56', 'amount'));
        $this->assertSame(1234.56, $this->parser->parse('RM1,234.56 CR', 'amount'));
        $this->assertSame(2000.0, $this->parser->parse('MYR 2,000.00', 'amount'));
        $this->assertSame(99.99, $this->parser->parse('$99.99', 'amount'));
        $this->assertSame(-75.0, $this->parser->parse('$75.00-', 'amount'));
    }

    public function test_only_a_truly_empty_cell_is_zero(): void
    {
        $this->assertSame(0.0, $this->parser->parse('', 'amount'));
        $this->assertSame(0.0, $this->parser->parse('   ', 'amount'));
    }

    public function test_unparsable_values_throw_a_field_named_error_never_zero(): void
    {
        foreach (['TWO HUNDRED', 'abc', 'RM', '12.3.4', '1,234.56 USD extra', '-'] as $bad) {
            try {
                $result = $this->parser->parse($bad, 'credit');
                $this->fail("[{$bad}] must throw, got {$result}");
            } catch (UnparsableAmountException $e) {
                $this->assertSame('credit', $e->field, "[{$bad}] must name the field");
                $this->assertStringContainsString('credit', $e->getMessage());
                $this->assertSame($bad, $e->raw);
            }
        }
    }
}
