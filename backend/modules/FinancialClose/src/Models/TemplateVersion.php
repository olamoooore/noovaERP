<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TemplateVersion extends Model
{
    use HasUuids;

    protected $table = 'fco_template_versions';

    protected $fillable = [
        'tenant_id',
        'checklist_template_id',
        'version_number',
        'status',
        'snapshot',
        'created_by',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'snapshot' => 'array',
    ];

    public function checklistTemplate(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(TemplateApproval::class, 'template_version_id');
    }
}