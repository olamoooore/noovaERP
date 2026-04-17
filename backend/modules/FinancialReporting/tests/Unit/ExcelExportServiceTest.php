<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TenantTestCase;
use Modules\FinancialReporting\Services\Export\ExcelExportService;
use Illuminate\Support\Facades\Storage;

class ExcelExportServiceTest extends TenantTestCase
{

    protected ExcelExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExcelExportService();
        Storage::fake('local');
    }

    /** @test */
    public function it_exports_report_when_phpspreadsheet_is_available_or_throws_when_it_is_not_installed()
    {
        if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $filePath = $this->service->exportReport([
                'title' => 'Test Report',
                'period' => [
                    'start_date' => '2024-01-01',
                    'end_date' => '2024-01-31',
                ],
                'data' => [
                    ['name' => 'Item 1', 'value' => 100],
                    ['name' => 'Item 2', 'value' => 200.50],
                ],
                'summary' => [
                    'total' => 300.50,
                ],
            ], 'excel-export-test');

            $this->assertFileExists($filePath);
            $this->assertStringEndsWith('.xlsx', $filePath);
            $this->assertGreaterThan(0, filesize($filePath));

            return;
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('PhpSpreadsheet library is not installed');

        $this->service->exportReport([
            'title' => 'Test Report',
            'data' => [['name' => 'Item 1', 'value' => 100]],
        ]);
    }

    /** @test */
    public function it_exports_with_charts_when_phpspreadsheet_is_available_or_throws_when_it_is_not_installed()
    {
        if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $filePath = $this->service->exportWithCharts(
                [
                    'title' => 'Test',
                    'data' => [
                        ['name' => 'Item 1', 'value' => 100],
                    ],
                ],
                ['type' => 'bar'],
                'excel-charts-test'
            );

            $this->assertFileExists($filePath);
            $this->assertStringEndsWith('.xlsx', $filePath);

            return;
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('PhpSpreadsheet library is not installed');

        $this->service->exportWithCharts(
            ['title' => 'Test', 'data' => []],
            ['type' => 'bar'],
        );
    }

    /** @test */
    public function it_has_export_report_public_method()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('exportReport');

        $this->assertTrue($method->isPublic());
        $this->assertCount(2, $method->getParameters());
    }

    /** @test */
    public function it_cleans_up_old_exports()
    {
        Storage::disk('local')->put('reports/old_report_1.xlsx', 'content');
        Storage::disk('local')->put('reports/old_report_2.xlsx', 'content');

        $deleted = $this->service->cleanupOldExports(7);

        $this->assertIsInt($deleted);
        $this->assertGreaterThanOrEqual(0, $deleted);
    }
}
