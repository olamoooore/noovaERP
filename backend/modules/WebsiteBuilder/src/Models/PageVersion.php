<?php

namespace Modules\WebsiteBuilder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageVersion extends Model
{
    protected $table = 'wb_page_versions';

    protected $fillable = [
        'page_id',
        'version',
        'content',
        'is_current',
        'is_published',
        'created_by',
    ];

    protected $casts = [
        'content' => 'array',
        'is_current' => 'boolean',
        'is_published' => 'boolean',
        'version' => 'integer',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
