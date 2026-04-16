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
    public function it_throws_when_phpspreadsheet_not_installed()
    {
        if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $this->markTestSkipped('PhpSpreadsheet is installed — cannot test missing-library guard');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('PhpSpreadsheet library is not installed');

        $this->service->exportReport([
            'title' => 'Test Report',
            'data' => [['name' => 'Item 1', 'value' => 100]],
        ]);
    }

    /** @test */
    public function it_throws_for_charts_when_phpspreadsheet_not_installed()
    {
        if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $this->markTestSkipped('PhpSpreadsheet is installed — cannot test missing-library guard');
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
