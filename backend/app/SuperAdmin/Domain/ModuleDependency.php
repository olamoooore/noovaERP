<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleDependency extends Model
{
    protected $table = 'module_dependencies';

    protected $fillable = [
        'module_catalog_id',
        'depends_on_module_catalog_id',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(ModuleCatalogEntry::class, 'module_catalog_id');
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(ModuleCatalogEntry::class, 'depends_on_module_catalog_id');
    }
}
