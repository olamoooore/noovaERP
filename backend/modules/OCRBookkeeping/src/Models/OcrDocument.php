<?php

namespace Modules\OCRBookkeeping\Models;

use Database\Factories\OcrDocumentFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Vendor;
use App\Models\JournalEntry;
use App\Models\User;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * OCR Document Model
 * 
 * Represents an uploaded document and tracks its processing lifecycle.
 * Status flow: PENDING -> PROCESSING -> NEEDS_REVIEW -> POSTED / PROMOTED
 *              PROCESSING -> EXTRACTION_FAILED (on error)
 */
class OcrDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ocr_documents';

    protected $fillable = [
        'uuid',
        'file_path',
        'file_name',
        'file_hash',
        'file_size',
        'mime_type',
        'status',
        'ocr_raw_data',
        'ocr_confidence_scores',
        'ocr_review_flags',
        'extracted_at',
        'vendor_id',
        'legal_entity_id',
        'entity_book_id',
        'vendor_name',
        'document_date',
        'due_date',
        'document_reference',
        'currency_code',
        'exchange_rate',
        'line_items',
        'subtotal',
        'tax_amount',
        'total_amount',
        'dimensions',
        'journal_entry_id',
        'posted_at',
        'posted_by',
        'created_by',
        'reviewed_by',
        'reviewed_at',
        'error_message',
        'retry_count',
        'customer_id',
        'customer_name',
    ];

    protected $casts = [
        'ocr_raw_data' => 'array',
        'ocr_confidence_scores' => 'array',
        'ocr_review_flags' => 'array',
        'line_items' => 'array',
        'dimensions' => 'array',
        'extracted_at' => 'datetime',
        'document_date' => 'date',
        'due_date' => 'date',
        'posted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'file_size' => 'integer',
        'retry_count' => 'integer',
        'legal_entity_id' => 'integer',
        'entity_book_id' => 'integer',
        'customer_id' => 'integer',
    ];

    protected $attributes = [
        'status' => 'PENDING',
        'currency_code' => 'USD',
        'exchange_rate' => 1.0,
        'retry_count' => 0,
    ];

    protected static function newFactory(): Factory
    {
        return OcrDocumentFactory::new();
    }

    /**
     * Boot method to auto-generate UUID
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Relationships
     */

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Scopes
     */

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'PROCESSING');
    }

    public function scopeNeedsReview($query)
    {
        return $query->where('status', 'NEEDS_REVIEW');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'POSTED');
    }

    public function scopePromoted($query)
    {
        return $query->where('status', 'PROMOTED');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'EXTRACTION_FAILED');
    }

    /**
     * Accessors
     */

    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        return \Storage::disk('ocr-documents')->url($this->file_path);
    }

    /**
     * Helper Methods
     */

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'PROCESSING';
    }

    public function needsReview(): bool
    {
        return $this->status === 'NEEDS_REVIEW';
    }

    public function isPosted(): bool
    {
        return $this->status === 'POSTED';
    }

    public function isPromoted(): bool
    {
        return $this->status === 'PROMOTED';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'EXTRACTION_FAILED';
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, ['NEEDS_REVIEW', 'EXTRACTION_FAILED']);
    }

    public function hasBlockingOcrReviewFlags(): bool
    {
        $blockingFlags = [
            'LINE_TOTAL_MISMATCH',
            'DOCUMENT_TOTAL_MISMATCH',
            'TAX_INCONSISTENT',
            'NEGATIVE_TOTAL',
            'MISSING_REQUIRED_AMOUNT',
            'DUPLICATE_TOTAL',
        ];

        $flags = is_array($this->ocr_review_flags) ? $this->ocr_review_flags : [];

        return count(array_intersect($blockingFlags, $flags)) > 0;
    }

    public function canBePosted(): bool
    {
        return $this->status === 'NEEDS_REVIEW'
            && !$this->hasBlockingOcrReviewFlags()
            && $this->total_amount !== null
            && $this->document_date !== null;
    }
    
    public function canBeDeleted(): bool
    {
        return !in_array($this->status, ['POSTED', 'PROMOTED'], true)
            && $this->journal_entry_id === null;
    }

    /**
     * Unpost a document and reverse its journal entry
     * Transition: POSTED -> NEEDS_REVIEW
     *
     * Delegates GL reversal to OcrPostingService to avoid calling PostingEngine
     * directly from the model layer (WS-08 FIX-03).
     *
     * @return bool
     */
    public function unpost(): bool
    {
        // Delegate all GL work to OcrPostingService — model must not call PostingEngine directly.
        app(\Modules\OCRBookkeeping\Services\OcrPostingService::class)
            ->unpostDocument($this, TenantUserHelper::getTenantUserId());

        return true;
    }

    /**
     * Status Transition Methods
     */

    /**
     * Mark document as processing
     * Transition: PENDING -> PROCESSING
     */
    public function markAsProcessing(): bool
    {
        if (!$this->isPending() && !$this->hasFailed()) {
            throw new \Exception("Cannot mark document as processing. Current status: {$this->status}");
        }

        return $this->update([
            'status' => 'PROCESSING',
            'error_message' => null,
        ]);
    }

    /**
     * Mark document as needs review with OCR data
     * Transition: PROCESSING -> NEEDS_REVIEW
     * 
     * @param array $ocrData The extracted OCR data
     * @return bool
     */
    public function markAsNeedsReview(array $ocrData): bool
    {
        if (!$this->isProcessing()) {
            throw new \Exception("Cannot mark document as needs review. Current status: {$this->status}");
        }

        return $this->update([
            'status' => 'NEEDS_REVIEW',
            'ocr_raw_data' => $ocrData['raw_data'] ?? null,
            'ocr_confidence_scores' => $ocrData['confidence_scores'] ?? null,
            'ocr_review_flags' => $ocrData['ocr_review_flags'] ?? [],
            'vendor_name' => $ocrData['vendor_name'] ?? null,
            'document_date' => $ocrData['document_date'] ?? null,
            'document_reference' => $ocrData['document_reference'] ?? null,
            'currency_code' => $ocrData['currency_code'] ?? 'USD',
            'line_items' => $ocrData['line_items'] ?? null,
            'subtotal' => $ocrData['subtotal'] ?? null,
            'tax_amount' => $ocrData['tax_amount'] ?? null,
            'total_amount' => $ocrData['total_amount'] ?? null,
            'extracted_at' => now(),
            'error_message' => null,
        ]);
    }

    /**
     * Mark document as extraction failed
     * Transition: PROCESSING -> EXTRACTION_FAILED
     * 
     * @param string $error The error message
     * @return bool
     */
    public function markAsExtractionFailed(string $error): bool
    {
        if (!$this->isProcessing()) {
            throw new \Exception("Cannot mark document as failed. Current status: {$this->status}");
        }

        return $this->update([
            'status' => 'EXTRACTION_FAILED',
            'error_message' => $error,
        ]);
    }

    /**
     * Mark document as posted
     * Transition: NEEDS_REVIEW -> POSTED
     * 
     * @param JournalEntry $journalEntry The created journal entry
     * @return bool
     */
    public function markAsPosted(JournalEntry $journalEntry): bool
    {
        if (!$this->needsReview()) {
            throw new \Exception("Cannot mark document as posted. Current status: {$this->status}");
        }

        if (!$this->canBePosted()) {
            throw new \Exception("Document is not ready to be posted. Missing required fields.");
        }

        return $this->update([
            'status' => 'POSTED',
            'journal_entry_id' => $journalEntry->id,
            'posted_at' => now(),
            'posted_by' => TenantUserHelper::getTenantUserId(),
        ]);
    }

    /**
     * Reset document to pending for retry
     * Transition: EXTRACTION_FAILED -> PENDING
     * 
     * @return bool
     */
    public function resetForRetry(): bool
    {
        if (!$this->hasFailed()) {
            throw new \Exception("Can only retry failed documents. Current status: {$this->status}");
        }

        return $this->update([
            'status' => 'PENDING',
            'error_message' => null,
        ]);
    }

    /**
     * Confidence Score Methods
     */

    /**
     * Get confidence score for a specific field
     * 
     * @param string $field The field name (e.g., 'vendor_name', 'total_amount', 'document_date')
     * @return float|null The confidence score (0-1) or null if not available
     */
    public function getConfidenceScore(string $field): ?float
    {
        if (!$this->ocr_confidence_scores || !is_array($this->ocr_confidence_scores)) {
            return null;
        }

        return $this->ocr_confidence_scores[$field] ?? null;
    }

    /**
     * Check if all critical fields have high confidence (>70%)
     * Critical fields: total_amount, document_date
     * 
     * @return bool
     */
    public function isHighConfidence(): bool
    {
        $criticalFields = ['total_amount', 'document_date'];
        $threshold = 0.70;

        foreach ($criticalFields as $field) {
            $score = $this->getConfidenceScore($field);
            
            // If score is null or below threshold, return false
            if ($score === null || $score < $threshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all fields with low confidence (<70%)
     * 
     * @return array Array of field names with low confidence
     */
    public function getLowConfidenceFields(): array
    {
        if (!$this->ocr_confidence_scores || !is_array($this->ocr_confidence_scores)) {
            return [];
        }

        $threshold = 0.70;
        $lowConfidenceFields = [];

        foreach ($this->ocr_confidence_scores as $field => $score) {
            if ($score < $threshold) {
                $lowConfidenceFields[] = $field;
            }
        }

        return $lowConfidenceFields;
    }

    /**
     * Get average confidence score across all fields
     * 
     * @return float|null Average confidence score or null if no scores available
     */
    public function getAverageConfidence(): ?float
    {
        if (!$this->ocr_confidence_scores || !is_array($this->ocr_confidence_scores)) {
            return null;
        }

        $scores = array_values($this->ocr_confidence_scores);
        
        if (empty($scores)) {
            return null;
        }

        return array_sum($scores) / count($scores);
    }

    /**
     * Check if a specific field needs mandatory verification
     * 
     * @param string $field The field name
     * @return bool
     */
    public function fieldNeedsVerification(string $field): bool
    {
        $score = $this->getConfidenceScore($field);
        
        if ($score === null) {
            return true; // No score means needs verification
        }

        // Critical fields need higher confidence
        $criticalFields = ['total_amount', 'document_date'];
        $threshold = in_array($field, $criticalFields) ? 0.70 : 0.60;

        return $score < $threshold;
    }

    /**
     * Update with optimistic locking
     * Checks if the document was modified since it was loaded
     * 
     * @param array $attributes
     * @param string $expectedUpdatedAt ISO 8601 timestamp from client
     * @return bool
     * @throws \Modules\OCRBookkeeping\Exceptions\OcrConcurrentModificationException
     */
    public function updateWithLock(array $attributes, string $expectedUpdatedAt): bool
    {
        // Parse expected timestamp
        $expected = \Carbon\Carbon::parse($expectedUpdatedAt);
        $current = \Carbon\Carbon::parse($this->updated_at);

        // Check if document was modified since client loaded it
        if (!$current->equalTo($expected)) {
            throw new \Modules\OCRBookkeeping\Exceptions\OcrConcurrentModificationException(
                'Document was modified by another user. Please refresh and try again.',
                [
                    'document_uuid' => $this->uuid,
                    'expected_updated_at' => $expectedUpdatedAt,
                    'current_updated_at' => $this->updated_at->toIso8601String(),
                ]
            );
        }

        // Perform update
        return $this->update($attributes);
    }

    /**
     * Check if document can be posted with optimistic locking
     * 
     * @param string $expectedUpdatedAt
     * @return bool
     * @throws \Modules\OCRBookkeeping\Exceptions\OcrConcurrentModificationException
     */
    public function canBePostedWithLock(string $expectedUpdatedAt): bool
    {
        // Check optimistic lock first
        $expected = \Carbon\Carbon::parse($expectedUpdatedAt);
        $current = \Carbon\Carbon::parse($this->updated_at);

        if (!$current->equalTo($expected)) {
            throw new \Modules\OCRBookkeeping\Exceptions\OcrConcurrentModificationException(
                'Document was modified by another user. Please refresh and try again.',
                [
                    'document_uuid' => $this->uuid,
                    'expected_updated_at' => $expectedUpdatedAt,
                    'current_updated_at' => $this->updated_at->toIso8601String(),
                ]
            );
        }

        return $this->canBePosted();
    }
}