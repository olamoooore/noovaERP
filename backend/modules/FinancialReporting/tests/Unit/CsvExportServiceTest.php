<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TenantTestCase;
use Modules\FinancialReporting\Services\Export\CsvExportService;
use Illuminate\Support\Facades\Storage;

class CsvExportServiceTest extends TenantTestCase
{

    protected CsvExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext();
        $this->service = new CsvExportService();
        Storage::fake('local');
    }

    /**
     * Helper to invoke the protected generateCsvContent method.
     */
    protected function invokeGenerateCsvContent(array $reportData): string
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCsvContent');
        $method->setAccessible(true);

        return $method->invoke($this->service, $reportData);
    }

    /** @test */
    public function it_converts_array_to_csv_format()
    {
        $reportData = [
            'data' => [
                ['name' => 'Product 1', 'price' => 100, 'quantity' => 5],
                ['name' => 'Product 2', 'price' => 200, 'quantity' => 3],
            ],
        ];

        $csv = $this->invokeGenerateCsvContent($reportData);

        $this->assertStringContainsString('name,price,quantity', $csv);
        // fputcsv may quote string values
        $this->assertStringContainsString('Product 1', $csv);
        $this->assertStringContainsString('100', $csv);
        $this->assertStringContainsString('Product 2', $csv);
    }

    /** @test */
    public function it_handles_empty_data()
    {
        $reportData = ['data' => []];

        $csv = $this->invokeGenerateCsvContent($reportData);

        $this->assertIsString($csv);
        // No data rows should be present (only possible title/period lines)
        $this->assertStringNotContainsString('name', $csv);
    }

    /** @test */
    public function it_escapes_special_characters()
    {
        $reportData = [
            'data' => [
                ['name' => 'Product, with comma', 'description' => 'Has "quotes"'],
            ],
        ];

        $csv = $this->invokeGenerateCsvContent($reportData);

        $this->assertStringContainsString('"Product, with comma"', $csv);
        $this->assertStringContainsString('"Has ""quotes"""', $csv);
    }

    /** @test */
    public function it_handles_null_values()
    {
        $reportData = [
            'data' => [
                ['name' => 'Product 1', 'description' => null, 'price' => 100],
            ],
        ];

        $csv = $this->invokeGenerateCsvContent($reportData);

        $this->assertIsString($csv);
        $this->assertStringContainsString('Product 1', $csv);
    }

    /** @test */
    public function it_cleans_up_old_exports()
    {
        Storage::disk('local')->put($this->tenantScopedPath('old_report_1.csv'), 'content');
        Storage::disk('local')->put($this->tenantScopedPath('old_report_2.csv'), 'content');

        $deleted = $this->service->cleanupOldExports(7);

        $this->assertIsInt($deleted);
        $this->assertGreaterThanOrEqual(0, $deleted);
    }
}
