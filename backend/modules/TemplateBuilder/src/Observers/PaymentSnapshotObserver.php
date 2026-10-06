<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Observers;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        // The test suite must not write issued receipts into application
        // storage: every test DB reset restarts payment ids, so real copies
        // would accumulate as orphaned files. Tests that assert the snapshot
        // behaviour fake the snapshot disk (Storage::fake) and still run.
        if (app()->runningUnitTests() && ! $this->snapshotDiskIsFaked()) {
            return;
        }

        $id = (string) $payment->id;

        // Posting updates the payment inside its transaction; rendering must
        // not extend that transaction's locks, so snapshot after it commits.
        DB::afterCommit(fn () => $this->snapshots->snapshot('receipt', $id));
    }

    private function snapshotDiskIsFaked(): bool
    {
        $disk = (string) config('templatebuilder.pdf.snapshot_disk', 'local');

        try {
            $root = Storage::disk($disk)->path('');
        } catch (\Throwable) {
            return false;
        }

        return str_contains($root, 'framework/testing');
    }
}
