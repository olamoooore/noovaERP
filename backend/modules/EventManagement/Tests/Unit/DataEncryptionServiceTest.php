<?php

namespace Modules\EventManagement\Tests\Unit;

use Tests\TestCase;
use Modules\EventManagement\Services\DataEncryptionService;
use Illuminate\Support\Facades\Config;

/**
 * Unit Test for DataEncryptionService
 * 
 * Tests the core encryption functionality for sensitive data and ID information
 */
class DataEncryptionServiceTest extends TestCase
{
    private DataEncryptionService $encryptionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->encryptionService = new DataEncryptionService();
    }

    /**
     * Test basic encryption and decryption functionality
     * 
     * @test
     */
    public function test_basic_encryption_and_decryption()
    {
        $testData = 'This is sensitive test data';
        
        $encrypted = $this->encryptionService->encryptSensitiveData($testData);
        $decrypted = $this->encryptionService->decryptSensitiveData($encrypted);
        
        $this->assertNotNull($encrypted);
        $this->assertNotEquals($testData, $encrypted);
        $this->assertEquals($testData, $decrypted);
    }

    /**
     * Test ID information encryption with validation
     * 
     * @test
     */
    public function test_id_information_encryption()
    {
        $validIds = [
            'PASSPORT123456A',
            'S1234567A',
            '123456789',
            'ABCD123456'
        ];
        
        foreach ($validIds as $idInfo) {
            $encrypted = $this->encryptionService->encryptIdInformation($idInfo);
            $decrypted = $this->encryptionService->decryptIdInformation($encrypted);
            
            $this->assertNotNull($encrypted);
            $this->assertNotEquals($idInfo, $encrypted);
            $this->assertEquals($idInfo, $decrypted);
        }
    }

    /**
     * Test ID validation with invalid formats
     * 
     * @test
     */
    public function test_id_validation_rejects_invalid_formats()
    {
        $invalidIds = [
            '12345',      // Too short
            'ABC',        // Too short
            '',           // Empty
            null          // Null
        ];
        
        foreach ($invalidIds as $invalidId) {
            if ($invalidId === null || $invalidId === '') {
                $result = $this->encryptionService->encryptIdInformation($invalidId);
                $this->assertNull($result);
            } else {
                $this->expectException(\InvalidArgumentException::class);
                $this->encryptionService->encryptIdInformation($invalidId);
            }
        }
    }

    /**
     * Test encryption validation (round-trip test)
     * 
     * @test
     */
    public function test_encryption_validation()
    {
        $testData = 'Test data for validation';
        $isValid = $this->encryptionService->validateEncryption($testData);
        
        $this->assertTrue($isValid);
    }

    /**
     * Test hash generation for sensitive data
     * 
     * @test
     */
    public function test_hash_generation()
    {
        $testData = 'Data to hash';
        $hash = $this->encryptionService->hashSensitiveData($testData);
        
        $this->assertNotNull($hash);
        $this->assertNotEquals($testData, $hash);
        $this->assertEquals(64, strlen($hash)); // SHA-256 produces 64-character hex string
    }

    /**
     * Test integrity hash generation and verification
     * 
     * @test
     */
    public function test_integrity_hash_generation_and_verification()
    {
        $testData = 'Data for integrity check';
        $hash = $this->encryptionService->generateIntegrityHash($testData);
        
        $this->assertNotNull($hash);
        $this->assertTrue($this->encryptionService->verifyIntegrityHash($testData, $hash));
        $this->assertFalse($this->encryptionService->verifyIntegrityHash('Modified data', $hash));
    }

    /**
     * Test batch encryption and decryption
     * 
     * @test
     */
    public function test_batch_encryption_and_decryption()
    {
        $testData = [
            'field1' => 'Sensitive data 1',
            'field2' => 'Sensitive data 2',
            'field3' => 'Sensitive data 3'
        ];
        
        $encrypted = $this->encryptionService->encryptBatch($testData);
        $decrypted = $this->encryptionService->decryptBatch($encrypted);
        
        $this->assertCount(3, $encrypted);
        $this->assertCount(3, $decrypted);
        
        foreach ($testData as $key => $value) {
            $this->assertNotEquals($value, $encrypted[$key]);
            $this->assertEquals($value, $decrypted[$key]);
        }
    }

    /**
     * Test encryption info retrieval
     * 
     * @test
     */
    public function test_encryption_info_retrieval()
    {
        $info = $this->encryptionService->getEncryptionInfo();
        
        $this->assertIsArray($info);
        $this->assertArrayHasKey('algorithm', $info);
        $this->assertArrayHasKey('max_data_length', $info);
        $this->assertArrayHasKey('min_id_length', $info);
        $this->assertArrayHasKey('is_configured', $info);
        $this->assertArrayHasKey('is_enabled', $info);
        
        $this->assertEquals('AES-256-CBC', $info['algorithm']);
        $this->assertIsInt($info['max_data_length']);
        $this->assertIsInt($info['min_id_length']);
        $this->assertIsBool($info['is_configured']);
        $this->assertIsBool($info['is_enabled']);
    }

    /**
     * Test encryption rotation functionality
     * 
     * @test
     */
    public function test_encryption_rotation()
    {
        $testData = 'Data for rotation test';
        $encrypted = $this->encryptionService->encryptSensitiveData($testData);
        $rotated = $this->encryptionService->rotateEncryption($encrypted);
        $decrypted = $this->encryptionService->decryptSensitiveData($rotated);
        
        $this->assertNotNull($rotated);
        $this->assertNotEquals($encrypted, $rotated); // Should be different encryption
        $this->assertEquals($testData, $decrypted); // But same plaintext
    }

    /**
     * Test handling of null and empty values
     * 
     * @test
     */
    public function test_null_and_empty_value_handling()
    {
        // Test null values
        $this->assertNull($this->encryptionService->encryptSensitiveData(null));
        $this->assertNull($this->encryptionService->decryptSensitiveData(null));
        $this->assertNull($this->encryptionService->encryptIdInformation(null));
        $this->assertNull($this->encryptionService->decryptIdInformation(null));
        
        // Test empty strings
        $this->assertNull($this->encryptionService->encryptSensitiveData(''));
        $this->assertNull($this->encryptionService->decryptSensitiveData(''));
        $this->assertNull($this->encryptionService->encryptIdInformation(''));
        $this->assertNull($this->encryptionService->decryptIdInformation(''));
    }

    /**
     * Test data length validation
     * 
     * @test
     */
    public function test_data_length_validation()
    {
        // Test with very large data (should throw exception)
        $largeData = str_repeat('A', 100000); // 100KB
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Data too large for encryption');
        
        $this->encryptionService->encryptSensitiveData($largeData);
    }

    /**
     * Test secure wipe functionality
     * 
     * @test
     */
    public function test_secure_wipe()
    {
        $sensitiveData = 'Very sensitive information';
        $originalData = $sensitiveData;
        
        $this->encryptionService->secureWipe($sensitiveData);
        
        $this->assertNotEquals($originalData, $sensitiveData);
        $this->assertEquals('', $sensitiveData);
    }
}