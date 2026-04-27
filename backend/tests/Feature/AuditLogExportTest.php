<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Console\Commands\ExportAuditLogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AUD-003: Audit log export feature tests
 */
class AuditLogExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuditLogs();
    }

    protected function createAuditLogs(): void
    {
        AuditLog::insert([
            [
                'operation' => 'pos_payment',
                'reference_type' => 'pos_order',
                'reference_id' => 1,
                'actor_id' => 1,
                'terminal_id' => 1,
                'session_id' => 1,
                'source_route' => 'pos.orders.pay',
                'idempotency_key' => 'payment_1_1',
                'before_state' => null,
                'after_state' => json_encode(['amount' => 100.00, 'method' => 'cash']),
                'metadata' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ],
            [
                'operation' => 'pos_void',
                'reference_type' => 'pos_order',
                'reference_id' => 2,
                'actor_id' => 2,
                'terminal_id' => 1,
                'session_id' => 1,
                'source_route' => 'pos.orders.void',
                'idempotency_key' => 'void_2',
                'before_state' => null,
                'after_state' => json_encode(['void_reason' => 'Customer request']),
                'metadata' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ],
            [
                'operation' => 'pos_refund',
                'reference_type' => 'pos_order',
                'reference_id' => 3,
                'actor_id' => 1,
                'terminal_id' => 2,
                'session_id' => 2,
                'source_route' => 'pos.orders.refund',
                'idempotency_key' => 'refund_3_5',
                'before_state' => null,
                'after_state' => json_encode(['refund_amount' => 50.00]),
                'metadata' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /** @test */
    public function export_command_exports_all_logs_as_csv()
    {
        $outputFile = sys_get_temp_dir() . '/audit_test_' . uniqid() . '.csv';

        $exitCode = Artisan::call('audit:export', [
            '--format' => 'csv',
            '--output' => $outputFile,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($outputFile);

        $contents = file_get_contents($outputFile);
        $this->assertStringContainsString('pos_payment', $contents);
        $this->assertStringContainsString('pos_void', $contents);
        $this->assertStringContainsString('pos_refund', $contents);

        unlink($outputFile);
    }

    /** @test */
    public function export_command_exports_all_logs_as_json()
    {
        $outputFile = sys_get_temp_dir() . '/audit_test_' . uniqid() . '.json';

        $exitCode = Artisan::call('audit:export', [
            '--format' => 'json',
            '--output' => $outputFile,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($outputFile);

        $data = json_decode(file_get_contents($outputFile), true);
        $this->assertCount(3, $data);
        $this->assertEquals('pos_payment', $data[0]['operation']);

        unlink($outputFile);
    }

    /** @test */
    public function export_command_filters_by_operation()
    {
        $outputFile = sys_get_temp_dir() . '/audit_test_' . uniqid() . '.json';

        Artisan::call('audit:export', [
            '--format' => 'json',
            '--operation' => 'pos_void',
            '--output' => $outputFile,
        ]);

        $data = json_decode(file_get_contents($outputFile), true);
        $this->assertCount(1, $data);
        $this->assertEquals('pos_void', $data[0]['operation']);

        unlink($outputFile);
    }

    /** @test */
    public function export_command_filters_by_date_range()
    {
        $outputFile = sys_get_temp_dir() . '/audit_test_' . uniqid() . '.json';

        Artisan::call('audit:export', [
            '--format' => 'json',
            '--from' => now()->subDay()->format('Y-m-d'),
            '--to' => now()->format('Y-m-d'),
            '--output' => $outputFile,
        ]);

        $data = json_decode(file_get_contents($outputFile), true);
        // Should include void (yesterday) and refund (today), not payment (2 days ago)
        $this->assertCount(2, $data);

        unlink($outputFile);
    }

    /** @test */
    public function export_command_filters_by_actor()
    {
        $outputFile = sys_get_temp_dir() . '/audit_test_' . uniqid() . '.json';

        Artisan::call('audit:export', [
            '--format' => 'json',
            '--actor' => 1,
            '--output' => $outputFile,
        ]);

        $data = json_decode(file_get_contents($outputFile), true);
        // Actor 1 has payment and refund
        $this->assertCount(2, $data);
        foreach ($data as $entry) {
            $this->assertEquals(1, $entry['actor_id']);
        }

        unlink($outputFile);
    }

    /** @test */
    public function csv_export_contains_required_compliance_fields()
    {
        $outputFile = sys_get_temp_dir() . '/audit_test_' . uniqid() . '.csv';

        Artisan::call('audit:export', [
            '--format' => 'csv',
            '--output' => $outputFile,
        ]);

        $lines = file($outputFile);
        $header = str_getcsv($lines[0]);

        $requiredFields = ['ID', 'Tenant ID', 'Actor ID', 'Terminal ID', 'Session ID', 'Operation', 'Created At'];
        foreach ($requiredFields as $field) {
            $this->assertContains($field, $header, "CSV header missing field: {$field}");
        }

        unlink($outputFile);
    }

    /** @test */
    public function export_returns_zero_when_no_logs_match_filter()
    {
        $exitCode = Artisan::call('audit:export', [
            '--operation' => 'nonexistent_operation',
            '--format' => 'json',
            '--output' => '/dev/null',
        ]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('No audit logs found', $output);
    }
}
