<?php

namespace Modules\OCRBookkeeping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\OcrProcessingService;

/**
 * Process OCR Document Job
 * 
 * Asynchronous job to process uploaded documents through OCR
 * Implements retry logic with exponential backoff
 */
class ProcessOcrDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job
     * Exponential backoff: 60s, 300s (5min), 900s (15min)
     */
    public $backoff = [60, 300, 900];

    /**
     * The maximum number of seconds the job can run
     */
    public $timeout = 120;

    /**
     * The document ID to process
     */
    protected int $documentId;

    /**
     * Create a new job instance
     */
    public function __construct(int $documentId)
    {
        $this->documentId = $documentId;
        $this->onQueue('ocr-processing'); // Use dedicated queue for OCR
    }

    /**
     * Execute the job
     */
    public function handle(OcrProcessingService $service): void
    {
        try {
            $document = OcrDocument::findOrFail($this->documentId);

            Log::info("Processing OCR document job", [
                'document_id' => $this->documentId,
                'document_uuid' => $document->uuid,
                'attempt' => $this->attempts(),
            ]);

            // Process the document
            $service->processDocument($document);

            Log::info("OCR document processed successfully", [
                'document_id' => $this->documentId,
                'document_uuid' => $document->uuid,
            ]);

        } catch (\Exception $e) {
            $document = OcrDocument::find($this->documentId);
            
            if ($document) {
                // Increment retry count
                $document->increment('retry_count');

                Log::error("OCR processing failed", [
                    'document_id' => $this->documentId,
                    'document_uuid' => $document->uuid,
                    'attempt' => $this->attempts(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // If this is the last attempt, mark as failed
                if ($this->attempts() >= $this->tries) {
                    $document->markAsExtractionFailed($e->getMessage());

                    Log::error("OCR processing failed after all retries", [
                        'document_id' => $this->documentId,
                        'document_uuid' => $document->uuid,
                        'total_attempts' => $this->attempts(),
                    ]);

                    // TODO: Send notification to user about failed extraction
                    // This can be implemented later with notification service
                }
            }

            // Re-throw to trigger retry
            throw $e;
        }
    }

    /**
     * Handle a job failure
     */
    public function failed(\Throwable $exception): void
    {
        $document = OcrDocument::find($this->documentId);

        if ($document) {
            Log::critical("OCR job failed permanently", [
                'document_id' => $this->documentId,
                'document_uuid' => $document->uuid,
                'error' => $exception->getMessage(),
            ]);

            // Ensure document is marked as failed
            if (!$document->hasFailed()) {
                $document->markAsExtractionFailed(
                    "Processing failed after {$this->tries} attempts: " . $exception->getMessage()
                );
            }

            // TODO: Send failure notification to user
        }
    }

    /**
     * Get the tags that should be assigned to the job
     */
    public function tags(): array
    {
        return ['ocr-processing', "document:{$this->documentId}"];
    }

    /**
     * Determine the time at which the job should timeout
     */
    public function retryUntil(): \DateTime
    {
        // Allow retries for up to 1 hour
        return now()->addHour();
    }
}
