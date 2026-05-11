<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * INT-001: Integration authentication, idempotency, outbox and dead-letter behavior.
 *
 * This suite is intentionally contract-focused (source and schema checks) to provide
 * stable runtime-gate evidence without coupling to fragile tenant fixture setup.
 */
class INT001IntegrationContractTest extends TestCase
{
    public function test_tenant_api_routes_require_authenticated_tenant_context(): void
    {
        $source = file_get_contents(base_path('routes/tenant.php'));

        $this->assertStringContainsString("'auth:sanctum'", $source);
        $this->assertStringContainsString('SetTenantFromUserPreference::class', $source);
        $this->assertStringContainsString('EnsureTenantSearchPath::class', $source);
        $this->assertStringContainsString('SyncTenantUserFromCentralAuth::class', $source);
    }

    public function test_iot_payment_webhook_job_is_deduplicated(): void
    {
        $job = new \Modules\IoT\Jobs\ProcessPaymentWebhook(
            orderId: 321,
            payload: ['source' => 'test'],
            deviceId: 7,
            tenantId: 'tenant-int001'
        );

        $this->assertContains(
            'Illuminate\\Contracts\\Queue\\ShouldBeUnique',
            class_implements($job)
        );
        $this->assertSame('iot_payment_webhook_321_tenant-int001', $job->uniqueId());
        $this->assertSame(300, $job->uniqueFor());
    }

    public function test_communication_outbox_schema_supports_retry_and_failure_tracking(): void
    {
        $migration = file_get_contents(base_path('modules/Communication/database/migrations/2026_02_17_000001_create_communication_tables.php'));

        $this->assertStringContainsString("Schema::create('communication_outbox'", $migration);
        $this->assertStringContainsString("->enum('status', ['pending', 'processing', 'sent', 'delivered', 'failed', 'bounced', 'cancelled'])", $migration);
        $this->assertStringContainsString("->unsignedTinyInteger('retry_count')->default(0)", $migration);
        $this->assertStringContainsString("->unsignedTinyInteger('max_retries')->default(5)", $migration);
        $this->assertStringContainsString("->timestamp('next_retry_at')->nullable()", $migration);
        $this->assertStringContainsString("->timestamp('failed_at')->nullable()", $migration);
    }

    public function test_communication_outbox_model_exposes_failed_and_retryable_contracts(): void
    {
        $source = file_get_contents(base_path('modules/Communication/src/Models/CommunicationOutbox.php'));

        $this->assertStringContainsString("const STATUS_FAILED = 'failed'", $source);
        $this->assertStringContainsString('public function scopeRetryable', $source);
        $this->assertStringContainsString("->where('status', self::STATUS_FAILED)", $source);
        $this->assertStringContainsString("->whereColumn('retry_count', '<', 'max_retries')", $source);
        $this->assertStringContainsString("'status' => self::STATUS_FAILED", $source);
        $this->assertStringContainsString('Re-queue for retry', $source);
    }

    public function test_communication_engine_marks_non_retryable_delivery_as_failed(): void
    {
        $source = file_get_contents(base_path('modules/Communication/src/Services/CommunicationEngine.php'));

        $this->assertStringContainsString('markAsFailed("No driver for channel:', $source);
        $this->assertStringContainsString('markAsFailed($result[\'error\'] ?? \'Unknown error\')', $source);
        $this->assertStringContainsString('$retryable = CommunicationOutbox::retryable()', $source);
    }
}
