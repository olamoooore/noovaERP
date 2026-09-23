<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\OcrProcessingService;
use Tests\TenantTestCase;

/**
 * Issue #174 (Medium-High) — the Laravel caller must authenticate to the OCR
 * microservice and must refuse to call it when the shared secret is missing.
 *
 * The OCR service side (X-OCR-Service-Key 401 / upload cap / loopback bind) is
 * covered by ocr-service/tests/test_service_auth.py.
 */
class HR174OcrServiceAuthTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext((string) Str::uuid());
        $this->assertTenantSchemaReady(['ocr_documents']);
    }

    private function document(): OcrDocument
    {
        Storage::fake('ocr-documents-local');
        Storage::disk('ocr-documents-local')->put('docs/hr174.jpg', 'fake-image-bytes');

        return OcrDocument::factory()->create([
            'file_path' => 'docs/hr174.jpg',
            'file_name' => 'hr174.jpg',
            'mime_type' => 'image/jpeg',
            'status' => 'PENDING',
        ]);
    }

    private function fakeSuccessResponse(): void
    {
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'extracted_data' => [
                    'vendor_customer_name' => 'HR174 Vendor',
                    'invoice_date' => now()->toDateString(),
                    'invoice_number' => 'INV-HR174',
                    'currency' => 'MYR',
                    'line_items' => [['description' => 'Item', 'amount' => 10]],
                    'subtotal' => 10,
                    'tax_amount' => 0,
                    'total_amount' => 10,
                ],
                'processing_details' => [
                    'ocr_confidence' => 95.0,
                    'raw_text' => 'HR174 OCR raw text',
                    'char_count' => 19,
                    'engine' => 'tesseract',
                ],
            ], 200),
        ]);
    }

    public function test_service_key_is_sent_on_every_ocr_request(): void
    {
        config()->set('ocr.service.key', 'hr174-test-secret');
        $this->fakeSuccessResponse();

        app(OcrProcessingService::class)->processDocument($this->document());

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('X-OCR-Service-Key', 'hr174-test-secret');
        });
    }

    public function test_calling_ocr_service_without_a_configured_key_is_refused(): void
    {
        config()->set('ocr.service.key', null);
        Http::fake(['*' => Http::response([], 200)]);

        $document = $this->document();

        try {
            app(OcrProcessingService::class)->processDocument($document);
            $this->fail('Processing must be refused when OCR_SERVICE_KEY is not configured');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('OCR_SERVICE_KEY is not configured', $e->getMessage());
        }

        Http::assertNothingSent();
        // processDocument records the refusal as an extraction failure.
        $this->assertSame('EXTRACTION_FAILED', $document->fresh()->status);
        $this->assertStringContainsString('OCR_SERVICE_KEY is not configured', (string) $document->fresh()->error_message);
    }

    public function test_error_message_from_the_service_is_surfaced(): void
    {
        config()->set('ocr.service.key', 'hr174-test-secret');

        Http::fake([
            '*' => Http::response(['detail' => 'Invalid or missing X-OCR-Service-Key'], 401),
        ]);

        $document = $this->document();

        try {
            app(OcrProcessingService::class)->processDocument($document);
            $this->fail('A 401 from the OCR service must surface as a processing failure');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Invalid or missing X-OCR-Service-Key', $e->getMessage());
        }

        $this->assertSame('EXTRACTION_FAILED', $document->fresh()->status);
    }
}
