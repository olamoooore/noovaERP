<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentTemplate extends Model
{
    use SoftDeletes;

    public const MODE_BLOCK = 'block';

    public const MODE_CODE = 'code';

    public const MODE_BLADE = 'blade';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'document_type',
        'code',
        'name',
        'description',
        'mode',
        'format',
        'orientation',
        'paper_width_mm',
        'paper_height_mm',
        'margin',
        'default_font',
        'default_locale',
        'layout_config',
        'html',
        'css',
        'status',
        'published_version',
        'is_system_default',
        'created_by',
        'updated_by',
        'metadata',
    ];

    protected $casts = [
        'margin' => 'array',
        'layout_config' => 'array',
        'metadata' => 'array',
        'published_version' => 'integer',
        'paper_width_mm' => 'integer',
        'paper_height_mm' => 'integer',
        'is_system_default' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $template) {
            if ($template->id === null) {
                $template->id = (string) \Illuminate\Support\Str::orderedUuid();
            }
        });
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentTemplateVersion::class, 'template_id')->orderByDesc('version');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DocumentTemplateAssignment::class, 'template_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(DocumentTemplateAsset::class, 'template_id');
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class, 'template_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeForDocumentType($query, string $type)
    {
        return $query->where('document_type', $type);
    }

    public function getPublishedDefinition(): array
    {
        if ($this->published_version > 0) {
            $version = $this->versions()->where('version', $this->published_version)->first();
            if ($version) {
                return [
                    'layout_config' => $version->layout_config,
                    'html' => $version->html,
                    'css' => $version->css,
                ];
            }
        }

        return [
            'layout_config' => $this->layout_config,
            'html' => $this->html,
            'css' => $this->css,
        ];
    }
}
