<?php

namespace Modules\WebsiteBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Template extends Model
{
    protected $table = 'wb_templates';

    protected $fillable = [
        'uuid',
        'name',
        'category',
        'thumbnail',
        'schema',
        'version',
        'is_system',
        'is_public',
    ];

    protected $casts = [
        'schema' => 'array',
        'version' => 'integer',
        'is_system' => 'boolean',
        'is_public' => 'boolean',
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

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
