<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTemplateVersion extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'document_template_versions';

    protected $fillable = [
        'id',
        'template_id',
        'version',
        'layout_config',
        'html',
        'css',
        'change_note',
        'is_published',
        'created_by',
    ];

    protected $casts = [
        'layout_config' => 'array',
        'is_published' => 'boolean',
        'version' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $version) {
            if ($version->id === null) {
                $version->id = (string) \Illuminate\Support\Str::orderedUuid();
            }
        });
    }

    public function template()
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }
}
