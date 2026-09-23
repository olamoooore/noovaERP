<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ChatterAttachment extends Model
{
    protected $table = 'chatter_attachments';

    protected $fillable = [
        'tenant_id',
        'subject_type',
        'subject_id',
        'uploaded_by_id',
        'filename',
        'mime_type',
        'size',
        'storage_disk',
        'storage_path',
        'checksum',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function getUrlAttribute(): string
    {
        // Issue #78: never expose the storage path/URL structure. Clients fetch
        // attachments through the authenticated download endpoint.
        //
        // Return a RELATIVE path (no scheme/host and no leading /api/v1) so the
        // frontend axios client (baseURL /api/v1) resolves it against its own
        // origin + dev proxy. An absolute url() would hard-code the backend
        // host (e.g. demo.localhost), which the browser cannot reach directly.
        return sprintf(
            'chatter/%s/%s/attachments/%s/download',
            rawurlencode((string) $this->subject_type),
            rawurlencode((string) $this->subject_id),
            (string) $this->id,
        );
    }
}
