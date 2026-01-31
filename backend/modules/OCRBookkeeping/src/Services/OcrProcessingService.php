<?php

namespace Modules\OCRBookkeeping\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Models\OcrDocument;

/**
 * OCR Processing Service
 * 
 * Orchestrates the OCR extraction workflow by calling the Python OCR service
 */
class OcrProcessingService
{
    /**
     * OCR service base URL
     */
    private string $ocrServiceUrl;

    /**
     * OCR service timeout in seconds
     */
    private int $timeout;

    public function __construct()
    {
        $this->ocrServiceUrl = config('services.ocr.url', env('OCR_SERVICE_URL', 'http://localhost:8000'));
        $this->timeout = (int) config('services.ocr.timeout', env('OCR_SERVICE_TIMEOUT', 60));
    }

    /**
     * Process uploaded document through OCR
     * 
     * @param OcrDocument $document
     * @return void
     * @throws \Exception
     */
    public function processDocument(OcrDocument $document): void
    {
        try {
            // Update status to PROCESSING
            $document->markAsProcessing();

            Log::info("Starting OCR processing for document: {$document->uuid}");

            // Get file from storage (use local storage for now)
            $storageDisk = 'ocr-documents-local';
            
            $fileContents = Storage::disk($storageDisk)->get($document->file_path);

            if (!$fileContents) {
                throw new \Exception("Failed to retrieve file from storage");
            }

            // Call OCR model
            $ocrData = $this->callOcrModel($fileContents, $document->file_name, $document->mime_type);

            // Validate extraction
            if (!$this->validateExtraction($ocrData)) {
                throw new \Exception("OCR extraction validation failed");
            }

            // Mark as needs review with extracted data
            $document->markAsNeedsReview($ocrData);

            Log::info("OCR processing completed successfully for document: {$document->uuid}");

        } catch (\Exception $e) {
            Log::error("OCR processing failed for document {$document->uuid}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Call external OCR model (Python service)
     * 
     * @param string $fileContents The file binary content
     * @param string $fileName Original file name
     * @param string $mimeType File MIME type
     * @return array Extracted OCR data
     * @throws \Exception
     */
    private function callOcrModel(string $fileContents, string $fileName, string $mimeType): array
    {
        try {
            Log::info("Calling OCR service at: {$this->ocrServiceUrl}/api/bookkeeping/upload");

            // Prepare multipart form data
            $response = Http::timeout($this->timeout)
                ->attach('file', $fileContents, $fileName)
                ->post("{$this->ocrServiceUrl}/api/bookkeeping/upload");

            if (!$response->successful()) {
                $errorMessage = $response->json('detail') ?? $response->body();
                throw new \Exception("OCR service error: {$errorMessage}");
            }

            $result = $response->json();

            if ($result['status'] !== 'success') {
                throw new \Exception("OCR service returned non-success status");
            }

            // Extract and format the data
            $extractedData = $result['extracted_data'] ?? [];
            $processingDetails = $result['processing_details'] ?? [];

            // Build confidence scores
            $confidenceScores = [
                'vendor_name' => $this->calculateFieldConfidence($extractedData, 'vendor_customer_name', $processingDetails),
                'document_date' => $this->calculateFieldConfidence($extractedData, 'invoice_date', $processingDetails),
                'total_amount' => $this->calculateFieldConfidence($extractedData, 'total_amount', $processingDetails),
                'invoice_number' => $this->calculateFieldConfidence($extractedData, 'invoice_number', $processingDetails),
            ];

            return [
                'raw_data' => $result, // Store complete response for audit
                'confidence_scores' => $confidenceScores,
                'vendor_name' => $extractedData['vendor_customer_name'] ?? null,
                'document_date' => $extractedData['invoice_date'] ?? null,
                'document_reference' => $extractedData['invoice_number'] ?? null,
                'currency_code' => $extractedData['currency'] ?? 'USD',
                'line_items' => $extractedData['line_items'] ?? null,
                'subtotal' => $extractedData['subtotal'] ?? null,
                'tax_amount' => $extractedData['tax_amount'] ?? null,
                'total_amount' => $extractedData['total_amount'] ?? null,
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("OCR service connection failed: {$e->getMessage()}");
            throw new \Exception("Failed to connect to OCR service. Please ensure the service is running.");
        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error("OCR service request failed: {$e->getMessage()}");
            throw new \Exception("OCR service request failed: " . $e->response->body());
        } catch (\Exception $e) {
            Log::error("OCR model call failed: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Calculate confidence score for a specific field
     * 
     * @param array $extractedData
     * @param string $fieldName
     * @param array $processingDetails
     * @return float Confidence score between 0 and 1
     */
    private function calculateFieldConfidence(array $extractedData, string $fieldName, array $processingDetails): float
    {
        // Use OCR confidence if available
        $ocrConfidence = ($processingDetails['ocr_confidence'] ?? 80) / 100;

        // Check if field has a value
        $fieldValue = $extractedData[$fieldName] ?? null;
        
        if ($fieldValue === null || $fieldValue === '' || $fieldValue === 'Unknown') {
            return 0.0;
        }

        // Apply field-specific confidence adjustments
        $confidence = $ocrConfidence;

        // Reduce confidence for critical fields if they look suspicious
        if ($fieldName === 'total_amount') {
            if (!is_numeric($fieldValue) || $fieldValue <= 0) {
                $confidence *= 0.5;
            }
        }

        if ($fieldName === 'document_date') {
            // Check if date is valid
            if (!strtotime($fieldValue)) {
                $confidence *= 0.3;
            }
        }

        return min(1.0, max(0.0, $confidence));
    }

    /**
     * Validate OCR extraction quality
     * 
     * @param array $ocrData
     * @return bool
     */
    private function validateExtraction(array $ocrData): bool
    {
        // Check required fields exist
        $requiredFields = ['vendor_name', 'total_amount'];
        
        foreach ($requiredFields as $field) {
            if (!isset($ocrData[$field]) || $ocrData[$field] === null) {
                Log::warning("OCR validation failed: Missing required field '{$field}'");
                return false;
            }
        }

        // Validate total amount is numeric and positive
        if (!is_numeric($ocrData['total_amount']) || $ocrData['total_amount'] <= 0) {
            Log::warning("OCR validation failed: Invalid total amount");
            return false;
        }

        // Check confidence scores
        if (isset($ocrData['confidence_scores'])) {
            $criticalFields = ['total_amount', 'document_date'];
            foreach ($criticalFields as $field) {
                $score = $ocrData['confidence_scores'][$field] ?? 0;
                if ($score < 0.3) { // Very low confidence threshold
                    Log::warning("OCR validation failed: Very low confidence for '{$field}': {$score}");
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if OCR service is available
     * 
     * @return bool
     */
    public function isServiceAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->ocrServiceUrl}/health");
            return $response->successful();
        } catch (\Exception $e) {
            Log::warning("OCR service health check failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Get OCR service status
     * 
     * @return array
     */
    public function getServiceStatus(): array
    {
        try {
            $response = Http::timeout(5)->get("{$this->ocrServiceUrl}/health");
            
            if ($response->successful()) {
                return [
                    'available' => true,
                    'status' => 'healthy',
                    'response' => $response->json(),
                ];
            }

            return [
                'available' => false,
                'status' => 'unhealthy',
                'error' => $response->body(),
            ];

        } catch (\Exception $e) {
            return [
                'available' => false,
                'status' => 'unreachable',
                'error' => $e->getMessage(),
            ];
        }
    }
}
