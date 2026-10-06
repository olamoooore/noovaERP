<?php

namespace Modules\FinancialReporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Issue #598 (RPT-UX3) — a named, reusable sales-report configuration:
 * active tab + filters + (pivot) grouping.
 */
class ReportSavedView extends Model
{
    protected $table = 'report_saved_views';

    protected $fillable = [
        'user_id',
        'name',
        'report_key',
        'tab',
        'filters',
        'grouping',
        'visibility',
        'is_default',
        'is_favourite',
    ];

    protected $casts = [
        'filters' => 'array',
        'grouping' => 'array',
        'is_default' => 'boolean',
        'is_favourite' => 'boolean',
    ];

    /** Tabs this view feature can save (the sales reports page tabs). */
    public const TABS = ['by-product', 'by-customer', 'trends', 'by-category', 'pivot', 'unbilled'];

    public const VISIBILITIES = ['personal', 'shared'];

    public function schedules(): HasMany
    {
        return $this->hasMany(ReportViewSchedule::class, 'view_id');
    }

    public function scopeVisibleTo($query, int $userId)
    {
        return $query->where(function ($inner) use ($userId) {
            $inner->where('user_id', $userId)->orWhere('visibility', 'shared');
        });
    }

    public function isOwnedBy(int $userId): bool
    {
        return (int) $this->user_id === $userId;
    }
}
