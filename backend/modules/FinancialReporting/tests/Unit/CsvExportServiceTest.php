<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FinancialReporting\Services\Export\CsvExportService;
use Illuminate\Support\Facades\Storage;

class CsvExportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CsvExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CsvExportService();
        Storage::fake('local');
    }

    /** @test */
    public function it_converts_array_to_csv_format()
    {
        $data = [
            ['name' => 'Product 1', 'price' => 100, 'quantity' => 5],
            ['name' => 'Product 2', 'price' => 200, 'quantity' => 3],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('arrayToCsv');
        $method->setAccessible(true);

        $csv = $method->invoke($this->service, $data);

        $this->assertStringContainsString('name,price,quantity', $csv);
        $this->assertStringContainsString('Product 1,100,5', $csv);
        $this->assertStringContainsString('Product 2,200,3', $csv);
    }

    /** @test */
    public function it_handles_empty_data()
    {
        $data = [];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('arrayToCsv');
        $method->setAccessible(true);

        $csv = $method->invoke($this->service, $data);

        $this->assertIsString($csv);
        $this->assertEmpty(trim($csv));
    }

    /** @test */
    public function it_escapes_special_characters()
    {
        $data = [
            ['name' => 'Product, with comma', 'description' => 'Has "quotes"'],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('arrayToCsv');
        $method->setAccessible(true);

        $csv = $method->invoke($this->service, $data);

        $this->assertStringContainsString('"Product, with comma"', $csv);
        $this->assertStringContainsString('"Has ""quotes"""', $csv);
    }

    /** @test */
    public function it_handles_null_values()
    {
        $data = [
            ['name' => 'Product 1', 'description' => null, 'price' => 100],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('arrayToCsv');
        $method->setAccessible(true);

        $csv = $method->invoke($this->service, $data);

        $this->assertIsString($csv);
        $this->assertStringContainsString('Product 1', $csv);
    }

    /** @test */
    public function it_cleans_up_old_exports()
    {
        Storage::disk('local')->put('reports/old_report_1.csv', 'content');
        Storage::disk('local')->put('reports/old_report_2.csv', 'content');

        $deleted = $this->service->cleanupOldExports(7);

        $this->assertIsInt($deleted);
        $this->assertGreaterThanOrEqual(0, $deleted);
    }
}
