<?php

namespace Tests\Feature;

use App\Services\AuditLogger;
use App\Services\TaxPeriodClosingService;
use App\Services\Reconciliation\PosPaymentGLReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * DEPLOY-003: Extended smoke tests for staging and production go-live
 * Covers audit, tax period, reconciliation, and recovery paths
 */
class PosExtendedSmokeSuiteTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function audit_logger_service_resolves_from_container()
    {
        $logger = app(AuditLogger::class);
        $this->assertInstanceOf(AuditLogger::class, $logger);
    }

    /** @test */
    public function audit_log_export_command_is_registered()
    {
        $commands = array_keys(Artisan::all());
        $this->assertContains('audit:export', $commands,
            'audit:export command must be registered');
    }

    /** @test */
    public function audit_log_prune_command_is_registered()
    {
        $commands = array_keys(Artisan::all());
        $this->assertContains('audit:prune', $commands,
            'audit:prune command must be registered');
    }

    /** @test */
    public function pos_recover_stuck_command_is_registered()
    {
        $commands = array_keys(Artisan::all());
        $this->assertContains('pos:recover-stuck', $commands,
            'pos:recover-stuck command must be registered');
    }

    /** @test */
    public function pos_reconciliation_report_command_is_registered()
    {
        $commands = array_keys(Artisan::all());
        $this->assertContains('pos:reconciliation-report', $commands,
            'pos:reconciliation-report command must be registered');
    }

    /** @test */
    public function reconciliation_service_runs_without_error_on_empty_db()
    {
        $service = app(PosPaymentGLReconciliationService::class);
        $result = $service->reconcile();

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('stats', $result);
        $this->assertArrayHasKey('exceptions', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertTrue($result['success'], 'Empty DB reconciliation should produce zero exceptions');
    }

    /** @test */
    public function tax_period_closing_service_resolves_from_container()
    {
        $service = app(TaxPeriodClosingService::class);
        $this->assertInstanceOf(TaxPeriodClosingService::class, $service);
    }

    /** @test */
    public function tax_period_closing_validates_period_format()
    {
        $service = app(TaxPeriodClosingService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->closePeriod('invalid-period', 1);
    }

    /** @test */
    public function audit_config_has_required_keys()
    {
        $this->assertNotNull(config('audit.retention_days'),
            'audit.retention_days config must be set');
        $this->assertIsInt(config('audit.retention_days'));
        $this->assertGreaterThan(0, config('audit.retention_days'));
    }

    /** @test */
    public function recover_stuck_command_runs_dry_run_without_error()
    {
        $exitCode = Artisan::call('pos:recover-stuck', ['--dry-run' => true]);
        $this->assertEquals(0, $exitCode);
    }

    /** @test */
    public function reconciliation_report_command_runs_without_error()
    {
        $exitCode = Artisan::call('pos:reconciliation-report', ['--format' => 'json']);
        $this->assertEquals(0, $exitCode);
    }
}
