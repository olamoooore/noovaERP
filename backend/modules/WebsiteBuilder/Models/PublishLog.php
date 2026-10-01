<?php

namespace Modules\WebsiteBuilder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishLog extends Model
{
    protected $table = 'wb_publish_logs';

    protected $fillable = [
        'website_id',
        'version',
        'output_path',
        'page_versions',
        'rollback_of',
        'status',
        'published_by',
        'published_at',
        'error_message',
    ];

    protected $casts = [
        'version' => 'integer',
        'page_versions' => 'array',
        'rollback_of' => 'integer',
        'published_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'website_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function markSuccess(?string $outputPath): void
    {
        $this->update([
            'status' => 'success',
            'output_path' => $outputPath,
            'published_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }
}
