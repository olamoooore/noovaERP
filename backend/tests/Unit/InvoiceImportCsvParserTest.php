<?php

namespace Tests\Unit;

use App\Services\InvoiceImportCsvParser;
use Tests\TestCase;

class InvoiceImportCsvParserTest extends TestCase
{
    public function test_headers_are_normalized_aliased_and_blank_rows_ignored(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'invoice-import-');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['Invoice No', 'Customer', 'Item Description', 'Qty', 'Unit Price']);
        fputcsv($handle, ['INV-100', 'Acme', 'Replacement part', '2', '12.50']);
        fputcsv($handle, ['', '', '', '', '']);
        fclose($handle);

        try {
            $result = (new InvoiceImportCsvParser)->parse($path);
        } finally {
            unlink($path);
        }

        $this->assertSame([
            [
                'invoice_number' => 'INV-100',
                'customer_name' => 'Acme',
                'description' => 'Replacement part',
                'quantity' => '2',
                'unit_price' => '12.50',
            ],
        ], $result['rows']);
    }

    public function test_empty_csv_returns_a_validation_error(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'invoice-import-');
        file_put_contents($path, "Invoice No,Customer\n");

        try {
            $result = (new InvoiceImportCsvParser)->parse($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(['error' => 'No rows found', 'status' => 422], $result);
    }
}
