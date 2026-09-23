<?php

namespace Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #88: a file attached to a communication message.
 */
class CommunicationAttachment extends Model
{
    protected $table = 'communication_attachments';

    protected $fillable = [
        'tenant_id',
        'message_id',
        'filename',
        'mime_type',
        'size',
        'storage_disk',
        'storage_path',
        'checksum',
        'is_inline',
        'uploaded_by_id',
    ];

    protected $casts = [
        'size' => 'integer',
        'is_inline' => 'boolean',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'message_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'uploaded_by_id');
    }
}
