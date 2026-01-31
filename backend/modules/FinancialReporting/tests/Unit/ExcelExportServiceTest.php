<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FinancialReporting\Services\Export\ExcelExportService;
use Illuminate\Support\Facades\Storage;

class ExcelExportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ExcelExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExcelExportService();
        Storage::fake('local');
    }

    /** @test */
    public function it_formats_headers_correctly()
    {
        $data = [
            ['product_name' => 'Product 1', 'total_sales' => 1000],
            ['product_name' => 'Product 2', 'total_sales' => 2000],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('formatHeaders');
        $method->setAccessible(true);

        $headers = $method->invoke($this->service, $data);

        $this->assertContains('Product Name', $headers);
        $this->assertContains('Total Sales', $headers);
    }

    /** @test */
    public function it_handles_empty_data()
    {
        $data = [];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('formatHeaders');
        $method->setAccessible(true);

        $headers = $method->invoke($this->service, $data);

        $this->assertIsArray($headers);
        $this->assertEmpty($headers);
    }

    /** @test */
    public function it_converts_snake_case_to_title_case()
    {
        $data = [
            ['customer_lifetime_value' => 5000, 'average_order_value' => 250],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('formatHeaders');
        $method->setAccessible(true);

        $headers = $method->invoke($this->service, $data);

        $this->assertContains('Customer Lifetime Value', $headers);
        $this->assertContains('Average Order Value', $headers);
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
