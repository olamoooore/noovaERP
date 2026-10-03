<?php

namespace App\Models\Accounting;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Compliance Document - Tracks invoices/bills pending submission
 * Links GL documents to compliance submission lifecycle
 */
class ComplianceDocument extends Model
{
    use SoftDeletes;

    protected $table = 'acct_compliance_documents';

    protected $fillable = [
        'tenant_id',
        'country_code',
        'document_type',        // invoice, credit_note, bill, payment
        'document_id',          // GL record ID (invoice ID, etc)
        'consolidated_document_id', // #815 AC2: the consolidated document this receipt was batched into
        'document_number',
        'issued_date',
        'total_amount',
        'currency_code',
        'mapped_payload',       // JSON - the country-specific format
        'signed_payload',
        'signature_metadata',
        'signature_status',
        'signature_signed_at',
        'signature_reference_id',
        'signature_digest_value',
        'signature_value',
        'signature_certificate_subject',
        'signature_certificate_issuer',
        'signature_certificate_serial',
        'signature_certificate_valid_from',
        'signature_certificate_valid_to',
        'validation_link',
        'qr_payload',
        'compliance_status',    // pending, submitted, accepted, rejected, cancelled
        'compliance_notes',
        'created_by',
        'submitted_by',
        'submitted_at',
        'original_compliance_document_id',
    ];

    protected $casts = [
        'issued_date' => 'datetime',
        'submitted_at' => 'datetime',
        'signature_signed_at' => 'datetime',
        'signature_certificate_valid_from' => 'datetime',
        'signature_certificate_valid_to' => 'datetime',
        'mapped_payload' => 'array',
        'signed_payload' => 'array',
        'signature_metadata' => 'array',
        'qr_payload' => 'array',
    ];

    /**
     * Get linked submissions for this document
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(ComplianceSubmission::class, 'document_id');
    }

    /**
     * Linked accounting invoice for document_type=invoice.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'document_id');
    }

    /**
     * Get document text status
     */
    public function getStatusLabel(): string
    {
        return match ($this->compliance_status) {
            'pending' => 'Pending Submission',
            'submitted' => 'Submitted',
            'accepted' => 'Accepted',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
            default => 'Unknown'
        };
    }
}
