<?php

namespace Modules\FinancialReporting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Issue #598 (RPT-UX3) — one recipient's outcome for one scheduled-view run.
 */
class ReportViewScheduleLog extends Model
{
    protected $table = 'report_view_schedule_logs';

    protected $fillable = [
        'schedule_id',
        'view_id',
        'user_id',
        'recipient',
        'format',
        'status',
        'reason',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';
}
