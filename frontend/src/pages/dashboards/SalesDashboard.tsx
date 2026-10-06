import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { LineChart } from '@/components/charts/LineChart';
import { BarChart } from '@/components/charts/BarChart';
import { PieChart } from '@/components/charts/PieChart';
import reportApi, { formatLocalDate } from '@/lib/reportApi';
import { mapCategoryRevenueSlices, mapRevenueTrendPoints } from '@/lib/salesDashboardMapping';
import { formatCurrency } from '@/lib/utils';
import type { SalesDashboardData } from '@/types/reports';

/**
 * Sales Dashboard Page
 * Comprehensive sales analytics and metrics
 */
export const SalesDashboard: React.FC = () => {
  const [selectedPeriod, setSelectedPeriod] = useState<'today' | 'week' | 'month' | 'year'>(
    'month'
  );
  
  // Add month/year selectors
  const now = new Date();
  const [selectedMonth, setSelectedMonth] = useState(now.getMonth()); // 0-11
  const [selectedYear, setSelectedYear] = useState(now.getFullYear());

  // Calculate date range based on selected period and month/year
  const getDateFilters = () => {
    if (selectedPeriod === 'month') {
      // Use custom month/year selection
      const startDate = new Date(selectedYear, selectedMonth, 1);
      const endDate = new Date(selectedYear, selectedMonth + 1, 0);
      return {
        start_date: formatLocalDate(startDate),
        end_date: formatLocalDate(endDate),
      };
    }
    
    // For other periods, use presets based on current date
    const datePresets = reportApi.getDateRangePresets();
    return {
      today: datePresets.today,
      week: datePresets.thisWeek,
      month: datePresets.thisMonth,
      year: datePresets.thisYear,
    }[selectedPeriod];
  };

  const currentFilters = getDateFilters();

  // Fetch all time period metrics for the summary cards
  const { data: allMetrics, isLoading: allMetricsLoading, refetch: refetchAllMetrics } = useQuery({
    queryKey: ['sales-metrics-all'],
    queryFn: async () => {
      const now = new Date();
      const datePresets = reportApi.getDateRangePresets();
      
      // Fetch metrics for all time periods
      const [todayData, weekData, monthData, yearData] = await Promise.all([
        reportApi.getSalesByProduct(datePresets.today),
        reportApi.getSalesByProduct(datePresets.thisWeek),
        reportApi.getSalesByProduct(datePresets.thisMonth),
        reportApi.getSalesByProduct(datePresets.thisYear),
      ]);
      
      // Analytics API returns `total_revenue` per product (see
      // SalesAnalyticsService::getSalesByProduct) — never `total_sales`.
      const sumRevenue = (payload: { products?: Array<{ total_revenue?: number }> }) =>
        (payload?.products ?? []).reduce((sum, p) => sum + (Number(p.total_revenue) || 0), 0);

      return {
        today: sumRevenue(todayData),
        week: sumRevenue(weekData),
        month: sumRevenue(monthData),
        year: sumRevenue(yearData),
      };
    },
    staleTime: 5 * 60 * 1000, // Cache for 5 minutes
  });

  // Fetch dashboard data for selected period
  // #351 (S3): the tile values come straight from the allMetrics query below.
  // Embedding them in this query's cached result captured allMetrics as
  // undefined when the two queries ran in parallel, so the tiles were stored
  // as 0 and never recomputed when allMetrics arrived.
  const { data, isLoading, error, refetch } = useQuery<Omit<SalesDashboardData, 'metrics'>>({
    queryKey: ['sales-dashboard', selectedPeriod, selectedMonth, selectedYear],
    queryFn: async () => {
      // Aggregate data from multiple endpoints
      const [salesTrends, salesByProduct, salesByCustomer, salesByCategory] = await Promise.all([
        reportApi.getSalesTrends({ ...currentFilters, interval: 'day' }),
        reportApi.getSalesByProduct(currentFilters),
        reportApi.getSalesByCustomer(currentFilters),
        reportApi.getSalesByCategory(currentFilters),
      ]);

      return {
        sales_by_product: (salesByProduct?.products ?? []).map((p) => ({
          product: p.name || 'Unknown',
          amount: Number(p.total_revenue) || 0,
        })),
        sales_by_customer: (salesByCustomer?.customers ?? []).map((c) => ({
          customer: c.customer_name || 'Walk-in customer',
          amount: Number(c.total_revenue) || 0,
        })),
        sales_trends: mapRevenueTrendPoints(salesTrends?.trends),
        top_selling_products: (salesByProduct?.products ?? [])
          .slice(0, 10)
          .map((p) => ({
            name: p.name || 'Unknown',
            quantity: Number(p.total_quantity) || 0,
          })),
        sales_by_category: mapCategoryRevenueSlices(salesByCategory?.categories),
      };
    },
  });

  // #351 (S3): tiles are loading until BOTH queries have data — otherwise a
  // not-yet-arrived metrics query would render 0.00.
  const metricsLoading = isLoading || allMetricsLoading;

  const handleRefresh = () => {
    // Refresh must re-run the metrics query too — it used to refresh only the
    // dashboard query, leaving stale tiles in place.
    refetch();
    refetchAllMetrics();
  };

  if (error) {
    return (
      <div className="p-6">
        <Card className="border-rose-200 bg-rose-50 dark:border-rose-900 dark:bg-rose-950/40">
          <CardContent className="p-4">
            <div className="flex items-start gap-3">
              <Icon icon="solar:danger-triangle-bold-duotone" className="mt-0.5 h-5 w-5 text-rose-600" />
              <div>
                <h3 className="text-sm font-semibold text-rose-900 dark:text-rose-200">
                  Error loading dashboard
                </h3>
                <p className="mt-1 text-sm text-rose-700 dark:text-rose-300">
                  {error instanceof Error ? error.message : 'Failed to load sales dashboard data'}
                </p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  const PERIODS = ['today', 'week', 'month', 'year'] as const;
  const periodSub: Record<string, string> = {
    today: 'Today',
    week: 'This week',
    month: 'Selected month',
    year: 'This year',
  };

  const MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ];

  const KPIS = [
    { label: "Today's sales", value: allMetrics?.today, icon: 'solar:calendar-bold-duotone', tile: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300', sub: 'Today' },
    { label: 'This week', value: allMetrics?.week, icon: 'solar:calendar-mark-bold-duotone', tile: 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300', sub: 'This week' },
    { label: 'This month', value: allMetrics?.month, icon: 'solar:chart-2-bold-duotone', tile: 'bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300', sub: 'This month' },
    { label: 'This year', value: allMetrics?.year, icon: 'solar:graph-up-bold-duotone', tile: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300', sub: 'This year' },
  ];

  return (
    <div className="space-y-4 p-6">
      {/* Single toolbar row: title · period · month/year · refresh */}
      <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
        <div className="min-w-0">
          <h1 className="text-3xl font-bold tracking-tight">Sales Dashboard</h1>
          <p className="text-sm text-muted-foreground">
            Net revenue, tax excluded, in the base currency: posted invoices less posted credit
            notes, plus POS sales net of returns, plus not-yet-invoiced orders (bookings).
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <div className="inline-flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-700 dark:bg-slate-900">
            {PERIODS.map((period) => (
              <button
                key={period}
                type="button"
                onClick={() => setSelectedPeriod(period)}
                className={`rounded-md px-3 py-1.5 text-xs font-medium capitalize transition ${
                  selectedPeriod === period
                    ? 'bg-slate-900 text-white shadow-sm dark:bg-slate-100 dark:text-slate-900'
                    : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100'
                }`}
              >
                {period}
              </button>
            ))}
          </div>

          {selectedPeriod === 'month' && (
            <>
              <Select
                value={String(selectedMonth)}
                onValueChange={(v) => setSelectedMonth(parseInt(v, 10))}
              >
                <SelectTrigger className="h-9 w-[140px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {MONTHS.map((month, index) => (
                    <SelectItem key={index} value={String(index)}>
                      {month}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              <Select
                value={String(selectedYear)}
                onValueChange={(v) => setSelectedYear(parseInt(v, 10))}
              >
                <SelectTrigger className="h-9 w-[110px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {[now.getFullYear(), now.getFullYear() - 1, now.getFullYear() - 2].map((year) => (
                    <SelectItem key={year} value={String(year)}>
                      {year}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </>
          )}

          <Button variant="outline" size="sm" onClick={handleRefresh} disabled={isLoading}>
            <Icon
              icon="solar:refresh-circle-bold-duotone"
              className={`mr-2 h-4 w-4 ${isLoading ? 'animate-spin' : ''}`}
            />
            Refresh
          </Button>
        </div>
      </div>

      {/* KPI tiles */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {KPIS.map((kpi) => (
          <Card key={kpi.label} className="rounded-xl border-slate-200/80 shadow-sm transition hover:shadow-md">
            <CardContent className="p-5">
              <div className={`flex h-10 w-10 items-center justify-center rounded-lg ${kpi.tile}`}>
                <Icon icon={kpi.icon} className="h-5 w-5" />
              </div>
              <p className="mt-4 text-[11px] font-medium uppercase tracking-wide text-slate-500">
                {kpi.label}
              </p>
              <p className="mt-1 text-[26px] font-semibold leading-tight tracking-tight tabular-nums text-slate-900 dark:text-slate-100">
                {metricsLoading ? '…' : formatCurrency(kpi.value ?? 0)}
              </p>
              <p className="mt-1 text-xs text-slate-500">{kpi.sub}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Charts row 1 */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card className="overflow-hidden">
          <CardHeader className="border-b bg-muted/20">
            <CardTitle className="text-base">Sales trends</CardTitle>
            <CardDescription>
              Net revenue per day — tax excluded, document discounts subtracted, credit notes
              netted, in the base currency. Includes un-invoiced orders.
            </CardDescription>
          </CardHeader>
          <CardContent className="pt-6">
            {isLoading ? (
              <ChartSkeleton height={280} />
            ) : data && data.sales_trends.length > 0 ? (
              <LineChart
                data={data.sales_trends}
                xKey="period"
                yKeys={['amount']}
                formatYAxis="currency"
                height={280}
                colors={['#3b82f6']}
              />
            ) : (
              <ChartEmpty height={280} label="No sales trends for this period" />
            )}
          </CardContent>
        </Card>

        <Card className="overflow-hidden">
          <CardHeader className="border-b bg-muted/20">
            <CardTitle className="text-base">Sales by category</CardTitle>
            <CardDescription>Net revenue split across product categories (tax excluded, base currency).</CardDescription>
          </CardHeader>
          <CardContent className="pt-6">
            {isLoading ? (
              <ChartSkeleton height={280} />
            ) : data && data.sales_by_category.length > 0 ? (
              <PieChart
                data={data.sales_by_category}
                nameKey="category"
                valueKey="amount"
                formatValue="currency"
                height={280}
              />
            ) : (
              <ChartEmpty height={280} label="No category data for this period" />
            )}
          </CardContent>
        </Card>
      </div>

      {/* Charts row 2 */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card className="overflow-hidden">
          <CardHeader className="border-b bg-muted/20">
            <CardTitle className="text-base">Sales by product (top 10)</CardTitle>
            <CardDescription>Best performing products by net revenue (tax excluded, base currency).</CardDescription>
          </CardHeader>
          <CardContent className="pt-6">
            {isLoading ? (
              <ChartSkeleton height={330} />
            ) : data && data.sales_by_product.length > 0 ? (
              <BarChart
                data={data.sales_by_product.slice(0, 10)}
                xKey="product"
                yKeys={['amount']}
                formatYAxis="currency"
                height={330}
                horizontal={true}
                colors={['#10b981']}
              />
            ) : (
              <ChartEmpty height={330} label="No product data for this period" />
            )}
          </CardContent>
        </Card>

        <Card className="overflow-hidden">
          <CardHeader className="border-b bg-muted/20">
            <CardTitle className="text-base">Top customers</CardTitle>
            <CardDescription>Highest value accounts by net revenue in the selected window.</CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            {isLoading ? (
              <div className="space-y-3 p-6">
                {[1, 2, 3, 4, 5].map((i) => (
                  <div key={i} className="flex items-center justify-between">
                    <div className="h-4 w-40 animate-pulse rounded bg-muted" />
                    <div className="h-4 w-24 animate-pulse rounded bg-muted" />
                  </div>
                ))}
              </div>
            ) : data && data.sales_by_customer.length > 0 ? (
              <div className="max-h-[330px] divide-y overflow-y-auto">
                {data.sales_by_customer.slice(0, 15).map((customer, index) => (
                  <div
                    key={index}
                    className="flex items-center justify-between px-6 py-2.5 transition-colors hover:bg-muted/40"
                  >
                    <div className="flex min-w-0 items-center gap-3">
                      <div
                        className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                          index === 0
                            ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'
                            : index === 1
                              ? 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200'
                              : index === 2
                                ? 'bg-orange-100 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300'
                                : 'bg-muted text-muted-foreground'
                        }`}
                      >
                        {index + 1}
                      </div>
                      <span className="truncate text-sm font-medium">{customer.customer}</span>
                    </div>
                    <span className="shrink-0 text-sm font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                      {formatCurrency(customer.amount)}
                    </span>
                  </div>
                ))}
              </div>
            ) : (
              <ChartEmpty height={330} label="No customer data for this period" />
            )}
          </CardContent>
        </Card>
      </div>

      {/* Top selling products */}
      <Card className="overflow-hidden">
        <CardHeader className="border-b bg-muted/20">
          <CardTitle className="text-base">Top selling products by quantity</CardTitle>
          <CardDescription>Units sold, ranked across all products.</CardDescription>
        </CardHeader>
        <CardContent className="p-6">
          {isLoading ? (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-5">
              {[1, 2, 3, 4, 5].map((i) => (
                <div key={i} className="rounded-xl border p-4">
                  <div className="mb-2 h-4 w-24 animate-pulse rounded bg-muted" />
                  <div className="h-6 w-16 animate-pulse rounded bg-muted" />
                </div>
              ))}
            </div>
          ) : data && data.top_selling_products.length > 0 ? (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-5">
              {data.top_selling_products.map((product, index) => (
                <div
                  key={index}
                  className="rounded-xl border p-4 transition-all hover:-translate-y-0.5 hover:shadow-md"
                >
                  <div className="flex items-center justify-between">
                    <span
                      className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold ${
                        index === 0
                          ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'
                          : index === 1
                            ? 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200'
                            : index === 2
                              ? 'bg-orange-100 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300'
                              : 'bg-muted text-muted-foreground'
                      }`}
                    >
                      {index + 1}
                    </span>
                    <span className="rounded-full bg-emerald-500/15 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300">
                      {product.quantity} sold
                    </span>
                  </div>
                  <p className="mt-3 line-clamp-2 text-sm font-medium leading-snug">{product.name}</p>
                </div>
              ))}
            </div>
          ) : (
            <ChartEmpty height={120} label="No product data for this period" />
          )}
        </CardContent>
      </Card>
    </div>
  );
};

function ChartSkeleton({ height }: { height: number }) {
  return (
    <div className="flex items-center justify-center" style={{ height }}>
      <Icon icon="eos-icons:loading" className="h-7 w-7 animate-spin text-primary" />
    </div>
  );
}

function ChartEmpty({ height, label }: { height: number; label: string }) {
  return (
    <div
      className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed text-muted-foreground"
      style={{ height }}
    >
      <Icon icon="solar:chart-2-bold-duotone" className="h-8 w-8 opacity-40" />
      <span className="text-sm">{label}</span>
    </div>
  );
}

export default SalesDashboard;
