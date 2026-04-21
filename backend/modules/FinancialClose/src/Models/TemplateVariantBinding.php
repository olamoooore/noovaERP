<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateVariantBinding extends Model
{
    use HasUuids;

    protected $table = 'fco_template_variant_bindings';

    protected $fillable = [
        'tenant_id',
        'template_version_id',
        'entity_id',
        'overrides',
    ];

    protected $casts = [
        'overrides' => 'array',
    ];

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }
}