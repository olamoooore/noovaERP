<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Evidence artifacts for an invoice (MRD FR-6): customer e-sign / acceptance,
 * supporting documents, payment proofs.
 *
 * evidence_status on invoices is derived from these rows and is written only
 * by InvoiceEvidenceService.
 */
class InvoiceEvidence extends Model
{
    use SoftDeletes;

    public const TYPE_CUSTOMER_ESIGN = 'customer_esign';

    public const TYPE_CUSTOMER_ACCEPTANCE = 'customer_acceptance';

    public const TYPE_PURCHASE_ORDER = 'purchase_order';

    public const TYPE_DELIVERY_ORDER = 'delivery_order';

    public const TYPE_PAYMENT_PROOF = 'payment_proof';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_CUSTOMER_ESIGN,
        self::TYPE_CUSTOMER_ACCEPTANCE,
        self::TYPE_PURCHASE_ORDER,
        self::TYPE_DELIVERY_ORDER,
        self::TYPE_PAYMENT_PROOF,
        self::TYPE_OTHER,
    ];

    /**
     * Types that satisfy the accounting evidence requirement. Payment proofs
     * are deliberately excluded (MRD FR-7.2) — they support settlement review,
     * not document acceptance.
     */
    public const QUALIFYING_TYPES = [
        self::TYPE_CUSTOMER_ESIGN,
        self::TYPE_CUSTOMER_ACCEPTANCE,
        self::TYPE_PURCHASE_ORDER,
        self::TYPE_DELIVERY_ORDER,
        self::TYPE_OTHER,
    ];

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_PORTAL_ACCEPTANCE = 'portal_acceptance';

    public const SOURCE_ESIGN_PROVIDER = 'esign_provider';

    public const SOURCES = [
        self::SOURCE_UPLOAD,
        self::SOURCE_PORTAL_ACCEPTANCE,
        self::SOURCE_ESIGN_PROVIDER,
    ];

    protected $fillable = [
        'invoice_id',
        'type',
        'source',
        'file_path',
        'file_name',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'signer_name',
        'signer_email',
        'signer_ip',
        'signer_user_agent',
        'signed_at',
        'expires_at',
        'provider',
        'external_id',
        'notes',
        'uploaded_by',
        'confirmed_at',
        'confirmed_by',
        'revoked_at',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function scopeQualifying($query)
    {
        return $query->whereIn('type', self::QUALIFYING_TYPES);
    }
}
