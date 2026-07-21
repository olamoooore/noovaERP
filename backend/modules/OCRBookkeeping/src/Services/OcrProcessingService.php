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
     * OCR service upload endpoint paths.
     *
     * We prefer the current FastAPI route but keep the legacy path as a
     * fallback so older OCR deployments still work during rollout.
     *
     * @var array<int, string>
     */
    private array $uploadEndpoints;

    /**
     * OCR service timeout in seconds
     */
    private int $timeout;

    public function __construct()
    {
        $this->ocrServiceUrl = rtrim((string) config('ocr.service.url', env('OCR_SERVICE_URL', 'http://localhost:8000')), '/');
        $this->timeout = (int) config('ocr.service.timeout', env('OCR_SERVICE_TIMEOUT', 60));
        $this->uploadEndpoints = [
            '/api/bookkeeping/upload',
            '/extract',
        ];
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
            
            $disk = Storage::disk($storageDisk);
            
            if (empty($document->file_path)) {
                throw new \Exception("OCR source file path is missing.");
            }

            if (!$disk->exists($document->file_path)) {
                throw new \Exception("OCR source file is missing from storage.");
            }
            
            $fileContents = $disk->get($document->file_path);
            
            if ($fileContents === false || $fileContents === '') {
                 throw new \Exception("Failed to retrieve OCR source file from storage.");
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
            if ($document->isProcessing()) {
                $document->markAsExtractionFailed($e->getMessage());
            }

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
            $response = null;

            foreach ($this->uploadEndpoints as $endpoint) {
                $targetUrl = $this->ocrServiceUrl . $endpoint;

                Log::info("Calling OCR service at: {$targetUrl}");

                $candidateResponse = Http::connectTimeout(5)
                 ->timeout($this->timeout)
                 ->attach('file', $fileContents, $fileName, ['Content-Type' => $mimeType])
                 ->post($targetUrl);

                $response = $candidateResponse;

                if ($candidateResponse->status() !== 404) {
                    break;
                }
            }

            if ($response === null) {
                throw new \Exception('OCR service did not return a response');
            }

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

            $reviewFlags = $this->detectReviewFlags($extractedData);

            return [
                'raw_data' => $result, // Store complete response for audit
                'raw_text' => $processingDetails['raw_text'] ?? null,
                'confidence_scores' => $confidenceScores,
                'ocr_review_flags' => $reviewFlags,
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
     * Detect OCR review flags without changing extracted financial values.
     *
     * OCR only detects and flags inconsistencies. It must not recalculate,
     * correct, or overwrite subtotal, tax, discount, freight, rounding, or total.
     *
     * @param array $extractedData
     * @return array<int, string>
     */
    private function detectReviewFlags(array $extractedData): array
    {
        $flags = [];

        $lineItems = $extractedData['line_items'] ?? [];
        $subtotal = $this->toMoney($extractedData['subtotal'] ?? null);
        $tax = $this->toMoney($extractedData['tax_amount'] ?? null);
        $total = $this->toMoney($extractedData['total_amount'] ?? null);
        $discount = $this->toMoney($extractedData['discount'] ?? 0.0) ?? 0.0;
        $freight = $this->toMoney($extractedData['freight'] ?? 0.0) ?? 0.0;
        $rounding = $this->toMoney($extractedData['rounding'] ?? 0.0) ?? 0.0;

        if ($subtotal === null || $total === null) {
            $flags[] = 'MISSING_REQUIRED_AMOUNT';
        }

        if (
            ($subtotal !== null && $subtotal < 0)
            || ($tax !== null && $tax < 0)
            || ($total !== null && $total < 0)
            || $discount < 0
            || $freight < 0
        ) {
            $flags[] = 'NEGATIVE_TOTAL';
        }

        // 1. Line Total Reconciliation
        if (is_array($lineItems) && count($lineItems) > 0 && $subtotal !== null) {
            $lineTotal = 0.0;

            foreach ($lineItems as $item) {
                $lineTotal += $this->extractLineAmount($item);
            }

            if (abs($lineTotal - $subtotal) > 0.01) {
                $flags[] = 'LINE_TOTAL_MISMATCH';
            }
        }

        // 2. Document Total Validation
        if ($subtotal !== null && $total !== null) {
            $expectedTotal = $subtotal - $discount + ($tax ?? 0.0) + $freight + $rounding;

            if (abs($expectedTotal - $total) > 0.01) {
                $flags[] = 'DOCUMENT_TOTAL_MISMATCH';
            }
        }

        // 3 and 4. Tax Validation / Tax-Inclusive Detection
        if ($subtotal !== null && $total !== null) {
            if (($tax ?? 0.0) == 0.0 && $total > $subtotal) {
                if ($this->canBeExplainedByTaxInclusivePricing($subtotal, $total)) {
                    $flags[] = 'POSSIBLE_TAX_INCLUSIVE';
                } else {
                    $flags[] = 'TAX_INCONSISTENT';
                }
            }
        }

        return array_values(array_unique($flags));
    }

    /**
     * Extract line amount for reconciliation.
     *
     * @param mixed $item
     * @return float
     */
    private function extractLineAmount($item): float
    {
        if (!is_array($item)) {
            return 0.0;
        }

        $amount = $this->toMoney(
            $item['amount']
            ?? $item['line_total']
            ?? $item['total_amount']
            ?? $item['total']
            ?? null
        );

        if ($amount !== null) {
            return $amount;
        }

        $quantity = $this->toMoney($item['quantity'] ?? null);
        $unitPrice = $this->toMoney($item['unit_price'] ?? $item['price'] ?? null);

        if ($quantity !== null && $unitPrice !== null) {
            return round($quantity * $unitPrice, 2);
        }

        return 0.0;
    }

    /**
     * Detect whether total-subtotal difference could be tax-inclusive pricing.
     *
     * @param float $subtotal
     * @param float $total
     * @return bool
     */
    private function canBeExplainedByTaxInclusivePricing(float $subtotal, float $total): bool
    {
        if ($subtotal <= 0 || $total <= $subtotal) {
            return false;
        }

        $difference = round($total - $subtotal, 2);
        $rate = $difference / $subtotal;

        return $rate >= 0.01 && $rate <= 0.25;
    }

    /**
     * Convert OCR amount value to money without changing the source value.
     *
     * @param mixed $value
     * @return float|null
     */
    private function toMoney($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            $value = preg_replace('/[^\d.\-]/', '', $value);
        }

        if ($value === '' || $value === '-' || $value === '.') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * Validate OCR extraction quality
     * 
     * @param array $ocrData
     * @return bool
     */
    private function validateExtraction(array $ocrData): bool
    {
        $totalAmount = $ocrData['total_amount'] ?? null;
        $rawText = trim((string) ($ocrData['raw_text'] ?? ''));

        if (!is_numeric($totalAmount) || (float) $totalAmount <= 0) {
            if ($rawText !== '') {
                Log::info('OCR validation: allowing manual review despite missing total amount because raw text was extracted');
                return true;
            }

            Log::warning("OCR validation failed: Invalid total amount");
            return false;
        }

        $hasIdentifyingData = !empty($ocrData['document_reference'])
            || !empty($ocrData['line_items'])
            || (!empty($ocrData['vendor_name']) && $ocrData['vendor_name'] !== 'Unknown');

        if (!$hasIdentifyingData) {
            Log::warning('OCR validation failed: No identifying data extracted');
            return false;
        }

        if (isset($ocrData['confidence_scores'])) {
            $score = $ocrData['confidence_scores']['total_amount'] ?? null;
            if ($score !== null && $score < 0.3) {
                Log::warning("OCR validation failed: Very low confidence for 'total_amount': {$score}");
                return false;
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