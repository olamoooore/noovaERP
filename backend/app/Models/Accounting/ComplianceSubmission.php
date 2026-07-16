<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Compliance Submission - Tracks provider submissions and status
 * Immutable audit trail for submission lifecycle
 */
class ComplianceSubmission extends Model
{
    use SoftDeletes;

    protected $table = 'acct_compliance_submissions';

    protected $fillable = [
        'tenant_id',
        'document_id',
        'country_code',
        'provider_name',
        'submission_number',           // Human-readable reference e.g. MY-202607-000001
        'payload_hash',                // SHA-256 of JSON payload sent to LHDN
        'document_hash',               // SHA-256 of UBL document (matches LHDN documentHash)
        'provider_reference',          // submissionUID returned by LHDN
        'external_document_uuid',      // LHDN document UUID
        'external_long_id',
        'validation_link',
        'submission_status',           // pending|ack|accepted|rejected|failed|cancelled|rejection_requested|rejection_declined_pending_expiry
        'provider_response',           // Full LHDN response body
        'status_details',
        'signature_metadata',
        'error_code',
        'error_message',
        'rejection_reason',            // LHDN rejection reason
        'cancellation_reason',         // Supplier-initiated cancellation reason
        'cancelled_by',
        'cancelled_at',
        'buyer_rejection_reason',      // Reason stated by buyer when requesting rejection
        'buyer_rejection_responded_by',
        'buyer_rejection_responded_at',
        'idempotency_key',
        'retry_count',
        'last_status_check',
        'submitted_by',
        'submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'last_status_check' => 'datetime',
        'cancelled_at' => 'datetime',
        'buyer_rejection_responded_at' => 'datetime',
        'provider_response' => 'array',
        'status_details' => 'array',
        'signature_metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(ComplianceDocument::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ComplianceEvent::class, 'submission_id');
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function buyerRejectionRespondedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_rejection_responded_by');
    }

    /**
     * Check if submission can be retried
     */
    public function canRetry(): bool
    {
        return in_array($this->submission_status, ['failed', 'rejected', 'pending'])
            && $this->retry_count < 5;
    }

    /**
     * Get human-readable status
     */
    public function getStatusLabel(): string
    {
        return match ($this->submission_status) {
            'pending' => 'Pending Submission',
            'ack' => 'Acknowledged by Provider',
            'accepted' => 'Accepted',
            'rejected' => 'Rejected',
            'failed' => 'Submission Failed',
            'cancelled' => 'Cancelled',
            default => 'Unknown'
        };
    }
}
