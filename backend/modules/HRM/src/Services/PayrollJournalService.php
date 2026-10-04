<?php

namespace Modules\HRM\Services;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\PostingResult;
use App\Accounting\DTOs\ReversalRequest;
use App\Accounting\PostingEngine;
use App\Accounting\Services\AccountMappingService;
use App\Models\BankAccount;
use App\Models\Journal;
use Illuminate\Support\Facades\Log;
use Modules\HRM\Models\PayrollRun;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PayrollJournalService
{
    public function __construct(
        private readonly PostingEngine $postingEngine,
        private readonly AccountMappingService $accountMapping,
    ) {}

    /**
     * Post the payroll ACCRUAL for an approved run.
     *
     * Double-entry:
     *   DR  Salary Expense   (total_net)
     *   CR  Payroll Payable  (total_net)
     *
     * The accrual credits the amount owed to employees (net pay), which the
     * payment leg (postPayrollPayment) then clears against the bank. The
     * gross/net split with statutory deduction payables (EPF/PCB/SOCSO…)
     * belongs to the statutory-engine phase; until then the module's only
     * deduction is unpaid leave, which reduces the expense rather than
     * creating a third-party liability.
     */
    public function postPayrollRun(PayrollRun $run): PostingResult
    {
        if ($run->journal_entry_id) {
            return PostingResult::failure("Payroll run {$run->reference} already has an accrual journal entry.");
        }

        $totalNet = (float) $run->total_net;

        if ($totalNet <= 0) {
            return PostingResult::failure("Payroll run {$run->reference} has zero net pay — nothing to accrue.");
        }

        $journal = Journal::where('code', 'PAYROLL')->where('is_active', true)->first();

        $lines = [
            // Debit: Salary Expense (the net amount earned in the period)
            new PostingRequestLine(
                accountMappingKey: 'salary_expense',
                accountId: null,
                debit: $totalNet,
                credit: 0,
                description: "Payroll {$run->reference} — Salary expense",
                reference: $run->reference,
            ),
            // Credit: Payroll Payable (amount owed to employees until paid)
            new PostingRequestLine(
                accountMappingKey: 'payroll_payable',
                accountId: null,
                debit: 0,
                credit: $totalNet,
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
            userId: TenantUserHelper::resolveTenantUserId(),
            metadata: [
                'total_gross' => $run->total_gross,
                'total_deductions' => $run->total_deductions,
                'total_net' => $run->total_net,
                'currency' => $run->currency,
                'employee_count' => $run->payrolls()->count(),
            ],
        );

        $result = $this->postingEngine->post($request);

        if ($result->success) {
            $run->update(['journal_entry_id' => $result->journalEntryId]);

            Log::info('PayrollJournalService: Posted payroll accrual to ledger', [
                'payroll_run_id' => $run->id,
                'reference' => $run->reference,
                'journal_entry_id' => $result->journalEntryId,
                'total_net' => $totalNet,
            ]);
        } else {
            Log::error('PayrollJournalService: Failed to post payroll accrual', [
                'payroll_run_id' => $run->id,
                'reference' => $run->reference,
                'error' => $result->error,
            ]);
        }

        return $result;
    }

    /**
     * Post the PAYMENT for a payroll run.
     *
     * Double-entry:
     *   DR  Payroll Payable  (total_net)
     *   CR  Bank             (total_net)
     *
     * This is deliberately a different document type (`payroll_payment`) from
     * the accrual (`payroll_run`) so the PostingEngine's idempotency link and
     * audit trail can never mistake one for the other. The payable is debited
     * with the same amount the accrual credited, so the run's Payroll Payable
     * balance is cleared to zero by this entry.
     */
    public function postPayrollPayment(
        PayrollRun $run,
        ?int $bankAccountId = null,
        ?string $paymentDate = null,
    ): PostingResult {
        $amount = (float) $run->total_net;

        if ($amount <= 0) {
            return PostingResult::failure("Payroll run {$run->reference} has zero net pay — nothing to pay.");
        }

        try {
            $payableAccountId = $this->accountMapping->resolveId('payroll_payable', 'payroll_run');
        } catch (\RuntimeException $e) {
            return PostingResult::failure('Payroll Payable account is not configured: '.$e->getMessage());
        }

        $bankGlAccountId = null;
        if ($bankAccountId !== null) {
            $bankAccount = BankAccount::query()
                ->where('is_active', true)
                ->find($bankAccountId);

            if (! $bankAccount || ! $bankAccount->gl_account_id) {
                return PostingResult::failure("Bank account {$bankAccountId} is not available for payment.");
            }

            $bankGlAccountId = (int) $bankAccount->gl_account_id;
        }

        $journal = Journal::where('code', 'PAYROLL')->where('is_active', true)->first();

        $lines = [
            // Debit: Payroll Payable (clear the liability for this run)
            new PostingRequestLine(
                accountMappingKey: 'payroll_payable',
                accountId: $payableAccountId,
                debit: $amount,
                credit: 0,
                description: "Payroll {$run->reference} — Clear payable",
                reference: $run->reference,
            ),
            // Credit: Bank (actual disbursement)
            new PostingRequestLine(
                accountMappingKey: 'bank',
                accountId: $bankGlAccountId,
                debit: 0,
                credit: $amount,
                description: "Payroll {$run->reference} — Bank payment",
                reference: $run->reference,
            ),
        ];

        $request = new PostingRequest(
            documentType: 'payroll_payment',
            documentId: $run->id,
            postingDate: $paymentDate ?? $run->period_end->toDateString(),
            description: "Payroll Run {$run->reference} — Payment",
            lines: $lines,
            reference: $run->reference,
            journalId: $journal?->id,
            userId: TenantUserHelper::resolveTenantUserId(),
            metadata: [
                'payroll_run_id' => $run->id,
                'total_gross' => $run->total_gross,
                'total_deductions' => $run->total_deductions,
                'total_net' => $run->total_net,
                'currency' => $run->currency,
                'bank_account_id' => $bankAccountId,
            ],
        );

        $result = $this->postingEngine->post($request);

        if ($result->success) {
            Log::info('PayrollJournalService: Posted payroll payment to ledger', [
                'payroll_run_id' => $run->id,
                'reference' => $run->reference,
                'journal_entry_id' => $result->journalEntryId,
                'total_net' => $amount,
            ]);
        } else {
            Log::error('PayrollJournalService: Failed to post payroll payment', [
                'payroll_run_id' => $run->id,
                'reference' => $run->reference,
                'error' => $result->error,
            ]);
        }

        return $result;
    }

    /**
     * Reverse a previously posted payroll journal entry.
     */
    public function reversePayrollRun(PayrollRun $run, string $reason): PostingResult
    {
        if (! $run->journal_entry_id) {
            return PostingResult::failure("Payroll run {$run->reference} has no journal entry to reverse.");
        }

        $result = $this->postingEngine->reverse(new ReversalRequest(
            journalEntryId: $run->journal_entry_id,
            reversalDate: now()->toDateString(),
            reason: $reason,
            reasonCode: 'PAYROLL_CORRECTION',
        ));

        if ($result->success) {
            $run->update(['journal_entry_id' => null]);

            Log::info('PayrollJournalService: Reversed payroll journal entry', [
                'payroll_run_id' => $run->id,
                'reference' => $run->reference,
                'reversal_entry_id' => $result->journalEntryId,
            ]);
        }

        return $result;
    }
}
