<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTemplateAsset extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'document_template_assets';

    protected $fillable = [
        'id',
        'template_id',
        'kind',
        'name',
        'url',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $asset) {
            if ($asset->id === null) {
                $asset->id = (string) \Illuminate\Support\Str::orderedUuid();
            }
        });
    }
}
