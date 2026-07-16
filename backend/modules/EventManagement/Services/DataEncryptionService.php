<?php

namespace Modules\EventManagement\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class DataEncryptionService
{
    /**
     * Encryption algorithm used for sensitive data
     */
    private const ENCRYPTION_ALGORITHM = 'AES-256-CBC';

    /**
     * Get maximum data length from configuration
     */
    private function getMaxDataLength(): int
    {
        return Config::get('event_management.encryption.max_data_length', 65535);
    }

    /**
     * Get minimum ID length from configuration
     */
    private function getMinIdLength(): int
    {
        return Config::get('event_management.encryption.min_id_length', 6);
    }

    /**
     * Check if encryption is enabled in configuration
     */
    private function isEncryptionEnabled(): bool
    {
        return Config::get('event_management.encryption.enabled', true);
    }

    /**
     * Encrypt sensitive data with additional security measures
     */
    public function encryptSensitiveData(?string $data): ?string
    {
        if (empty($data)) {
            return null;
        }

        // Check if encryption is enabled
        if (! $this->isEncryptionEnabled()) {
            Log::warning('Encryption is disabled in configuration, returning data as-is');

            return $data;
        }

        // Validate data length
        $maxLength = $this->getMaxDataLength();
        if (strlen($data) > $maxLength) {
            throw new \InvalidArgumentException('Data too large for encryption (max '.$maxLength.' bytes)');
        }

        try {
            // Use Laravel's encryption with additional metadata
            $encrypted = Crypt::encryptString($data);

            // Log encryption activity (without sensitive data) if secure logging is enabled
            if (Config::get('event_management.encryption.secure_logging', true)) {
                Log::info('Sensitive data encrypted', [
                    'data_length' => strlen($data),
                    'encrypted_length' => strlen($encrypted),
                    'timestamp' => now()->toISOString(),
                ]);
            }

            return $encrypted;
        } catch (\Exception $e) {
            Log::error('Failed to encrypt sensitive data', [
                'error' => $e->getMessage(),
                'data_length' => strlen($data),
            ]);
            throw new \RuntimeException('Failed to encrypt sensitive data: '.$e->getMessage());
        }
    }

    /**
     * Decrypt sensitive data with enhanced error handling
     */
    public function decryptSensitiveData(?string $encryptedData): ?string
    {
        if (empty($encryptedData)) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($encryptedData);

            // Log successful decryption (without sensitive data)
            Log::debug('Sensitive data decrypted successfully', [
                'encrypted_length' => strlen($encryptedData),
                'decrypted_length' => strlen($decrypted),
            ]);

            return $decrypted;
        } catch (DecryptException $e) {
            // Log the error but don't expose it
            Log::warning('Failed to decrypt sensitive data', [
                'error' => $e->getMessage(),
                'encrypted_data_length' => strlen($encryptedData),
                'error_code' => 'DECRYPTION_FAILED',
            ]);

            return null;
        }
    }

    /**
     * Encrypt ID information with enhanced validation and security
     */
    public function encryptIdInformation(?string $idInfo): ?string
    {
        if (empty($idInfo)) {
            return null;
        }

        // Sanitize and validate ID format
        $cleanedId = $this->sanitizeIdInformation($idInfo);

        $minLength = $this->getMinIdLength();
        if (strlen($cleanedId) < $minLength) {
            throw new \InvalidArgumentException('ID information must be at least '.$minLength.' characters long after sanitization');
        }

        // Additional validation for common ID formats
        if (! $this->validateIdFormat($cleanedId)) {
            throw new \InvalidArgumentException('ID information format is not valid');
        }

        return $this->encryptSensitiveData($idInfo); // Encrypt original, not cleaned version
    }

    /**
     * Decrypt ID information with validation
     */
    public function decryptIdInformation(?string $encryptedIdInfo): ?string
    {
        $decrypted = $this->decryptSensitiveData($encryptedIdInfo);

        if ($decrypted !== null && Config::get('event_management.encryption.integrity_check', true)) {
            // Validate decrypted data integrity
            if (! $this->validateDecryptedIdIntegrity($decrypted)) {
                Log::warning('Decrypted ID information failed integrity check', [
                    'decrypted_length' => strlen($decrypted),
                ]);

                return null;
            }
        }

        return $decrypted;
    }

    /**
     * Sanitize ID information by removing non-alphanumeric characters
     */
    private function sanitizeIdInformation(string $idInfo): string
    {
        // Remove all non-alphanumeric characters for validation
        return preg_replace('/[^a-zA-Z0-9]/', '', $idInfo);
    }

    /**
     * Validate ID format using common patterns from configuration
     */
    private function validateIdFormat(string $cleanedId): bool
    {
        $minLength = $this->getMinIdLength();

        // Basic validation - at least minimum alphanumeric characters
        if (strlen($cleanedId) < $minLength) {
            return false;
        }

        // Get patterns from configuration
        $patterns = Config::get('event_management.encryption.id_validation_patterns', [
            'passport' => '/^[A-Z]{2,4}[0-9]{6,}[A-Z]?$/',
            'numeric' => '/^[0-9]{6,12}$/',
            'nric' => '/^[A-Z][0-9]{7,}[A-Z]$/',
            'general' => '/^[A-Z0-9]{8,}$/',
        ]);

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $cleanedId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate integrity of decrypted ID information
     */
    private function validateDecryptedIdIntegrity(string $decrypted): bool
    {
        // Check for reasonable length
        if (strlen($decrypted) < 3 || strlen($decrypted) > 50) {
            return false;
        }

        // Check for printable characters only
        if (! ctype_print($decrypted)) {
            return false;
        }

        // Check for suspicious patterns that might indicate corruption
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $decrypted)) {
            return false;
        }

        return true;
    }

    /**
     * Validate that data can be encrypted and decrypted properly (round-trip test)
     */
    public function validateEncryption(string $testData): bool
    {
        try {
            $encrypted = $this->encryptSensitiveData($testData);
            if ($encrypted === null) {
                return false;
            }

            $decrypted = $this->decryptSensitiveData($encrypted);

            return $decrypted === $testData;
        } catch (\Exception $e) {
            Log::error('Encryption validation failed', [
                'error' => $e->getMessage(),
                'test_data_length' => strlen($testData),
            ]);

            return false;
        }
    }

    /**
     * Securely hash sensitive data for comparison purposes
     */
    public function hashSensitiveData(string $data): string
    {
        // Use SHA-256 with salt for secure hashing
        $salt = Config::get('app.key', 'default-salt');

        return hash('sha256', $salt.$data);
    }

    /**
     * Generate a secure hash for data integrity verification
     */
    public function generateIntegrityHash(string $data): string
    {
        return hash_hmac('sha256', $data, Config::get('app.key'));
    }

    /**
     * Verify data integrity using HMAC
     */
    public function verifyIntegrityHash(string $data, string $hash): bool
    {
        $expectedHash = $this->generateIntegrityHash($data);

        return hash_equals($expectedHash, $hash);
    }

    /**
     * Check if encryption is properly configured
     */
    public function isEncryptionConfigured(): bool
    {
        try {
            // Test basic encryption functionality
            $testData = 'encryption-test-'.time();

            return $this->validateEncryption($testData);
        } catch (\Exception $e) {
            Log::error('Encryption configuration check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get encryption algorithm information
     */
    public function getEncryptionInfo(): array
    {
        return [
            'algorithm' => self::ENCRYPTION_ALGORITHM,
            'max_data_length' => $this->getMaxDataLength(),
            'min_id_length' => $this->getMinIdLength(),
            'is_configured' => $this->isEncryptionConfigured(),
            'is_enabled' => $this->isEncryptionEnabled(),
            'laravel_cipher' => Config::get('app.cipher', 'unknown'),
            'integrity_check_enabled' => Config::get('event_management.encryption.integrity_check', true),
            'secure_logging_enabled' => Config::get('event_management.encryption.secure_logging', true),
        ];
    }

    /**
     * Encrypt multiple fields at once for batch operations
     */
    public function encryptBatch(array $data): array
    {
        $encrypted = [];
        $errors = [];

        foreach ($data as $key => $value) {
            try {
                $encrypted[$key] = $this->encryptSensitiveData($value);
            } catch (\Exception $e) {
                $errors[$key] = $e->getMessage();
                $encrypted[$key] = null;
            }
        }

        if (! empty($errors)) {
            Log::warning('Batch encryption had errors', [
                'errors' => $errors,
                'successful_count' => count($encrypted) - count($errors),
            ]);
        }

        return $encrypted;
    }

    /**
     * Decrypt multiple fields at once for batch operations
     */
    public function decryptBatch(array $encryptedData): array
    {
        $decrypted = [];
        $errors = [];

        foreach ($encryptedData as $key => $value) {
            try {
                $decrypted[$key] = $this->decryptSensitiveData($value);
            } catch (\Exception $e) {
                $errors[$key] = $e->getMessage();
                $decrypted[$key] = null;
            }
        }

        if (! empty($errors)) {
            Log::warning('Batch decryption had errors', [
                'errors' => $errors,
                'successful_count' => count($decrypted) - count($errors),
            ]);
        }

        return $decrypted;
    }

    /**
     * Rotate encryption for existing data (for key rotation scenarios)
     */
    public function rotateEncryption(string $encryptedData): ?string
    {
        try {
            // Decrypt with old key
            $plaintext = $this->decryptSensitiveData($encryptedData);

            if ($plaintext === null) {
                return null;
            }

            // Re-encrypt with current key
            return $this->encryptSensitiveData($plaintext);
        } catch (\Exception $e) {
            Log::error('Encryption rotation failed', [
                'error' => $e->getMessage(),
                'encrypted_data_length' => strlen($encryptedData),
            ]);

            return null;
        }
    }

    /**
     * Securely wipe sensitive data from memory (best effort)
     */
    public function secureWipe(string &$data): void
    {
        // Overwrite the string with random data multiple times
        $length = strlen($data);
        for ($i = 0; $i < 3; $i++) {
            $data = str_repeat(chr(random_int(0, 255)), $length);
        }
        $data = '';
    }
}
