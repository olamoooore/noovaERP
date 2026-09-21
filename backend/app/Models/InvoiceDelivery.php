<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per delivery attempt of an invoice document (MRD FR-4).
 *
 * delivery_status on invoices is a denormalized rollup of these rows and is
 * written only by InvoiceDeliveryService.
 */
class InvoiceDelivery extends Model
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_PORTAL = 'portal';

    public const CHANNELS = [
        self::CHANNEL_EMAIL,
        self::CHANNEL_WHATSAPP,
        self::CHANNEL_PORTAL,
    ];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public const DOCUMENT_ACCEPTANCE_COPY = 'acceptance_copy';
    public const DOCUMENT_FINAL_INVOICE = 'final_invoice';
    public const DOCUMENT_PAYMENT_REQUEST = 'payment_request';
    public const DOCUMENT_REMINDER = 'reminder';

    public const DOCUMENT_TYPES = [
        self::DOCUMENT_ACCEPTANCE_COPY,
        self::DOCUMENT_FINAL_INVOICE,
        self::DOCUMENT_PAYMENT_REQUEST,
        self::DOCUMENT_REMINDER,
    ];

    protected $fillable = [
        'invoice_id',
        'channel',
        'document_type',
        'recipient',
        'status',
        'provider_message_id',
        'error',
        'attempted_at',
        'sent_by',
        'metadata',
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
