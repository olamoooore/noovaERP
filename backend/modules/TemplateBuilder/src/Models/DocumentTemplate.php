<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\TemplateBuilder\Platform\EditorialState;
use Modules\TemplateBuilder\Platform\InteractionType;

class DocumentTemplate extends Model
{
    use SoftDeletes;

    public const MODE_BLOCK = 'block';

    public const MODE_CODE = 'code';

    public const MODE_BLADE = 'blade';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * Legacy status set kept for back-compat with pre-ADR-004 callers.
     *
     * @return list<string>
     */
    public static function legacyStatuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];
    }

    /** @return list<string> */
    public static function adr004Statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_IN_REVIEW, self::STATUS_PUBLISHED, self::STATUS_SUPERSEDED, self::STATUS_ARCHIVED];
    }

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
        'interaction_type',
        'domain_module_code',
        'registration_id',
        'data_contract_version',
        'published_version',
        'is_system_default',
        'created_by',
        'updated_by',
        'metadata',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_decision',
        'review_notes',
        'superseded_by',
    ];

    protected $casts = [
        'margin' => 'array',
        'layout_config' => 'array',
        'metadata' => 'array',
        'published_version' => 'integer',
        'paper_width_mm' => 'integer',
        'paper_height_mm' => 'integer',
        'is_system_default' => 'boolean',
        'reviewed_at' => 'datetime',
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

    public function registration()
    {
        return $this->belongsTo(TemplateRegistration::class, 'registration_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeResolvable($query)
    {
        // Only PUBLISHED templates are eligible for runtime resolution (ADR-004 §9).
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeForDocumentType($query, string $type)
    {
        return $query->where('document_type', $type);
    }

    public function scopeForInteraction($query, string $interactionType)
    {
        return $query->where('interaction_type', $interactionType);
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

    public function editorialState(): EditorialState
    {
        return EditorialState::from($this->status ?? EditorialState::Draft->value);
    }

    public function interactionType(): InteractionType
    {
        $value = $this->interaction_type ?? InteractionType::BusinessDocument->value;

        return InteractionType::from($value);
    }

    /** Whether the current status permits editing the definition (ADR-004 §9). */
    public function isEditable(): bool
    {
        return $this->editorialState()->isEditable();
    }

    /** Whether this template is eligible for runtime resolution (ADR-004 §9). */
    public function isResolvable(): bool
    {
        return $this->editorialState()->isResolvable();
    }

    public function canTransitionTo(EditorialState|string $target): bool
    {
        $targetState = $target instanceof EditorialState ? $target : EditorialState::from($target);

        return $this->editorialState()->canTransitionTo($targetState);
    }
}
