<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Observers;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Modules\TemplateBuilder\Services\DocumentSnapshotService;

/**
 * Issue #613 (TP3): a payment that reaches the ledger (`journal_entry_id`
 * set) is an issued receipt — store its immutable copy so the customer's
 * reprint never changes with later template/company edits.
 */
final class PaymentSnapshotObserver
{
    public function __construct(
        private readonly DocumentSnapshotService $snapshots,
    ) {}

    public function updated(Payment $payment): void
    {
        if (! $payment->wasChanged('journal_entry_id') || $payment->journal_entry_id === null) {
            return;
        }

        $id = (string) $payment->id;

        // Posting updates the payment inside its transaction; rendering must
        // not extend that transaction's locks, so snapshot after it commits.
        DB::afterCommit(fn () => $this->snapshots->snapshot('receipt', $id));
    }
}
