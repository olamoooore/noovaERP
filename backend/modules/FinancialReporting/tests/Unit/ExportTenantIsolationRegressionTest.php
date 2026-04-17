<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Modules\FinancialReporting\Services\Export\CsvExportService;
use Modules\FinancialReporting\Services\Export\PdfExportService;
use Tests\TenantTestCase;

class ExportTenantIsolationRegressionTest extends TenantTestCase
{
    public function test_csv_exports_write_into_the_current_tenant_directory(): void
    {
        $this->bindTenantContext('tenant-report-a');

        $service = new CsvExportService();
        $path = $service->exportReport([
            'title' => 'Scoped CSV',
            'data' => [
                ['name' => 'Row 1', 'amount' => 10],
            ],
        ], 'quarterly-report');

        $this->assertFileExists($path);
        $this->assertStringContainsString('/reports/tenant-report-a/', str_replace('\\', '/', $path));
        $this->assertMatchesRegularExpression('/quarterly-report_[a-z0-9]{16}\.csv$/', basename($path));
    }

    public function test_pdf_landscape_exports_write_into_the_current_tenant_directory(): void
    {
        if (!class_exists('\Barryvdh\DomPDF\Facade\Pdf')) {
            $this->markTestSkipped('DomPDF not installed');
        }

        $this->bindTenantContext('tenant-report-b');

        $service = new PdfExportService();
        $path = $service->exportLandscape([
            'title' => 'Scoped PDF',
            'data' => [
                ['name' => 'Row 1', 'amount' => 10],
            ],
        ], 'default', 'landscape-report');

        $this->assertFileExists($path);
        $this->assertStringContainsString('/reports/tenant-report-b/', str_replace('\\', '/', $path));
        $this->assertMatchesRegularExpression('/landscape-report_[a-z0-9]{16}\.pdf$/', basename($path));
    }

    public function test_csv_exports_fail_closed_without_tenant_context(): void
    {
        $service = new CsvExportService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Report exports require an active tenant context.');

        $service->exportReport([
            'data' => [
                ['name' => 'Row 1'],
            ],
        ]);
    }
}
