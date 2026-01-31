<?php

namespace Modules\FinancialReporting\Services\Reports;

use Modules\FinancialReporting\Services\BaseReportService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\DTOs\ReportData;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CashFlowService extends BaseReportService
{
    /**
     * Generate Cash Flow Statement
     */
    public function generate(ReportFilter $filter): ReportData
    {
        // Validate date range
        if (!$filter->startDate || !$filter->endDate) {
            throw new \InvalidArgumentException('Start date and end date are required for Cash Flow report');
        }

        // Determine method (direct or indirect)
        $method = $filter->interval ?? 'indirect'; // Default to indirect method

        if ($method === 'direct') {
            return $this->generateDirectMethod($filter);
        } else {
            return $this->generateIndirectMethod($filter);
        }
    }

    /**
     * Generate Cash Flow using Indirect Method
     * Starts with net income and adjusts for non-cash items
     */
    protected function generateIndirectMethod(ReportFilter $filter): ReportData
    {
        // Get net income from P&L
        $netIncome = $this->getNetIncome($filter);

        // Operating Activities
        $operatingActivities = $this->getOperatingActivitiesIndirect($filter, $netIncome);
        
        // Investing Activities
        $investingActivities = $this->getInvestingActivities($filter);
        
        // Financing Activities
        $financingActivities = $this->getFinancingActivities($filter);

        // Calculate net cash flow
        $netCashFlow = $operatingActivities['net_cash'] + 
                       $investingActivities['net_cash'] + 
                       $financingActivities['net_cash'];

        // Get beginning and ending cash
        $beginningCash = $this->getCashBalance($filter->startDate->copy()->subDay());
        $endingCash = $this->getCashBalance($filter->endDate);

        $data = [
            'method' => 'indirect',
            'operating_activities' => $operatingActivities,
            'investing_activities' => $investingActivities,
            'financing_activities' => $financingActivities,
            'net_cash_flow' => $netCashFlow,
            'beginning_cash' => $beginningCash,
            'ending_cash' => $endingCash,
            'cash_change' => $endingCash - $beginningCash,
        ];

        return new ReportData(
            title: 'Cash Flow Statement (Indirect Method)',
            data: $data,
            summary: [
                'net_cash_from_operations' => $operatingActivities['net_cash'],
                'net_cash_from_investing' => $investingActivities['net_cash'],
                'net_cash_from_financing' => $financingActivities['net_cash'],
                'net_cash_flow' => $netCashFlow,
                'beginning_cash' => $beginningCash,
                'ending_cash' => $endingCash,
            ],
            metadata: [
                'period' => [
                    'start' => $filter->startDate->format('Y-m-d'),
                    'end' => $filter->endDate->format('Y-m-d'),
                ],
                'method' => 'indirect',
                'generated_at' => now()->toIso8601String(),
            ]
        );
    }

    /**
     * Generate Cash Flow using Direct Method
     * Shows actual cash receipts and payments
     */
    protected function generateDirectMethod(ReportFilter $filter): ReportData
    {
        // Operating Activities
        $operatingActivities = $this->getOperatingActivitiesDirect($filter);
        
        // Investing Activities
        $investingActivities = $this->getInvestingActivities($filter);
        
        // Financing Activities
        $financingActivities = $this->getFinancingActivities($filter);

        // Calculate net cash flow
        $netCashFlow = $operatingActivities['net_cash'] + 
                       $investingActivities['net_cash'] + 
                       $financingActivities['net_cash'];

        // Get beginning and ending cash
        $beginningCash = $this->getCashBalance($filter->startDate->copy()->subDay());
        $endingCash = $this->getCashBalance($filter->endDate);

        $data = [
            'method' => 'direct',
            'operating_activities' => $operatingActivities,
            'investing_activities' => $investingActivities,
            'financing_activities' => $financingActivities,
            'net_cash_flow' => $netCashFlow,
            'beginning_cash' => $beginningCash,
            'ending_cash' => $endingCash,
            'cash_change' => $endingCash - $beginningCash,
        ];

        return new ReportData(
            title: 'Cash Flow Statement (Direct Method)',
            data: $data,
            summary: [
                'net_cash_from_operations' => $operatingActivities['net_cash'],
                'net_cash_from_investing' => $investingActivities['net_cash'],
                'net_cash_from_financing' => $financingActivities['net_cash'],
                'net_cash_flow' => $netCashFlow,
                'beginning_cash' => $beginningCash,
                'ending_cash' => $endingCash,
            ],
            metadata: [
                'period' => [
                    'start' => $filter->startDate->format('Y-m-d'),
                    'end' => $filter->endDate->format('Y-m-d'),
                ],
                'method' => 'direct',
                'generated_at' => now()->toIso8601String(),
            ]
        );
    }

    /**
     * Get operating activities using indirect method
     */
    protected function getOperatingActivitiesIndirect(ReportFilter $filter, float $netIncome): array
    {
        // Start with net income
        $items = [
            [
                'description' => 'Net Income',
                'amount' => $netIncome,
            ],
        ];

        // Add back non-cash expenses (depreciation, amortization)
        $depreciation = $this->getDepreciation($filter);
        if ($depreciation > 0) {
            $items[] = [
                'description' => 'Depreciation and Amortization',
                'amount' => $depreciation,
            ];
        }

        // Changes in working capital
        $workingCapitalChanges = $this->getWorkingCapitalChanges($filter);
        foreach ($workingCapitalChanges as $change) {
            $items[] = $change;
        }

        $netCash = array_sum(array_column($items, 'amount'));

        return [
            'items' => $items,
            'net_cash' => $netCash,
        ];
    }

    /**
     * Get operating activities using direct method
     */
    protected function getOperatingActivitiesDirect(ReportFilter $filter): array
    {
        $items = [];

        // Cash received from customers
        $cashFromCustomers = $this->getCashFromCustomers($filter);
        $items[] = [
            'description' => 'Cash Received from Customers',
            'amount' => $cashFromCustomers,
        ];

        // Cash paid to suppliers
        $cashToSuppliers = $this->getCashToSuppliers($filter);
        $items[] = [
            'description' => 'Cash Paid to Suppliers',
            'amount' => -$cashToSuppliers,
        ];

        // Cash paid for operating expenses
        $cashForExpenses = $this->getCashForOperatingExpenses($filter);
        $items[] = [
            'description' => 'Cash Paid for Operating Expenses',
            'amount' => -$cashForExpenses,
        ];

        $netCash = array_sum(array_column($items, 'amount'));

        return [
            'items' => $items,
            'net_cash' => $netCash,
        ];
    }

    /**
     * Get investing activities
     */
    protected function getInvestingActivities(ReportFilter $filter): array
    {
        $items = [];

        // Purchase of fixed assets (negative cash flow)
        $assetPurchases = $this->getAssetPurchases($filter);
        if ($assetPurchases > 0) {
            $items[] = [
                'description' => 'Purchase of Fixed Assets',
                'amount' => -$assetPurchases,
            ];
        }

        // Sale of fixed assets (positive cash flow)
        $assetSales = $this->getAssetSales($filter);
        if ($assetSales > 0) {
            $items[] = [
                'description' => 'Proceeds from Sale of Assets',
                'amount' => $assetSales,
            ];
        }

        $netCash = array_sum(array_column($items, 'amount'));

        return [
            'items' => $items,
            'net_cash' => $netCash,
        ];
    }

    /**
     * Get financing activities
     */
    protected function getFinancingActivities(ReportFilter $filter): array
    {
        $items = [];

        // Proceeds from loans (positive cash flow)
        $loanProceeds = $this->getLoanProceeds($filter);
        if ($loanProceeds > 0) {
            $items[] = [
                'description' => 'Proceeds from Loans',
                'amount' => $loanProceeds,
            ];
        }

        // Loan repayments (negative cash flow)
        $loanRepayments = $this->getLoanRepayments($filter);
        if ($loanRepayments > 0) {
            $items[] = [
                'description' => 'Repayment of Loans',
                'amount' => -$loanRepayments,
            ];
        }

        // Owner contributions (positive cash flow)
        $ownerContributions = $this->getOwnerContributions($filter);
        if ($ownerContributions > 0) {
            $items[] = [
                'description' => 'Owner Contributions',
                'amount' => $ownerContributions,
            ];
        }

        // Owner withdrawals (negative cash flow)
        $ownerWithdrawals = $this->getOwnerWithdrawals($filter);
        if ($ownerWithdrawals > 0) {
            $items[] = [
                'description' => 'Owner Withdrawals',
                'amount' => -$ownerWithdrawals,
            ];
        }

        $netCash = array_sum(array_column($items, 'amount'));

        return [
            'items' => $items,
            'net_cash' => $netCash,
        ];
    }

    /**
     * Get net income for the period
     */
    protected function getNetIncome(ReportFilter $filter): float
    {
        $revenue = $this->getAmountByType($filter, 'revenue');
        $expenses = $this->getAmountByType($filter, 'expense');
        
        return $revenue - $expenses;
    }

    /**
     * Get cash balance at a specific date
     */
    protected function getCashBalance(Carbon $date): float
    {
        return DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<=', $date)
            ->whereBetween('coa.code', ['1000', '1099']) // Cash accounts
            ->sum(DB::raw('jel.debit - jel.credit'));
    }

    /**
     * Get depreciation for the period
     */
    protected function getDepreciation(ReportFilter $filter): float
    {
        // Look for depreciation expense accounts (typically 5300-5399)
        return DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$filter->startDate, $filter->endDate])
            ->where('coa.name', 'like', '%depreciation%')
            ->sum('jel.debit');
    }

    /**
     * Get working capital changes
     */
    protected function getWorkingCapitalChanges(ReportFilter $filter): array
    {
        $changes = [];

        // Accounts Receivable change (increase = negative, decrease = positive)
        $arChange = $this->getAccountBalanceChange($filter, '1100', '1199');
        if ($arChange != 0) {
            $changes[] = [
                'description' => 'Change in Accounts Receivable',
                'amount' => -$arChange,
            ];
        }

        // Inventory change (increase = negative, decrease = positive)
        $inventoryChange = $this->getAccountBalanceChange($filter, '1200', '1299');
        if ($inventoryChange != 0) {
            $changes[] = [
                'description' => 'Change in Inventory',
                'amount' => -$inventoryChange,
            ];
        }

        // Accounts Payable change (increase = positive, decrease = negative)
        $apChange = $this->getAccountBalanceChange($filter, '2000', '2099');
        if ($apChange != 0) {
            $changes[] = [
                'description' => 'Change in Accounts Payable',
                'amount' => $apChange,
            ];
        }

        return $changes;
    }

    /**
     * Get account balance change between start and end date
     */
    protected function getAccountBalanceChange(ReportFilter $filter, string $codeStart, string $codeEnd): float
    {
        $startBalance = $this->getAccountBalanceAtDate($filter->startDate->copy()->subDay(), $codeStart, $codeEnd);
        $endBalance = $this->getAccountBalanceAtDate($filter->endDate, $codeStart, $codeEnd);
        
        return $endBalance - $startBalance;
    }

    /**
     * Get account balance at specific date
     */
    protected function getAccountBalanceAtDate(Carbon $date, string $codeStart, string $codeEnd): float
    {
        return DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<=', $date)
            ->whereBetween('coa.code', [$codeStart, $codeEnd])
            ->sum(DB::raw('jel.debit - jel.credit'));
    }

    /**
     * Get cash from customers (direct method)
     */
    protected function getCashFromCustomers(ReportFilter $filter): float
    {
        // Revenue + decrease in AR (or - increase in AR)
        $revenue = $this->getAmountByType($filter, 'revenue');
        $arChange = $this->getAccountBalanceChange($filter, '1100', '1199');
        
        return $revenue - $arChange;
    }

    /**
     * Get cash to suppliers (direct method)
     */
    protected function getCashToSuppliers(ReportFilter $filter): float
    {
        // COGS + increase in inventory - increase in AP
        $cogs = $this->getAmountByCodeRange($filter, '5000', '5099');
        $inventoryChange = $this->getAccountBalanceChange($filter, '1200', '1299');
        $apChange = $this->getAccountBalanceChange($filter, '2000', '2099');
        
        return $cogs + $inventoryChange - $apChange;
    }

    /**
     * Get cash for operating expenses (direct method)
     */
    protected function getCashForOperatingExpenses(ReportFilter $filter): float
    {
        // Operating expenses (excluding depreciation)
        return $this->getAmountByCodeRange($filter, '5100', '5199');
    }

    /**
     * Get asset purchases
     */
    protected function getAssetPurchases(ReportFilter $filter): float
    {
        // Increase in fixed assets (debit to fixed asset accounts)
        return $this->getAccountBalanceChange($filter, '1300', '1899');
    }

    /**
     * Get asset sales
     */
    protected function getAssetSales(ReportFilter $filter): float
    {
        // Look for gains/losses on asset sales or cash from asset disposals
        return 0; // Simplified - would need specific tracking
    }

    /**
     * Get loan proceeds
     */
    protected function getLoanProceeds(ReportFilter $filter): float
    {
        // Increase in long-term liabilities
        $change = $this->getAccountBalanceChange($filter, '2100', '2999');
        return $change > 0 ? $change : 0;
    }

    /**
     * Get loan repayments
     */
    protected function getLoanRepayments(ReportFilter $filter): float
    {
        // Decrease in long-term liabilities
        $change = $this->getAccountBalanceChange($filter, '2100', '2999');
        return $change < 0 ? abs($change) : 0;
    }

    /**
     * Get owner contributions
     */
    protected function getOwnerContributions(ReportFilter $filter): float
    {
        // Increase in capital accounts
        $change = $this->getAccountBalanceChange($filter, '3000', '3099');
        return $change > 0 ? $change : 0;
    }

    /**
     * Get owner withdrawals
     */
    protected function getOwnerWithdrawals(ReportFilter $filter): float
    {
        // Drawings/withdrawals
        return $this->getAmountByCodeRange($filter, '3200', '3299');
    }

    /**
     * Get amount by account type
     */
    protected function getAmountByType(ReportFilter $filter, string $type): float
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->where('coa.type', $type)
            ->whereBetween('je.entry_date', [$filter->startDate, $filter->endDate]);

        if ($type === 'revenue') {
            return $query->sum('jel.credit');
        } else {
            return $query->sum('jel.debit');
        }
    }

    /**
     * Get amount by code range
     */
    protected function getAmountByCodeRange(ReportFilter $filter, string $codeStart, string $codeEnd): float
    {
        return DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$filter->startDate, $filter->endDate])
            ->whereBetween('coa.code', [$codeStart, $codeEnd])
            ->sum('jel.debit');
    }
}
