<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only invoice activity timeline (MRD FR-10).
 *
 * Rows are never updated or deleted. InvoiceActivityService is the only writer.
 */
class InvoiceActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'invoice_activity_log';

    public const EVENT_CREATED = 'created';
    public const EVENT_UPDATED = 'updated';
    public const EVENT_SUBMITTED = 'submitted';
    public const EVENT_APPROVED = 'approved';
    public const EVENT_REJECTED = 'rejected';
    public const EVENT_WITHDRAWN = 'withdrawn';
    public const EVENT_EXPIRED = 'expired';
    public const EVENT_SENT = 'sent';
    public const EVENT_DELIVERY_FAILED = 'delivery_failed';
    public const EVENT_EVIDENCE_ADDED = 'evidence_added';
    public const EVENT_POSTED = 'posted';
    public const EVENT_PAYMENT_LINK_ISSUED = 'payment_link_issued';
    public const EVENT_PAYMENT_RECORDED = 'payment_recorded';
    public const EVENT_CANCELLED = 'cancelled';
    public const EVENT_RESET_TO_DRAFT = 'reset_to_draft';
    public const EVENT_VOIDED = 'voided';

    public const ACTOR_USER = 'user';
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_CUSTOMER = 'customer';

    protected $fillable = [
        'invoice_id',
        'event',
        'actor_type',
        'actor_id',
        'from_state',
        'to_state',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
