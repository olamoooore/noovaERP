<?php

namespace App\Models;

use App\Accounting\Enums\PaymentExecutionState;
use Illuminate\Database\Eloquent\Model;

/**
 * Payment execution — owns external money-movement lifecycle.
 * ADR-005 §21 — separate from accounting allocation/settlement.
 *
 * Payment Execution represents the interaction with external payment rails/providers.
 * Settlement represents the accounting allocation of payments to receivables/payables.
 */
class PaymentExecution extends Model
{
    protected $table = 'payment_executions';

    protected $fillable = [
        'tenant_id',
        'entity_id',
        'execution_number',
        'payment_id',
        'execution_state',
        'payment_method',
        'amount',
        'currency_code',
        'exchange_rate',
        'bank_account_id',
        'provider_name',
        'provider_reference',
        'provider_idempotency_key',
        'provider_operation_reference',
        'external_settlement_reference',
        'submission_attempts',
        'last_submission_error',
        'submitted_at',
        'completed_at',
        'failed_at',
        'reversed_at',
        'approval_required',
        'approved_by',
        'approved_at',
        'initiated_by',
        'correlation_id',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:8',
        'submission_attempts' => 'integer',
        'approval_required' => 'boolean',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'reversed_at' => 'datetime',
        'approved_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentExecution $model) {
            $model->execution_state ??= PaymentExecutionState::PREPARED->value;
            if (!$model->execution_number) {
                $model->execution_number = 'PE-' . strtoupper(uniqid('', true));
            }
            $model->submission_attempts ??= 0;
        });
    }

    // ── Accessors ──────────────────────────────────────

    public function getStateEnumAttribute(): PaymentExecutionState
    {
        return PaymentExecutionState::from($this->execution_state);
    }

    // ── State Transitions ──────────────────────────────

    public function canSubmit(): bool
    {
        return in_array($this->execution_state, [
            PaymentExecutionState::APPROVED->value,
            PaymentExecutionState::PREPARED->value,
        ]);
    }

    public function markSubmitted(string $providerReference, ?string $idempotencyKey = null): void
    {
        $this->update([
            'execution_state' => PaymentExecutionState::SUBMITTED->value,
            'provider_reference' => $providerReference,
            'provider_idempotency_key' => $idempotencyKey,
            'submitted_at' => now(),
            'submission_attempts' => $this->submission_attempts + 1,
        ]);
    }

    public function markSubmissionUnknown(): void
    {
        $this->update([
            'execution_state' => PaymentExecutionState::SUBMISSION_UNKNOWN->value,
            'last_submission_error' => 'Transport response lost or timeout',
        ]);
    }

    public function markProcessing(?string $providerReference = null): void
    {
        $this->update([
            'execution_state' => PaymentExecutionState::PROCESSING->value,
            'provider_reference' => $providerReference ?? $this->provider_reference,
        ]);
    }

    public function markCompleted(?string $settlementReference = null): void
    {
        $this->update([
            'execution_state' => PaymentExecutionState::COMPLETED->value,
            'completed_at' => now(),
            'external_settlement_reference' => $settlementReference,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'execution_state' => PaymentExecutionState::FAILED->value,
            'failed_at' => now(),
            'last_submission_error' => $error,
        ]);
    }

    public function markReversed(): void
    {
        $this->update([
            'execution_state' => PaymentExecutionState::REVERSED->value,
            'reversed_at' => now(),
        ]);
    }

    public function markCancelled(): void
    {
        $this->update([
            'execution_state' => PaymentExecutionState::CANCELLED->value,
        ]);
    }

    // ── Scopes ────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereNotIn('execution_state', [
            PaymentExecutionState::REVERSED->value,
            PaymentExecutionState::CANCELLED->value,
        ]);
    }

    public function scopeForPayment($query, int $paymentId)
    {
        return $query->where('payment_id', $paymentId);
    }

    public function scopeUnknownOutcome($query)
    {
        return $query->where('execution_state', PaymentExecutionState::SUBMISSION_UNKNOWN->value);
    }

    // ── Relationships ──────────────────────────────────

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
