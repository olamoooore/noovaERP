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
        $disk = $this->storage_disk ?: 'local';

        return \Storage::disk($disk)->url($this->storage_path);
    }
}
