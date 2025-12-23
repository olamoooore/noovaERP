<?php

namespace Modules\WebsiteBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Asset extends Model
{
    protected $table = 'wb_assets';

    protected $fillable = [
        'website_id',
        'uuid',
        'filename',
        'path',
        'mime_type',
        'size',
        'hash',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'website_id');
    }
}
