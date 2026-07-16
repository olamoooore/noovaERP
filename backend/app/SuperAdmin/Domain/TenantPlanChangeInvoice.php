<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantPlanChangeInvoice extends Model
{
    protected $fillable = [
        'plan_change_id',
        'invoice_id',
        'type',
        'amount_cents',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
    ];

    // ── Constants ─────────────────────────────────────────────────────────────

    public const TYPE_CREDIT = 'credit';

    public const TYPE_CHARGE = 'charge';

    // ── Relations ─────────────────────────────────────────────────────────────

    public function planChange(): BelongsTo
    {
        return $this->belongsTo(TenantPlanChange::class, 'plan_change_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'invoice_id');
    }
}
