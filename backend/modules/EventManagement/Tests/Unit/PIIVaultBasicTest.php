<?php

namespace Modules\EventManagement\Tests\Unit;

use Tests\TestCase;
use Modules\EventManagement\Services\PIIVaultService;
use Modules\EventManagement\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * Basic Unit Test for PII Vault Service - Task 4.5 Validation
 * 
 * Validates core PII vault functionality with basic unit tests
 * to ensure the implementation works correctly.
 */
class PIIVaultBasicTest extends TestCase
{
    use RefreshDatabase;

    private PIIVaultService $piiVaultService;
    private AuditService $auditService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create mock audit service
        $this->auditService = $this->createMock(AuditService::class);
        $this->piiVaultService = new PIIVaultService($this->auditService);
        
        // Create PII vault tables manually for testing
        $this->createPIIVaultTables();
    }

    /**
     * Test basic PII storage and retrieval functionality.
     */
    public function test_basic_pii_storage_and_retrieval(): void
    {
        // Test data
        $entityType = 'customer';
        $entityId = 123;
        $fieldName = 'email';
        $piiValue = 'test@example.com';
        
        // Store PII
        $storeResult = $this->piiVaultService->storePII(
            $entityType,
            $entityId,
            $fieldName,
            $piiValue,
            ['retention_policy' => 'customer_data']
        );
        
        $this->assertTrue($storeResult['success']);
        $this->assertIsInt($storeResult['vault_id']);
        $this->assertEquals($entityType, $storeResult['entity_type']);
        $this->assertEquals($entityId, $storeResult['entity_id']);
        $this->assertEquals($fieldName, $storeResult['field_name']);
        
        // Verify data is encrypted in database
        $vaultRecord = DB::table('pii_vault')->where('id', $storeResult['vault_id'])->first();
        $this->assertNotNull($vaultRecord);
        $this->assertNotEquals($piiValue, $vaultRecord->encrypted_value);
        
        // Retrieve PII
        $retrievedValue = $this->piiVaultService->retrievePII(
            $entityType,
            $entityId,
            $fieldName,
            'Unit test validation'
        );
        
        $this->assertEquals($piiValue, $retrievedValue);
        
        // Verify access is logged
        $accessLog = DB::table('pii_access_log')
            ->where('vault_id', $storeResult['vault_id'])
            ->where('access_type', 'read')
            ->first();
        
        $this->assertNotNull($accessLog);
        $this->assertTrue($accessLog->access_granted);
        
        echo "\n✅ Basic PII storage and retrieval test passed\n";
    }

    /**
     * Test PII anonymization functionality.
     */
    public function test_pii_anonymization(): void
    {
        // Store PII first
        $storeResult = $this->piiVaultService->storePII(
            'customer',
            456,
            'phone',
            '+1234567890',
            ['retention_policy' => 'customer_data']
        );
        
        $this->assertTrue($storeResult['success']);
        
        // Anonymize PII
        $anonymizeResult = $this->piiVaultService->anonymizePII(
            $storeResult['vault_id'],
            'hash',
            'Unit test anonymization'
        );
        
        $this->assertTrue($anonymizeResult['success']);
        $this->assertEquals('hash', $anonymizeResult['method']);
        
        // Verify anonymized data cannot be retrieved
        $retrievedValue = $this->piiVaultService->retrievePII(
            'customer',
            456,
            'phone',
            'Attempting to access anonymized data'
        );
        
        $this->assertNull($retrievedValue);
        
        // Verify vault record is marked as anonymized
        $vaultRecord = DB::table('pii_vault')->where('id', $storeResult['vault_id'])->first();
        $this->assertNotNull($vaultRecord->anonymized_at);
        $this->assertEquals('hash', $vaultRecord->anonymization_method);
        
        echo "\n✅ PII anonymization test passed\n";
    }

    /**
     * Test GDPR data export functionality.
     */
    public function test_gdpr_data_export(): void
    {
        $entityType = 'customer';
        $entityId = 789;
        
        // Store multiple PII fields
        $piiData = [
            'email' => 'customer@example.com',
            'phone' => '+1987654321',
            'first_name' => 'John',
            'last_name' => 'Doe'
        ];
        
        foreach ($piiData as $fieldName => $value) {
            $storeResult = $this->piiVaultService->storePII(
                $entityType,
                $entityId,
                $fieldName,
                $value,
                ['retention_policy' => 'customer_data']
            );
            $this->assertTrue($storeResult['success']);
        }
        
        // Export all PII data
        $exportData = $this->piiVaultService->exportPIIData($entityType, $entityId);
        
        $this->assertIsArray($exportData);
        $this->assertCount(4, $exportData);
        
        // Verify all fields are in export
        $exportedFields = array_column($exportData, 'field_name');
        foreach (array_keys($piiData) as $fieldName) {
            $this->assertContains($fieldName, $exportedFields);
        }
        
        // Verify data integrity in export
        foreach ($exportData as $record) {
            $this->assertEquals($piiData[$record['field_name']], $record['value']);
        }
        
        echo "\n✅ GDPR data export test passed\n";
    }

    /**
     * Test consent management functionality.
     */
    public function test_consent_management(): void
    {
        $entityType = 'customer';
        $entityId = 999;
        $consentType = 'processing';
        
        // Record consent
        $consentResult = $this->piiVaultService->recordConsent(
            $entityType,
            $entityId,
            $consentType,
            true,
            [
                'method' => 'web_form',
                'ip_address' => '192.168.1.1',
                'expires_days' => 365
            ]
        );
        
        $this->assertTrue($consentResult['success']);
        $this->assertIsInt($consentResult['consent_id']);
        $this->assertTrue($consentResult['consent_given']);
        
        // Verify consent is stored
        $consentRecord = DB::table('pii_consent_tracking')
            ->where('id', $consentResult['consent_id'])
            ->first();
        
        $this->assertNotNull($consentRecord);
        $this->assertEquals($entityType, $consentRecord->entity_type);
        $this->assertEquals($entityId, $consentRecord->entity_id);
        $this->assertEquals($consentType, $consentRecord->consent_type);
        $this->assertTrue($consentRecord->consent_given);
        
        echo "\n✅ Consent management test passed\n";
    }

    /**
     * Test access control with expired data.
     */
    public function test_access_control_expired_data(): void
    {
        // Store PII
        $storeResult = $this->piiVaultService->storePII(
            'customer',
            111,
            'email',
            'expired@example.com',
            ['retention_policy' => 'customer_data']
        );
        
        $this->assertTrue($storeResult['success']);
        
        // Manually expire the data
        DB::table('pii_vault')
            ->where('id', $storeResult['vault_id'])
            ->update(['expires_at' => Carbon::now()->subDays(1)]);
        
        // Attempt to retrieve expired data
        $retrievedValue = $this->piiVaultService->retrievePII(
            'customer',
            111,
            'email',
            'Attempting to access expired data'
        );
        
        $this->assertNull($retrievedValue);
        
        // Verify access denial is logged
        $denialLog = DB::table('pii_access_log')
            ->where('vault_id', $storeResult['vault_id'])
            ->where('access_granted', false)
            ->where('denial_reason', 'PII data has expired')
            ->first();
        
        $this->assertNotNull($denialLog);
        
        echo "\n✅ Access control with expired data test passed\n";
    }

    /**
     * Create PII vault tables for testing.
     */
    private function createPIIVaultTables(): void
    {
        // Create pii_vault table
        Schema::create('pii_vault', function ($table) {
            $table->id();
            $table->string('entity_type', 100);
            $table->bigInteger('entity_id');
            $table->string('field_name', 100);
            $table->text('encrypted_value');
            $table->integer('encryption_key_version')->default(1);
            $table->string('retention_policy', 100);
            $table->string('data_classification', 50)->default('sensitive');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->string('anonymization_method', 50)->nullable();
            $table->json('metadata')->nullable();
            
            $table->unique(['entity_type', 'entity_id', 'field_name'], 'unique_entity_field');
            $table->index(['retention_policy', 'expires_at']);
            $table->index(['anonymized_at']);
        });

        // Create pii_access_log table
        Schema::create('pii_access_log', function ($table) {
            $table->id();
            $table->bigInteger('vault_id')->nullable();
            $table->enum('accessor_type', ['user', 'system', 'job']);
            $table->string('accessor_id', 255);
            $table->string('access_reason', 255);
            $table->string('access_type', 50);
            $table->boolean('access_granted');
            $table->string('denial_reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('session_id')->nullable();
            $table->json('request_context')->nullable();
            $table->timestamp('accessed_at')->useCurrent();
            
            $table->index(['accessed_at']);
            $table->index(['accessor_type', 'accessor_id']);
        });

        // Create pii_consent_tracking table
        Schema::create('pii_consent_tracking', function ($table) {
            $table->id();
            $table->string('entity_type', 100);
            $table->bigInteger('entity_id');
            $table->string('consent_type', 100);
            $table->boolean('consent_given');
            $table->text('consent_details')->nullable();
            $table->string('consent_method', 100)->default('system');
            $table->string('ip_address', 45)->nullable();
            $table->json('consent_metadata')->nullable();
            $table->timestamp('consent_given_at')->useCurrent();
            $table->timestamp('consent_expires_at')->nullable();
            $table->timestamp('consent_withdrawn_at')->nullable();
            $table->string('withdrawal_reason')->nullable();
            
            $table->index(['entity_type', 'entity_id']);
            $table->index(['consent_type', 'consent_given']);
        });

        // Create pii_anonymization_log table
        Schema::create('pii_anonymization_log', function ($table) {
            $table->id();
            $table->bigInteger('vault_id');
            $table->string('anonymization_method', 50);
            $table->string('policy_applied', 100);
            $table->json('original_metadata');
            $table->json('anonymization_metadata');
            $table->boolean('anonymization_successful');
            $table->text('error_message')->nullable();
            $table->string('processed_by', 255);
            $table->timestamp('processed_at')->useCurrent();
            
            $table->index(['processed_at']);
            $table->index(['anonymization_successful']);
        });
    }
}