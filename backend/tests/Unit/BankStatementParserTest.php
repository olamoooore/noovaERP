<?php

namespace Tests\Unit;

use App\Services\BankStatements\BankStatementAuditService;
use App\Services\BankStatements\BankStatementImportService;
use App\Services\BankStatements\BankStatementParser;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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

    public function test_import_automatically_accepts_day_month_year_dates(): void
    {
        $service = new BankStatementImportService(
            new BankStatementParser,
            $this->createStub(BankStatementAuditService::class),
        );
        $normalize = new ReflectionMethod($service, 'normalize');

        $result = $normalize->invoke($service, [
            'source_row' => 2,
            'values' => [
                'Date' => '30/06/2025',
                'Description' => 'Receipt',
                'Deposit' => '545.00',
                'Withdrawal' => '0',
            ],
        ], [
            'mapping' => [
                'transaction_date' => 'Date',
                'description' => 'Description',
                'credit' => 'Deposit',
                'debit' => 'Withdrawal',
            ],
            'currency_code' => 'SGD',
            'source_file_hash' => 'test-file',
        ], 1);

        self::assertSame('2025-06-30', $result['transaction_date']);
        self::assertSame(545.0, $result['amount']);

        $ambiguousDate = new ReflectionMethod($service, 'parseTransactionDate');
        self::assertSame('2025-06-01', $ambiguousDate->invoke($service, '01/06/2025', null)->format('Y-m-d'));
    }
}
