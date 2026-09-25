import React, { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { BarChart3, DollarSign, LineChart as LineChartIcon, PieChart as PieChartIcon, Package, TrendingUp, Wallet } from 'lucide-react';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { KPICard, DashboardGrid } from '@/components/dashboards';
import { LineChart } from '@/components/charts/LineChart';
import { PieChart } from '@/components/charts/PieChart';
import { BarChart } from '@/components/charts/BarChart';
import { formatCurrency } from '@/lib/utils';
import reportApi from '@/lib/reportApi';
import type { ExecutiveDashboardData } from '@/types/reports';

const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

interface ChartCardProps {
  title: string;
  description: string;
  loading: boolean;
  empty: boolean;
  children: React.ReactNode;
  icon?: React.ComponentType<{ className?: string }>;
  iconClassName?: string;
}

/** Consistent frame for every chart: title + description, one loading state, one empty state. */
const ChartCard: React.FC<ChartCardProps> = ({ title, description, loading, empty, children, icon: ChartIcon, iconClassName }) => (
  <Card className="rounded-xl shadow-sm">
    <CardHeader className="flex flex-row items-center gap-3 space-y-0 border-b pb-4">
      {ChartIcon && (
        <div className={`grid h-9 w-9 shrink-0 place-items-center rounded-xl ${iconClassName ?? 'bg-slate-100 text-slate-600'}`}>
          <ChartIcon className="h-4 w-4" />
        </div>
      )}
      <div className="min-w-0">
        <CardTitle className="text-base">{title}</CardTitle>
        <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>
      </div>
    </CardHeader>
    <CardContent className="pt-5">
      {loading ? (
        <div className="flex h-[300px] items-center justify-center">
          <Icon icon="eos-icons:loading" className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : empty ? (
        <div className="flex h-[300px] flex-col items-center justify-center gap-2 text-muted-foreground">
          <Icon icon="solar:chart-2-linear" className="h-8 w-8" />
          <p className="text-sm">No data for this period</p>
        </div>
      ) : (
        children
      )}
    </CardContent>
  </Card>
);

/**
 * Executive Dashboard
 * High-level overview of business performance with key metrics and charts.
 */
export const ExecutiveDashboard: React.FC = () => {
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [lastRefresh, setLastRefresh] = useState(new Date());

  // Initialize with the current month
  const now = new Date();
  const [selectedMonth, setSelectedMonth] = useState(now.getMonth()); // 0-11
  const [selectedYear, setSelectedYear] = useState(now.getFullYear());

  const getDateFilters = () => {
    const startDate = new Date(selectedYear, selectedMonth, 1);
    const endDate = new Date(selectedYear, selectedMonth + 1, 0); // Last day of month

    return {
      start_date: startDate.toISOString().split('T')[0],
      end_date: endDate.toISOString().split('T')[0],
    };
  };

  const currentMonthFilters = getDateFilters();

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

  // Fetch dashboard data
  const { data, isLoading, error, refetch } = useQuery<ExecutiveDashboardData>({
    queryKey: ['executive-dashboard', selectedMonth, selectedYear],
    queryFn: async () => {
      try {
        // No dedicated dashboard endpoint yet — aggregate from the report endpoints.
        const [profitLoss, salesTrends, salesByCategory, expensesByCategory, salesByProduct] =
          await Promise.all([
            reportApi.getProfitLoss(currentMonthFilters).catch(() => null),
            reportApi.getSalesTrends({ ...currentMonthFilters, interval: 'day' }).catch(() => null),
            reportApi.getSalesByCategory(currentMonthFilters).catch(() => null),
            reportApi.getExpensesByCategory(currentMonthFilters).catch(() => null),
            reportApi.getSalesByProduct(currentMonthFilters).catch(() => null),
          ]);

        // Safely extract data with proper null checks
        const trends = Array.isArray(salesTrends?.trends) ? salesTrends.trends : [];
        const categories = Array.isArray(salesByCategory?.categories) ? salesByCategory.categories : [];
        const expenses = Array.isArray(expensesByCategory?.categories) ? expensesByCategory.categories : [];
        const products = Array.isArray(salesByProduct?.products) ? salesByProduct.products : [];
        const netProfit = getProfitAmount(profitLoss?.net_profit);
        const totalExpenses = getExpenseTotal(profitLoss);

        return {
          kpis: {
            revenue: {
              current: profitLoss?.revenue?.total ?? 0,
              previous: profitLoss?.comparison?.revenue_change
                ? (profitLoss?.revenue?.total ?? 0) / (1 + profitLoss.comparison.revenue_change / 100)
                : 0,
              change_percentage: profitLoss?.comparison?.revenue_change ?? 0,
            },
            profit: {
              current: netProfit,
              previous: profitLoss?.comparison?.profit_change
                ? netProfit / (1 + profitLoss.comparison.profit_change / 100)
                : 0,
              change_percentage: profitLoss?.comparison?.profit_change ?? 0,
            },
            cash: {
              current: (profitLoss?.revenue?.total ?? 0) - totalExpenses,
              previous: 0,
              change_percentage: 0,
            },
          },
          sales_trends: trends
            .filter((trend) => trend && typeof trend === 'object' && (trend.total_sales ?? trend.revenue) != null)
            .map((trend) => ({
              period: String(trend.period || ''),
              revenue: Number(trend.total_sales ?? trend.revenue) || 0,
            })),
          revenue_by_category: categories
            .filter((cat) => cat && typeof cat === 'object' && (cat.total_sales ?? cat.revenue) != null)
            .map((cat) => ({
              category: String(cat.category_name || 'Uncategorized'),
              amount: Number(cat.total_sales ?? cat.revenue) || 0,
            })),
          expense_breakdown: expenses
            .filter((cat) => cat && typeof cat === 'object' && cat.total_amount != null)
            .map((cat) => ({
              category: String(cat.account_name || 'Unknown'),
              amount: Number(cat.total_amount) || 0,
            })),
          top_products: products
            .filter((prod) => prod && typeof prod === 'object' && (prod.total_revenue ?? prod.total_sales) != null)
            .slice(0, 5)
            .map((prod) => ({
              name: String(prod.name || prod.product_name || prod.sku || 'Unassigned'),
              sku: prod.sku ? String(prod.sku) : undefined,
              quantity: Number(prod.quantity_sold ?? prod.total_quantity) || 0,
              revenue: Number(prod.total_revenue ?? prod.total_sales) || 0,
            })),
        };
      } catch (error) {
        console.error('Dashboard data fetch error:', error);
        // Return empty dashboard data structure
        return {
          kpis: {
            revenue: { current: 0, previous: 0, change_percentage: 0 },
            profit: { current: 0, previous: 0, change_percentage: 0 },
            cash: { current: 0, previous: 0, change_percentage: 0 },
          },
          sales_trends: [],
          revenue_by_category: [],
          expense_breakdown: [],
          top_products: [],
        };
      }
    },
    refetchInterval: autoRefresh ? 5 * 60 * 1000 : false, // 5 minutes
  });

  // Update last refresh time when data changes
  useEffect(() => {
    if (data) {
      setLastRefresh(new Date());
    }
  }, [data]);

  const handleManualRefresh = () => {
    refetch();
  };

  // Generate year options (current year and 2 years back)
  const currentYear = new Date().getFullYear();
  const yearOptions = [currentYear, currentYear - 1, currentYear - 2];

  if (error) {
    return (
      <div className="p-6">
        <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-4">
          <h3 className="font-medium text-destructive">Error loading dashboard</h3>
          <p className="mt-1 text-sm text-destructive/90">
            {error instanceof Error ? error.message : 'Failed to load dashboard data'}
          </p>
        </div>
      </div>
    );
  }

  const periodLabel = `${MONTH_NAMES[selectedMonth]} ${selectedYear}`;
  const salesTrends = data?.sales_trends ?? [];
  const revenueByCategory = data?.revenue_by_category ?? [];
  const expenseBreakdown = data?.expense_breakdown ?? [];
  const topProducts = data?.top_products ?? [];
  // A series with rows but only zero values renders as an empty plot — treat
  // it as "no data" so the card shows the empty state instead of bare axes.
  const hasTrends = salesTrends.some((row: any) => Number(row?.revenue ?? 0) !== 0);
  const hasCategories = revenueByCategory.some((row: any) => Number(row?.amount ?? 0) !== 0);
  const hasExpenses = expenseBreakdown.some((row: any) => Number(row?.amount ?? 0) !== 0);
  const hasProducts = topProducts.length > 0;

  return (
    <div className="space-y-6 p-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Executive Dashboard</h1>
          <p className="mt-1 text-muted-foreground">
            Revenue, profitability and cash at a glance
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Select value={String(selectedMonth)} onValueChange={(value) => setSelectedMonth(Number(value))}>
            <SelectTrigger className="h-8 w-32 rounded-full text-xs" aria-label="Month">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {MONTH_NAMES.map((month, index) => (
                <SelectItem key={month} value={String(index)}>
                  {month}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select value={String(selectedYear)} onValueChange={(value) => setSelectedYear(Number(value))}>
            <SelectTrigger className="h-8 w-24 rounded-full text-xs" aria-label="Year">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {yearOptions.map((year) => (
                <SelectItem key={year} value={String(year)}>
                  {year}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Button
            variant={autoRefresh ? 'secondary' : 'outline'}
            className="h-8 rounded-full px-3 text-xs"
            onClick={() => setAutoRefresh((value) => !value)}
          >
            <Icon
              icon={autoRefresh ? 'solar:refresh-circle-bold' : 'solar:refresh-circle-linear'}
              className="mr-1.5 h-3.5 w-3.5"
            />
            Auto-refresh {autoRefresh ? 'on' : 'off'}
          </Button>

          <HeaderActionButton
            variant="outline"
            icon="solar:refresh-bold"
            loading={isLoading}
            disabled={isLoading}
            onClick={handleManualRefresh}
          >
            Refresh
          </HeaderActionButton>
        </div>
      </div>

      <p className="-mt-4 text-right text-xs text-muted-foreground">
        {periodLabel} · last updated {lastRefresh.toLocaleTimeString()}
      </p>

      {/* KPI Cards */}
      <DashboardGrid columns={3} gap="md">
        <KPICard
          title="Total Revenue"
          value={isLoading ? '—' : formatCurrency(data?.kpis.revenue.current || 0)}
          icon={DollarSign}
          trend={
            data
              ? {
                  value: data.kpis.revenue.change_percentage,
                  isPositive: data.kpis.revenue.change_percentage >= 0,
                  label: 'vs last period',
                }
              : undefined
          }
          subtitle={periodLabel}
          loading={isLoading}
        />
        <KPICard
          title="Net Profit"
          value={isLoading ? '—' : formatCurrency(data?.kpis.profit.current || 0)}
          icon={TrendingUp}
          trend={
            data
              ? {
                  value: data.kpis.profit.change_percentage,
                  isPositive: data.kpis.profit.change_percentage >= 0,
                  label: 'vs last period',
                }
              : undefined
          }
          subtitle={periodLabel}
          loading={isLoading}
        />
        <KPICard
          title="Cash Position"
          value={isLoading ? '—' : formatCurrency(data?.kpis.cash.current || 0)}
          icon={Wallet}
          trend={
            data
              ? {
                  value: data.kpis.cash.change_percentage,
                  isPositive: data.kpis.cash.change_percentage >= 0,
                  label: 'vs last period',
                }
              : undefined
          }
          subtitle="Revenue less expenses"
          loading={isLoading}
        />
      </DashboardGrid>

      {/* Charts */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <ChartCard
          title="Sales Trends"
          description={`Daily revenue · ${periodLabel}`}
          loading={isLoading}
          empty={!hasTrends}
          icon={LineChartIcon}
          iconClassName="bg-indigo-50 text-indigo-600"
        >
          <LineChart
            data={salesTrends}
            xKey="period"
            yKeys={['revenue']}
            formatYAxis="currency"
            height={300}
          />
        </ChartCard>

        <ChartCard
          title="Revenue by Category"
          description={`Where the revenue came from · ${periodLabel}`}
          loading={isLoading}
          empty={!hasCategories}
          icon={PieChartIcon}
          iconClassName="bg-violet-50 text-violet-600"
        >
          <PieChart
            data={revenueByCategory}
            nameKey="category"
            valueKey="amount"
            formatValue="currency"
            height={300}
            innerRadius={60}
          />
        </ChartCard>

        <ChartCard
          title="Expense Breakdown"
          description={`Operating costs by account · ${periodLabel}`}
          loading={isLoading}
          empty={!hasExpenses}
          icon={BarChart3}
          iconClassName="bg-rose-50 text-rose-600"
        >
          <BarChart
            data={expenseBreakdown}
            xKey="category"
            yKeys={['amount']}
            formatYAxis="currency"
            height={300}
            colors={['#f43f5e']}
            horizontal
            truncateLabels={30}
          />
        </ChartCard>

        <ChartCard
          title="Top Products"
          description={`Best sellers by revenue · ${periodLabel}`}
          loading={isLoading}
          empty={!hasProducts}
          icon={Package}
          iconClassName="bg-emerald-50 text-emerald-600"
        >
          <div className="space-y-3">
            {topProducts
              .filter((product) => product && typeof product === 'object' && product.revenue != null)
              .map((product, index) => {
                const top = Number(topProducts[0]?.revenue) || 0;
                const share = top > 0 ? (Number(product.revenue) / top) * 100 : 0;

                return (
                  <div key={`${product.name}-${product.sku ?? index}`} className="space-y-1.5">
                    <div className="flex items-baseline justify-between gap-3">
                      <div className="flex min-w-0 items-baseline gap-2">
                        <span className="w-4 shrink-0 text-xs font-semibold text-muted-foreground">
                          {index + 1}
                        </span>
                        <span className="truncate text-sm font-medium">{product.name || 'Unassigned'}</span>
                        {product.sku && (
                          <span className="shrink-0 font-mono text-[11px] text-muted-foreground">
                            {product.sku}
                          </span>
                        )}
                      </div>
                      <span className="shrink-0 text-sm font-semibold tabular-nums">
                        {formatCurrency(Number(product.revenue || 0))}
                      </span>
                    </div>

                    <div className="flex items-center gap-3 pl-6">
                      <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                        <div
                          className="h-full rounded-full bg-primary"
                          style={{ width: `${Math.max(share, 2)}%` }}
                        />
                      </div>
                      {typeof product.quantity === 'number' && (
                        <span className="shrink-0 text-right text-[11px] tabular-nums text-muted-foreground">
                          {product.quantity.toLocaleString()} sold
                        </span>
                      )}
                    </div>
                  </div>
                );
              })}
          </div>
        </ChartCard>
      </div>
    </div>
  );
};

export default ExecutiveDashboard;
