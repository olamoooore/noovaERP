<?php

namespace Modules\FinancialReporting\Services\Reports;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportData;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\BaseReportService;

class GeneralLedgerService extends BaseReportService
{
    /**
     * Generate General Ledger report
     *
     * A General Ledger shows all transactions for selected accounts
     * with running balances, providing a complete audit trail
     */
    public function generate(ReportFilter $filter): ReportData
    {
        $accountId = $filter->accountId;
        $startDate = $filter->startDate;
        $endDate = $filter->endDate;
        $page = $filter->page;
        $perPage = $filter->perPage;

        // Validate required parameters
        if (! $accountId) {
            throw new \InvalidArgumentException('Account ID is required for General Ledger report');
        }

        // Get account information
        $account = $this->getAccountInfo($accountId);

        if (! $account) {
            // Account not found in this tenant — return empty result instead of crashing.
            // This commonly happens when cross-tenant links or stale URLs pass account IDs
            // that don't exist in the current schema.
            return new ReportData(
                title: 'General Ledger',
                data: [],
                summary: [
                    'opening_balance' => 0,
                    'closing_balance' => 0,
                    'total_debits' => 0,
                    'total_credits' => 0,
                    'net_change' => 0,
                ],
                metadata: [
                    'account' => null,
                    'period' => [
                        'start_date' => $startDate?->format('Y-m-d'),
                        'end_date' => $endDate?->format('Y-m-d'),
                    ],
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                        'last_page' => 1,
                    ],
                    'generated_at' => now()->toIso8601String(),
                ]
            );
        }

        // Get opening balance (before start date)
        $openingBalance = $this->getOpeningBalance($accountId, $startDate, $filter);

        // Get transactions with pagination
        $transactions = $this->getTransactions($accountId, $startDate, $endDate, $page, $perPage, $filter);

        // Calculate running balances
        $transactionsWithBalance = $this->calculateRunningBalances(
            $transactions->items(),
            $openingBalance,
            $account->type
        );

        // Get closing balance
        $closingBalance = $this->getClosingBalance($accountId, $endDate, $filter);

        return new ReportData(
            title: 'General Ledger',
            data: $transactionsWithBalance,
            summary: [
                'opening_balance' => round($openingBalance, 2),
                'closing_balance' => round($closingBalance, 2),
                'total_debits' => round($transactions->sum('debit'), 2),
                'total_credits' => round($transactions->sum('credit'), 2),
                'net_change' => round($closingBalance - $openingBalance, 2),
            ],
            metadata: [
                'account' => [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                ],
                'period' => [
                    'start_date' => $startDate?->format('Y-m-d'),
                    'end_date' => $endDate?->format('Y-m-d'),
                ],
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                    'last_page' => $transactions->lastPage(),
                ],
                'generated_at' => now()->toIso8601String(),
            ]
        );
    }

    /**
     * Get account information
     */
    protected function getAccountInfo(int $accountId): ?object
    {
        return DB::table('chart_of_accounts')
            ->where('id', $accountId)
            ->select('id', 'code', 'name', 'type', 'subtype')
            ->first();
    }

    /**
     * Get opening balance (balance before start date)
     */
    protected function getOpeningBalance(int $accountId, ?Carbon $startDate, ?ReportFilter $filter = null): float
    {
        if (! $startDate) {
            return 0.0;
        }

        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.account_id', $accountId)
            ->where('je.entry_date', '<', $startDate)
            ->where('je.status', 'posted')->where('je.is_active_version', true);

        if ($filter?->legalEntityId) {
            $query->where('je.legal_entity_id', $filter->legalEntityId);
        }
        if ($filter?->entityBookId) {
            $query->where('je.entity_book_id', $filter->entityBookId);
        }

        $result = $query->select([
            DB::raw('SUM(jel.debit) as total_debit'),
            DB::raw('SUM(jel.credit) as total_credit'),
        ])
            ->first();

        return ($result->total_debit ?? 0) - ($result->total_credit ?? 0);
    }

    /**
     * Get closing balance (balance up to end date)
     */
    protected function getClosingBalance(int $accountId, ?Carbon $endDate, ?ReportFilter $filter = null): float
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.account_id', $accountId)
            ->where('je.status', 'posted')->where('je.is_active_version', true);

        if ($endDate) {
            $query->where('je.entry_date', '<=', $endDate);
        }

        if ($filter?->legalEntityId) {
            $query->where('je.legal_entity_id', $filter->legalEntityId);
        }
        if ($filter?->entityBookId) {
            $query->where('je.entity_book_id', $filter->entityBookId);
        }

        $result = $query->select([
            DB::raw('SUM(jel.debit) as total_debit'),
            DB::raw('SUM(jel.credit) as total_credit'),
        ])
            ->first();

        return ($result->total_debit ?? 0) - ($result->total_credit ?? 0);
    }

    /**
     * Get transactions for the account with pagination
     */
    protected function getTransactions(
        int $accountId,
        ?Carbon $startDate,
        ?Carbon $endDate,
        int $page,
        int $perPage,
        ?ReportFilter $filter = null
    ): LengthAwarePaginator {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.account_id', $accountId)
            ->where('je.status', 'posted')->where('je.is_active_version', true);

        // Apply date filters
        if ($startDate) {
            $query->where('je.entry_date', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('je.entry_date', '<=', $endDate);
        }

        // Apply entity filters
        if ($filter?->legalEntityId) {
            $query->where('je.legal_entity_id', $filter->legalEntityId);
        }
        if ($filter?->entityBookId) {
            $query->where('je.entity_book_id', $filter->entityBookId);
        }

        $query->select([
            'je.id as journal_entry_id',
            'je.entry_date',
            'je.reference',
            'je.description as journal_description',
            'jel.id as line_id',
            'jel.description as line_description',
            'jel.debit',
            'jel.credit',
            'jel.dimensions',
        ])
            // Subquery: invoice linked to this journal entry (if any)
            // Used by the frontend to render the reference as a clickable link
            // to the invoice detail page.
            ->addSelect(DB::raw("(SELECT id FROM invoices WHERE journal_entry_id = je.id AND deleted_at IS NULL LIMIT 1) as invoice_id"))
            ->orderBy('je.entry_date', 'asc')
            ->orderBy('je.id', 'asc')
            ->orderBy('jel.id', 'asc');

        if ($perPage <= 0) {
            $items = $query->get();
            $total = $items->count();

            return new LengthAwarePaginator($items, $total, max(1, $total), 1);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Calculate running balances for transactions
     */
    protected function calculateRunningBalances(array $transactions, float $openingBalance, string $accountType): array
    {
        $runningBalance = $openingBalance;
        $result = [];

        // Determine if this is a debit-normal account
        $isDebitNormal = in_array($accountType, ['asset', 'expense']);

        foreach ($transactions as $transaction) {
            // Calculate change
            $change = $transaction->debit - $transaction->credit;
            $runningBalance += $change;

            // Format for display
            $result[] = [
                'journal_entry_id' => $transaction->journal_entry_id,
                'line_id' => $transaction->line_id,
                'entry_date' => $transaction->entry_date,
                'reference' => $transaction->reference,
                'description' => $transaction->line_description ?: $transaction->journal_description,
                'debit' => round($transaction->debit, 2),
                'credit' => round($transaction->credit, 2),
                'balance' => round($runningBalance, 2),
                'dimensions' => $transaction->dimensions,
                'invoice_id' => $transaction->invoice_id ?? null,
            ];
        }

        return $result;
    }

    /**
     * Get general ledger for multiple accounts (summary view)
     */
    public function getMultiAccountSummary(ReportFilter $filter): array
    {
        $startDate = $filter->startDate;
        $endDate = $filter->endDate;
        $accountType = $filter->accountType;

        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')->where('je.is_active_version', true);

        // Apply date filters
        if ($startDate) {
            $query->where('je.entry_date', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('je.entry_date', '<=', $endDate);
        }

        // Apply account type filter
        if ($accountType) {
            $query->where('coa.type', $accountType);
        }

        $accounts = $query->select([
            'coa.id',
            'coa.code',
            'coa.name',
            'coa.type',
            DB::raw('COUNT(DISTINCT je.id) as transaction_count'),
            DB::raw('SUM(jel.debit) as total_debit'),
            DB::raw('SUM(jel.credit) as total_credit'),
            DB::raw('SUM(jel.debit - jel.credit) as net_change'),
        ])
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.type')
            ->orderBy('coa.code')
            ->get();

        return [
            'accounts' => $accounts->map(function ($account) {
                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'transaction_count' => $account->transaction_count,
                    'total_debit' => round($account->total_debit, 2),
                    'total_credit' => round($account->total_credit, 2),
                    'net_change' => round($account->net_change, 2),
                ];
            })->toArray(),
            'period' => [
                'start_date' => $startDate?->format('Y-m-d'),
                'end_date' => $endDate?->format('Y-m-d'),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
