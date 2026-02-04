<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Analytic Account - Cost centers, projects, departments, locations
 * Provides dimensional analysis of transactions
 */
class AnalyticAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'parent_id',
        'manager_id',
        'budget_amount',
        'is_active',
        'description',
    ];

    protected $casts = [
        'budget_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the parent analytic account
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(AnalyticAccount::class, 'parent_id');
    }

    /**
     * Get child analytic accounts
     */
    public function children(): HasMany
    {
        return $this->hasMany(AnalyticAccount::class, 'parent_id');
    }

    /**
     * Get the manager
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Get journal entry lines for this analytic account (as department)
     */
    public function journalEntryLinesAsDepartment(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'department_id');
    }

    /**
     * Get journal entry lines for this analytic account (as project)
     */
    public function journalEntryLinesAsProject(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'project_id');
    }

    /**
     * Get journal entry lines for this analytic account (as location)
     */
    public function journalEntryLinesAsLocation(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'location_id');
    }

    /**
     * Get all descendants (recursive)
     */
    public function descendants()
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get all ancestors (recursive)
     */
    public function ancestors()
    {
        return $this->parent()->with('ancestors');
    }

    /**
     * Calculate balance for this analytic account
     */
    public function getBalance(string $dimension = 'department', $startDate = null, $endDate = null): float
    {
        $query = JournalEntryLine::query()
            ->whereHas('journalEntry', function ($q) use ($startDate, $endDate) {
                $q->where('status', 'posted');
                if ($startDate) {
                    $q->where('entry_date', '>=', $startDate);
                }
                if ($endDate) {
                    $q->where('entry_date', '<=', $endDate);
                }
            });

        // Filter by dimension
        switch ($dimension) {
            case 'department':
                $query->where('department_id', $this->id);
                break;
            case 'project':
                $query->where('project_id', $this->id);
                break;
            case 'location':
                $query->where('location_id', $this->id);
                break;
        }

        $totals = $query->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->first();

        return (float) ($totals->total_credit ?? 0) - (float) ($totals->total_debit ?? 0);
    }

    /**
     * Scope to get only active analytic accounts
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get analytic accounts by type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get root analytic accounts (no parent)
     */
    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }
}
