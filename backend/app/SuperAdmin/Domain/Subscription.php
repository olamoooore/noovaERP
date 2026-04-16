<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $table = 'subscriptions';

    protected $fillable = [
        'tenant_id',
        'plan_version_id',
        'status',
        'trial_starts_at',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'canceled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'trial_starts_at'      => 'datetime',
        'trial_ends_at'        => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end'   => 'datetime',
        'canceled_at'          => 'datetime',
    ];

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class, 'plan_version_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class, 'subscription_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeTrialing($query)
    {
        return $query->where('status', 'trialing');
    }

    public function scopeCanceled($query)
    {
        return $query->where('status', 'canceled');
    }

    /**
     * Access states — these statuses mean the tenant can use the product.
     * Note: 'incomplete' is NOT included — first payment has not cleared yet.
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing'], true);
    }

    public function hasAccess(): bool
    {
        return in_array($this->status, ['active', 'trialing', 'past_due'], true);
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    public function isIncomplete(): bool
    {
        return in_array($this->status, ['incomplete', 'incomplete_expired'], true);
    }

    public function daysUntilRenewal(): int
    {
        return (int) now()->diffInDays($this->current_period_end, false);
    }

    public function toAuditSnapshot(): array
    {
        return $this->only([
            'id',
            'tenant_id',
            'plan_version_id',
            'status',
            'current_period_end',
        ]);
    }
}
