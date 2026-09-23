<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Issue #92: real budget header (period + version + approval state).
 */
class Budget extends Model
{
    protected $table = 'budgets';

    protected $fillable = [
        'tenant_id',
        'name',
        'fiscal_year',
        'period_start',
        'period_end',
        'status',
        'version',
        'legal_entity_id',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'fiscal_year' => 'integer',
        'version' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'approved_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
