<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compliance Event — immutable audit trail for statutory compliance activities.
 *
 * Rows are insert-only. No updated_at, no soft deletes.
 * Every write must go through ::log() or ::transition() — never direct ::create().
 */
class ComplianceEvent extends Model
{
    protected $table = 'acct_compliance_events';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'submission_id',
        'event_type',
        'previous_status',   // status before this event — enables full transition replay
        'actor_type',        // 'user' | 'scheduler' | 'system'
        'event_data',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'event_data' => 'array',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ComplianceSubmission::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Log a compliance event.
     *
     * @param  string  $eventType  submit | retry | retry_failed | cancel | status_sync |
     *                             accepted | rejected | rejection_request_declined |
     *                             rejection_declined_pending_expiry | …
     * @param  array  $data  Arbitrary event payload stored in event_data.
     * @param  string|null  $previousStatus  Status the submission held BEFORE this event.
     * @param  string|null  $actorType  'user' | 'scheduler' | 'system'
     */
    public static function log(
        int $submissionId,
        string $eventType,
        array $data = [],
        ?string $previousStatus = null,
        ?string $actorType = null
    ): self {
        $actorType ??= \Auth::id() ? 'user' : 'scheduler';

        return self::create([
            'tenant_id' => tenancy()->tenant?->id,
            'submission_id' => $submissionId,
            'event_type' => $eventType,
            'previous_status' => $previousStatus,
            'actor_type' => $actorType,
            'event_data' => $data,
            'created_by' => \Auth::id(),
            'created_at' => now(),
        ]);
    }
}
