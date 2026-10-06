import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { DollarSign, TrendingUp, TrendingDown, RefreshCw, Calendar } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { MetricCard, DashboardGrid } from '@/components/dashboards';
import { LineChart } from '@/components/charts/LineChart';
import { BarChart } from '@/components/charts/BarChart';
import { PieChart } from '@/components/charts/PieChart';
import reportApi from '@/lib/reportApi';
import api from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import type { FinancialDashboardData } from '@/types/reports';

/**
 * Financial Dashboard Page
 * Comprehensive financial overview with P&L, Balance Sheet, and Cash Flow metrics
 */
export const FinancialDashboard: React.FC = () => {
  const [selectedPeriod, setSelectedPeriod] = useState<'month' | 'quarter' | 'year'>('month');

  const getProfitAmount = (value: unknown) => {
    if (typeof value === 'number') {
      return value;
    }

    if (value && typeof value === 'object' && 'amount' in value) {
      const amount = (value as { amount?: unknown }).amount;
      return typeof amount === 'number' ? amount : 0;
    }

    return 0;
  };

  const getExpenseTotal = (profitLoss: any) => {
    return (
      (profitLoss?.cost_of_goods_sold?.total ?? 0) +
      (profitLoss?.operating_expenses?.total ?? profitLoss?.expenses?.total ?? 0) +
      (profitLoss?.other_expenses?.total ?? 0)
    );
  };

  // Get date range presets
  const datePresets = reportApi.getDateRangePresets();
  const filters = {
    month: datePresets.thisMonth,
    quarter: datePresets.thisQuarter,
    year: datePresets.thisYear,
  };

  // Fetch dashboard data
  const { data, isLoading, error, refetch } = useQuery<FinancialDashboardData>({
    queryKey: ['financial-dashboard', selectedPeriod],
    queryFn: async () => {
      // Aggregate data from multiple endpoints
      const currentFilters = filters[selectedPeriod];
      const [profitLoss, balanceSheet, cashFlow, expensesByCategory] = await Promise.all([
        reportApi.getProfitLoss(currentFilters),
        reportApi.getBalanceSheet({ as_of_date: currentFilters.end_date }),
        reportApi.getCashFlow(currentFilters),
        reportApi.getExpensesByCategory(currentFilters),
      ]);

      // Get revenue vs expenses trends
      const salesTrends = await reportApi.getSalesTrends({
        ...currentFilters,
        interval: selectedPeriod === 'month' ? 'day' : selectedPeriod === 'quarter' ? 'week' : 'month',
      });

      const totalExpenses = getExpenseTotal(profitLoss);
      const netProfit = getProfitAmount(profitLoss.net_profit);

      // Issue #92: real budget data — the previous implementation fabricated
      // "budget" as actual x 1.1. Read the approved budget's variance for the
      // period's fiscal year and map budget lines by account name; when no
      // approved budget exists the chart shows its empty state instead of
      // synthetic numbers.
      const budgetByAccount: Record<string, number> = {};
      try {
        const fiscalYear = new Date(currentFilters.end_date).getFullYear();
        const budgetsResponse = await api.get('/accounting/budgets', {
          params: { fiscal_year: fiscalYear, status: 'approved', per_page: 1 },
        });
        const approvedBudget = budgetsResponse.data?.data?.[0];
        if (approvedBudget) {
          const varianceResponse = await api.get(`/accounting/budgets/${approvedBudget.id}/variance`);
          (varianceResponse.data?.data?.lines ?? []).forEach((line: any) => {
            if (line.account_name) {
              budgetByAccount[line.account_name] =
                (budgetByAccount[line.account_name] ?? 0) + Number(line.budget_amount ?? 0);
            }
          });
        }
      } catch {
        // Budget data unavailable — surface no synthetic fallback.
      }

      const hasApprovedBudget = Object.keys(budgetByAccount).length > 0;

      // Transform data into dashboard format
      const revenueVsExpenses = salesTrends.trends.map((trend) => {
        // Calculate expenses proportionally based on total
        const expenseRatio = profitLoss.revenue.total > 0 ? totalExpenses / profitLoss.revenue.total : 0;
        return {
          period: trend.period,
          revenue: trend.total_revenue,
          expenses: trend.total_revenue * expenseRatio,
        };
      });

      return {
        profit_loss_summary: {
          revenue: profitLoss.revenue.total,
          expenses: totalExpenses,
          net_profit: netProfit,
        },
        balance_sheet_summary: {
          total_assets: balanceSheet.total_assets,
          total_liabilities: balanceSheet.total_liabilities,
          total_equity: balanceSheet.total_equity,
        },
        cash_flow: [
          {
            period: 'Operating',
            amount: cashFlow.operating_activities.total,
          },
          {
            period: 'Investing',
            amount: cashFlow.investing_activities.total,
          },
          {
            period: 'Financing',
            amount: cashFlow.financing_activities.total,
          },
        ],
        expense_breakdown: expensesByCategory.categories.map((cat) => ({
          category: cat.account_name,
          amount: cat.total_amount,
        })),
        revenue_vs_expenses: revenueVsExpenses,
        budget_vs_actual: hasApprovedBudget
          ? expensesByCategory.categories.slice(0, 8).map((cat) => ({
              category: cat.account_name,
              budget: budgetByAccount[cat.account_name] ?? 0,
              actual: cat.total_amount,
            }))
          : [],
      };
    },
  });

  const handleRefresh = () => {
    refetch();
  };

  if (error) {
    return (
      <div className="p-6">
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <h3 className="text-red-800 font-medium">Error Loading Dashboard</h3>
          <p className="text-red-600 text-sm mt-1">
            {error instanceof Error ? error.message : 'Failed to load financial dashboard data'}
          </p>
        </div>
      </div>
    );
  }

  const profitMargin = data
    ? ((data.profit_loss_summary.net_profit / data.profit_loss_summary.revenue) * 100).toFixed(1)
    : '0';

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Financial Dashboard</h1>
          <p className="text-muted-foreground mt-1">
            Comprehensive financial overview and key metrics
          </p>
        </div>
        <button
          onClick={handleRefresh}
          className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
          disabled={isLoading}
        >
          <RefreshCw className={`h-4 w-4 ${isLoading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      {/* Period Selector */}
      <div className="flex gap-2">
        {(['month', 'quarter', 'year'] as const).map((period) => (
          <button
            key={period}
            onClick={() => setSelectedPeriod(period)}
            className={`px-4 py-2 rounded-lg font-medium transition-colors ${
              selectedPeriod === period
                ? 'bg-blue-600 text-white'
                : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700'
            }`}
          >
            {period === 'month' ? 'This Month' : period === 'quarter' ? 'This Quarter' : 'This Year'}
          </button>
        ))}
      </div>

      {/* P&L Summary Cards */}
      <div>
        <h2 className="text-xl font-semibold mb-4">Profit & Loss Summary</h2>
        <DashboardGrid columns={4} gap="md">
          <MetricCard
            label="Total Revenue"
            value={isLoading ? '...' : formatCurrency(data?.profit_loss_summary.revenue || 0)}
            icon={DollarSign}
            iconColor="text-green-600"
            loading={isLoading}
          />
          <MetricCard
            label="Total Expenses"
            value={isLoading ? '...' : formatCurrency(data?.profit_loss_summary.expenses || 0)}
            icon={TrendingDown}
            iconColor="text-red-600"
            loading={isLoading}
          />
          <MetricCard
            label="Net Profit"
            value={isLoading ? '...' : formatCurrency(data?.profit_loss_summary.net_profit || 0)}
            icon={TrendingUp}
            iconColor={
              data && data.profit_loss_summary.net_profit >= 0
                ? 'text-green-600'
                : 'text-red-600'
            }
            loading={isLoading}
          />
          <MetricCard
            label="Profit Margin"
            value={isLoading ? '...' : `${profitMargin}%`}
            icon={TrendingUp}
            iconColor={parseFloat(profitMargin) >= 0 ? 'text-green-600' : 'text-red-600'}
            loading={isLoading}
          />
        </DashboardGrid>
      </div>

      {/* Balance Sheet Summary */}
      <div>
        <h2 className="text-xl font-semibold mb-4">Balance Sheet Summary</h2>
        <DashboardGrid columns={3} gap="md">
          <MetricCard
            label="Total Assets"
            value={isLoading ? '...' : formatCurrency(data?.balance_sheet_summary.total_assets || 0)}
            icon={DollarSign}
            iconColor="text-blue-600"
            loading={isLoading}
          />
          <MetricCard
            label="Total Liabilities"
            value={
              isLoading ? '...' : formatCurrency(data?.balance_sheet_summary.total_liabilities || 0)
            }
            icon={TrendingDown}
            iconColor="text-orange-600"
            loading={isLoading}
          />
          <MetricCard
            label="Total Equity"
            value={isLoading ? '...' : formatCurrency(data?.balance_sheet_summary.total_equity || 0)}
            icon={TrendingUp}
            iconColor="text-purple-600"
            loading={isLoading}
          />
        </DashboardGrid>
      </div>

      {/* Charts Row 1 */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Cash Flow Chart */}
        <Card>
          <CardHeader>
            <CardTitle>Cash Flow by Activity</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="h-[300px] flex items-center justify-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
              </div>
            ) : data && data.cash_flow.length > 0 ? (
              <BarChart
                data={data.cash_flow}
                xKey="period"
                yKeys={['amount']}
                formatYAxis="currency"
                height={300}
                colors={['#3b82f6']}
              />
            ) : (
              <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                No cash flow data available
              </div>
            )}
          </CardContent>
        </Card>

        {/* Expense Breakdown Chart */}
        <Card>
          <CardHeader>
            <CardTitle>Expense Breakdown</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="h-[300px] flex items-center justify-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
              </div>
            ) : data && data.expense_breakdown.length > 0 ? (
              <PieChart
                data={data.expense_breakdown}
                nameKey="category"
                valueKey="amount"
                formatValue="currency"
                height={300}
              />
            ) : (
              <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                No expense data available
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Charts Row 2 */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Revenue vs Expenses Chart */}
        <Card>
          <CardHeader>
            <CardTitle>Revenue vs Expenses</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="h-[300px] flex items-center justify-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
              </div>
            ) : data && data.revenue_vs_expenses.length > 0 ? (
              <LineChart
                data={data.revenue_vs_expenses}
                xKey="period"
                yKeys={['revenue', 'expenses']}
                formatYAxis="currency"
                height={300}
                colors={['#10b981', '#ef4444']}
              />
            ) : (
              <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                No trend data available
              </div>
            )}
          </CardContent>
        </Card>

        {/* Budget vs Actual Chart */}
        <Card>
          <CardHeader>
            <CardTitle>Budget vs Actual (Top Categories)</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="h-[300px] flex items-center justify-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
              </div>
            ) : data && data.budget_vs_actual.length > 0 ? (
              <BarChart
                data={data.budget_vs_actual}
                xKey="category"
                yKeys={['budget', 'actual']}
                formatYAxis="currency"
                height={300}
                horizontal={true}
                colors={['#94a3b8', '#3b82f6']}
              />
            ) : (
              <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                No budget data available
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Financial Insights */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Calendar className="h-5 w-5 text-blue-600" />
            Financial Insights
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-3">
              {[1, 2, 3].map((i) => (
                <div key={i} className="h-4 w-full bg-gray-200 animate-pulse rounded" />
              ))}
            </div>
          ) : data ? (
            <div className="space-y-4">
              <div className="flex items-start gap-3 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                <TrendingUp className="h-5 w-5 text-blue-600 mt-0.5" />
                <div>
                  <p className="font-medium text-gray-900 dark:text-white">
                    Profit Margin: {profitMargin}%
                  </p>
                  <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
                    {parseFloat(profitMargin) >= 15
                      ? 'Excellent profit margin. Your business is performing well.'
                      : parseFloat(profitMargin) >= 10
                      ? 'Good profit margin. Consider optimizing expenses for better results.'
                      : parseFloat(profitMargin) >= 5
                      ? 'Moderate profit margin. Review pricing and cost structure.'
                      : 'Low profit margin. Immediate attention needed to improve profitability.'}
                  </p>
                </div>
              </div>

              <div className="flex items-start gap-3 p-4 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-lg">
                <DollarSign className="h-5 w-5 text-purple-600 mt-0.5" />
                <div>
                  <p className="font-medium text-gray-900 dark:text-white">
                    Equity Ratio:{' '}
                    {(
                      (data.balance_sheet_summary.total_equity /
                        data.balance_sheet_summary.total_assets) *
                      100
                    ).toFixed(1)}
                    %
                  </p>
                  <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
                    {data.balance_sheet_summary.total_equity /
                      data.balance_sheet_summary.total_assets >=
                    0.5
                      ? 'Strong equity position. Your business has a healthy financial foundation.'
                      : 'Consider reducing liabilities or increasing equity for better financial stability.'}
                  </p>
                </div>
              </div>

              <div className="flex items-start gap-3 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                <TrendingUp className="h-5 w-5 text-green-600 mt-0.5" />
                <div>
                  <p className="font-medium text-gray-900 dark:text-white">
                    Operating Cash Flow:{' '}
                    {formatCurrency(
                      data.cash_flow.find((cf) => cf.period === 'Operating')?.amount || 0
                    )}
                  </p>
                  <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
                    {(data.cash_flow.find((cf) => cf.period === 'Operating')?.amount || 0) > 0
                      ? 'Positive operating cash flow indicates healthy business operations.'
                      : 'Negative operating cash flow. Review operational efficiency and receivables.'}
                  </p>
                </div>
              </div>
            </div>
          ) : (
            <div className="h-32 flex items-center justify-center text-muted-foreground">
              No insights available
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default FinancialDashboard;
