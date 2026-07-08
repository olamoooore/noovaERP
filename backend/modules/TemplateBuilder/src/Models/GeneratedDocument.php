<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Models;

use Illuminate\Database\Eloquent\Model;

class GeneratedDocument extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'generated_documents';

    protected $fillable = [
        'id',
        'document_type',
        'document_id',
        'template_id',
        'template_version',
        'render_hash',
        'output_format',
        'disk_path',
        'size_bytes',
        'generated_at',
        'expires_at',
    ];

    protected $casts = [
        'template_version' => 'integer',
        'size_bytes' => 'integer',
        'generated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $doc) {
            if ($doc->id === null) {
                $doc->id = (string) \Illuminate\Support\Str::orderedUuid();
            }
        });
    }

    public function template()
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }
}
