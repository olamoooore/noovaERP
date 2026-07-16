<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\OcrProcessingService;
use Tests\TenantTestCase;

class OcrExtractionTest extends TenantTestCase
{
    protected User $user;

    protected OcrProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->service = app(OcrProcessingService::class);

        Storage::fake('ocr-documents-local');
    }

    private function createStoredDocument(array $attributes = []): OcrDocument
    {
        $document = OcrDocument::factory()->create(array_merge([
            'status' => 'PENDING',
            'file_name' => 'receipt.jpg',
            'file_path' => '2024/12/26/receipt.jpg',
            'mime_type' => 'image/jpeg',
            'created_by' => $this->user->id,
        ], $attributes));

        Storage::disk('ocr-documents-local')->put($document->file_path, 'fake-image-bytes');

        return $document;
    }

    private function fakeOcrResponse(array $payload, int $status = 200): void
    {
        Http::fake([
            config('ocr.service.url').'/api/bookkeeping/upload' => Http::response($payload, $status),
        ]);
    }

    /**
     * Property 5: OCR extraction produces complete data
     */
    public function test_ocr_extraction_produces_complete_data()
    {
        $document = $this->createStoredDocument();

        $this->fakeOcrResponse([
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
            'processing_details' => [
                'ocr_confidence' => 98,
            ],
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
        $document = $this->createStoredDocument();

        $this->fakeOcrResponse([
            'status' => 'success',
            'extracted_data' => [
                'vendor_customer_name' => 'Test Vendor',
                'invoice_number' => 'INV-001',
                'total_amount' => 100.00,
            ],
            'processing_details' => [
                'ocr_confidence' => 85,
            ],
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
        $document = $this->createStoredDocument();

        $this->fakeOcrResponse([
            'status' => 'error',
            'message' => 'Unable to extract text from image',
        ], 400);

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
        $document = $this->createStoredDocument();

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

        $this->fakeOcrResponse($rawData);

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
        $document = $this->createStoredDocument();

        $this->fakeOcrResponse([
            'status' => 'success',
            'extracted_data' => [
                'vendor_customer_name' => 'Test Vendor',
                'invoice_number' => 'INV-001',
                'total_amount' => 100.00,
            ],
            'processing_details' => [
                'ocr_confidence' => 95,
            ],
        ]);

        $this->service->processDocument($document);

        $document->refresh();

        // Verify confidence scores stored
        $this->assertNotNull($document->ocr_confidence_scores);
        // vendor_name is present -> gets full OCR confidence (0.95)
        $this->assertEquals(0.95, $document->ocr_confidence_scores['vendor_name']);
        // document_date is missing (invoice_date not in extracted_data) -> confidence 0.0
        $this->assertEquals(0.0, $document->ocr_confidence_scores['document_date']);

        // Test confidence methods: high vs low
        $this->assertTrue($document->getConfidenceScore('vendor_name') > 0.7);
        $this->assertFalse($document->getConfidenceScore('document_date') > 0.7);
    }

    /**
     * Test: OCR service timeout is handled
     */
    public function test_ocr_service_timeout_is_handled()
    {
        $document = $this->createStoredDocument();

        Http::fake([
            config('ocr.service.url').'/api/bookkeeping/upload' => function () {
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
        $document = $this->createStoredDocument();

        $this->fakeOcrResponse([
            'status' => 'success',
            'extracted_data' => [
                'vendor_customer_name' => 'Test Vendor',
                'total_amount' => 100.00,
                // Missing: line_items, tax_amount, invoice_date, invoice_number
            ],
            'processing_details' => [
                'ocr_confidence' => 85,
            ],
        ]);

        $this->service->processDocument($document);

        $document->refresh();

        // Should still succeed with partial data (vendor_name satisfies identifying data check)
        $this->assertEquals('NEEDS_REVIEW', $document->status);
        $this->assertEquals('Test Vendor', $document->vendor_name);
        $this->assertEquals(100.00, $document->total_amount);
        $this->assertNull($document->document_reference);
    }

    /**
     * Test: Extraction with raw OCR text but missing structured totals still
     * succeeds so users can complete review manually.
     */
    public function test_extraction_with_raw_text_but_missing_total_amount_still_needs_review()
    {
        $document = $this->createStoredDocument();

        $this->fakeOcrResponse([
            'status' => 'success',
            'extracted_data' => [
                'vendor_customer_name' => 'Unknown',
                'invoice_number' => null,
                'total_amount' => null,
            ],
            'processing_details' => [
                'ocr_confidence' => 72,
                'raw_text' => 'ACME SUPPLIES\nInvoice\nSubtotal 45.00\nTax 5.00\n',
            ],
        ]);

        $this->service->processDocument($document);

        $document->refresh();

        $this->assertEquals('NEEDS_REVIEW', $document->status);
        $this->assertNull($document->total_amount);
        $this->assertNotNull($document->ocr_raw_data);
        $this->assertSame(
            'ACME SUPPLIES\nInvoice\nSubtotal 45.00\nTax 5.00\n',
            $document->ocr_raw_data['processing_details']['raw_text'] ?? null
        );
    }

    /**
     * Test: Multiple extractions don't duplicate data
     */
    public function test_multiple_extractions_dont_duplicate_data()
    {
        $document = $this->createStoredDocument();

        $this->fakeOcrResponse([
            'status' => 'success',
            'extracted_data' => [
                'vendor_customer_name' => 'Test Vendor',
                'invoice_number' => 'INV-RETRY-001',
                'total_amount' => 100.00,
            ],
            'processing_details' => [
                'ocr_confidence' => 85,
            ],
        ]);

        // Process twice
        $this->service->processDocument($document);
        $document->refresh();
        $firstExtractedAt = $document->extracted_at;

        $document->update(['status' => 'EXTRACTION_FAILED']);

        sleep(1);

        $this->service->processDocument($document);
        $document->refresh();

        // Should update, not duplicate
        $this->assertNotEquals($firstExtractedAt, $document->extracted_at);
        $this->assertEquals(1, OcrDocument::where('uuid', $document->uuid)->count());
    }
}
