<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;

class DocumentLifecycleService
{
    public function markVoided(Invoice|Bill|JournalEntry $document, CorrectionRequest $request): void
    {
        $document->update([
            'lifecycle_status' => 'voided',
            'correction_type' => 'void',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'voided_at' => now(),
            'is_active_version' => false,
        ]);
    }

    public function markReversed(Invoice|Bill|JournalEntry $document, CorrectionRequest $request, ?int $reversalJournalEntryId = null): void
    {
        $document->update([
            'lifecycle_status' => 'reversed',
            'correction_type' => 'reverse',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'reversed_at' => now(),
            'reversal_journal_entry_id' => $reversalJournalEntryId,
            'is_active_version' => false,
        ]);
    }

    public function markCredited(Invoice|Bill $document, CorrectionRequest $request, int $creditNoteId): void
    {
        $document->update([
            'lifecycle_status' => 'credited',
            'correction_type' => 'credit_note',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'credited_at' => now(),
            'credit_note_id' => $creditNoteId,
            'is_active_version' => false,
        ]);
    }

    public function markSuperseded(Invoice|Bill|JournalEntry $document, CorrectionRequest $request): void
    {
        $document->update([
            'lifecycle_status' => 'superseded',
            'correction_type' => 'replacement',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'superseded_at' => now(),
            'is_active_version' => false,
        ]);
    }

    public function markAsActiveVersion(Invoice|Bill|JournalEntry $document, int $versionNumber): void
    {
        $document->update([
            'version_number' => $versionNumber,
            'is_active_version' => true,
        ]);
    }

    public function markOtherVersionsInactive(Invoice|Bill|JournalEntry $document): void
    {
        $documentType = match (true) {
            $document instanceof Invoice => 'invoice',
            $document instanceof Bill => 'bill',
            $document instanceof JournalEntry => 'journal_entry',
            default => null,
        };

        if (! $documentType || ! $document->correction_group_id) {
            return;
        }

        $modelClass = match ($documentType) {
            'invoice' => Invoice::class,
            'bill' => Bill::class,
            'journal_entry' => JournalEntry::class,
        };

        $modelClass::where('correction_group_id', $document->correction_group_id)
            ->where('id', '!=', $document->id)
            ->update(['is_active_version' => false]);
    }

    public function recalculateInvoiceState(Invoice $invoice): void
    {
        $settlementStatus = $this->calculateSettlementStatus($invoice);

        $invoice->update([
            'settlement_status' => $settlementStatus,
        ]);
    }

    public function recalculateBillState(Bill $bill): void
    {
        $settlementStatus = $this->calculateSettlementStatus($bill);

        $bill->update([
            'settlement_status' => $settlementStatus,
        ]);
    }

    public function recalculatePaymentState(Payment $payment): void
    {
        $invoice = $payment->invoice;
        if (! $invoice) {
            return;
        }

        $this->recalculateInvoiceState($invoice);
    }

    public function getDisplayStatus(Invoice|Bill $document): string
    {
        $lifecycle = $document->lifecycle_status;
        $settlement = $document->settlement_status;
        $reconciliation = $document->reconciliation_status ?? 'unreconciled';

        if ($lifecycle === 'draft') {
            return 'Draft';
        }
        if ($lifecycle === 'voided') {
            return 'Voided';
        }
        if ($lifecycle === 'reversed') {
            return 'Reversed';
        }
        if ($lifecycle === 'credited') {
            return 'Credited';
        }
        if ($lifecycle === 'superseded') {
            return 'Superseded';
        }
        if ($lifecycle === 'cancelled') {
            return 'Cancelled';
        }

        if ($lifecycle === 'posted') {
            if ($settlement === 'unpaid') {
                return 'Awaiting Payment';
            }
            if ($settlement === 'partially_paid') {
                return 'Partially Paid';
            }
            if ($settlement === 'paid') {
                if ($reconciliation === 'cleared') {
                    return 'Cleared';
                }

                return 'Paid';
            }
            if ($settlement === 'refunded') {
                return 'Refunded';
            }
        }

        return $lifecycle ?? 'Draft';
    }

    public function getJournalDisplayStatus(JournalEntry $entry): string
    {
        $lifecycle = $entry->lifecycle_status;

        if ($lifecycle === 'draft') {
            return 'Draft';
        }
        if ($lifecycle === 'voided') {
            return 'Voided';
        }
        if ($lifecycle === 'reversed') {
            return 'Reversed';
        }
        if ($lifecycle === 'superseded') {
            return 'Superseded';
        }
        if ($lifecycle === 'posted') {
            return 'Posted';
        }

        return $lifecycle ?? 'Draft';
    }

    private function calculateSettlementStatus(Invoice|Bill $document): string
    {
        $total = (float) $document->total;
        $paid = (float) $document->paid_amount;

        if ($paid <= 0) {
            return 'unpaid';
        }

        if ($paid >= $total) {
            return 'paid';
        }

        return 'partially_paid';
    }
}
