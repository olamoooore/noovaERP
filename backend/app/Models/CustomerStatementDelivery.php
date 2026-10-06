<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #607 (RPT-F1) — one row per statement send attempt. Mirrors the
 * invoice_deliveries contract: channel, recipient, status, error.
 */
class CustomerStatementDelivery extends Model
{
    use HasFactory;

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'customer_statement_id',
        'customer_id',
        'customer_statement_batch_id',
        'channel',
        'recipient',
        'status',
        'provider_message_id',
        'error',
        'sent_by',
        'sent_at',
        'metadata',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CustomerStatement::class, 'customer_statement_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CustomerStatementBatch::class, 'customer_statement_batch_id');
    }
}
