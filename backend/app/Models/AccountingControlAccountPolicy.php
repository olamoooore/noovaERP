<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Control account source policy.
 * ADR-005 §17 — each control account stores or resolves an approved source policy.
 *
 * Defines which source capabilities are allowed to post to a control account.
 * Exceptional manual journal posting requires exceptional permission.
 */
class AccountingControlAccountPolicy extends Model
{
    protected $table = 'accounting_control_account_policies';

    protected $fillable = [
        'tenant_id',
        'account_id',
        'control_account_type',
        'allowed_source_capabilities',
        'requires_exceptional_authority',
        'exceptional_permission_code',
        'requires_workflow_approval',
        'reconciliation_consequence_required',
        'blocking_policy',
        'effective_from',
        'effective_to',
        'status',
        'created_by',
    ];

    protected $casts = [
        'allowed_source_capabilities' => 'array',
        'requires_exceptional_authority' => 'boolean',
        'requires_workflow_approval' => 'boolean',
        'reconciliation_consequence_required' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    // ── Scopes ────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', now());
            });
    }

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeForCapability($query, string $capability)
    {
        return $query->whereJsonContains('allowed_source_capabilities', $capability);
    }

    // ── Helpers ─────────────────────────────────────────

    /**
     * Check if a source capability is allowed to post to this control account.
     */
    public function isSourceAllowed(string $sourceCapability): bool
    {
        $allowed = $this->allowed_source_capabilities ?? [];
        return in_array($sourceCapability, $allowed);
    }

    /**
     * Check if posting requires exceptional authority.
     */
    public function requiresExceptionalAuthority(): bool
    {
        return $this->requires_exceptional_authority === true;
    }
}
