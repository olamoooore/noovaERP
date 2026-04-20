<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateApproval extends Model
{
    use HasUuids;

    protected $table = 'fco_template_approvals';

    protected $fillable = [
        'tenant_id',
        'template_version_id',
        'approved_by',
        'status',
        'notes',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }
}