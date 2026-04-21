<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateChangeAudit extends Model
{
    use HasUuids;

    protected $table = 'fco_template_change_audit';

    protected $fillable = [
        'tenant_id',
        'template_version_id',
        'changed_by',
        'change_type',
        'before',
        'after',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }
}