<?php

namespace App\Services\Reconciliation;

use App\Models\AccountMapping;
use App\Models\ARTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use App\Models\ReconciliationControlResult;
use App\Models\ReconciliationException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * ARGLReconciliationService
 *
 * Provides monthly reconciliation between AR subledger and GL control account.
 * Identifies discrepancies and generates control reports.
 *
 * Hardening notes (2026-04-15):
 *   - Fixed class name: ChartOfAccounts → ChartOfAccount
 *   - Fixed column name: posting_date → entry_date (journal_entries table)
 *   - Fixed AR control account resolution to use account_mappings table
 *   - Fixed MySQL-specific DATEDIFF / INTERVAL syntax → PostgreSQL
 *   - Fixed references to non-existent columns: journal_entry_lines.transaction_date
 *   - Fixed view column names: id → transaction_id, reference → document_number,
 *     balance → amount (ar_transactions is a VIEW, not a table)
 *   - Created missing ARTransaction Eloquent model for the ar_transactions view
 *
 * Issue #881 (SA8): reconciliation is per legal entity, the control account is
 * mapping-driven with no code/name fallback (a missing mapping is a setup
 * error), the difference threshold is a parameter, and a variance beyond it is
 * logged as an alert.
 */
class ARGLReconciliationService
{
    /**
     * Run AR to GL reconciliation control
     *
     * @param  string  $asOfDate  Date to reconcile as of (YYYY-MM-DD)
     * @param  int|null  $controlAccountId  Optional explicit AR control account
     * @param  int|null  $legalEntityId  Reconcile only this legal entity (null = tenant-wide)
     * @param  float  $tolerance  Absolute difference treated as reconciled
     *
     * @throws ARControlAccountNotConfiguredException when no active mapping exists
     */
    public function reconcile(
        string $asOfDate,
        ?int $controlAccountId = null,
        ?int $legalEntityId = null,
        float $tolerance = 0.01,
    ): ReconciliationControlResult {
        $asOfDate = Carbon::parse($asOfDate);

        // An explicit control account is a caller decision; otherwise the
        // configured mapping is the only source — never a guessed code.
        if (! $controlAccountId) {
            $controlAccountId = $this->getARControlAccount()->id;
        }

        // Get subledger balance
        $subledgerBalance = $this->getSubledgerBalance($asOfDate, $legalEntityId);

        // Get GL control account balance
        $glBalance = $this->getGLBalance($controlAccountId, $asOfDate, $legalEntityId);

        // Calculate difference
        $difference = round($subledgerBalance - $glBalance, 2);
        $withinTolerance = abs($difference) < $tolerance;

        // Identify discrepancies if any
        $discrepancies = [];
        if (! $withinTolerance) {
            $discrepancies = $this->identifyDiscrepancies($asOfDate, $controlAccountId, $legalEntityId);
        }

        // Create control result record
        $result = ReconciliationControlResult::create([
            'control_name' => 'ar_gl_reconciliation',
            'legal_entity_id' => $legalEntityId,
            'as_of_date' => $asOfDate->toDateString(),
            'subledger_balance' => $subledgerBalance,
            'gl_balance' => $glBalance,
            'difference' => $difference,
            'status' => $withinTolerance ? 'passed' : 'failed',
            'discrepancies' => $discrepancies,
            // Console/scheduled runs may have no authenticated user; the
            // column is nullable and must not abort the reconciliation.
            'executed_by' => TenantUserHelper::resolveTenantUserId(),
            'notes' => null,
        ]);

        // Automatically create reconciliation exceptions for each discrepancy
        if ($result->status === 'failed' && ! empty($discrepancies)) {
            $this->createExceptionsFromDiscrepancies($result, $discrepancies);
        }

        // Alert: a variance beyond tolerance is an operational alarm, not just
        // an info line. The scheduled command additionally fails its run.
        if (! $withinTolerance) {
            Log::warning('AR to GL reconciliation variance exceeds tolerance', [
                'legal_entity_id' => $legalEntityId,
                'as_of_date' => $asOfDate->toDateString(),
                'subledger_balance' => $subledgerBalance,
                'gl_balance' => $glBalance,
                'difference' => $difference,
                'tolerance' => $tolerance,
            ]);
        }

        Log::info('AR to GL reconciliation completed', [
            'legal_entity_id' => $legalEntityId,
            'as_of_date' => $asOfDate->toDateString(),
            'subledger_balance' => $subledgerBalance,
            'gl_balance' => $glBalance,
            'difference' => $difference,
            'status' => $result->status,
        ]);

        return $result;
    }

    /**
     * Resolve the AR control account from account_mappings.
     *
     * Issue #881: mapping-driven only. The historical COA code '1100' /
     * name-match fallbacks are deliberately gone — on the MY CoA the AR
     * control account is 124000, so guessing 1100 reconciled against the
     * wrong account.
     *
     * @throws ARControlAccountNotConfiguredException
     */
    private function getARControlAccount(): ChartOfAccount
    {
        $mapping = AccountMapping::where('mapping_key', 'accounts_receivable')
            ->whereNull('document_type')
            ->where('is_active', true)
            ->with('account')
            ->first();

        if (! $mapping || ! $mapping->account || ! $mapping->account->is_active) {
            throw new ARControlAccountNotConfiguredException(
                "AR control account is not configured: no active 'accounts_receivable' account mapping exists. ".
                'Configure it via Accounting Settings > Default Accounts (account-mappings health) before reconciling.'
            );
        }

        return $mapping->account;
    }

    /**
     * Get AR subledger balance as of date
     *
     * Uses the ar_transactions view which exposes debit/credit columns.
     * For AR: balance = SUM(debit) - SUM(credit) = net receivable outstanding.
     */
    private function getSubledgerBalance(Carbon $asOfDate, ?int $legalEntityId = null): float
    {
        $query = ARTransaction::whereIn('status', ['sent', 'posted', 'partial', 'overdue', 'paid'])
            ->where('transaction_date', '<=', $asOfDate);

        if ($legalEntityId) {
            $query->where('legal_entity_id', $legalEntityId);
        }

        return $query->selectRaw('SUM(debit - credit) as balance')->value('balance') ?? 0.0;
    }

    /**
     * Get GL control account balance as of date
     */
    private function getGLBalance(int $controlAccountId, Carbon $asOfDate, ?int $legalEntityId = null): float
    {
        return JournalEntryLine::where('account_id', $controlAccountId)
            ->whereHas('journalEntry', function ($q) use ($asOfDate, $legalEntityId) {
                $q->where('entry_date', '<=', $asOfDate)
                    ->where('status', 'posted');

                if ($legalEntityId) {
                    $q->where('legal_entity_id', $legalEntityId);
                }
            })
            ->selectRaw('SUM(debit - credit) as balance')
            ->value('balance') ?? 0.0;
    }

    /**
     * Identify specific discrepancies between subledger and GL
     */
    private function identifyDiscrepancies(Carbon $asOfDate, int $controlAccountId, ?int $legalEntityId = null): array
    {
        $discrepancies = [];

        // 1. Find unposted invoices
        $unpostedInvoices = $this->findUnpostedInvoices($asOfDate, $legalEntityId);
        if (! empty($unpostedInvoices)) {
            $discrepancies[] = [
                'type' => 'unposted_invoices',
                'description' => 'Invoices in subledger but not posted to GL',
                'count' => count($unpostedInvoices),
                'total_amount' => array_sum(array_column($unpostedInvoices, 'amount')),
                'items' => $unpostedInvoices,
            ];
        }

        // 2. Find unposted payments
        $unpostedPayments = $this->findUnpostedPayments($asOfDate, $legalEntityId);
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
        $missingSubledger = $this->findGLEntriesWithoutSubledger($asOfDate, $controlAccountId, $legalEntityId);
        if (! empty($missingSubledger)) {
            $discrepancies[] = [
                'type' => 'missing_subledger',
                'description' => 'GL entries without corresponding subledger record',
                'count' => count($missingSubledger),
                'total_amount' => array_sum(array_column($missingSubledger, 'amount')),
                'items' => $missingSubledger,
            ];
        }

        // 4. Find timing differences (posted in different periods)
        $timingDifferences = $this->findTimingDifferences($asOfDate, $controlAccountId, $legalEntityId);
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
     * Find invoices in subledger not posted to GL.
     *
     * View columns: transaction_id, document_number, customer_id, debit, transaction_date, status
     */
    private function findUnpostedInvoices(Carbon $asOfDate, ?int $legalEntityId = null): array
    {
        return ARTransaction::where('transaction_type', 'invoice')
            ->where('transaction_date', '<=', $asOfDate)
            ->where('status', 'sent') // Not posted
            ->when($legalEntityId, fn ($q) => $q->where('legal_entity_id', $legalEntityId))
            ->select('transaction_id', 'customer_id', 'document_number', 'debit as amount', 'transaction_date')
            ->limit(100)
            ->get()
            ->toArray();
    }

    /**
     * Find payments in subledger not posted to GL.
     *
     * We look for AR payment records whose document_number does not appear as
     * a reference on any journal_entry_line hitting the AR control account.
     */
    private function findUnpostedPayments(Carbon $asOfDate, ?int $legalEntityId = null): array
    {
        return ARTransaction::where('transaction_type', 'payment')
            ->where('transaction_date', '<=', $asOfDate)
            ->where('status', 'posted')
            ->when($legalEntityId, fn ($q) => $q->where('legal_entity_id', $legalEntityId))
            ->whereNotExists(function ($query) use ($legalEntityId) {
                $query->select(DB::raw(1))
                    ->from('journal_entry_lines')
                    ->whereColumn('journal_entry_lines.reference', 'ar_transactions.document_number');

                if ($legalEntityId) {
                    $query->whereExists(function ($je) use ($legalEntityId) {
                        $je->select(DB::raw(1))
                            ->from('journal_entries')
                            ->whereColumn('journal_entries.id', 'journal_entry_lines.journal_entry_id')
                            ->where('journal_entries.legal_entity_id', $legalEntityId);
                    });
                }
            })
            ->select('transaction_id', 'customer_id', 'document_number', 'credit as amount', 'transaction_date')
            ->limit(100)
            ->get()
            ->toArray();
    }

    /**
     * Find GL entries on the AR control account without a corresponding subledger record.
     *
     * Joins through journal_entries to get entry_date (journal_entry_lines has no date column).
     */
    private function findGLEntriesWithoutSubledger(Carbon $asOfDate, int $controlAccountId, ?int $legalEntityId = null): array
    {
        return JournalEntryLine::where('account_id', $controlAccountId)
            ->whereHas('journalEntry', function ($q) use ($asOfDate, $legalEntityId) {
                $q->where('entry_date', '<=', $asOfDate)
                    ->where('status', 'posted');

                if ($legalEntityId) {
                    $q->where('legal_entity_id', $legalEntityId);
                }
            })
            ->where(function ($q) use ($legalEntityId) {
                $q->whereNull('journal_entry_lines.reference')
                    ->orWhereNotExists(function ($sub) use ($legalEntityId) {
                        $sub->select(DB::raw(1))
                            ->from('ar_transactions')
                            ->whereColumn('ar_transactions.document_number', 'journal_entry_lines.reference');

                        if ($legalEntityId) {
                            $sub->where('ar_transactions.legal_entity_id', $legalEntityId);
                        }
                    });
            })
            ->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->when($legalEntityId, fn ($q) => $q->where('je.legal_entity_id', $legalEntityId))
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
                'amount' => (float) $row->debit - (float) $row->credit,
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
    private function findTimingDifferences(Carbon $asOfDate, int $controlAccountId, ?int $legalEntityId = null): array
    {
        $sql = "
            SELECT 
                ar.transaction_id as ar_transaction_id,
                ar.document_number,
                ar.transaction_date as ar_date,
                je.entry_date as gl_date,
                ABS(ar.transaction_date::date - je.entry_date::date) as days_difference
            FROM ar_transactions ar
            JOIN journal_entry_lines jel ON ar.document_number = jel.reference
            JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE ar.transaction_date <= ?
                AND jel.account_id = ?
                AND je.status = 'posted'
                AND ABS(ar.transaction_date::date - je.entry_date::date) > 0
        ";

        $bindings = [$asOfDate->toDateString(), $controlAccountId];

        if ($legalEntityId) {
            $sql .= ' AND ar.legal_entity_id = ? AND je.legal_entity_id = ?';
            $bindings[] = $legalEntityId;
            $bindings[] = $legalEntityId;
        }

        $sql .= ' LIMIT 100';

        return DB::select($sql, $bindings);
    }

    /**
     * Generate reconciliation report
     *
     * @return array Report data
     */
    public function generateReport(string $asOfDate, ?int $legalEntityId = null): array
    {
        $result = $this->reconcile($asOfDate, null, $legalEntityId);

        // Get aging summary
        $agingSummary = $this->getAgingSummary(Carbon::parse($asOfDate), $legalEntityId);

        // Get customer balances
        $customerBalances = $this->getCustomerBalances(Carbon::parse($asOfDate), $legalEntityId);

        return [
            'report_date' => now()->toDateTimeString(),
            'as_of_date' => $asOfDate,
            'legal_entity_id' => $legalEntityId,
            'reconciliation_result' => [
                'subledger_balance' => $result->subledger_balance,
                'gl_balance' => $result->gl_balance,
                'difference' => $result->difference,
                'status' => $result->status,
                'discrepancies_count' => count($result->discrepancies),
            ],
            'aging_summary' => $agingSummary,
            'top_customer_balances' => array_slice($customerBalances, 0, 20),
            'discrepancies' => $result->discrepancies,
        ];
    }

    /**
     * Get aging summary for AR.
     *
     * PostgreSQL-compatible: uses (date - INTERVAL 'N days') syntax.
     * Uses 'amount' column from the ar_transactions view (not 'balance').
     */
    private function getAgingSummary(Carbon $asOfDate, ?int $legalEntityId = null): array
    {
        $date = $asOfDate->toDateString();

        $sql = "
            SELECT 
                COALESCE(SUM(CASE WHEN due_date >= ?::date THEN amount ELSE 0 END), 0) as current,
                COALESCE(SUM(CASE WHEN due_date BETWEEN (?::date - INTERVAL '30 days') AND (?::date - INTERVAL '1 day') THEN amount ELSE 0 END), 0) as days_1_30,
                COALESCE(SUM(CASE WHEN due_date BETWEEN (?::date - INTERVAL '60 days') AND (?::date - INTERVAL '31 days') THEN amount ELSE 0 END), 0) as days_31_60,
                COALESCE(SUM(CASE WHEN due_date BETWEEN (?::date - INTERVAL '90 days') AND (?::date - INTERVAL '61 days') THEN amount ELSE 0 END), 0) as days_61_90,
                COALESCE(SUM(CASE WHEN due_date < (?::date - INTERVAL '90 days') THEN amount ELSE 0 END), 0) as days_90_plus,
                COALESCE(SUM(amount), 0) as total
            FROM ar_transactions
            WHERE status IN ('sent', 'posted', 'partial', 'overdue')
                AND transaction_type = 'invoice'
                AND transaction_date <= ?::date
        ";

        $bindings = [$date, $date, $date, $date, $date, $date, $date, $date, $date];

        if ($legalEntityId) {
            $sql .= ' AND legal_entity_id = ?';
            $bindings[] = $legalEntityId;
        }

        return DB::select($sql, $bindings);
    }

    /**
     * Get customer balances
     */
    private function getCustomerBalances(Carbon $asOfDate, ?int $legalEntityId = null): array
    {
        return ARTransaction::whereIn('status', ['sent', 'posted', 'partial', 'overdue'])
            ->where('transaction_date', '<=', $asOfDate)
            ->when($legalEntityId, fn ($q) => $q->where('legal_entity_id', $legalEntityId))
            ->selectRaw('customer_id, SUM(debit - credit) as balance')
            ->groupBy('customer_id')
            ->havingRaw('ABS(SUM(debit - credit)) > 0.01')
            ->orderByDesc('balance')
            ->limit(100)
            ->with('customer:id,name')
            ->get()
            ->toArray();
    }

    /**
     * Get reconciliation history
     *
     * @param  int  $limit  Number of historical results
     */
    public function getHistory(int $limit = 12, ?int $legalEntityId = null): array
    {
        return ReconciliationControlResult::where('control_name', 'ar_gl_reconciliation')
            ->when($legalEntityId, fn ($q) => $q->where('legal_entity_id', $legalEntityId))
            ->orderByDesc('as_of_date')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Map discrepancy type to exception type
     */
    private function mapDiscrepancyType(string $discrepancyType): string
    {
        return match ($discrepancyType) {
            'unposted_invoices' => ReconciliationException::TYPE_UNMATCHED_TRANSACTION,
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

        Log::info('AR reconciliation exceptions created', [
            'control_result_id' => $result->id,
            'discrepancy_count' => count($discrepancies),
        ]);
    }
}
