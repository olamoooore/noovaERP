<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountingFinding extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'accounting_findings';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'entity_id',
        'finding_type',
        'severity',
        'status',
        'source_type',
        'source_id',
        'account_code',
        'reference_number',
        'title',
        'description',
        'recommendation',
        'amount',
        'currency',
        'confidence',
        'metadata',
        'assigned_to',
        'acknowledged_at',
        'resolved_at',
        'escalated_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'confidence' => 'float',
        'metadata' => 'array',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'escalated_at' => 'datetime',
    ];

    // ─── Constants ───────────────────────────────────────────────

    public const FINDING_TYPES = [
        'anomaly' => 'Balance Anomaly',
        'missing_support' => 'Missing Documentation',
        'unposted_entries' => 'Unposted Journal Entries',
        'overdue_invoices' => 'Overdue Invoices',
        'unreconciled_transactions' => 'Unreconciled Transactions',
        'control_weakness' => 'Control Weakness',
        'duplicate_transaction' => 'Duplicate Transaction',
        'threshold_breach' => 'Threshold Breach',
    ];

    public const SEVERITIES = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
    ];

    public const STATUSES = [
        'open' => 'Open',
        'acknowledged' => 'Acknowledged',
        'investigating' => 'Investigating',
        'resolved' => 'Resolved',
        'dismissed' => 'Dismissed',
        'escalated' => 'Escalated',
    ];

    public const SEVERITY_WEIGHTS = [
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    // ─── Relationships ──────────────────────────────────────────

    public function source()
    {
        return $this->morphTo();
    }

    // ─── Scopes ─────────────────────────────────────────────────

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('finding_type', $type);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical')->where('status', '!=', 'resolved');
    }

    public function scopeForEntity($query, string $entityId)
    {
        return $query->where('entity_id', $entityId);
    }

    // ─── Accessors ──────────────────────────────────────────────

    public function getSeverityWeightAttribute(): int
    {
        return self::SEVERITY_WEIGHTS[$this->severity] ?? 0;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'open'
            && $this->created_at
            && $this->created_at->diffInDays(now()) > 7
            && in_array($this->severity, ['high', 'critical']);
    }

    // ─── Methods ────────────────────────────────────────────────

    public function acknowledge(string $userId): self
    {
        $this->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'assigned_to' => $userId,
        ]);

        return $this;
    }

    public function investigate(string $userId): self
    {
        $this->update([
            'status' => 'investigating',
            'assigned_to' => $userId,
        ]);

        return $this;
    }

    public function resolve(?string $notes = null): self
    {
        $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'metadata' => array_merge($this->metadata ?? [], [
                'resolution_notes' => $notes,
                'resolved_at' => now()->toIso8601String(),
            ]),
        ]);

        return $this;
    }

    public function dismiss(?string $reason = null): self
    {
        $this->update([
            'status' => 'dismissed',
            'metadata' => array_merge($this->metadata ?? [], [
                'dismissal_reason' => $reason,
                'dismissed_at' => now()->toIso8601String(),
            ]),
        ]);

        return $this;
    }

    public function escalate(?string $reason = null): self
    {
        $this->update([
            'status' => 'escalated',
            'escalated_at' => now(),
            'severity' => $this->severity === 'critical' ? 'critical' : 'high',
            'metadata' => array_merge($this->metadata ?? [], [
                'escalation_reason' => $reason,
                'escalated_at' => now()->toIso8601String(),
            ]),
        ]);

        return $this;
    }

    /**
     * Calculate risk score based on severity, age, and amount.
     */
    public function calculateRiskScore(): float
    {
        $severityScore = self::SEVERITY_WEIGHTS[$this->severity] ?? 1;
        $ageDays = $this->created_at ? max(1, $this->created_at->diffInDays(now())) : 1;
        $ageFactor = min($ageDays / 30, 3); // caps at 3x for 30+ days
        $amountFactor = $this->amount ? min(abs($this->amount) / 10000, 2) : 1; // caps at 2x for 10k+

        return round($severityScore * (1 + $ageFactor * 0.5) * (1 + $amountFactor * 0.25), 2);
    }
}
