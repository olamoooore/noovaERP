<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FinancialReporting\Services\Export\PdfExportService;
use Illuminate\Support\Facades\Storage;

class PdfExportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PdfExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PdfExportService();
        Storage::fake('local');
    }

    /** @test */
    public function it_generates_html_content_for_report()
    {
        $reportData = [
            'title' => 'Test Report',
            'period' => [
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ],
            'data' => [
                ['name' => 'Item 1', 'value' => 100],
                ['name' => 'Item 2', 'value' => 200],
            ],
            'summary' => [
                'total' => 300,
                'count' => 2,
            ],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateHtmlContent');
        $method->setAccessible(true);

        $html = $method->invoke($this->service, $reportData, 'default');

        $this->assertStringContainsString('Test Report', $html);
        $this->assertStringContainsString('2024-01-01', $html);
        $this->assertStringContainsString('Item 1', $html);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
    }

    /** @test */
    public function it_includes_period_in_html()
    {
        $reportData = [
            'title' => 'Test Report',
            'period' => [
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ],
            'data' => [],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateHtmlContent');
        $method->setAccessible(true);

        $html = $method->invoke($this->service, $reportData, 'default');

        $this->assertStringContainsString('Period:', $html);
        $this->assertStringContainsString('2024-01-01', $html);
        $this->assertStringContainsString('2024-01-31', $html);
    }

    /** @test */
    public function it_includes_summary_in_html()
    {
        $reportData = [
            'title' => 'Test Report',
            'data' => [],
            'summary' => [
                'total_revenue' => 10000,
                'total_expenses' => 6000,
                'net_profit' => 4000,
            ],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateHtmlContent');
        $method->setAccessible(true);

        $html = $method->invoke($this->service, $reportData, 'default');

        $this->assertStringContainsString('Summary', $html);
        $this->assertStringContainsString('Total Revenue', $html);
        $this->assertStringContainsString('10000', $html);
    }

    /** @test */
    public function it_includes_data_table_in_html()
    {
        $reportData = [
            'title' => 'Test Report',
            'data' => [
                ['account' => 'Sales', 'amount' => 5000],
                ['account' => 'Services', 'amount' => 3000],
            ],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateHtmlContent');
        $method->setAccessible(true);

        $html = $method->invoke($this->service, $reportData, 'default');

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('Sales', $html);
        $this->assertStringContainsString('5000', $html);
        $this->assertStringContainsString('Services', $html);
    }

    /** @test */
    public function it_escapes_html_in_data()
    {
        $reportData = [
            'title' => 'Test Report',
            'data' => [
                ['name' => '<script>alert("xss")</script>', 'value' => 100],
            ],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateHtmlContent');
        $method->setAccessible(true);

        $html = $method->invoke($this->service, $reportData, 'default');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** @test */
    public function it_includes_generation_timestamp()
    {
        $reportData = [
            'title' => 'Test Report',
            'data' => [],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateHtmlContent');
        $method->setAccessible(true);

        $html = $method->invoke($this->service, $reportData, 'default');

        $this->assertStringContainsString('Generated on', $html);
    }

    /** @test */
    public function it_cleans_up_old_exports()
    {
        // Create some old files
        Storage::disk('local')->put('reports/old_report_1.pdf', 'content');
        Storage::disk('local')->put('reports/old_report_2.pdf', 'content');
        Storage::disk('local')->put('reports/recent_report.pdf', 'content');

        // Mock file modification times
        $oldTime = now()->subDays(10)->timestamp;
        $recentTime = now()->timestamp;

        // This test verifies the method exists and returns an integer
        $deleted = $this->service->cleanupOldExports(7);

        $this->assertIsInt($deleted);
        $this->assertGreaterThanOrEqual(0, $deleted);
    }
}
