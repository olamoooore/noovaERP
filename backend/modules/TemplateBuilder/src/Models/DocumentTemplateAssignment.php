<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTemplateAssignment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'document_template_assignments';

    protected $fillable = [
        'id',
        'document_type',
        'template_type_code',
        'interaction_type',
        'template_id',
        'scope_type',
        'scope_value',
        'entity_id',
        'branch_id',
        'language',
        'currency',
        'country',
        'jurisdiction',
        'customer_group',
        'channel',
        'effective_from',
        'effective_to',
        'priority',
        'tiebreaker',
        'is_active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'tiebreaker' => 'integer',
        'is_active' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $assignment) {
            if ($assignment->id === null) {
                $assignment->id = (string) \Illuminate\Support\Str::orderedUuid();
            }
        });
    }

    public function template()
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
