<?php

namespace Tests\Unit;

use App\Services\BankStatements\BankStatementParser;
use PHPUnit\Framework\TestCase;

class BankStatementParserTest extends TestCase
{
    public function test_csv_headers_are_mapped_to_canonical_statement_fields(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bank-statement-');
        file_put_contents($path, "Date,Description,Our Reference,Deposit,Withdrawal,Invoice_Numbers\n2024-09-02,Receipt,BR-1,100.00,0,INV-1\n");

        try {
            $parser = new BankStatementParser;
            $result = $parser->read($path, 'csv');
            $mapping = $parser->proposeMapping($result['headers']);

            self::assertSame('Date', $mapping['transaction_date']);
            self::assertSame('Description', $mapping['description']);
            self::assertSame('Withdrawal', $mapping['debit']);
            self::assertSame('Deposit', $mapping['credit']);
            self::assertSame('Invoice_Numbers', $mapping['invoice_reference']);
            self::assertCount(1, $result['rows']);
            self::assertSame(2, $result['rows'][0]['source_row']);
        } finally {
            @unlink($path);
        }
    }
}
