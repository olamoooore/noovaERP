<?php

namespace Modules\HRM\Services;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\PostingResult;
use App\Accounting\PostingEngine;
use App\Models\Journal;
use Illuminate\Support\Facades\Log;
use Modules\HRM\Models\PayrollRun;

class PayrollJournalService
{
    public function __construct(
        private readonly PostingEngine $postingEngine,
    ) {}

    /**
     * Post a payroll run to the general ledger.
     *
     * Double-entry:
     *   DR  Salary Expense   (total_gross)
     *   CR  Payroll Payable  (total_gross)
     *
     * If there are deductions, the net effect is still balanced because
     * deductions reduce net_pay but not the employer's expense obligation.
     * When actual payment is made, a separate entry would:
     *   DR  Payroll Payable
     *   CR  Cash / Bank
     *
     * @return PostingResult
     */
    public function postPayrollRun(PayrollRun $run): PostingResult
    {
        if ($run->journal_entry_id) {
            return PostingResult::failure("Payroll run {$run->reference} already has a journal entry.");
        }

        $totalGross = (float) $run->total_gross;

        if ($totalGross <= 0) {
            return PostingResult::failure("Payroll run {$run->reference} has zero gross — nothing to post.");
        }

        $journal = Journal::where('code', 'PAYROLL')->where('is_active', true)->first();

        $lines = [
            // Debit: Salary Expense (the full gross amount the company owes)
            new PostingRequestLine(
                accountMappingKey: 'salary_expense',
                accountId: null,
                debit: $totalGross,
                credit: 0,
                description: "Payroll {$run->reference} — Salary expense",
                reference: $run->reference,
            ),
            // Credit: Payroll Payable (liability until employees are actually paid)
            new PostingRequestLine(
                accountMappingKey: 'payroll_payable',
                accountId: null,
                debit: 0,
                credit: $totalGross,
                description: "Payroll {$run->reference} — Payable to employees",
                reference: $run->reference,
            ),
        ];

        $request = new PostingRequest(
            documentType: 'payroll_run',
            documentId: $run->id,
            postingDate: $run->period_end->toDateString(),
            description: "Payroll Run {$run->reference} ({$run->period_start->format('M d')} – {$run->period_end->format('M d, Y')})",
            lines: $lines,
            reference: $run->reference,
            journalId: $journal?->id,
            userId: auth()->id(),
            metadata: [
                'total_gross'      => $run->total_gross,
                'total_deductions' => $run->total_deductions,
                'total_net'        => $run->total_net,
                'currency'         => $run->currency,
                'employee_count'   => $run->payrolls()->count(),
            ],
        );

        $result = $this->postingEngine->post($request);

        if ($result->success) {
            $run->update(['journal_entry_id' => $result->journalEntryId]);

            Log::info('PayrollJournalService: Posted payroll run to ledger', [
                'payroll_run_id'   => $run->id,
                'reference'        => $run->reference,
                'journal_entry_id' => $result->journalEntryId,
                'total_gross'      => $totalGross,
            ]);
        } else {
            Log::error('PayrollJournalService: Failed to post payroll run', [
                'payroll_run_id' => $run->id,
                'reference'      => $run->reference,
                'error'          => $result->error,
            ]);
        }

        return $result;
    }

    /**
     * Reverse a previously posted payroll journal entry.
     */
    public function reversePayrollRun(PayrollRun $run, string $reason): PostingResult
    {
        if (!$run->journal_entry_id) {
            return PostingResult::failure("Payroll run {$run->reference} has no journal entry to reverse.");
        }

        $result = $this->postingEngine->reverse($run->journal_entry_id, $reason);

        if ($result->success) {
            $run->update(['journal_entry_id' => null]);

            Log::info('PayrollJournalService: Reversed payroll journal entry', [
                'payroll_run_id'          => $run->id,
                'reference'               => $run->reference,
                'reversal_entry_id'       => $result->journalEntryId,
            ]);
        }

        return $result;
    }
}
