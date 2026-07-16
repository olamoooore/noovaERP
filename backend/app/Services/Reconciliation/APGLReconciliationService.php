<?php

namespace App\Services\Reconciliation;

use App\Models\APTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use App\Models\ReconciliationControlResult;
use App\Models\ReconciliationException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * APGLReconciliationService
 *
 * Provides monthly reconciliation between AP subledger and GL control account.
 * Identifies discrepancies and generates control reports.
 *
 * Hardening notes (2026-04-15):
 *   - Fixed class name: ChartOfAccounts → ChartOfAccount
 *   - Fixed column name: posting_date → entry_date (journal_entries table)
 *   - Fixed AP control account resolution to use account_mappings table
 *   - Fixed MySQL-specific DATEDIFF / INTERVAL syntax → PostgreSQL
 *   - Fixed references to non-existent columns: journal_entry_lines.transaction_date
 *   - Fixed view column names: id → transaction_id, reference → document_number,
 *     balance → amount (ap_transactions is a VIEW, not a table)
 *   - Created missing APTransaction Eloquent model for the ap_transactions view
 */
class APGLReconciliationService
{
    /**
     * Run AP to GL reconciliation control
     *
     * @param  string  $asOfDate  Date to reconcile as of (YYYY-MM-DD)
     * @param  int|null  $controlAccountId  Optional specific AP control account
     */
    public function reconcile(string $asOfDate, ?int $controlAccountId = null): ReconciliationControlResult
    {
        $asOfDate = Carbon::parse($asOfDate);

        // Get AP control account if not specified
        if (! $controlAccountId) {
            $controlAccount = $this->getAPControlAccount();
            $controlAccountId = $controlAccount?->id;
        }

        if (! $controlAccountId) {
            throw new \Exception('AP control account not found');
        }

        // Get subledger balance (credit - debit for AP)
        $subledgerBalance = $this->getSubledgerBalance($asOfDate);

        // Get GL control account balance
        $glBalance = $this->getGLBalance($controlAccountId, $asOfDate);

        // Calculate difference
        $difference = round($subledgerBalance - $glBalance, 2);

        // Identify discrepancies if any
        $discrepancies = [];
        if (abs($difference) > 0.01) {
            $discrepancies = $this->identifyDiscrepancies($asOfDate, $controlAccountId);
        }

        // Create control result record
        $result = ReconciliationControlResult::create([
            'control_name' => 'ap_gl_reconciliation',
            'as_of_date' => $asOfDate->toDateString(),
            'subledger_balance' => $subledgerBalance,
            'gl_balance' => $glBalance,
            'difference' => $difference,
            'status' => abs($difference) < 0.01 ? 'passed' : 'failed',
            'discrepancies' => $discrepancies,
            'executed_by' => TenantUserHelper::requireTenantUserId(),
            'notes' => null,
        ]);

        // Automatically create reconciliation exceptions for each discrepancy
        if ($result->status === 'failed' && ! empty($discrepancies)) {
            $this->createExceptionsFromDiscrepancies($result, $discrepancies);
        }

        Log::info('AP to GL reconciliation completed', [
            'as_of_date' => $asOfDate->toDateString(),
            'subledger_balance' => $subledgerBalance,
            'gl_balance' => $glBalance,
            'difference' => $difference,
            'status' => $result->status,
        ]);

        return $result;
    }

    /**
     * Get AP control account from chart of accounts.
     *
     * Resolution order:
     *   1. account_mappings table (mapping_key = 'accounts_payable')
     *   2. COA code '2100' (seeded default for AP)
     *   3. Name-based fallback
     */
    private function getAPControlAccount(): ?ChartOfAccount
    {
        // Prefer the configured AP mapping
        $mapping = \App\Models\AccountMapping::where('mapping_key', 'accounts_payable')
            ->whereNull('document_type')
            ->where('is_active', true)
            ->first();

        if ($mapping) {
            return $mapping->account;
        }

        return ChartOfAccount::where('code', '2100')
            ->orWhere('name', 'like', '%Accounts Payable%')
            ->first();
    }

    /**
     * Get AP subledger balance as of date.
     *
     * For AP (a liability): balance = SUM(credit) - SUM(debit) = net payable outstanding.
     */
    private function getSubledgerBalance(Carbon $asOfDate): float
    {
        return APTransaction::whereIn('status', ['open', 'approved', 'partial', 'overdue'])
            ->where('transaction_date', '<=', $asOfDate)
            ->selectRaw('SUM(credit - debit) as balance')
            ->value('balance') ?? 0.0;
    }

    /**
     * Get GL control account balance as of date
     */
    private function getGLBalance(int $controlAccountId, Carbon $asOfDate): float
    {
        return JournalEntryLine::where('account_id', $controlAccountId)
            ->whereHas('journalEntry', function ($q) use ($asOfDate) {
                $q->where('entry_date', '<=', $asOfDate)
                    ->where('status', 'posted');
            })
            ->selectRaw('SUM(credit - debit) as balance')
            ->value('balance') ?? 0.0;
    }

    /**
     * Identify specific discrepancies between subledger and GL
     */
    private function identifyDiscrepancies(Carbon $asOfDate, int $controlAccountId): array
    {
        $discrepancies = [];

        // 1. Find unposted bills
        $unpostedBills = $this->findUnpostedBills($asOfDate);
        if (! empty($unpostedBills)) {
            $discrepancies[] = [
                'type' => 'unposted_bills',
                'description' => 'Bills in subledger but not posted to GL',
                'count' => count($unpostedBills),
                'total_amount' => array_sum(array_column($unpostedBills, 'amount')),
                'items' => $unpostedBills,
            ];
        }

        // 2. Find unposted payments
        $unpostedPayments = $this->findUnpostedPayments($asOfDate);
        if (! empty($unpostedPayments)) {
            $discrepancies[] = [
                'type' => 'unposted_payments',
                'description' => 'Payments in subledger but not posted to GL',
                'count' => count($unpostedPayments),
                'total_amount' => array_sum(array_column($unpostedPayments, 'amount')),
                'items' => $unpostedPayments,
            ];
        }

        // 3. Find GL entries without subledger record
        $missingSubledger = $this->findGLEntriesWithoutSubledger($asOfDate, $controlAccountId);
        if (! empty($missingSubledger)) {
            $discrepancies[] = [
                'type' => 'missing_subledger',
                'description' => 'GL entries without corresponding subledger record',
                'count' => count($missingSubledger),
                'total_amount' => array_sum(array_column($missingSubledger, 'amount')),
                'items' => $missingSubledger,
            ];
        }

        // 4. Find timing differences
        $timingDifferences = $this->findTimingDifferences($asOfDate, $controlAccountId);
        if (! empty($timingDifferences)) {
            $discrepancies[] = [
                'type' => 'timing_differences',
                'description' => 'Transactions posted in different periods',
                'count' => count($timingDifferences),
                'items' => $timingDifferences,
            ];
        }

        return $discrepancies;
    }

    /**
     * Find bills in subledger not posted to GL.
     *
     * View columns: transaction_id, document_number, vendor_id, credit, transaction_date, status
     */
    private function findUnpostedBills(Carbon $asOfDate): array
    {
        return APTransaction::where('transaction_type', 'bill')
            ->where('transaction_date', '<=', $asOfDate)
            ->where('status', 'open') // Not posted
            ->select('transaction_id', 'vendor_id', 'document_number', 'credit as amount', 'transaction_date')
            ->limit(100)
            ->get()
            ->toArray();
    }

    /**
     * Find payments in subledger not posted to GL.
     *
     * We look for AP payment records whose document_number does not appear as
     * a reference on any journal_entry_line.
     */
    private function findUnpostedPayments(Carbon $asOfDate): array
    {
        return APTransaction::where('transaction_type', 'bill_payment')
            ->where('transaction_date', '<=', $asOfDate)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('journal_entry_lines')
                    ->whereColumn('journal_entry_lines.reference', 'ap_transactions.document_number');
            })
            ->select('transaction_id', 'vendor_id', 'document_number', 'debit as amount', 'transaction_date')
            ->limit(100)
            ->get()
            ->toArray();
    }

    /**
     * Find GL entries on the AP control account without a corresponding subledger record.
     *
     * Joins through journal_entries to get entry_date (journal_entry_lines has no date column).
     */
    private function findGLEntriesWithoutSubledger(Carbon $asOfDate, int $controlAccountId): array
    {
        return JournalEntryLine::where('account_id', $controlAccountId)
            ->whereHas('journalEntry', function ($q) use ($asOfDate) {
                $q->where('entry_date', '<=', $asOfDate)
                    ->where('status', 'posted');
            })
            ->where(function ($q) {
                $q->whereNull('reference')
                    ->orWhereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('ap_transactions')
                            ->whereColumn('ap_transactions.document_number', 'journal_entry_lines.reference');
                    });
            })
            ->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->select(
                'journal_entry_lines.id',
                'journal_entry_lines.journal_entry_id',
                'journal_entry_lines.reference',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
                'je.entry_date'
            )
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'journal_entry_id' => $row->journal_entry_id,
                'reference' => $row->reference,
                'amount' => (float) $row->credit - (float) $row->debit,
                'debit' => $row->debit,
                'credit' => $row->credit,
                'entry_date' => $row->entry_date,
            ])
            ->toArray();
    }

    /**
     * Find timing differences between subledger and GL posting.
     *
     * PostgreSQL-compatible: uses (date1 - date2) which returns an integer (days).
     * Joins journal_entry_lines → journal_entries for the GL posting date.
     */
    private function findTimingDifferences(Carbon $asOfDate, int $controlAccountId): array
    {
        return DB::select("
            SELECT 
                ap.transaction_id as ap_transaction_id,
                ap.document_number,
                ap.transaction_date as ap_date,
                je.entry_date as gl_date,
                ABS(ap.transaction_date::date - je.entry_date::date) as days_difference
            FROM ap_transactions ap
            JOIN journal_entry_lines jel ON ap.document_number = jel.reference
            JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE ap.transaction_date <= ?
                AND jel.account_id = ?
                AND je.status = 'posted'
                AND ABS(ap.transaction_date::date - je.entry_date::date) > 0
            LIMIT 100
        ", [$asOfDate->toDateString(), $controlAccountId]);
    }

    /**
     * Generate reconciliation report
     *
     * @return array Report data
     */
    public function generateReport(string $asOfDate): array
    {
        $result = $this->reconcile($asOfDate);

        // Get aging summary
        $agingSummary = $this->getAgingSummary(Carbon::parse($asOfDate));

        // Get vendor balances
        $vendorBalances = $this->getVendorBalances(Carbon::parse($asOfDate));

        return [
            'report_date' => now()->toDateTimeString(),
            'as_of_date' => $asOfDate,
            'reconciliation_result' => [
                'subledger_balance' => $result->subledger_balance,
                'gl_balance' => $result->gl_balance,
                'difference' => $result->difference,
                'status' => $result->status,
                'discrepancies_count' => count($result->discrepancies),
            ],
            'aging_summary' => $agingSummary,
            'top_vendor_balances' => array_slice($vendorBalances, 0, 20),
            'discrepancies' => $result->discrepancies,
        ];
    }

    /**
     * Get aging summary for AP.
     *
     * PostgreSQL-compatible: uses (date - INTERVAL 'N days') syntax.
     * Uses 'amount' column from the ap_transactions view (not 'balance').
     */
    private function getAgingSummary(Carbon $asOfDate): array
    {
        $date = $asOfDate->toDateString();

        return DB::select("
            SELECT 
                COALESCE(SUM(CASE WHEN due_date >= ?::date THEN amount ELSE 0 END), 0) as current,
                COALESCE(SUM(CASE WHEN due_date BETWEEN (?::date - INTERVAL '30 days') AND (?::date - INTERVAL '1 day') THEN amount ELSE 0 END), 0) as days_1_30,
                COALESCE(SUM(CASE WHEN due_date BETWEEN (?::date - INTERVAL '60 days') AND (?::date - INTERVAL '31 days') THEN amount ELSE 0 END), 0) as days_31_60,
                COALESCE(SUM(CASE WHEN due_date BETWEEN (?::date - INTERVAL '90 days') AND (?::date - INTERVAL '61 days') THEN amount ELSE 0 END), 0) as days_61_90,
                COALESCE(SUM(CASE WHEN due_date < (?::date - INTERVAL '90 days') THEN amount ELSE 0 END), 0) as days_90_plus,
                COALESCE(SUM(amount), 0) as total
            FROM ap_transactions
            WHERE status IN ('open', 'approved', 'partial', 'overdue')
                AND transaction_type = 'bill'
                AND transaction_date <= ?::date
        ", [$date, $date, $date, $date, $date, $date, $date, $date, $date]);
    }

    /**
     * Get vendor balances
     */
    private function getVendorBalances(Carbon $asOfDate): array
    {
        return APTransaction::whereIn('status', ['open', 'approved', 'partial', 'overdue'])
            ->where('transaction_date', '<=', $asOfDate)
            ->selectRaw('vendor_id, SUM(credit - debit) as balance')
            ->groupBy('vendor_id')
            ->havingRaw('ABS(SUM(credit - debit)) > 0.01')
            ->orderByDesc('balance')
            ->limit(100)
            ->with('vendor:id,name')
            ->get()
            ->toArray();
    }

    /**
     * Map discrepancy type to exception type
     */
    private function mapDiscrepancyType(string $discrepancyType): string
    {
        return match ($discrepancyType) {
            'unposted_bills' => ReconciliationException::TYPE_UNMATCHED_TRANSACTION,
            'unposted_payments' => ReconciliationException::TYPE_UNMATCHED_TRANSACTION,
            'missing_subledger' => ReconciliationException::TYPE_TIMING_DIFFERENCE,
            'timing_differences' => ReconciliationException::TYPE_TIMING_DIFFERENCE,
            default => ReconciliationException::TYPE_UNMATCHED_TRANSACTION,
        };
    }

    /**
     * Create reconciliation exceptions from discrepancies
     *
     * Idempotent: only creates exceptions for items that don't already have an open exception
     */
    private function createExceptionsFromDiscrepancies(ReconciliationControlResult $result, array $discrepancies): void
    {
        foreach ($discrepancies as $discrepancy) {
            $exceptionType = $this->mapDiscrepancyType($discrepancy['type']);

            foreach ($discrepancy['items'] ?? [] as $item) {
                $reference = $item['document_number'] ?? $item['reference'] ?? null;

                // Skip if no reference to track
                if (! $reference) {
                    continue;
                }

                // Idempotent: check if an open exception already exists for this reference
                $existingException = ReconciliationException::where('reference', $reference)
                    ->whereIn('status', [ReconciliationException::STATUS_OPEN, ReconciliationException::STATUS_IN_PROGRESS])
                    ->first();

                if ($existingException) {
                    continue;
                }

                ReconciliationException::create([
                    'exception_type' => $exceptionType,
                    'amount' => $item['amount'] ?? $item['total_amount'] ?? null,
                    'transaction_date' => $item['transaction_date'] ?? $item['entry_date'] ?? null,
                    'reference' => $reference,
                    'description' => $discrepancy['description'],
                    'priority' => ReconciliationException::PRIORITY_MEDIUM,
                    'status' => ReconciliationException::STATUS_OPEN,
                    'aging_bucket' => ReconciliationException::BUCKET_CURRENT,
                    'days_open' => 0,
                    'reconciliation_control_result_id' => $result->id,
                ]);
            }
        }

        Log::info('AP reconciliation exceptions created', [
            'control_result_id' => $result->id,
            'discrepancy_count' => count($discrepancies),
        ]);
    }
}
