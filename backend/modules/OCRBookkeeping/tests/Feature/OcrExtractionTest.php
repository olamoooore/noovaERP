<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\OcrProcessingService;
use App\Models\User;

class OcrExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected OcrProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->service = app(OcrProcessingService::class);
    }

    /**
     * Property 5: OCR extraction produces complete data
     */
    public function test_ocr_extraction_produces_complete_data()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'PENDING',
            'file_path' => '2024/12/26/receipt.jpg',
        ]);

        // Mock OCR service response
        Http::fake([
            config('ocr.service.url') . '/extract' => Http::response([
                'status' => 'success',
                'extracted_data' => [
                    'vendor_customer_name' => 'ABC Supplies Ltd',
                    'invoice_date' => '2024-12-20',
                    'invoice_number' => 'INV-2024-001',
                    'total_amount' => 1250.00,
                    'currency' => 'USD',
                    'line_items' => [
                        [
                            'description' => 'Office Supplies',
                            'amount' => 1000.00,
                            'quantity' => 1,
                        ],
                    ],
                    'tax_amount' => 250.00,
                ],
                'confidence_scores' => [
                    'vendor_name' => 0.95,
                    'document_date' => 0.92,
                    'total_amount' => 0.98,
                    'invoice_number' => 0.90,
                ],
            ], 200),
        ]);

        $this->service->processDocument($document);

        $document->refresh();

        // Verify all required fields extracted
        $this->assertEquals('NEEDS_REVIEW', $document->status);
        $this->assertEquals('ABC Supplies Ltd', $document->vendor_name);
        $this->assertEquals('2024-12-20', $document->document_date->format('Y-m-d'));
        $this->assertEquals('INV-2024-001', $document->document_reference);
        $this->assertEquals(1250.00, $document->total_amount);
        $this->assertEquals('USD', $document->currency_code);
        $this->assertNotNull($document->line_items);
        $this->assertCount(1, $document->line_items);
        $this->assertNotNull($document->extracted_at);
    }

    /**
     * Property 6: Successful extraction updates status
     */
    public function test_successful_extraction_updates_status()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'PENDING',
        ]);

        Http::fake([
            config('ocr.service.url') . '/extract' => Http::response([
                'status' => 'success',
                'extracted_data' => [
                    'vendor_customer_name' => 'Test Vendor',
                    'total_amount' => 100.00,
                ],
                'confidence_scores' => [
                    'vendor_name' => 0.85,
                ],
            ], 200),
        ]);

        $this->service->processDocument($document);

        $document->refresh();
        $this->assertEquals('NEEDS_REVIEW', $document->status);
    }

    /**
     * Property 7: Failed extraction updates status
     */
    public function test_failed_extraction_updates_status()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'PROCESSING',
        ]);

        Http::fake([
            config('ocr.service.url') . '/extract' => Http::response([
                'status' => 'error',
                'message' => 'Unable to extract text from image',
            ], 400),
        ]);

        try {
            $this->service->processDocument($document);
        } catch (\Exception $e) {
            // Expected to throw exception
        }

        $document->refresh();
        $this->assertEquals('EXTRACTION_FAILED', $document->status);
        $this->assertNotNull($document->error_message);
    }

    /**
     * Property 8: Raw OCR data is preserved
     */
    public function test_raw_ocr_data_is_preserved()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'PENDING',
        ]);

        $rawData = [
            'status' => 'success',
            'extracted_data' => [
                'vendor_customer_name' => 'Test Vendor',
                'total_amount' => 100.00,
                'raw_text' => 'Some raw OCR text...',
            ],
            'confidence_scores' => [
                'vendor_name' => 0.85,
            ],
        ];

        Http::fake([
            config('ocr.service.url') . '/extract' => Http::response($rawData, 200),
        ]);

        $this->service->processDocument($document);

        $document->refresh();
        
        // Verify raw data stored
        $this->assertNotNull($document->ocr_raw_data);
        $this->assertEquals('success', $document->ocr_raw_data['status']);
        $this->assertArrayHasKey('extracted_data', $document->ocr_raw_data);
    }

    /**
     * Property 9: Low confidence fields are flagged
     */
    public function test_low_confidence_fields_are_flagged()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'PENDING',
        ]);

        Http::fake([
            config('ocr.service.url') . '/extract' => Http::response([
                'status' => 'success',
                'extracted_data' => [
                    'vendor_customer_name' => 'Test Vendor',
                    'invoice_number' => 'INV-001',
                    'total_amount' => 100.00,
                ],
                'confidence_scores' => [
                    'vendor_name' => 0.95, // High confidence
                    'invoice_number' => 0.45, // Low confidence
                    'total_amount' => 0.88, // Medium confidence
                ],
            ], 200),
        ]);

        $this->service->processDocument($document);

        $document->refresh();
        
        // Verify confidence scores stored
        $this->assertNotNull($document->ocr_confidence_scores);
        $this->assertEquals(0.95, $document->ocr_confidence_scores['vendor_name']);
        $this->assertEquals(0.45, $document->ocr_confidence_scores['invoice_number']);
        
        // Test confidence methods
        $this->assertTrue($document->getConfidenceScore('vendor_name') > 0.7);
        $this->assertFalse($document->getConfidenceScore('invoice_number') > 0.7);
    }

    /**
     * Test: OCR service timeout is handled
     */
    public function test_ocr_service_timeout_is_handled()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'PROCESSING',
        ]);

        Http::fake([
            config('ocr.service.url') . '/extract' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timeout');
            },
        ]);

        $this->expectException(\Exception::class);
        
        $this->service->processDocument($document);

        $document->refresh();
        $this->assertEquals('EXTRACTION_FAILED', $document->status);
    }

    /**
     * Test: Extraction handles missing optional fields
     */
    public function test_extraction_handles_missing_optional_fields()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'PENDING',
        ]);

        Http::fake([
            config('ocr.service.url') . '/extract' => Http::response([
                'status' => 'success',
                'extracted_data' => [
                    'vendor_customer_name' => 'Test Vendor',
                    'total_amount' => 100.00,
                    // Missing: invoice_number, line_items, tax_amount
                ],
                'confidence_scores' => [
                    'vendor_name' => 0.85,
                ],
            ], 200),
        ]);

        $this->service->processDocument($document);

        $document->refresh();
        
        // Should still succeed with partial data
        $this->assertEquals('NEEDS_REVIEW', $document->status);
        $this->assertEquals('Test Vendor', $document->vendor_name);
        $this->assertEquals(100.00, $document->total_amount);
        $this->assertNull($document->document_reference);
    }

    /**
     * Test: Multiple extractions don't duplicate data
     */
    public function test_multiple_extractions_dont_duplicate_data()
    {
        $document = OcrDocument::factory()->create([
            'status' => 'PENDING',
        ]);

        Http::fake([
            config('ocr.service.url') . '/extract' => Http::response([
                'status' => 'success',
                'extracted_data' => [
                    'vendor_customer_name' => 'Test Vendor',
                    'total_amount' => 100.00,
                ],
                'confidence_scores' => [
                    'vendor_name' => 0.85,
                ],
            ], 200),
        ]);

        // Process twice
        $this->service->processDocument($document);
        $document->refresh();
        $firstExtractedAt = $document->extracted_at;

        sleep(1);

        $this->service->processDocument($document);
        $document->refresh();
        
        // Should update, not duplicate
        $this->assertNotEquals($firstExtractedAt, $document->extracted_at);
        $this->assertEquals(1, OcrDocument::where('uuid', $document->uuid)->count());
    }
}
