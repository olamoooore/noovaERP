<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ReconciliationControlResult
 *
 * Stores results of automated reconciliation controls (AR to GL, AP to GL, etc.)
 */
class ReconciliationControlResult extends Model
{
    use HasFactory;

    protected $table = 'reconciliation_control_results';

    protected $fillable = [
        'control_name',
        'legal_entity_id',
        'as_of_date',
        'subledger_balance',
        'gl_balance',
        'difference',
        'status',
        'discrepancies',
        'executed_by',
        'notes',
    ];

    protected $casts = [
        'as_of_date' => 'date',
        'subledger_balance' => 'decimal:2',
        'gl_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'discrepancies' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    const STATUS_PASSED = 'passed';

    const STATUS_FAILED = 'failed';

    const STATUS_WARNING = 'warning';

    /**
     * Get the user who executed this control
     */
    public function executedBy()
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /**
     * Legal entity this result reconciled (issue #881); null = tenant-wide.
     */
    public function legalEntity()
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    /**
     * Check if reconciliation passed
     */
    public function isPassed(): bool
    {
        return $this->status === self::STATUS_PASSED;
    }

    /**
     * Get discrepancy count
     */
    public function getDiscrepancyCount(): int
    {
        return count($this->discrepancies ?? []);
    }

    /**
     * Scope for specific control
     */
    public function scopeForControl($query, string $controlName)
    {
        return $query->where('control_name', $controlName);
    }

    /**
     * Scope for date range
     */
    public function scopeForDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('as_of_date', [$from, $to]);
    }

    /**
     * Scope for failed controls
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
