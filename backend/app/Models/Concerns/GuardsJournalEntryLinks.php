<?php

namespace App\Models\Concerns;

/**
 * Issue #47 (hostile re-review HR47-01): four source models (Invoice, Bill,
 * Payment, BillPayment) allowed a parent to be deleted while a valid
 * JournalEntryLink still pointed at a live ledger posting — leaving the
 * posting with no live source document. The rejected "third behaviour":
 * the link survives, the deletion proceeds anyway.
 *
 * Chosen behaviour — BLOCK: a live link (referenced JournalEntry exists,
 * including soft-deleted) is authoritative traceability and must stop the
 * deletion. Only genuinely orphaned links (referenced JE physically gone,
 * or no JE id at all) are cleaned up before the delete proceeds.
 *
 * Issue #684: only a `post` link is the document's live ledger posting. A
 * `reversal` link is the correction record of a posting that has ALREADY been
 * reversed — the original entry is deactivated and the reset/cancel path
 * releases the `post` link — so it must not block deleting the reset-to-draft
 * document it no longer backs. Counting reversal links here made every
 * reset draft undeletable.
 */
trait GuardsJournalEntryLinks
{
    public function guardJournalEntryLinksOnDelete(string $documentLabel): void
    {
        $liveLinks = $this->journalEntryLinks()
            ->where('relation_type', 'post')
            ->whereHas('journalEntry', fn ($query) => $query->withTrashed())
            ->count();

        if ($liveLinks > 0) {
            throw new \RuntimeException(
                "Cannot delete {$documentLabel}: {$liveLinks} journal entry link(s) reference a live ledger posting. ".
                'Reverse or credit the document instead.'
            );
        }

        // Only orphaned links are removed (referenced JE physically absent,
        // or a dangling link with no JE id).
        $this->journalEntryLinks()
            ->where(function ($query) {
                $query->whereNull('journal_entry_id')
                    ->orWhereDoesntHave('journalEntry', fn ($inner) => $inner->withTrashed());
            })
            ->delete();
    }
}
