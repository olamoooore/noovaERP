<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\PIIVaultService;
use Tests\TestCase;

/**
 * Property Test 21: PII Vault Security and Compliance - Task 4.5
 *
 * Validates PII vault security, encryption, access controls, and GDPR compliance
 * through property-based testing with comprehensive security scenarios.
 *
 * Requirements: 20.1, 20.2, 20.3, 20.4, 20.5
 */
class PIIVaultSecurityTest extends TestCase
{
    use RefreshDatabase;

    private PIIVaultService $piiVaultService;

    private AuditService $auditService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditService = $this->createMock(AuditService::class);
        $this->piiVaultService = new PIIVaultService($this->auditService);

        // Run PII vault migrations
        $this->artisan('migrate', [
            '--path' => 'modules/EventManagement/Database/Migrations/2025_01_01_000014_create_pii_vault_tables.php',
        ]);
    }

    /**
     * Property 21.1: PII Storage Encryption and Integrity
     *
     * Tests that all PII data is properly encrypted when stored and maintains
     * integrity through encryption/decryption cycles.
     *
     * Requirements: 20.1, 20.2
     */
    public function test_pii_storage_encryption_and_integrity(): void
    {
        $iterations = 25; // Reduced for performance
        $testResults = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random PII data
            $entityType = $this->faker->randomElement(['customer', 'booking', 'attendee']);
            $entityId = $this->faker->numberBetween(1, 1000);
            $fieldName = $this->faker->randomElement(['email', 'phone', 'first_name', 'last_name', 'address']);
            $originalValue = $this->generateRandomPII($fieldName);

            // Store PII in vault
            $storeResult = $this->piiVaultService->storePII(
                $entityType,
                $entityId,
                $fieldName,
                $originalValue,
                ['retention_policy' => 'customer_data']
            );

            $this->assertTrue($storeResult['success'], "Failed to store PII for iteration {$i}");

            // Verify data is encrypted in database
            $vaultRecord = DB::table('pii_vault')->where('id', $storeResult['vault_id'])->first();
            $this->assertNotNull($vaultRecord, "Vault record not found for iteration {$i}");
            $this->assertNotEquals($originalValue, $vaultRecord->encrypted_value, "PII not encrypted for iteration {$i}");

            // Verify encrypted value is not plaintext
            $this->assertStringNotContainsString($originalValue, $vaultRecord->encrypted_value, "Encrypted value contains plaintext for iteration {$i}");

            // Retrieve PII from vault
            $retrievedValue = $this->piiVaultService->retrievePII(
                $entityType,
                $entityId,
                $fieldName,
                'Property test validation'
            );

            // Verify integrity - retrieved value matches original
            $this->assertEquals($originalValue, $retrievedValue, "PII integrity compromised for iteration {$i}");

            // Verify access is logged
            $accessLog = DB::table('pii_access_log')
                ->where('vault_id', $storeResult['vault_id'])
                ->where('access_type', 'read')
                ->first();

            $this->assertNotNull($accessLog, "Access not logged for iteration {$i}");
            $this->assertTrue($accessLog->access_granted, "Access should be granted for iteration {$i}");

            $testResults[] = [
                'iteration' => $i,
                'entity_type' => $entityType,
                'field_name' => $fieldName,
                'encryption_verified' => true,
                'integrity_verified' => true,
                'access_logged' => true,
            ];
        }

        // Verify all iterations passed
        $successCount = count(array_filter($testResults, fn ($result) => $result['encryption_verified'] && $result['integrity_verified'] && $result['access_logged']
        ));

        $this->assertEquals($iterations, $successCount, 'Not all PII encryption and integrity tests passed');

        echo "\nProperty 21.1 Results: {$successCount}/{$iterations} PII encryption and integrity tests passed\n";
    }

    /**
     * Property 21.2: Access Control and Authorization
     *
     * Tests that PII access is properly controlled, logged, and denied when
     * appropriate based on consent and retention policies.
     *
     * Requirements: 20.2, 20.3
     */
    public function test_pii_access_control_and_authorization(): void
    {
        $iterations = 25; // Reduced for performance
        $testResults = [];

        for ($i = 0; $i < $iterations; $i++) {
            $entityType = 'customer';
            $entityId = $this->faker->numberBetween(1, 1000);
            $fieldName = 'email';
            $piiValue = $this->faker->email;

            // Store PII with specific retention policy
            $retentionDays = $this->faker->numberBetween(1, 30);
            $storeResult = $this->piiVaultService->storePII(
                $entityType,
                $entityId,
                $fieldName,
                $piiValue,
                [
                    'retention_policy' => 'customer_data',
                    'metadata' => ['test_retention_days' => $retentionDays],
                ]
            );

            $this->assertTrue($storeResult['success'], "Failed to store PII for iteration {$i}");

            // Test 1: Valid access should succeed
            $validRetrievedValue = $this->piiVaultService->retrievePII(
                $entityType,
                $entityId,
                $fieldName,
                'Valid business operation'
            );

            $this->assertEquals($piiValue, $validRetrievedValue, "Valid access failed for iteration {$i}");

            // Test 2: Simulate expired data by manually updating expiration
            DB::table('pii_vault')
                ->where('id', $storeResult['vault_id'])
                ->update(['expires_at' => Carbon::now()->subDays(1)]);

            $expiredRetrievedValue = $this->piiVaultService->retrievePII(
                $entityType,
                $entityId,
                $fieldName,
                'Attempting to access expired data'
            );

            $this->assertNull($expiredRetrievedValue, "Expired data access should be denied for iteration {$i}");

            // Test 3: Verify access denial is logged
            $denialLog = DB::table('pii_access_log')
                ->where('vault_id', $storeResult['vault_id'])
                ->where('access_granted', false)
                ->where('denial_reason', 'PII data has expired')
                ->first();

            $this->assertNotNull($denialLog, "Access denial not logged for iteration {$i}");

            // Test 4: Test non-existent PII access
            $nonExistentValue = $this->piiVaultService->retrievePII(
                $entityType,
                99999, // Non-existent entity ID
                $fieldName,
                'Attempting to access non-existent data'
            );

            $this->assertNull($nonExistentValue, "Non-existent data access should return null for iteration {$i}");

            $testResults[] = [
                'iteration' => $i,
                'valid_access_succeeded' => $validRetrievedValue === $piiValue,
                'expired_access_denied' => $expiredRetrievedValue === null,
                'denial_logged' => $denialLog !== null,
                'nonexistent_access_handled' => $nonExistentValue === null,
            ];
        }

        // Verify all access control tests passed
        $successCount = count(array_filter($testResults, fn ($result) => $result['valid_access_succeeded'] &&
            $result['expired_access_denied'] &&
            $result['denial_logged'] &&
            $result['nonexistent_access_handled']
        ));

        $this->assertEquals($iterations, $successCount, 'Not all PII access control tests passed');

        echo "\nProperty 21.2 Results: {$successCount}/{$iterations} PII access control tests passed\n";
    }

    /**
     * Property 21.3: Anonymization and Retention Compliance
     *
     * Tests that PII anonymization works correctly and maintains audit trails
     * while preventing access to anonymized data.
     *
     * Requirements: 20.2, 20.4
     */
    public function test_pii_anonymization_and_retention_compliance(): void
    {
        $iterations = 25; // Reduced for performance
        $testResults = [];

        for ($i = 0; $i < $iterations; $i++) {
            $entityType = $this->faker->randomElement(['customer', 'booking', 'attendee']);
            $entityId = $this->faker->numberBetween(1, 1000);
            $fieldName = $this->faker->randomElement(['email', 'phone', 'first_name']);
            $originalValue = $this->generateRandomPII($fieldName);
            $anonymizationMethod = $this->faker->randomElement(['hash', 'delete', 'pseudonymize', 'generalize']);

            // Store PII
            $storeResult = $this->piiVaultService->storePII(
                $entityType,
                $entityId,
                $fieldName,
                $originalValue,
                ['retention_policy' => 'customer_data']
            );

            $this->assertTrue($storeResult['success'], "Failed to store PII for iteration {$i}");

            // Verify original value can be retrieved
            $preAnonymizationValue = $this->piiVaultService->retrievePII(
                $entityType,
                $entityId,
                $fieldName,
                'Pre-anonymization verification'
            );

            $this->assertEquals($originalValue, $preAnonymizationValue, "Pre-anonymization retrieval failed for iteration {$i}");

            // Anonymize the PII
            $anonymizeResult = $this->piiVaultService->anonymizePII(
                $storeResult['vault_id'],
                $anonymizationMethod,
                'Property test anonymization'
            );

            $this->assertTrue($anonymizeResult['success'], "Anonymization failed for iteration {$i}");
            $this->assertEquals($anonymizationMethod, $anonymizeResult['method'], "Anonymization method mismatch for iteration {$i}");

            // Verify anonymized data cannot be retrieved as original value
            $postAnonymizationValue = $this->piiVaultService->retrievePII(
                $entityType,
                $entityId,
                $fieldName,
                'Post-anonymization verification'
            );

            $this->assertNull($postAnonymizationValue, "Anonymized data should not be retrievable for iteration {$i}");

            // Verify vault record is marked as anonymized
            $vaultRecord = DB::table('pii_vault')->where('id', $storeResult['vault_id'])->first();
            $this->assertNotNull($vaultRecord->anonymized_at, "Vault record not marked as anonymized for iteration {$i}");
            $this->assertEquals($anonymizationMethod, $vaultRecord->anonymization_method, "Anonymization method not recorded for iteration {$i}");

            // Verify anonymization is logged
            $anonymizationLog = DB::table('pii_anonymization_log')
                ->where('vault_id', $storeResult['vault_id'])
                ->where('anonymization_successful', true)
                ->first();

            $this->assertNotNull($anonymizationLog, "Anonymization not logged for iteration {$i}");

            // Test double anonymization prevention
            $doubleAnonymizeResult = $this->piiVaultService->anonymizePII(
                $storeResult['vault_id'],
                $anonymizationMethod,
                'Attempting double anonymization'
            );

            $this->assertFalse($doubleAnonymizeResult['success'], "Double anonymization should be prevented for iteration {$i}");

            $testResults[] = [
                'iteration' => $i,
                'anonymization_succeeded' => $anonymizeResult['success'],
                'post_anonymization_access_denied' => $postAnonymizationValue === null,
                'vault_record_updated' => $vaultRecord->anonymized_at !== null,
                'anonymization_logged' => $anonymizationLog !== null,
                'double_anonymization_prevented' => ! $doubleAnonymizeResult['success'],
            ];
        }

        // Verify all anonymization tests passed
        $successCount = count(array_filter($testResults, fn ($result) => $result['anonymization_succeeded'] &&
            $result['post_anonymization_access_denied'] &&
            $result['vault_record_updated'] &&
            $result['anonymization_logged'] &&
            $result['double_anonymization_prevented']
        ));

        $this->assertEquals($iterations, $successCount, 'Not all PII anonymization tests passed');

        echo "\nProperty 21.3 Results: {$successCount}/{$iterations} PII anonymization tests passed\n";
    }

    /**
     * Property 21.4: GDPR Data Export Compliance
     *
     * Tests that GDPR data export functionality works correctly and includes
     * all required data with proper formatting and security.
     *
     * Requirements: 20.4, 20.5
     */
    public function test_gdpr_data_export_compliance(): void
    {
        $iterations = 25; // Reduced for performance
        $testResults = [];

        for ($i = 0; $i < $iterations; $i++) {
            $entityType = 'customer';
            $entityId = $this->faker->numberBetween(1, 1000);
            $piiFields = ['email', 'phone', 'first_name', 'last_name', 'address'];
            $storedData = [];

            // Store multiple PII fields for the entity
            foreach ($piiFields as $fieldName) {
                $piiValue = $this->generateRandomPII($fieldName);
                $storeResult = $this->piiVaultService->storePII(
                    $entityType,
                    $entityId,
                    $fieldName,
                    $piiValue,
                    [
                        'retention_policy' => 'customer_data',
                        'data_classification' => 'sensitive',
                    ]
                );

                $this->assertTrue($storeResult['success'], "Failed to store {$fieldName} for iteration {$i}");
                $storedData[$fieldName] = $piiValue;
            }

            // Test full export (all fields)
            $fullExportData = $this->piiVaultService->exportPIIData($entityType, $entityId);

            $this->assertIsArray($fullExportData, "Export data should be array for iteration {$i}");
            $this->assertCount(count($piiFields), $fullExportData, "Export should include all fields for iteration {$i}");

            // Verify all stored data is in export
            $exportedFields = array_column($fullExportData, 'field_name');
            foreach ($piiFields as $fieldName) {
                $this->assertContains($fieldName, $exportedFields, "Field {$fieldName} missing from export for iteration {$i}");

                // Find the exported record for this field
                $exportedRecord = array_filter($fullExportData, fn ($record) => $record['field_name'] === $fieldName);
                $exportedRecord = reset($exportedRecord);

                $this->assertEquals($storedData[$fieldName], $exportedRecord['value'], "Exported value mismatch for {$fieldName} in iteration {$i}");
                $this->assertEquals('sensitive', $exportedRecord['data_classification'], "Data classification missing for {$fieldName} in iteration {$i}");
            }

            // Test selective export (specific fields)
            $requestedFields = $this->faker->randomElements($piiFields, $this->faker->numberBetween(1, 3));
            $selectiveExportData = $this->piiVaultService->exportPIIData($entityType, $entityId, $requestedFields);

            $this->assertCount(count($requestedFields), $selectiveExportData, "Selective export count mismatch for iteration {$i}");

            $selectiveExportedFields = array_column($selectiveExportData, 'field_name');
            foreach ($requestedFields as $requestedField) {
                $this->assertContains($requestedField, $selectiveExportedFields, "Requested field {$requestedField} missing from selective export for iteration {$i}");
            }

            // Test export of non-existent entity
            $nonExistentExport = $this->piiVaultService->exportPIIData($entityType, 99999);
            $this->assertEmpty($nonExistentExport, "Non-existent entity export should be empty for iteration {$i}");

            // Verify export access is logged
            $exportAccessLogs = DB::table('pii_access_log')
                ->where('access_type', 'export')
                ->where('access_reason', 'GDPR data export request')
                ->get();

            $this->assertGreaterThan(0, $exportAccessLogs->count(), "Export access not logged for iteration {$i}");

            $testResults[] = [
                'iteration' => $i,
                'full_export_complete' => count($fullExportData) === count($piiFields),
                'selective_export_accurate' => count($selectiveExportData) === count($requestedFields),
                'data_integrity_maintained' => true, // Verified above
                'nonexistent_entity_handled' => empty($nonExistentExport),
                'export_access_logged' => $exportAccessLogs->count() > 0,
            ];
        }

        // Verify all export tests passed
        $successCount = count(array_filter($testResults, fn ($result) => $result['full_export_complete'] &&
            $result['selective_export_accurate'] &&
            $result['data_integrity_maintained'] &&
            $result['nonexistent_entity_handled'] &&
            $result['export_access_logged']
        ));

        $this->assertEquals($iterations, $successCount, 'Not all GDPR export tests passed');

        echo "\nProperty 21.4 Results: {$successCount}/{$iterations} GDPR export tests passed\n";
    }

    /**
     * Property 21.5: Consent Management and Tracking
     *
     * Tests that consent is properly recorded, tracked, and enforced for
     * PII access and processing operations.
     *
     * Requirements: 20.3, 20.5
     */
    public function test_consent_management_and_tracking(): void
    {
        $iterations = 25; // Reduced for performance
        $testResults = [];

        for ($i = 0; $i < $iterations; $i++) {
            $entityType = 'customer';
            $entityId = $this->faker->numberBetween(1, 1000);
            $consentType = $this->faker->randomElement(['processing', 'marketing', 'analytics', 'sharing']);
            $consentGiven = $this->faker->boolean(80); // 80% chance of consent given

            // Record consent
            $consentResult = $this->piiVaultService->recordConsent(
                $entityType,
                $entityId,
                $consentType,
                $consentGiven,
                [
                    'method' => 'web_form',
                    'ip_address' => $this->faker->ipv4,
                    'expires_days' => $this->faker->numberBetween(30, 365),
                    'metadata' => [
                        'user_agent' => $this->faker->userAgent,
                        'consent_version' => '1.0',
                    ],
                ]
            );

            $this->assertTrue($consentResult['success'], "Failed to record consent for iteration {$i}");
            $this->assertEquals($consentGiven, $consentResult['consent_given'], "Consent value mismatch for iteration {$i}");

            // Verify consent is stored in database
            $consentRecord = DB::table('pii_consent_tracking')
                ->where('id', $consentResult['consent_id'])
                ->first();

            $this->assertNotNull($consentRecord, "Consent record not found for iteration {$i}");
            $this->assertEquals($entityType, $consentRecord->entity_type, "Entity type mismatch for iteration {$i}");
            $this->assertEquals($entityId, $consentRecord->entity_id, "Entity ID mismatch for iteration {$i}");
            $this->assertEquals($consentType, $consentRecord->consent_type, "Consent type mismatch for iteration {$i}");
            $this->assertEquals($consentGiven, $consentRecord->consent_given, "Consent given mismatch for iteration {$i}");

            // Test consent withdrawal
            if ($consentGiven) {
                $withdrawalResult = $this->piiVaultService->recordConsent(
                    $entityType,
                    $entityId,
                    $consentType,
                    false, // Withdraw consent
                    ['method' => 'web_form']
                );

                $this->assertTrue($withdrawalResult['success'], "Failed to record consent withdrawal for iteration {$i}");
                $this->assertFalse($withdrawalResult['consent_given'], "Consent withdrawal not recorded for iteration {$i}");
            }

            // Test consent expiration
            $expiredConsentResult = $this->piiVaultService->recordConsent(
                $entityType,
                $entityId + 1000, // Different entity to avoid conflicts
                $consentType,
                true,
                [
                    'method' => 'web_form',
                    'expires_days' => -1, // Already expired
                ]
            );

            $this->assertTrue($expiredConsentResult['success'], "Failed to record expired consent for iteration {$i}");

            // Verify expired consent record
            $expiredConsentRecord = DB::table('pii_consent_tracking')
                ->where('id', $expiredConsentResult['consent_id'])
                ->first();

            $this->assertNotNull($expiredConsentRecord, "Expired consent record not found for iteration {$i}");
            $this->assertTrue(Carbon::parse($expiredConsentRecord->consent_expires_at)->isPast(), "Consent should be expired for iteration {$i}");

            // Test multiple consent types for same entity
            $additionalConsentTypes = ['marketing', 'analytics'];
            foreach ($additionalConsentTypes as $additionalType) {
                if ($additionalType !== $consentType) {
                    $additionalConsentResult = $this->piiVaultService->recordConsent(
                        $entityType,
                        $entityId,
                        $additionalType,
                        $this->faker->boolean(),
                        ['method' => 'web_form']
                    );

                    $this->assertTrue($additionalConsentResult['success'], "Failed to record additional consent type {$additionalType} for iteration {$i}");
                }
            }

            // Verify multiple consent records exist
            $allConsentRecords = DB::table('pii_consent_tracking')
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->get();

            $this->assertGreaterThanOrEqual(2, $allConsentRecords->count(), "Multiple consent records not found for iteration {$i}");

            $testResults[] = [
                'iteration' => $i,
                'consent_recorded' => $consentResult['success'],
                'consent_data_accurate' => $consentRecord !== null && $consentRecord->consent_given == $consentGiven,
                'withdrawal_handled' => ! $consentGiven || $withdrawalResult['success'],
                'expiration_handled' => $expiredConsentRecord !== null,
                'multiple_consents_supported' => $allConsentRecords->count() >= 2,
            ];
        }

        // Verify all consent management tests passed
        $successCount = count(array_filter($testResults, fn ($result) => $result['consent_recorded'] &&
            $result['consent_data_accurate'] &&
            $result['withdrawal_handled'] &&
            $result['expiration_handled'] &&
            $result['multiple_consents_supported']
        ));

        $this->assertEquals($iterations, $successCount, 'Not all consent management tests passed');

        echo "\nProperty 21.5 Results: {$successCount}/{$iterations} consent management tests passed\n";
    }

    /**
     * Generate random PII data based on field type.
     *
     * @param  string  $fieldName  Field name to generate data for
     * @return string Generated PII value
     */
    private function generateRandomPII(string $fieldName): string
    {
        switch ($fieldName) {
            case 'email':
                return $this->faker->email;
            case 'phone':
                return $this->faker->phoneNumber;
            case 'first_name':
                return $this->faker->firstName;
            case 'last_name':
                return $this->faker->lastName;
            case 'address':
            case 'address_line_1':
                return $this->faker->streetAddress;
            case 'city':
                return $this->faker->city;
            case 'postal_code':
                return $this->faker->postcode;
            case 'country':
                return $this->faker->country;
            case 'date_of_birth':
                return $this->faker->date('Y-m-d', '-18 years');
            default:
                return $this->faker->words(3, true);
        }
    }
}
