<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanModule extends Model
{
    protected $table = 'plan_modules';

    protected $fillable = [
        'plan_version_id',
        'module_catalog_id',
        'is_included',
    ];

    protected $casts = [
        'is_included' => 'boolean',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class, 'plan_version_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(ModuleCatalogEntry::class, 'module_catalog_id');
    }
}
