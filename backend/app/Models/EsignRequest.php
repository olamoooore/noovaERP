<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A built-in e-signature request (see the create_esign_requests_table
 * migration). Kept deliberately plain: the behaviour lives in EsignService so
 * the token, expiry and audit rules are enforced in one place.
 */
class EsignRequest extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const METHOD_DRAW = 'draw';

    public const METHOD_TYPE = 'type';

    public const METHOD_UPLOAD = 'upload';

    protected $fillable = [
        'document_type',
        'document_id',
        'document_number',
        'template_id',
        'token',
        'status',
        'signer_name',
        'signer_email',
        'signature_method',
        'signature_path',
        'signed_document_path',
        'signer_ip',
        'signer_user_agent',
        'document_hash',
        'signature_hash',
        'meta',
        'sent_at',
        'signed_at',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'meta' => 'array',
        'sent_at' => 'datetime',
        'signed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Usable for signing right now (pending and not past its expiry). */
    public function isOpen(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }
}
