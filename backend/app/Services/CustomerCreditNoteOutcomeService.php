<?php

namespace App\Services;

use App\Models\CustomerCreditNote;
use App\Support\EInvoiceListStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * #525 (CN-UX4): what happened to a customer credit note's credit, derived
 * from existing data rather than stored.
 *
 * Posting a credit note applies it to its invoice's open balance
 * (total − paid − written off) through credited_amount; whatever the invoice
 * cannot absorb is unapplied customer credit (#510,
 * InvoiceService::refreshCustomerCreditBalance). When several posted notes
 * credit one invoice, they consume the open balance in posting order, so the
 * per-note remaining credit sums to the customer's credit balance.
 *
 * Voiding releases the credit (#510), so a voided note has nothing applied
 * and nothing remaining. Drafts have no outcome yet.
 *
 * #495: remaining credit can be applied to other open invoices of the same
 * customer (customer_credit_note_applications); live applications count as
 * applied, so remaining = total − applied to own invoice − applied elsewhere.
 */
class CustomerCreditNoteOutcomeService
{
    /**
     * Posted notes with their applied amount, computed per invoice in posting
     * order with a window function.
     *
     * @param  array<int, int>|null  $invoiceIds  restrict to these invoices (whole partitions)
     */
    private function postedOutcomeQuery(?array $invoiceIds = null): QueryBuilder
    {
        $openBalance = 'GREATEST(0, i.total - COALESCE(i.paid_amount, 0) - COALESCE(i.written_off_amount, 0))';
        $creditedBefore = 'SUM(cn.total_amount) OVER (PARTITION BY cn.invoice_id ORDER BY cn.posted_at NULLS FIRST, cn.id) - cn.total_amount';

        return DB::table('customer_credit_notes as cn')
            ->join('invoices as i', 'i.id', '=', 'cn.invoice_id')
            ->where('cn.status', 'posted')
            ->when($invoiceIds !== null, fn ($q) => $q->whereIn('cn.invoice_id', $invoiceIds))
            ->select('cn.id', 'cn.total_amount')
            ->selectRaw("LEAST(cn.total_amount, GREATEST(0, {$openBalance} - ({$creditedBefore}))) AS applied_amount")
            ->selectRaw('('.self::APPLIED_ELSEWHERE_SQL.') AS applied_elsewhere');
    }

    /** #495: live applications of the note's credit to other invoices. */
    private const APPLIED_ELSEWHERE_SQL = 'SELECT COALESCE(SUM(ca.applied_amount), 0) FROM customer_credit_note_applications ca '
        .'WHERE ca.credit_note_id = cn.id AND ca.reversed_at IS NULL';

    /**
     * #495: the credit a posted note still holds for the customer — what its
     * own invoice could not absorb, less live applications to other invoices.
     * Zero for drafts and voided notes.
     */
    public function remainingCredit(CustomerCreditNote $note): float
    {
        if ($note->status !== 'posted' || ! $note->invoice_id) {
            return 0.0;
        }

        $row = $this->postedOutcomeQuery([(int) $note->invoice_id])->get()->firstWhere('id', $note->id);

        return (float) ($this->outcomeFor($note, $row?->applied_amount, $row?->applied_elsewhere)['remaining_credit'] ?? 0.0);
    }

    /**
     * Filter the credit note list by outcome:
     *  - applied: posted and fully absorbed by the invoice
     *  - customer_credit: posted with credit left over for the customer
     *
     * @param  Builder<CustomerCreditNote>  $query
     */
    public function applyFilter(Builder $query, string $outcome): void
    {
        $condition = match ($outcome) {
            'applied' => 'o.total_amount - o.applied_amount - o.applied_elsewhere <= 0',
            'customer_credit' => 'o.total_amount - o.applied_amount - o.applied_elsewhere > 0',
            default => null,
        };

        if ($condition === null) {
            return;
        }

        $query->whereIn(
            'customer_credit_notes.id',
            DB::query()->fromSub($this->postedOutcomeQuery(), 'o')->select('o.id')->whereRaw($condition)
        );
    }

    /**
     * Attach `outcome` and `einvoice_status` to each credit note — a constant
     * number of queries for the whole page. Expects the `invoice` relation to
     * be loaded (it is on the list and detail endpoints).
     *
     * @param  iterable<CustomerCreditNote>  $creditNotes
     */
    public function annotate(iterable $creditNotes): void
    {
        $notes = collect($creditNotes);

        if ($notes->isEmpty()) {
            return;
        }

        $postedInvoiceIds = $notes->where('status', 'posted')->pluck('invoice_id')->filter()->unique()->values()->all();
        $applied = $postedInvoiceIds === []
            ? collect()
            : $this->postedOutcomeQuery($postedInvoiceIds)->get()->keyBy('id');

        $einvoice = EInvoiceListStatus::statusMap(
            'credit_note',
            $notes->whereIn('status', ['posted', 'voided'])->pluck('id')->all(),
        );

        foreach ($notes as $note) {
            $row = $applied->get($note->id);
            $note->setAttribute('outcome', $this->outcomeFor($note, $row?->applied_amount, $row?->applied_elsewhere));
            $note->setAttribute('einvoice_status', in_array($note->status, ['posted', 'voided'], true)
                ? ($einvoice[(int) $note->id] ?? 'not_submitted')
                : null);
        }
    }

    private function outcomeFor(CustomerCreditNote $note, mixed $appliedAmount, mixed $appliedElsewhere = 0): ?array
    {
        if ($note->status === 'voided') {
            return [
                'state' => 'voided',
                'applied_amount' => 0.0,
                'remaining_credit' => 0.0,
                'applied_to' => null,
                'applied_elsewhere' => 0.0,
            ];
        }

        if ($note->status !== 'posted') {
            return null;
        }

        $total = round((float) $note->total_amount, 2);
        $ownApplied = round(max(0.0, min($total, (float) $appliedAmount)), 2);
        // #495: credit applied to other invoices from the outstanding-credits action.
        $elsewhere = round(max(0.0, min($total - $ownApplied, (float) $appliedElsewhere)), 2);
        $applied = round($ownApplied + $elsewhere, 2);
        $remaining = round($total - $applied, 2);

        return [
            'state' => match (true) {
                $remaining <= 0 => 'applied',
                $applied <= 0 => 'customer_credit',
                default => 'partially_applied',
            },
            'applied_amount' => $applied,
            'remaining_credit' => $remaining,
            'applied_elsewhere' => $elsewhere,
            'applied_to' => $ownApplied > 0 && $note->invoice_id
                ? [
                    'invoice_id' => (int) $note->invoice_id,
                    'invoice_number' => $note->invoice?->invoice_number,
                ]
                : null,
        ];
    }
}
