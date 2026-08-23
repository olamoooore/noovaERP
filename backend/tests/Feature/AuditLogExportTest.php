<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Tests\TenantTestCase;

/**
 * AUD-003: Audit log export feature tests
 */
class AuditLogExportTest extends TenantTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDependencies();
        $this->createAuditLogs();
    }

    protected function createDependencies(): void
    {
        $actor1 = User::factory()->create(['name' => 'Actor One', 'email' => 'actor1@audit.test']);
        $actor2 = User::factory()->create(['name' => 'Actor Two', 'email' => 'actor2@audit.test']);

        $terminal1 = PosTerminal::create([
            'name' => 'Terminal 1',
            'code' => 'T-001',
            'is_active' => true,
        ]);
        $terminal2 = PosTerminal::create([
            'name' => 'Terminal 2',
            'code' => 'T-002',
            'is_active' => true,
        ]);

        $session1 = PosSession::create([
            'terminal_id' => $terminal1->id,
            'user_id' => $actor1->id,
            'session_number' => 'S-001',
            'opening_balance' => 0,
            'status' => 'open',
            'opened_at' => now()->subDay(),
        ]);
        $session2 = PosSession::create([
            'terminal_id' => $terminal2->id,
            'user_id' => $actor2->id,
            'session_number' => 'S-002',
            'opening_balance' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->auditContext = [
            'actor1_id' => $actor1->id,
            'actor2_id' => $actor2->id,
            'terminal1_id' => $terminal1->id,
            'terminal2_id' => $terminal2->id,
            'session1_id' => $session1->id,
            'session2_id' => $session2->id,
        ];
    }

    private array $auditContext = [];

    protected function createAuditLogs(): void
    {
        AuditLog::insert([
            [
                'operation' => 'pos_payment',
                'reference_type' => 'pos_order',
                'reference_id' => 1,
                'actor_id' => $this->auditContext['actor1_id'],
                'terminal_id' => $this->auditContext['terminal1_id'],
                'session_id' => $this->auditContext['session1_id'],
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
                'actor_id' => $this->auditContext['actor2_id'],
                'terminal_id' => $this->auditContext['terminal1_id'],
                'session_id' => $this->auditContext['session1_id'],
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
                'actor_id' => $this->auditContext['actor1_id'],
                'terminal_id' => $this->auditContext['terminal2_id'],
                'session_id' => $this->auditContext['session2_id'],
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
        $outputFile = sys_get_temp_dir().'/audit_test_'.uniqid().'.csv';

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
        $outputFile = sys_get_temp_dir().'/audit_test_'.uniqid().'.json';

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
        $outputFile = sys_get_temp_dir().'/audit_test_'.uniqid().'.json';

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
        $outputFile = sys_get_temp_dir().'/audit_test_'.uniqid().'.json';

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
        $outputFile = sys_get_temp_dir().'/audit_test_'.uniqid().'.json';

        Artisan::call('audit:export', [
            '--format' => 'json',
            '--actor' => $this->auditContext['actor1_id'],
            '--output' => $outputFile,
        ]);

        $data = json_decode(file_get_contents($outputFile), true);
        // Actor 1 has payment and refund
        $this->assertCount(2, $data);
        foreach ($data as $entry) {
            $this->assertEquals($this->auditContext['actor1_id'], $entry['actor_id']);
        }

        unlink($outputFile);
    }

    /** @test */
    public function csv_export_contains_required_compliance_fields()
    {
        $outputFile = sys_get_temp_dir().'/audit_test_'.uniqid().'.csv';

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
