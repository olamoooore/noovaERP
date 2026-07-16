<?php

namespace Modules\FieldService\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorksheetTemplate extends Model
{
    protected $table = 'fs_worksheet_templates';

    protected $fillable = [
        'name',
        'description',
        'is_default',
        'fields',
        'is_active',
    ];

    protected $casts = [
        'fields' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    // ──────────────── RELATIONSHIPS ────────────────

    public function responses(): HasMany
    {
        return $this->hasMany(WorksheetResponse::class, 'template_id');
    }

    public function workOrderTypes(): HasMany
    {
        return $this->hasMany(WorkOrderType::class, 'worksheet_template_id');
    }

    // ──────────────── HELPERS ──────────────────────

    /**
     * Set one template as default (unsets all others first).
     */
    public function setAsDefault(): void
    {
        static::where('id', '!=', $this->id)->update(['is_default' => false]);
        $this->update(['is_default' => true]);
    }
}
