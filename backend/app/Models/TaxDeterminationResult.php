<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Versioned tax determination result.
 * ADR-005 §26.2 — historical transactions retain the exact result version used.
 */
class TaxDeterminationResult extends Model
{
    protected $table = 'tax_determination_results';

    protected $fillable = [
        'tenant_id',
        'entity_id',
        'result_version',
        'jurisdiction',
        'transaction_type',
        'transaction_date',
        'tax_rule_refs',
        'tax_lines',
        'input_hash',
        'rounding_method',
        'calculation_basis',
        'result_status',
        'determined_at',
        'source_record_type',
        'source_record_id',
        'superseded_by',
        'correlation_id',
    ];

    protected $casts = [
        'tax_rule_refs' => 'array',
        'tax_lines' => 'array',
        'result_version' => 'integer',
        'determined_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (TaxDeterminationResult $model) {
            $model->result_version ??= 1;
            $model->result_status ??= 'DETERMINED';
            $model->determined_at ??= now();
        });
    }

    // ── Scopes ────────────────────────────────────────

    public function scopeForSource($query, string $type, int $id)
    {
        return $query->where('source_record_type', $type)->where('source_record_id', $id);
    }

    public function scopeCurrent($query)
    {
        return $query->whereNull('superseded_by');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // ── Helpers ─────────────────────────────────────────

    public function isSuperseded(): bool
    {
        return $this->superseded_by !== null;
    }

    public function getTotalTaxAmount(): float
    {
        $lines = $this->tax_lines ?? [];

        return array_sum(array_column($lines, 'amount'));
    }
}
