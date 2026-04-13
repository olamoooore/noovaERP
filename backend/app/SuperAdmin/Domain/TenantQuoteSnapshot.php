<?php

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantQuoteSnapshot extends Model
{
    protected $fillable = [
        'tenant_id',
        'plan_family_id',
        'billing_interval',
        'currency',
        'subtotal_cents',
        'discount_cents',
        'total_cents',
        'line_items',
        'generated_by_admin_id',
        'generated_at',
    ];

    protected $casts = [
        'line_items'      => 'array',
        'subtotal_cents'  => 'integer',
        'discount_cents'  => 'integer',
        'total_cents'     => 'integer',
        'generated_at'    => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function planFamily(): BelongsTo
    {
        return $this->belongsTo(PlanFamily::class);
    }

    public function generatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'generated_by_admin_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeLatest($query)
    {
        return $query->orderByDesc('generated_at');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function formattedSubtotal(): string
    {
        return '$' . number_format($this->subtotal_cents / 100, 2);
    }

    public function formattedDiscount(): string
    {
        return '$' . number_format($this->discount_cents / 100, 2);
    }

    public function formattedTotal(): string
    {
        return '$' . number_format($this->total_cents / 100, 2);
    }
}
