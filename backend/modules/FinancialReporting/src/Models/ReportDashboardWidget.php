<?php

namespace Modules\FinancialReporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #603 — a per-user pin of a saved report view (#598) onto the Sales
 * cockpit. The view is re-authorised on every render (visibility + entity
 * scope); the widget itself is private to `user_id`.
 */
class ReportDashboardWidget extends Model
{
    protected $table = 'report_dashboard_widgets';

    protected $fillable = [
        'user_id',
        'view_id',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function view(): BelongsTo
    {
        return $this->belongsTo(ReportSavedView::class, 'view_id');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
