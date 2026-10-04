<?php

namespace Modules\HRM\Services;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\PostingResult;
use App\Accounting\DTOs\ReversalRequest;
use App\Accounting\PostingEngine;
use App\Accounting\Services\AccountMappingService;
use App\Models\AccountMapping;
use App\Models\BankAccount;
use App\Models\Journal;
use Illuminate\Support\Facades\Log;
use Modules\HRM\Models\PayrollItem;
use Modules\HRM\Models\PayrollRun;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PayrollJournalService
{
    /**
     * Mapping key for the liability that holds employee deductions withheld
     * from pay until they are remitted (statutory contributions, tax, …).
     * Accounts are tenant configuration — this service never invents one.
     */
    private const DEDUCTIONS_PAYABLE_MAPPING_KEY = 'payroll_deductions_payable';

    public function __construct(
        private readonly PostingEngine $postingEngine,
        private readonly AccountMappingService $accountMapping,
    ) {}

    /**
     * Post the payroll ACCRUAL for an approved run.
     *
     * Double-entry:
     *   DR  Salary Expense               (earned gross = net + withheld deductions)
     *   CR  Payroll Deductions Payable   (withheld deductions only)
     *   CR  Payroll Payable              (total_net)
     *
     * The run's per-payslip deduction items decide how total_deductions is
     * split:
     *  - `leave_deduction` reduces what the employee actually earned, so it
     *    lowers Salary Expense and never becomes a third-party liability;
     *  - every other deduction category (late/early penalties and the generic
     *    `custom` used by imported payroll results) is money withheld from the
     *    employee's pay and owed onward. It is credited to the
     *    `payroll_deductions_payable` account mapping.
     *
     * If a withholding exists but that mapping is not configured, the accrual
     * fails closed with a clear error instead of silently netting the amount
     * into Payroll Payable (which would overstate the amount owed to
     * employees and understate the remittance liability). The payment leg
     * (postPayrollPayment) then clears total_net against the bank.
     */
    public function postPayrollRun(PayrollRun $run): PostingResult
    {
        if ($run->journal_entry_id) {
            return PostingResult::failure("Payroll run {$run->reference} already has an accrual journal entry.");
        }

        $totalNet = (float) $run->total_net;
        $totalDeductions = (float) $run->total_deductions;

        // Deduction items other than unpaid leave are employee withholdings.
        // Runs without per-payslip items keep the legacy treatment: their
        // unclassified deductions reduce earned gross (leave was the only
        // deduction producer before imported/provider payslips existed).
        $withheldDeductions = $this->sumWithheldDeductions($run);

        if ($withheldDeductions > round($totalDeductions, 2) + 0.005) {
            return PostingResult::failure(
                "Payroll run {$run->reference} deduction items ({$withheldDeductions}) exceed the run's total deductions ({$totalDeductions}) — refusing to post inconsistent payslip data."
            );
        }

        if ($totalNet < 0) {
            return PostingResult::failure("Payroll run {$run->reference} has negative net pay — refusing to accrue.");
        }

        if ($totalNet <= 0 && $withheldDeductions <= 0) {
            return PostingResult::failure("Payroll run {$run->reference} has zero net pay — nothing to accrue.");
        }

        // Earned gross = employees' net pay + amounts withheld from their pay.
        // Leave deductions deliberately never reach this figure.
        $earnedExpense = round($totalNet + $withheldDeductions, 2);

        $deductionsPayableAccountId = null;
        if ($withheldDeductions > 0) {
            $deductionsPayableAccountId = $this->resolveDeductionsPayableAccountId();

            if ($deductionsPayableAccountId === null) {
                return PostingResult::failure(
                    "Payroll run {$run->reference} withholds {$withheldDeductions} in employee deductions, ".
                    'but no \''.self::DEDUCTIONS_PAYABLE_MAPPING_KEY.'\' account is configured for document type \'payroll_run\'. '.
                    'Configure that account mapping before approving the run — withholdings are not netted into Payroll Payable.'
                );
            }
        }

        $journal = Journal::where('code', 'PAYROLL')->where('is_active', true)->first();

        $lines = [
            // Debit: Salary Expense (gross earned in the period)
            new PostingRequestLine(
                accountMappingKey: 'salary_expense',
                accountId: null,
                debit: $earnedExpense,
                credit: 0,
                description: "Payroll {$run->reference} — Salary expense",
                reference: $run->reference,
            ),
        ];

        // Credit: Payroll Deductions Payable (amounts withheld, owed onward)
        if ($withheldDeductions > 0 && $deductionsPayableAccountId !== null) {
            $lines[] = new PostingRequestLine(
                accountMappingKey: self::DEDUCTIONS_PAYABLE_MAPPING_KEY,
                accountId: $deductionsPayableAccountId,
                debit: 0,
                credit: $withheldDeductions,
                description: "Payroll {$run->reference} — Employee deductions withheld",
                reference: $run->reference,
            );
        }

        // Credit: Payroll Payable (amount owed to employees until paid)
        $lines[] = new PostingRequestLine(
            accountMappingKey: 'payroll_payable',
            accountId: null,
            debit: 0,
            credit: $totalNet,
            description: "Payroll {$run->reference} — Payable to employees",
            reference: $run->reference,
        );

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
                'withheld_deductions' => $withheldDeductions,
                'gross_reducing_deductions' => round($totalDeductions - $withheldDeductions, 2),
                'total_net' => $run->total_net,
                'deductions_payable_account_id' => $deductionsPayableAccountId,
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
                'earned_expense' => $earnedExpense,
                'withheld_deductions' => $withheldDeductions,
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
     * Sum the run's deduction items that are employee withholdings — every
     * deduction category except unpaid leave, which reduces earned gross.
     */
    private function sumWithheldDeductions(PayrollRun $run): float
    {
        return round((float) PayrollItem::query()
            ->whereHas('payroll', fn ($query) => $query->where('payroll_run_id', $run->id))
            ->where('type', 'deduction')
            ->where('category', '!=', 'leave_deduction')
            ->sum('amount'), 2);
    }

    /**
     * Resolve the tenant-configured "Payroll Deductions Payable" account.
     * Returns null when no active mapping exists so the caller can fail
     * closed instead of auto-creating or guessing an account.
     */
    private function resolveDeductionsPayableAccountId(): ?int
    {
        $hasMapping = AccountMapping::query()
            ->where('mapping_key', self::DEDUCTIONS_PAYABLE_MAPPING_KEY)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->where('document_type', 'payroll_run')
                    ->orWhereNull('document_type');
            })
            ->exists();

        if (! $hasMapping) {
            return null;
        }

        try {
            return $this->accountMapping->resolveId(self::DEDUCTIONS_PAYABLE_MAPPING_KEY, 'payroll_run');
        } catch (\Throwable) {
            return null;
        }
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
