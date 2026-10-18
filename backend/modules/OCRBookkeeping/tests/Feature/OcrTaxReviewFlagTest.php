<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TenantTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\OcrProcessingService;

class OcrTaxReviewFlagTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext('00000000-0000-0000-0000-000000000001');

        Storage::fake('ocr-documents-local');
    }

    public function test_tax_total_mismatch_creates_review_flags_and_keeps_document_in_needs_review(): void
    {
        $file = UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 fake invoice');

        $path = $file->storeAs('test-tenant', 'invoice.pdf', 'ocr-documents-local');

        $document = OcrDocument::factory()->create([
            'status' => 'PENDING',
            'file_path' => $path,
            'file_name' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
        ]);

        Http::fake([
            '*/api/bookkeeping/upload' => Http::response([
                'status' => 'success',
                'extracted_data' => [
                    'vendor_customer_name' => 'Test Vendor',
                    'invoice_date' => now()->toDateString(),
                    'invoice_number' => 'INV-TAX-001',
                    'currency' => 'USD',
                    'line_items' => [
                        [
                            'description' => 'Service Fee',
                            'amount' => 150.00,
                        ],
                    ],
                    'subtotal' => 150.00,
                    'tax_amount' => 0.00,
                    'discount' => 0.00,
                    'total_amount' => 165.00,
                ],
                'processing_details' => [
                    'raw_text' => 'Subtotal 150 Tax 0 Total 165',
                    'ocr_confidence' => 95,
                ],
            ], 200),
        ]);

        app(OcrProcessingService::class)->processDocument($document);

        $document->refresh();

        $this->assertEquals('NEEDS_REVIEW', $document->status);
        $this->assertEquals(150.00, (float) $document->subtotal);
        $this->assertEquals(0.00, (float) $document->tax_amount);
        $this->assertEquals(165.00, (float) $document->total_amount);

        $this->assertContains('DOCUMENT_TOTAL_MISMATCH', $document->ocr_review_flags);
        $this->assertContains('POSSIBLE_TAX_INCLUSIVE', $document->ocr_review_flags);
        $this->assertTrue($document->hasBlockingOcrReviewFlags());
        $this->assertFalse($document->canBePosted());
    }
}