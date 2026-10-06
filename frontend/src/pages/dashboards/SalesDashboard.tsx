import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import {
  MONTH_NAMES,
  percentChange,
  resolveSalesDashboardPeriod,
  type DateRange,
  type SalesDashboardPeriod,
} from '@/lib/salesDashboardPeriods';
import { formatCurrency } from '@/lib/utils';
import type { SalesDashboardData } from '@/types/reports';
import { SavedViewWidgets } from './SavedViewWidgets';

// Analytics API returns `total_revenue` per product (see
// SalesAnalyticsService::getSalesByProduct) — never `total_sales`.
type ProductPayload = { products?: Array<{ total_revenue?: number; total_quantity?: number }> };
const sumRevenue = (payload: ProductPayload) =>
  (payload?.products ?? []).reduce((sum, p) => sum + (Number(p.total_revenue) || 0), 0);
const sumUnits = (payload: ProductPayload) =>
  (payload?.products ?? []).reduce((sum, p) => sum + (Number(p.total_quantity) || 0), 0);

const PERIODS: SalesDashboardPeriod[] = ['today', 'week', 'month', 'year', 'custom'];
const YEARS_OFFERED = 10;

/**
 * Sales Dashboard Page
 * Comprehensive sales analytics and metrics
 */
export const SalesDashboard: React.FC = () => {
  const now = new Date();
  const [selectedPeriod, setSelectedPeriod] = useState<SalesDashboardPeriod>('month');
  const [selectedMonth, setSelectedMonth] = useState(now.getMonth()); // 0-11
  const [selectedYear, setSelectedYear] = useState(now.getFullYear());
  const [customStart, setCustomStart] = useState(() =>
    formatLocalDate(new Date(now.getFullYear(), now.getMonth(), 1))
  );
  const [customEnd, setCustomEnd] = useState(() => formatLocalDate(now));

  // #358 (S10): one resolved period drives the KPI row AND the charts, with the
  // comparison range the KPI row is measured against.
  const resolution = resolveSalesDashboardPeriod(
    {
      period: selectedPeriod,
      month: selectedMonth,
      year: selectedYear,
      customStart,
      customEnd,
    },
    now
  );
  const resolved = resolution.ok ? resolution.value : null;
  const currentFilters: DateRange | null = resolved?.current ?? null;
  const previousFilters: DateRange | null = resolved?.previous ?? null;

  // KPI row: selected period vs its comparison period.
  // #351 (S3): tile values come straight from this query, never from a value
  // cached inside the dashboard query below.
  const {
    data: kpis,
    isLoading: kpisLoading,
    refetch: refetchKpis,
  } = useQuery({
    queryKey: ['sales-kpis', currentFilters, previousFilters],
    enabled: resolved !== null,
    queryFn: async () => {
      const [currentData, previousData] = await Promise.all([
        reportApi.getSalesByProduct(currentFilters!),
        reportApi.getSalesByProduct(previousFilters!),
      ]);
      return {
        current: sumRevenue(currentData),
        previous: sumRevenue(previousData),
        units: sumUnits(currentData),
      };
    },
    staleTime: 5 * 60 * 1000,
  });

  // Live pulse: today's sales, deliberately independent of the period filter
  // and labelled as such.
  const todayRange = { start_date: formatLocalDate(now), end_date: formatLocalDate(now) };
  const {
    data: todaySales,
    isLoading: todayLoading,
    refetch: refetchToday,
  } = useQuery({
    queryKey: ['sales-kpi-today', todayRange.start_date],
    queryFn: async () => sumRevenue(await reportApi.getSalesByProduct(todayRange)),
    staleTime: 5 * 60 * 1000,
  });

  // Fetch dashboard data for selected period
  const { data, isLoading, error, refetch } = useQuery<Omit<SalesDashboardData, 'metrics'>>({
    queryKey: ['sales-dashboard', currentFilters],
    enabled: currentFilters !== null,
    queryFn: async () => {
      const filters = currentFilters!;
      // Aggregate data from multiple endpoints
      const [salesTrends, salesByProduct, salesByCustomer, salesByCategory] = await Promise.all([
        reportApi.getSalesTrends({ ...filters, interval: 'day' }),
        reportApi.getSalesByProduct(filters),
        reportApi.getSalesByCustomer(filters),
        reportApi.getSalesByCategory(filters),
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

  const handleRefresh = () => {
    // Refresh must re-run the KPI queries too (#351) — not only the charts.
    if (resolved) {
      refetch();
      refetchKpis();
    }
    refetchToday();
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

  const yearOptions = Array.from({ length: YEARS_OFFERED }, (_, i) => now.getFullYear() - i);

  const change = kpis ? percentChange(kpis.current, kpis.previous) : null;
  const delta = kpis ? kpis.current - kpis.previous : 0;
  const changeText =
    change === null ? '—' : `${change > 0 ? '+' : ''}${change.toFixed(1)}%`;
  const changeTone =
    change === null || change === 0
      ? 'text-slate-900 dark:text-slate-100'
      : change > 0
        ? 'text-emerald-600 dark:text-emerald-400'
        : 'text-rose-600 dark:text-rose-400';
  const kpiValue = (value: number | undefined) =>
    !resolved ? '—' : kpisLoading ? '…' : formatCurrency(value ?? 0);

  const KPIS = [
    {
      id: 'kpi-selected',
      label: 'Net revenue',
      value: kpiValue(kpis?.current),
      valueClass: 'text-slate-900 dark:text-slate-100',
      icon: 'solar:chart-2-bold-duotone',
      tile: 'bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300',
      sub: resolved
        ? `${resolved.label}${kpis ? ` · ${kpis.units.toLocaleString()} units sold` : ''}`
        : 'Select a valid period',
    },
    {
      id: 'kpi-previous',
      label: 'Previous period',
      value: kpiValue(kpis?.previous),
      valueClass: 'text-slate-900 dark:text-slate-100',
      icon: 'solar:history-bold-duotone',
      tile: 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300',
      sub: resolved?.previousLabel ?? '—',
    },
    {
      id: 'kpi-change',
      label: 'Change vs previous',
      value: !resolved ? '—' : kpisLoading ? '…' : changeText,
      valueClass: changeTone,
      icon: 'solar:graph-up-bold-duotone',
      tile: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
      sub:
        !resolved || !kpis
          ? '—'
          : change === null
            ? 'No sales in the previous period'
            : `${delta >= 0 ? '+' : '−'}${formatCurrency(Math.abs(delta))}`,
    },
    {
      id: 'kpi-today',
      label: "Today's sales",
      value: todayLoading ? '…' : formatCurrency(todaySales ?? 0),
      valueClass: 'text-slate-900 dark:text-slate-100',
      icon: 'solar:calendar-bold-duotone',
      tile: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
      sub: 'Live — not affected by the period filter',
    },
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
                aria-pressed={selectedPeriod === period}
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
            <Select
              value={String(selectedMonth)}
              onValueChange={(v) => setSelectedMonth(parseInt(v, 10))}
            >
              <SelectTrigger className="h-9 w-[140px]" aria-label="Month">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {MONTH_NAMES.map((month, index) => (
                  <SelectItem key={index} value={String(index)}>
                    {month}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}

          {(selectedPeriod === 'month' || selectedPeriod === 'year') && (
            <Select
              value={String(selectedYear)}
              onValueChange={(v) => setSelectedYear(parseInt(v, 10))}
            >
              <SelectTrigger className="h-9 w-[110px]" aria-label="Year">
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
          )}

          {selectedPeriod === 'custom' && (
            <div className="flex items-center gap-2">
              <label htmlFor="sales-dash-from" className="text-xs text-muted-foreground">
                From
              </label>
              <Input
                id="sales-dash-from"
                type="date"
                className="h-9 w-[150px]"
                value={customStart}
                max={customEnd || undefined}
                onChange={(e) => setCustomStart(e.target.value)}
              />
              <label htmlFor="sales-dash-to" className="text-xs text-muted-foreground">
                To
              </label>
              <Input
                id="sales-dash-to"
                type="date"
                className="h-9 w-[150px]"
                value={customEnd}
                min={customStart || undefined}
                onChange={(e) => setCustomEnd(e.target.value)}
              />
            </div>
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

      {!resolution.ok && (
        <p role="alert" className="text-sm text-rose-600 dark:text-rose-400">
          {resolution.error}
        </p>
      )}

      {/* Issue #603: saved-view widgets pinned from the reports page. The
          section hides itself when the user has no pins (or no reports.view). */}
      <SavedViewWidgets />

      {/* KPI tiles — #358: the selected period vs its comparison period */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {KPIS.map((kpi) => (
          <Card
            key={kpi.id}
            data-testid={kpi.id}
            className="rounded-xl border-slate-200/80 shadow-sm transition hover:shadow-md"
          >
            <CardContent className="p-5">
              <div className={`flex h-10 w-10 items-center justify-center rounded-lg ${kpi.tile}`}>
                <Icon icon={kpi.icon} className="h-5 w-5" />
              </div>
              <p className="mt-4 text-[11px] font-medium uppercase tracking-wide text-slate-500">
                {kpi.label}
              </p>
              <p
                className={`mt-1 text-[26px] font-semibold leading-tight tracking-tight tabular-nums ${kpi.valueClass}`}
              >
                {kpi.value}
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
