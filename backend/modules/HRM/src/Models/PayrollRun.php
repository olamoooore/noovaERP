<?php

namespace Modules\HRM\Models;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollRun extends Model
{
    use SoftDeletes;

    protected $table = 'hrm_payroll_runs';

    protected $attributes = [
        'status'           => 'draft',
        'total_gross'      => 0,
        'total_deductions' => 0,
        'total_net'        => 0,
        'currency'         => 'USD',
    ];

    protected $fillable = [
        'reference',
        'period_start',
        'period_end',
        'status',
        'total_gross',
        'total_deductions',
        'total_net',
        'currency',
        'created_by',
        'approved_by',
        'approved_at',
        'notes',
        'journal_entry_id',
    ];

    protected $casts = [
        'period_start'     => 'date',
        'period_end'       => 'date',
        'approved_at'      => 'datetime',
        'total_gross'      => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'total_net'        => 'decimal:2',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class, 'payroll_run_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Find runs whose period overlaps with the given date range.
     */
    public function scopeOverlapping($query, string $start, string $end)
    {
        return $query->where('period_start', '<=', $end)
                     ->where('period_end', '>=', $start);
    }
}
