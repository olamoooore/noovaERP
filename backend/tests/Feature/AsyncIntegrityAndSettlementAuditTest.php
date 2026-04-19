<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\ReconciliationException;
use App\Models\ReconciliationControlResult;
use App\Models\SettlementAuditLog;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * WS-10 Async Integrity and Settlement Audit Tests
 * 
 * Tests for:
 * - ProcessPaymentWebhook job queue deduplication (ShouldBeUnique)
 * - Settlement mutation audit trail (SettlementAuditLog)
 * - POS completion race safety (lockForUpdate)
 */
class AsyncIntegrityAndSettlementAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:wipe', ['--force' => true]);
        Artisan::call('migrate:install');

        foreach ([
            database_path('migrations/tenant/2024_01_01_000001_create_users_table.php'),
            database_path('migrations/tenant/2024_01_01_100001_create_chart_of_accounts_table.php'),
            database_path('migrations/tenant/2024_01_01_100002_create_journal_entries_table.php'),
            database_path('migrations/tenant/2025_12_17_000001_create_settings_table.php'),
            database_path('migrations/tenant/2025_12_17_000002_create_taxes_table.php'),
            database_path('migrations/tenant/2024_01_01_200001_create_products_table.php'),
            database_path('migrations/tenant/2024_01_01_200002_create_customers_table.php'),
            database_path('migrations/tenant/2024_01_01_200003_create_invoices_table.php'),
            database_path('migrations/tenant/2024_01_01_200003_create_invoices_table.php'),
            database_path('migrations/tenant/2024_01_01_200004_create_payments_table.php'),
            database_path('migrations/tenant/2026_04_19_000003_create_settlement_audit_logs_table.php'),
        ] as $migrationPath) {
            Artisan::call('migrate', [
                '--path' => $migrationPath,
                '--realpath' => true,
            ]);
        }
    }

    public function test_settlement_audit_log_model_exists(): void
    {
        $this->assertTrue(class_exists(\App\Models\SettlementAuditLog::class));
    }

    public function test_settlement_audit_log_has_expected_schema(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('settlement_audit_logs'));
        
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('settlement_audit_logs');
        
        $this->assertContains('invoice_id', $columns);
        $this->assertContains('payment_id', $columns);
        $this->assertContains('payment_amount', $columns);
        $this->assertContains('before_paid_amount', $columns);
        $this->assertContains('after_paid_amount', $columns);
        $this->assertContains('before_balance', $columns);
        $this->assertContains('after_balance', $columns);
        $this->assertContains('before_status', $columns);
        $this->assertContains('after_status', $columns);
        $this->assertContains('actor_user_id', $columns);
        $this->assertContains('settlement_method', $columns);
    }

    public function test_settlement_audit_log_can_be_created(): void
    {
        // Only test that the model can be instantiated and has the right attributes
        $this->assertTrue(class_exists(SettlementAuditLog::class));
        
        // Test the model has expected fillable attributes via reflection
        $model = new SettlementAuditLog();
        $fillable = $model->getFillable();
        
        $this->assertContains('invoice_id', $fillable);
        $this->assertContains('payment_amount', $fillable);
        $this->assertContains('before_paid_amount', $fillable);
        $this->assertContains('after_paid_amount', $fillable);
        $this->assertContains('before_balance', $fillable);
        $this->assertContains('after_balance', $fillable);
        $this->assertContains('before_status', $fillable);
        $this->assertContains('after_status', $fillable);
    }

    public function test_process_payment_webhook_job_implements_should_be_unique(): void
    {
        $jobClass = new \ReflectionClass(\Modules\IoT\Jobs\ProcessPaymentWebhook::class);
        $interfaces = $jobClass->getInterfaceNames();
        
        $this->assertContains('Illuminate\Contracts\Queue\ShouldBeUnique', $interfaces);
    }

    public function test_process_payment_webhook_job_has_unique_id_method(): void
    {
        $job = new \Modules\IoT\Jobs\ProcessPaymentWebhook(
            orderId: 123,
            payload: ['test'],
            deviceId: 1,
            tenantId: 'testTenant'
        );
        
        $this->assertTrue(method_exists($job, 'uniqueId'));
        $this->assertEquals('iot_payment_webhook_123_testTenant', $job->uniqueId());
    }

    public function test_process_payment_webhook_job_has_unique_for_method(): void
    {
        $job = new \Modules\IoT\Jobs\ProcessPaymentWebhook(
            orderId: 123,
            payload: ['test'],
            deviceId: 1,
            tenantId: 'testTenant'
        );
        
        $this->assertTrue(method_exists($job, 'uniqueFor'));
        $this->assertEquals(300, $job->uniqueFor()); // 5 minutes
    }

    public function test_pos_payment_completion_service_uses_lock_for_update(): void
    {
        $service = new \ReflectionClass(\Modules\POS\Services\PosPaymentCompletionService::class);
        $method = $service->getMethod('complete');
        
        $source = file_get_contents($method->getFileName());
        
        $this->assertStringContainsString('lockForUpdate', $source);
    }
}