<?php

namespace Modules\AccountingFoundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ACC-001 Opening Balance — immutable audit trail.
 *
 * Issue #70: append-only log of every lifecycle transition, approval
 * decision, and posting event. Rows are never updated or deleted.
 */
class OpeningBalanceAuditTrail extends Model
{
    protected $table = 'opening_balance_audit_trail';

    public $timestamps = false;

    protected $fillable = [
        'batch_id',
        'action',
        'from_status',
        'to_status',
        'actor_id',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceBatch::class, 'batch_id');
    }
}
