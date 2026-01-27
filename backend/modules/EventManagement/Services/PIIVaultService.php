<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PIIVaultService - Task 4.2
 * 
 * Provides secure PII storage and retrieval with encryption, access logging,
 * and automated anonymization workflows.
 * 
 * Requirements: 20.1, 20.2, 20.3, 20.4
 */
class PIIVaultService
{
    protected AuditService $auditService;
    
    // Current encryption key version
    private const CURRENT_KEY_VERSION = 1;
    
    // Default retention policies
    private const DEFAULT_RETENTION_POLICIES = [
        'customer_data' => 2555, // 7 years
        'booking_data' => 2555,  // 7 years
        'marketing_data' => 1095, // 3 years
        'analytics_data' => 365,  // 1 year
        'session_data' => 30,     // 30 days
    ];

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Store PII data in the vault with encryption and access logging.
     * 
     * @param string $entityType Type of entity (customer, booking, etc.)
     * @param int $entityId ID of the entity
     * @param string $fieldName Name of the PII field
     * @param mixed $value PII value to encrypt and store
     * @param array $options Storage options (retention_policy, classification, etc.)
     * @return array Storage result with vault ID and metadata
     * 
     * Requirements: 20.1, 20.2
     */
    public function storePII(string $entityType, int $entityId, string $fieldName, $value, array $options = []): array
    {
        return DB::transaction(function () use ($entityType, $entityId, $fieldName, $value, $options) {
            // Validate input
            if (empty($value) || is_null($value)) {
                throw new \InvalidArgumentException('PII value cannot be empty or null');
            }

            // Determine retention policy
            $retentionPolicy = $options['retention_policy'] ?? $this->determineRetentionPolicy($entityType, $fieldName);
            $dataClassification = $options['data_classification'] ?? 'sensitive';
            
            // Calculate expiration date
            $expiresAt = $this->calculateExpirationDate($retentionPolicy);
            
            // Encrypt the value
            $encryptedValue = $this->encryptValue($value);
            
            // Check if PII already exists for this entity/field
            $existingVault = DB::table('pii_vault')
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('field_name', $fieldName)
                ->first();
            
            if ($existingVault) {
                // Update existing PII
                $vaultId = $existingVault->id;
                
                DB::table('pii_vault')
                    ->where('id', $vaultId)
                    ->update([
                        'encrypted_value' => $encryptedValue,
                        'encryption_key_version' => self::CURRENT_KEY_VERSION,
                        'retention_policy' => $retentionPolicy,
                        'data_classification' => $dataClassification,
                        'expires_at' => $expiresAt,
                        'metadata' => json_encode($options['metadata'] ?? []),
                    ]);
                
                $action = 'pii_updated';
            } else {
                // Create new PII entry
                $vaultId = DB::table('pii_vault')->insertGetId([
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'field_name' => $fieldName,
                    'encrypted_value' => $encryptedValue,
                    'encryption_key_version' => self::CURRENT_KEY_VERSION,
                    'retention_policy' => $retentionPolicy,
                    'data_classification' => $dataClassification,
                    'created_at' => Carbon::now(),
                    'expires_at' => $expiresAt,
                    'metadata' => json_encode($options['metadata'] ?? []),
                ]);
                
                $action = 'pii_stored';
            }
            
            // Log the access
            $this->logPIIAccess($vaultId, 'write', $action, [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'field_name' => $fieldName,
                'retention_policy' => $retentionPolicy,
                'data_classification' => $dataClassification,
            ]);
            
            return [
                'success' => true,
                'vault_id' => $vaultId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'field_name' => $fieldName,
                'retention_policy' => $retentionPolicy,
                'expires_at' => $expiresAt->toISOString(),
                'action' => $action,
            ];
        });
    }

    /**
     * Retrieve PII data from the vault with decryption and access logging.
     * 
     * @param string $entityType Type of entity
     * @param int $entityId ID of the entity
     * @param string $fieldName Name of the PII field
     * @param string $accessReason Reason for accessing the PII
     * @param array $context Additional access context
     * @return mixed Decrypted PII value or null if not found/expired
     * 
     * Requirements: 20.1, 20.2, 20.3
     */
    public function retrievePII(string $entityType, int $entityId, string $fieldName, string $accessReason, array $context = [])
    {
        return DB::transaction(function () use ($entityType, $entityId, $fieldName, $accessReason, $context) {
            // Find the PII record
            $vaultRecord = DB::table('pii_vault')
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('field_name', $fieldName)
                ->whereNull('anonymized_at')
                ->first();
            
            if (!$vaultRecord) {
                // Log failed access attempt
                $this->logPIIAccess(null, 'read', $accessReason, [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'field_name' => $fieldName,
                    'access_granted' => false,
                    'denial_reason' => 'PII record not found',
                ], false, 'PII record not found');
                
                return null;
            }
            
            // Check if data has expired
            if ($vaultRecord->expires_at && Carbon::parse($vaultRecord->expires_at)->isPast()) {
                // Log expired access attempt
                $this->logPIIAccess($vaultRecord->id, 'read', $accessReason, [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'field_name' => $fieldName,
                    'access_granted' => false,
                    'denial_reason' => 'PII data has expired',
                ], false, 'PII data has expired');
                
                return null;
            }
            
            // Check consent if required
            if (!$this->checkConsentForAccess($entityType, $entityId, $accessReason, $context)) {
                // Log consent-denied access attempt
                $this->logPIIAccess($vaultRecord->id, 'read', $accessReason, [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'field_name' => $fieldName,
                    'access_granted' => false,
                    'denial_reason' => 'Insufficient consent for access',
                ], false, 'Insufficient consent for access');
                
                return null;
            }
            
            // Decrypt the value
            try {
                $decryptedValue = $this->decryptValue($vaultRecord->encrypted_value, $vaultRecord->encryption_key_version);
                
                // Log successful access
                $this->logPIIAccess($vaultRecord->id, 'read', $accessReason, [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'field_name' => $fieldName,
                    'access_granted' => true,
                    'data_classification' => $vaultRecord->data_classification,
                ]);
                
                return $decryptedValue;
                
            } catch (\Exception $e) {
                // Log decryption failure
                $this->logPIIAccess($vaultRecord->id, 'read', $accessReason, [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'field_name' => $fieldName,
                    'access_granted' => false,
                    'denial_reason' => 'Decryption failed',
                    'error' => $e->getMessage(),
                ], false, 'Decryption failed');
                
                Log::error('PII decryption failed', [
                    'vault_id' => $vaultRecord->id,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'field_name' => $fieldName,
                    'error' => $e->getMessage(),
                ]);
                
                return null;
            }
        });
    }

    /**
     * Anonymize PII data based on retention policies.
     * 
     * @param int $vaultId Vault record ID to anonymize
     * @param string $method Anonymization method (delete, hash, pseudonymize, generalize)
     * @param string $reason Reason for anonymization
     * @return array Anonymization result
     * 
     * Requirements: 20.2, 20.4
     */
    public function anonymizePII(int $vaultId, string $method = 'hash', string $reason = 'Retention policy'): array
    {
        return DB::transaction(function () use ($vaultId, $method, $reason) {
            // Get the vault record
            $vaultRecord = DB::table('pii_vault')->where('id', $vaultId)->first();
            
            if (!$vaultRecord) {
                throw new \InvalidArgumentException("Vault record not found: {$vaultId}");
            }
            
            if ($vaultRecord->anonymized_at) {
                return [
                    'success' => false,
                    'message' => 'PII already anonymized',
                    'vault_id' => $vaultId,
                    'anonymized_at' => $vaultRecord->anonymized_at,
                ];
            }
            
            // Store original metadata for audit
            $originalMetadata = [
                'entity_type' => $vaultRecord->entity_type,
                'entity_id' => $vaultRecord->entity_id,
                'field_name' => $vaultRecord->field_name,
                'data_classification' => $vaultRecord->data_classification,
                'retention_policy' => $vaultRecord->retention_policy,
                'created_at' => $vaultRecord->created_at,
            ];
            
            try {
                // Perform anonymization based on method
                $anonymizedValue = $this->performAnonymization($vaultRecord->encrypted_value, $method);
                
                // Update vault record
                DB::table('pii_vault')
                    ->where('id', $vaultId)
                    ->update([
                        'encrypted_value' => $anonymizedValue,
                        'anonymized_at' => Carbon::now(),
                        'anonymization_method' => $method,
                    ]);
                
                // Log anonymization
                DB::table('pii_anonymization_log')->insert([
                    'vault_id' => $vaultId,
                    'anonymization_method' => $method,
                    'policy_applied' => $vaultRecord->retention_policy,
                    'original_metadata' => json_encode($originalMetadata),
                    'anonymization_metadata' => json_encode([
                        'method' => $method,
                        'reason' => $reason,
                        'processed_by' => 'PIIVaultService',
                    ]),
                    'anonymization_successful' => true,
                    'processed_by' => 'system',
                    'processed_at' => Carbon::now(),
                ]);
                
                // Log the access
                $this->logPIIAccess($vaultId, 'delete', $reason, [
                    'anonymization_method' => $method,
                    'original_entity_type' => $vaultRecord->entity_type,
                    'original_entity_id' => $vaultRecord->entity_id,
                    'original_field_name' => $vaultRecord->field_name,
                ]);
                
                return [
                    'success' => true,
                    'vault_id' => $vaultId,
                    'method' => $method,
                    'anonymized_at' => Carbon::now()->toISOString(),
                    'reason' => $reason,
                ];
                
            } catch (\Exception $e) {
                // Log failed anonymization
                DB::table('pii_anonymization_log')->insert([
                    'vault_id' => $vaultId,
                    'anonymization_method' => $method,
                    'policy_applied' => $vaultRecord->retention_policy,
                    'original_metadata' => json_encode($originalMetadata),
                    'anonymization_metadata' => json_encode([
                        'method' => $method,
                        'reason' => $reason,
                        'error' => $e->getMessage(),
                    ]),
                    'anonymization_successful' => false,
                    'error_message' => $e->getMessage(),
                    'processed_by' => 'system',
                    'processed_at' => Carbon::now(),
                ]);
                
                Log::error('PII anonymization failed', [
                    'vault_id' => $vaultId,
                    'method' => $method,
                    'error' => $e->getMessage(),
                ]);
                
                return [
                    'success' => false,
                    'vault_id' => $vaultId,
                    'error' => $e->getMessage(),
                ];
            }
        });
    }

    /**
     * Get PII records that are eligible for anonymization.
     * 
     * @param int $batchSize Number of records to return
     * @return array List of vault IDs eligible for anonymization
     * 
     * Requirements: 20.2
     */
    public function getExpiredPIIRecords(int $batchSize = 100): array
    {
        return DB::table('pii_vault')
            ->whereNull('anonymized_at')
            ->where('expires_at', '<', Carbon::now())
            ->limit($batchSize)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Export PII data for GDPR compliance.
     * 
     * @param string $entityType Type of entity
     * @param int $entityId ID of the entity
     * @param array $requestedFields Specific fields to export (empty for all)
     * @return array Exported PII data
     * 
     * Requirements: 20.4
     */
    public function exportPIIData(string $entityType, int $entityId, array $requestedFields = []): array
    {
        $query = DB::table('pii_vault')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereNull('anonymized_at');
        
        if (!empty($requestedFields)) {
            $query->whereIn('field_name', $requestedFields);
        }
        
        $vaultRecords = $query->get();
        $exportData = [];
        
        foreach ($vaultRecords as $record) {
            try {
                $decryptedValue = $this->decryptValue($record->encrypted_value, $record->encryption_key_version);
                
                $exportData[] = [
                    'field_name' => $record->field_name,
                    'value' => $decryptedValue,
                    'data_classification' => $record->data_classification,
                    'retention_policy' => $record->retention_policy,
                    'created_at' => $record->created_at,
                    'expires_at' => $record->expires_at,
                ];
                
                // Log export access
                $this->logPIIAccess($record->id, 'export', 'GDPR data export request', [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'field_name' => $record->field_name,
                ]);
                
            } catch (\Exception $e) {
                Log::error('Failed to decrypt PII for export', [
                    'vault_id' => $record->id,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'field_name' => $record->field_name,
                    'error' => $e->getMessage(),
                ]);
                
                // Include error in export
                $exportData[] = [
                    'field_name' => $record->field_name,
                    'value' => '[DECRYPTION_ERROR]',
                    'error' => 'Unable to decrypt data',
                    'data_classification' => $record->data_classification,
                    'created_at' => $record->created_at,
                ];
            }
        }
        
        return $exportData;
    }

    /**
     * Record consent for PII processing.
     * 
     * @param string $entityType Type of entity giving consent
     * @param int $entityId ID of the entity
     * @param string $consentType Type of consent
     * @param bool $consentGiven Whether consent was given
     * @param array $options Additional consent options
     * @return array Consent recording result
     */
    public function recordConsent(string $entityType, int $entityId, string $consentType, bool $consentGiven, array $options = []): array
    {
        $consentId = DB::table('pii_consent_tracking')->insertGetId([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'consent_type' => $consentType,
            'consent_given' => $consentGiven,
            'consent_details' => $options['details'] ?? null,
            'consent_method' => $options['method'] ?? 'system',
            'ip_address' => $options['ip_address'] ?? null,
            'consent_metadata' => json_encode($options['metadata'] ?? []),
            'consent_given_at' => Carbon::now(),
            'consent_expires_at' => isset($options['expires_days']) ? 
                Carbon::now()->addDays($options['expires_days']) : null,
        ]);
        
        return [
            'success' => true,
            'consent_id' => $consentId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'consent_type' => $consentType,
            'consent_given' => $consentGiven,
        ];
    }

    // Private helper methods

    private function encryptValue($value): string
    {
        return Crypt::encrypt($value);
    }

    private function decryptValue(string $encryptedValue, int $keyVersion): string
    {
        // For now, we use Laravel's default encryption
        // In production, you might want to implement key versioning
        return Crypt::decrypt($encryptedValue);
    }

    private function determineRetentionPolicy(string $entityType, string $fieldName): string
    {
        // Determine retention policy based on entity type and field
        $policyMap = [
            'customer' => 'customer_data',
            'booking' => 'booking_data',
            'attendee' => 'booking_data',
            'marketing' => 'marketing_data',
            'analytics' => 'analytics_data',
            'session' => 'session_data',
        ];
        
        return $policyMap[$entityType] ?? 'customer_data';
    }

    private function calculateExpirationDate(string $retentionPolicy): Carbon
    {
        $retentionDays = self::DEFAULT_RETENTION_POLICIES[$retentionPolicy] ?? 
                        self::DEFAULT_RETENTION_POLICIES['customer_data'];
        
        return Carbon::now()->addDays($retentionDays);
    }

    private function performAnonymization(string $encryptedValue, string $method): string
    {
        switch ($method) {
            case 'delete':
                return '[DELETED]';
            
            case 'hash':
                // Hash the encrypted value (double protection)
                return hash('sha256', $encryptedValue);
            
            case 'pseudonymize':
                // Generate a consistent pseudonym
                return 'ANON_' . substr(hash('sha256', $encryptedValue), 0, 16);
            
            case 'generalize':
                return '[ANONYMIZED]';
            
            default:
                throw new \InvalidArgumentException("Unknown anonymization method: {$method}");
        }
    }

    private function checkConsentForAccess(string $entityType, int $entityId, string $accessReason, array $context): bool
    {
        // Check if consent is required for this type of access
        $consentRequiredReasons = ['marketing', 'analytics', 'sharing'];
        
        if (!in_array($accessReason, $consentRequiredReasons)) {
            return true; // No consent required for operational access
        }
        
        // Check for valid consent
        $consent = DB::table('pii_consent_tracking')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('consent_type', 'processing')
            ->where('consent_given', true)
            ->whereNull('consent_withdrawn_at')
            ->where(function ($query) {
                $query->whereNull('consent_expires_at')
                      ->orWhere('consent_expires_at', '>', Carbon::now());
            })
            ->first();
        
        return $consent !== null;
    }

    private function logPIIAccess(
        ?int $vaultId, 
        string $accessType, 
        string $accessReason, 
        array $context = [], 
        bool $accessGranted = true, 
        ?string $denialReason = null
    ): void {
        DB::table('pii_access_log')->insert([
            'vault_id' => $vaultId,
            'accessor_type' => 'system', // Could be enhanced to detect actual accessor type
            'accessor_id' => 'PIIVaultService',
            'access_reason' => $accessReason,
            'access_type' => $accessType,
            'access_granted' => $accessGranted,
            'denial_reason' => $denialReason,
            'ip_address' => request()->ip() ?? null,
            'user_agent' => request()->userAgent() ?? null,
            'session_id' => session()->getId() ?? null,
            'request_context' => json_encode($context),
            'accessed_at' => Carbon::now(),
        ]);
    }
}