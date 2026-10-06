<?php

declare(strict_types=1);

namespace App\Services;

use App\Accounting\Enums\SettlementState;
use App\Accounting\Events\ReceiptRecognised;
use App\Models\Allocation;
use App\Models\BillPayment;
use App\Models\Payment;
use App\Models\Settlement;
use App\Models\UnappliedCash;
use Illuminate\Support\Facades\DB;

/**
 * ACC-005B settlement recognition.
 *
 * Creates/updates the accounting Settlement overlay for a cash-bearing
 * receipt (customer Payment) or vendor payment (BillPayment). The overlay is
 * GL-silent by design (see SettlementController): the GL effect remains the
 * payment posting; this service only records which obligations the cash is
 * earmarked against, and how much remains unapplied.
 *
 * Idempotent per source record: one Settlement per Payment/BillPayment.
 */
class SettlementRecognitionService
{
    /** Payment states that mean the cash has left the books. */
    private const REVERSED_PAYMENT_STATES = ['reversed', 'refunded', 'unapplied'];

    public function recognisePayment(Payment $payment): ?Settlement
    {
        // A draft receipt carries no cash — it must not appear as a settlement
        // (#477: the invoice/AR moves only on posting).
        if ((string) $payment->payment_status === 'recorded') {
            return null;
        }

        return DB::transaction(function () use ($payment) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if (! $payment || (string) $payment->payment_status === 'recorded') {
                return null;
            }

            $capacity = round((float) $payment->amount + (float) ($payment->discount_amount ?? 0), 2);
            $applied = round((float) $payment->applications()->whereNull('reversed_at')->sum('applied_amount'), 2);
            $overlay = $this->overlayAllocated(Payment::class, (int) $payment->id);
            $isReversed = in_array((string) $payment->payment_status, self::REVERSED_PAYMENT_STATES, true);

            $allocated = $isReversed ? 0.0 : min(round($applied + $overlay, 2), $capacity);
            $unapplied = $isReversed ? $capacity : max(round($capacity - $allocated, 2), 0.0);
            $state = $this->stateFor($allocated, $unapplied, $isReversed);

            $settlement = $this->upsert(
                sourceType: Payment::class,
                sourceId: (int) $payment->id,
                attributes: [
                    'tenant_id' => $payment->tenant_id ?? tenant()?->id,
                    'settlement_state' => $state->value,
                    'settlement_type' => 'customer_receipt',
                    'allocated_amount' => $allocated,
                    'unapplied_amount' => $unapplied,
                    'currency_code' => $this->currencyFor(
                        $payment->currency ?? null,
                        $payment->base_currency_code ?? null,
                    ),
                    'journal_entry_id' => $payment->journal_entry_id,
                    'initiated_by' => $payment->received_by,
                    'metadata' => [
                        'payment_number' => $payment->payment_number,
                        'customer_id' => $payment->customer_id,
                        'method' => $payment->method,
                        'reference' => $payment->reference,
                        'discount_amount' => (float) ($payment->discount_amount ?? 0),
                    ],
                    'reversed_at' => $isReversed
                        ? ($payment->refunded_at ?? $payment->unapplied_at ?? now())
                        : null,
                    'reversal_reason' => $isReversed ? $payment->correction_reason_text : null,
                ],
                amount: (float) $payment->amount,
                customerId: $payment->customer_id ? (int) $payment->customer_id : null,
                vendorId: null,
                unapplied: $unapplied,
                state: $state,
                journalEntryId: $payment->journal_entry_id ? (int) $payment->journal_entry_id : null,
            );

            return $settlement;
        });
    }

    public function recogniseBillPayment(BillPayment $payment): ?Settlement
    {
        if (! $payment->journal_entry_id) {
            return null;
        }

        return DB::transaction(function () use ($payment) {
            $payment = BillPayment::whereKey($payment->id)->lockForUpdate()->first();

            if (! $payment || ! $payment->journal_entry_id) {
                return null;
            }

            $capacity = round((float) $payment->amount, 2);
            $isReversed = in_array((string) $payment->payment_status, self::REVERSED_PAYMENT_STATES, true);

            // A bill payment applies in full to its bill; without a bill it is
            // an on-account payment waiting for a future bill.
            $applied = (! $isReversed && $payment->bill_id) ? $capacity : 0.0;
            $allocated = min(round($applied + $this->overlayAllocated(BillPayment::class, (int) $payment->id), 2), $capacity);
            $unapplied = $isReversed ? $capacity : max(round($capacity - $allocated, 2), 0.0);
            $state = $this->stateFor($allocated, $unapplied, $isReversed);

            return $this->upsert(
                sourceType: BillPayment::class,
                sourceId: (int) $payment->id,
                attributes: [
                    'tenant_id' => $payment->tenant_id ?? tenant()?->id,
                    'settlement_state' => $state->value,
                    'settlement_type' => 'vendor_payment',
                    'allocated_amount' => $allocated,
                    'unapplied_amount' => $unapplied,
                    'currency_code' => $this->currencyFor($payment->currency_code ?? null, null),
                    'journal_entry_id' => $payment->journal_entry_id,
                    'initiated_by' => $payment->created_by,
                    'metadata' => [
                        'payment_number' => $payment->payment_number,
                        'vendor_id' => $payment->vendor_id,
                        'bill_id' => $payment->bill_id,
                        'method' => $payment->payment_method,
                        'reference' => $payment->reference,
                    ],
                    'reversed_at' => $isReversed
                        ? ($payment->refunded_at ?? $payment->unapplied_at ?? now())
                        : null,
                    'reversal_reason' => $isReversed ? $payment->correction_reason_text : null,
                ],
                amount: (float) $payment->amount,
                customerId: null,
                vendorId: $payment->vendor_id ? (int) $payment->vendor_id : null,
                unapplied: $unapplied,
                state: $state,
                journalEntryId: (int) $payment->journal_entry_id,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(
        string $sourceType,
        int $sourceId,
        array $attributes,
        float $amount,
        ?int $customerId,
        ?int $vendorId,
        float $unapplied,
        SettlementState $state,
        ?int $journalEntryId,
    ): Settlement {
        $settlement = Settlement::query()
            ->where('source_record_type', $sourceType)
            ->where('source_record_id', $sourceId)
            ->lockForUpdate()
            ->first();

        if ($settlement) {
            $settlement->fill($attributes)->save();
        } else {
            $settlement = Settlement::create($attributes + [
                'source_record_type' => $sourceType,
                'source_record_id' => $sourceId,
            ]);

            ReceiptRecognised::dispatch(
                $settlement->id,
                $amount,
                $sourceType,
                $sourceId,
                $settlement->correlation_id,
                $settlement->tenant_id,
            );
        }

        $this->syncUnappliedCash($settlement, $customerId, $vendorId, $unapplied, $state, $journalEntryId);

        return $settlement->fresh();
    }

    private function stateFor(float $allocated, float $unapplied, bool $isReversed): SettlementState
    {
        if ($isReversed) {
            return SettlementState::REVERSED;
        }

        if ($allocated <= 0) {
            return SettlementState::UNALLOCATED;
        }

        return $unapplied > 0
            ? SettlementState::PARTIALLY_ALLOCATED
            : SettlementState::FULLY_ALLOCATED;
    }

    /**
     * Live earmark allocations created from the Settlements page (GL-silent
     * overlay), so recognising a payment does not wipe them on the next sync.
     */
    private function overlayAllocated(string $sourceType, int $sourceId): float
    {
        $settlementId = Settlement::query()
            ->where('source_record_type', $sourceType)
            ->where('source_record_id', $sourceId)
            ->value('id');

        if (! $settlementId) {
            return 0.0;
        }

        return max(round((float) Allocation::query()
            ->where('settlement_id', $settlementId)
            ->whereNull('reversed_at')
            ->sum('allocated_amount'), 2), 0.0);
    }

    private function syncUnappliedCash(
        Settlement $settlement,
        ?int $customerId,
        ?int $vendorId,
        float $unapplied,
        SettlementState $state,
        ?int $journalEntryId,
    ): void {
        $row = UnappliedCash::where('settlement_id', $settlement->id)->first();
        $status = match ($state) {
            SettlementState::REVERSED => 'REVERSED',
            SettlementState::FULLY_ALLOCATED => 'APPLIED',
            default => $unapplied > 0 ? 'AVAILABLE' : 'APPLIED',
        };

        if (! $row) {
            if ($state === SettlementState::REVERSED || $unapplied <= 0) {
                return;
            }

            UnappliedCash::create([
                'tenant_id' => $settlement->tenant_id,
                'settlement_id' => $settlement->id,
                'amount' => $unapplied,
                'currency_code' => $settlement->currency_code,
                'customer_id' => $customerId,
                'vendor_id' => $vendorId,
                'reason' => 'Receipt not yet applied to an obligation',
                'status' => $status,
                'journal_entry_id' => $journalEntryId,
            ]);

            return;
        }

        $row->update([
            'amount' => $unapplied,
            'currency_code' => $settlement->currency_code,
            'customer_id' => $customerId,
            'vendor_id' => $vendorId,
            'status' => $status,
            'journal_entry_id' => $journalEntryId,
        ]);
    }

    private function currencyFor(?string $currency, ?string $base): string
    {
        $code = strtoupper(trim((string) ($currency ?: $base)));

        return $code !== '' ? substr($code, 0, 10) : DocumentCurrencyResolver::baseFor();
    }
}
