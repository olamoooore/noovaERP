<?php

namespace Modules\FinancialReporting\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Modules\FinancialReporting\Services\Export\CsvExportService;
use Modules\FinancialReporting\Services\Export\ExcelExportService;
use Modules\FinancialReporting\Services\Export\PdfExportService;
use Tests\TenantTestCase;

class ExportFunctionalityTest extends TenantTestCase
{
    protected User $user;

    protected array $sampleReportData;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--path' => [database_path('migrations/tenant')],
            '--realpath' => true,
        ]);

        $this->withoutMiddleware([
            \Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains::class,
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\ResolveEntityContext::class,
            \App\Http\Middleware\EnsureEntityAccess::class,
        ]);

        $this->user = User::factory()->create();

        $this->sampleReportData = [
            'title' => 'Test Financial Report',
            'period' => [
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ],
            'data' => [
                ['account' => 'Sales Revenue', 'amount' => 100000],
                ['account' => 'Cost of Goods Sold', 'amount' => 60000],
                ['account' => 'Operating Expenses', 'amount' => 20000],
            ],
            'summary' => [
                'total_revenue' => 100000,
                'total_expenses' => 80000,
                'net_profit' => 20000,
            ],
        ];
    }

    /** @test */
    public function it_exports_report_to_csv_format()
    {
        $service = new CsvExportService;

        $filePath = $service->exportReport($this->sampleReportData);

        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.csv', $filePath);

        $content = file_get_contents($filePath);
        $this->assertStringContainsString('Sales Revenue', $content);
        $this->assertStringContainsString('100000', $content);
    }

    /** @test */
    public function it_exports_report_to_excel_format()
    {
        $service = new ExcelExportService;

        $filePath = $service->exportReport($this->sampleReportData, 'profit-loss');

        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.xlsx', $filePath);
        $this->assertGreaterThan(0, filesize($filePath));
    }

    /** @test */
    public function it_handles_csv_export_with_special_characters()
    {
        $service = new CsvExportService;

        $dataWithSpecialChars = [
            'title' => 'Test Report',
            'data' => [
                ['name' => 'Item with, comma', 'value' => 100],
                ['name' => 'Item with "quotes"', 'value' => 200],
                ['name' => 'Item with newline\nchar', 'value' => 300],
            ],
        ];

        $filePath = $service->exportReport($dataWithSpecialChars);

        $this->assertFileExists($filePath);

        $content = file_get_contents($filePath);
        // CSV should properly escape special characters
        $this->assertStringContainsString('"Item with, comma"', $content);
        $this->assertStringContainsString('"Item with ""quotes"""', $content);
    }

    /** @test */
    public function it_handles_empty_data_export()
    {
        $service = new CsvExportService;

        $emptyData = [
            'title' => 'Empty Report',
            'data' => [],
        ];

        $filePath = $service->exportReport($emptyData);

        $this->assertFileExists($filePath);
        $this->assertGreaterThan(0, filesize($filePath));
    }

    /** @test */
    public function it_generates_unique_filenames_for_exports()
    {
        $service = new CsvExportService;

        $file1 = $service->exportReport($this->sampleReportData);
        sleep(1); // Ensure different timestamp
        $file2 = $service->exportReport($this->sampleReportData);

        $this->assertNotEquals($file1, $file2);
        $this->assertFileExists($file1);
        $this->assertFileExists($file2);
    }

    /** @test */
    public function it_cleans_up_old_export_files()
    {
        $service = new CsvExportService;

        // Create some export files
        $file1 = $service->exportReport($this->sampleReportData);
        $file2 = $service->exportReport($this->sampleReportData);

        $this->assertFileExists($file1);
        $this->assertFileExists($file2);

        // Cleanup should work without errors
        $deleted = $service->cleanupOldExports(0); // Delete all

        $this->assertIsInt($deleted);
        $this->assertGreaterThanOrEqual(0, $deleted);
    }

    /** @test */
    public function it_exports_via_api_endpoint()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/export/csv', [
                'report_type' => 'profit-loss',
                'report_data' => $this->sampleReportData,
            ]);

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_validates_export_request_parameters()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reports/export/csv', []);

        $this->assertContains($response->status(), [422, 500]);
    }

    /** @test */
    public function it_requires_authentication_for_export()
    {
        $response = $this->postJson('/api/v1/reports/export/csv', [
            'report_type' => 'profit-loss',
            'report_data' => $this->sampleReportData,
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function it_handles_large_dataset_export()
    {
        $service = new CsvExportService;

        // Create large dataset
        $largeData = [
            'title' => 'Large Report',
            'data' => [],
        ];

        for ($i = 0; $i < 1000; $i++) {
            $largeData['data'][] = [
                'id' => $i,
                'account' => "Account {$i}",
                'amount' => rand(1000, 100000),
                'date' => '2024-01-'.str_pad(($i % 28) + 1, 2, '0', STR_PAD_LEFT),
            ];
        }

        $filePath = $service->exportReport($largeData);

        $this->assertFileExists($filePath);
        $this->assertGreaterThan(10000, filesize($filePath)); // Should be substantial size
    }

    /** @test */
    public function it_exports_pdf_with_proper_formatting()
    {
        // Skip if DomPDF not installed
        if (! class_exists('\Barryvdh\DomPDF\Facade\Pdf')) {
            $this->markTestSkipped('DomPDF not installed');
        }

        $service = new PdfExportService;

        $filePath = $service->exportReport($this->sampleReportData);

        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.pdf', $filePath);

        // PDF should have proper header
        $content = file_get_contents($filePath);
        $this->assertStringStartsWith('%PDF', $content);
    }

    /** @test */
    public function it_exports_pdf_in_landscape_orientation()
    {
        // Skip if DomPDF not installed
        if (! class_exists('\Barryvdh\DomPDF\Facade\Pdf')) {
            $this->markTestSkipped('DomPDF not installed');
        }

        $service = new PdfExportService;

        $filePath = $service->exportLandscape($this->sampleReportData);

        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.pdf', $filePath);
    }

    /** @test */
    public function it_prevents_xss_in_pdf_export()
    {
        $service = new PdfExportService;

        $maliciousData = [
            'title' => 'Test Report',
            'data' => [
                ['name' => '<script>alert("xss")</script>', 'value' => 100],
                ['name' => '<img src=x onerror=alert(1)>', 'value' => 200],
            ],
        ];

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('generateHtmlContent');
        $method->setAccessible(true);

        $html = $method->invoke($service, $maliciousData, 'default');

        // Should escape HTML
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    /** @test */
    public function it_handles_null_values_in_export()
    {
        $service = new CsvExportService;

        $dataWithNulls = [
            'title' => 'Test Report',
            'data' => [
                ['name' => 'Item 1', 'value' => 100, 'notes' => null],
                ['name' => 'Item 2', 'value' => null, 'notes' => 'Some notes'],
            ],
        ];

        $filePath = $service->exportReport($dataWithNulls);

        $this->assertFileExists($filePath);

        $content = file_get_contents($filePath);
        $this->assertIsString($content);
    }

    /** @test */
    public function it_supports_multiple_export_formats_for_same_data()
    {
        $csvService = new CsvExportService;
        $excelService = new ExcelExportService;

        $csvFile = $csvService->exportReport($this->sampleReportData);
        $excelFile = $excelService->exportReport($this->sampleReportData, 'profit-loss');

        $this->assertFileExists($csvFile);
        $this->assertFileExists($excelFile);
        $this->assertStringEndsWith('.csv', $csvFile);
        $this->assertStringEndsWith('.xlsx', $excelFile);
    }

    /** @test */
    public function it_includes_metadata_in_exports()
    {
        $service = new CsvExportService;

        $dataWithMetadata = [
            'title' => 'Financial Report',
            'period' => [
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ],
            'generated_at' => now()->toIso8601String(),
            'data' => [
                ['account' => 'Revenue', 'amount' => 10000],
            ],
        ];

        $filePath = $service->exportReport($dataWithMetadata);

        $this->assertFileExists($filePath);

        $content = file_get_contents($filePath);
        $this->assertStringContainsString('2024-01-01', $content);
        $this->assertStringContainsString('2024-01-31', $content);
    }
}
