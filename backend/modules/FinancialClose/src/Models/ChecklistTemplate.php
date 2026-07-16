<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistTemplate extends Model
{
    use HasUuids;

    protected $table = 'fco_checklist_templates';

    protected $fillable = [
        'tenant_id',
        'name',
        'status',
        'created_by',
        'task_definitions',
        'metadata',
    ];

    protected $casts = [
        'task_definitions' => 'array',
        'metadata' => 'array',
    ];

    public function templateVersions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class, 'checklist_template_id');
    }
}
