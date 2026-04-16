<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantPlanChange extends Model
{
    protected $fillable = [
        'tenant_id',
        'from_family_id',
        'to_family_id',
        'effective_date',
        'status',
        'proration_credit_cents',
        'proration_charge_cents',
        'reason',
        'applied_at',
        'applied_by_admin_id',
        'created_by_admin_id',
    ];

    protected $casts = [
        'effective_date'         => 'date',
        'applied_at'             => 'datetime',
        'proration_credit_cents' => 'integer',
        'proration_charge_cents' => 'integer',
    ];

    // ── Constants ─────────────────────────────────────────────────────────────

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPLIED   = 'applied';
    public const STATUS_CANCELLED = 'cancelled';

    // ── Relations ─────────────────────────────────────────────────────────────

    public function fromFamily(): BelongsTo
    {
        return $this->belongsTo(PlanFamily::class, 'from_family_id');
    }

    public function toFamily(): BelongsTo
    {
        return $this->belongsTo(PlanFamily::class, 'to_family_id');
    }

    public function appliedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_admin_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function changeInvoices(): HasMany
    {
        return $this->hasMany(TenantPlanChangeInvoice::class, 'plan_change_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }

    /** Net proration — positive means tenant owes more, negative means credit owed. */
    public function netProrationCents(): int
    {
        return $this->proration_charge_cents - $this->proration_credit_cents;
    }

    public function toAuditSnapshot(): array
    {
        return $this->only([
            'id',
            'tenant_id',
            'from_family_id',
            'to_family_id',
            'status',
            'effective_date',
            'proration_credit_cents',
            'proration_charge_cents',
        ]);
    }
}
