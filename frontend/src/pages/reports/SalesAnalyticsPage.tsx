import { useRef, useState, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import {
  Users,
  ShoppingCart,
  DollarSign,
  TrendingUp,
  Percent,
  Package,
  Layers,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { TagFilterSelect } from '@/components/tags/TagFilterSelect';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar';
import { ReportTable, Column } from '@/components/reports/ReportTable';
import { KPICard } from '@/components/dashboards/KPICard';
import { BarChart } from '@/components/charts/BarChart';
import { LineChart } from '@/components/charts/LineChart';
import { PieChart } from '@/components/charts/PieChart';
import { useReportAutoRefresh } from '@/hooks/useReportAutoRefresh';
import reportApi from '@/lib/reportApi';
import { formatCurrency, formatNumber } from '@/lib/utils';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';
import {
  avgBy,
  normalizeCategories,
  normalizeCustomers,
  normalizeProducts,
  normalizeTrends,
  safeDate,
  sumBy,
} from './salesAnalyticsRows';

interface SalesAnalyticsPageProps {
  productOnly?: boolean;
}

const isoDate = (date: Date) => date.toISOString().split('T')[0];

const defaultFilters = (): ReportFiltersType => {
  const now = new Date();
  return {
    start_date: isoDate(new Date(now.getFullYear(), now.getMonth(), 1)),
    end_date: isoDate(now),
    interval: 'day',
  };
};

/** `2026-08-31` → `31 Aug 2026` (falls back to the raw value). */
const formatPeriodDate = (value?: string | null) => {
  if (!value) return '…';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

/** Human period label for trend rows: day → date, month → `Sep 2026`. */
const formatTrendPeriod = (period: string) => {
  if (/^\d{4}-\d{2}-\d{2}$/.test(period)) {
    return formatPeriodDate(period);
  }
  if (/^\d{4}-\d{2}$/.test(period)) {
    const date = new Date(`${period}-01`);
    return Number.isNaN(date.getTime())
      ? period
      : date.toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
  }
  return period;
};

/** True revenue-weighted AOV; averaging per-row AOVs skews large order counts. */
const aov = (revenue: number, orders: number) => (orders > 0 ? revenue / orders : 0);

function ReportHeading({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle: string;
  children?: ReactNode;
}) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 className="text-lg font-semibold tracking-tight">{title}</h2>
        <p className="mt-0.5 text-sm text-muted-foreground">{subtitle}</p>
      </div>
      {children && <div className="flex flex-wrap items-center gap-2">{children}</div>}
    </div>
  );
}

function ReportPanelState({
  isLoading,
  error,
  empty,
  emptyMessage = 'No data for the selected period.',
}: {
  isLoading: boolean;
  error: string | null;
  empty: boolean;
  emptyMessage?: string;
}) {
  if (isLoading) {
    return (
      <div className="py-12 text-center text-muted-foreground">
        <Icon icon="svg-spinners:ring-resize" className="mx-auto h-8 w-8" />
        <p className="mt-2">Loading report data…</p>
      </div>
    );
  }
  if (error) {
    return (
      <div className="py-12 text-center text-destructive">
        <Icon icon="solar:danger-circle-bold-duotone" className="mx-auto mb-3 h-12 w-12 opacity-60" />
        <p className="font-medium">Failed to load report</p>
        <p className="mt-1 text-sm">{error}</p>
      </div>
    );
  }
  if (empty) {
    return (
      <div className="py-12 text-center text-muted-foreground">
        <Icon icon="solar:document-bold-duotone" className="mx-auto mb-3 h-12 w-12 opacity-50" />
        <p>{emptyMessage}</p>
        <p className="mt-1 text-sm">Try adjusting the period or tag filters.</p>
      </div>
    );
  }
  return null;
}

export function SalesAnalyticsPage({ productOnly = false }: SalesAnalyticsPageProps) {
  const [activeTab, setActiveTab] = useState('by-customer');
  const queryClient = useQueryClient();
  useReportAutoRefresh();
  const [filters, setFilters] = useState<ReportFiltersType>(defaultFilters);

  const productRef = useRef<HTMLDivElement>(null);
  const customerRef = useRef<HTMLDivElement>(null);
  const trendsRef = useRef<HTMLDivElement>(null);
  const categoryRef = useRef<HTMLDivElement>(null);

  // Sales by Product Query
  const {
    data: productData,
    isLoading: isProductLoading,
    isFetching: isProductFetching,
    error: productError,
  } = useQuery({
    queryKey: ['sales-by-product', filters],
    queryFn: () => reportApi.getSalesByProduct(filters),
    enabled: productOnly,
    staleTime: 15 * 1000,
  });

  // Sales by Customer Query
  const {
    data: customerData,
    isLoading: isCustomerLoading,
    isFetching: isCustomerFetching,
    error: customerError,
  } = useQuery({
    queryKey: ['sales-by-customer', filters],
    queryFn: () => reportApi.getSalesByCustomer(filters),
    enabled: !productOnly && activeTab === 'by-customer',
    staleTime: 15 * 1000,
  });

  // Sales Trends Query
  const {
    data: trendsData,
    isLoading: isTrendsLoading,
    isFetching: isTrendsFetching,
    error: trendsError,
  } = useQuery({
    queryKey: ['sales-trends', filters],
    queryFn: () => reportApi.getSalesTrends(filters),
    enabled: !productOnly && activeTab === 'trends',
    staleTime: 15 * 1000,
  });

  // Sales by Category Query
  const {
    data: categoryData,
    isLoading: isCategoryLoading,
    isFetching: isCategoryFetching,
    error: categoryError,
  } = useQuery({
    queryKey: ['sales-by-category', filters],
    queryFn: () => reportApi.getSalesByCategory(filters),
    enabled: !productOnly && activeTab === 'by-category',
    staleTime: 15 * 1000,
  });

  // ---------------------------------------------------------------------------
  // Normalised rows — the single place that knows the API's field aliases.
  // ---------------------------------------------------------------------------
  const products = normalizeProducts(productData);
  const customers = normalizeCustomers(customerData);
  const trends = normalizeTrends(trendsData);
  const categories = normalizeCategories(categoryData);

  const handleRefresh = () => {
    queryClient.invalidateQueries({ queryKey: ['sales-by-product'] });
    queryClient.invalidateQueries({ queryKey: ['sales-by-customer'] });
    queryClient.invalidateQueries({ queryKey: ['sales-trends'] });
    queryClient.invalidateQueries({ queryKey: ['sales-by-category'] });
  };

  const activeFetching = productOnly
    ? isProductFetching
    : activeTab === 'by-customer'
      ? isCustomerFetching
      : activeTab === 'trends'
        ? isTrendsFetching
        : isCategoryFetching;

  const updateFilter = <K extends keyof ReportFiltersType>(
    key: K,
    value: ReportFiltersType[K],
  ) => setFilters((prev) => ({ ...prev, [key]: value }));

  const hasCustomFilters =
    !!filters.tag ||
    filters.interval !== 'day' ||
    filters.start_date !== defaultFilters().start_date ||
    filters.end_date !== defaultFilters().end_date;

  const periodLabel = `${formatPeriodDate(filters.start_date)} → ${formatPeriodDate(filters.end_date)}`;
  const exportRange = `${filters.start_date}_${filters.end_date}`;

  // ---------------------------------------------------------------------------
  // Columns — numeric columns are tabular so figures line up vertically.
  // ---------------------------------------------------------------------------
  const productColumns: Column[] = [
    { key: 'name', label: 'Product', sortable: true, filterable: true },
    { key: 'sku', label: 'SKU', sortable: true, filterable: true },
    {
      key: 'total_quantity',
      label: 'Qty Sold',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => formatNumber(value),
    },
    {
      key: 'total_revenue',
      label: 'Revenue',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'total_cost',
      label: 'Cost',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'total_profit',
      label: 'Profit',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => (
        <span className={value >= 0 ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold'}>
          {formatCurrency(value)}
        </span>
      ),
    },
    {
      key: 'profit_margin',
      label: 'Margin %',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => (
        <span className={value >= 0 ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold'}>
          {value.toFixed(2)}%
        </span>
      ),
    },
  ];

  const customerColumns: Column[] = [
    { key: 'name', label: 'Customer', sortable: true, filterable: true },
    {
      key: 'total_orders',
      label: 'Orders',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => formatNumber(value),
    },
    {
      key: 'total_revenue',
      label: 'Total Revenue',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'average_order_value',
      label: 'Avg Order Value',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'last_purchase_date',
      label: 'Last Purchase',
      sortable: true,
      className: 'whitespace-nowrap',
      format: (value) => safeDate(value),
    },
  ];

  const categoryColumns: Column[] = [
    { key: 'category_name', label: 'Category', sortable: true, filterable: true },
    {
      key: 'product_count',
      label: 'Products',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => formatNumber(value),
    },
    {
      key: 'quantity_sold',
      label: 'Qty Sold',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => formatNumber(value),
    },
    {
      key: 'total_revenue',
      label: 'Revenue',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'total_profit',
      label: 'Profit',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => (
        <span className={value >= 0 ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold'}>
          {formatCurrency(value)}
        </span>
      ),
    },
    {
      key: 'percentage',
      label: 'Share',
      sortable: true,
      align: 'right',
      format: (value) => (
        <div className="flex items-center justify-end gap-2">
          <div className="w-20 bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
            <div
              className="bg-blue-600 h-1.5 rounded-full"
              style={{ width: `${Math.min(100, Math.max(0, value))}%` }}
            />
          </div>
          <span className="font-semibold tabular-nums w-12 text-right">{value.toFixed(1)}%</span>
        </div>
      ),
    },
  ];

  const trendColumns: Column[] = [
    {
      key: 'period',
      label: 'Period',
      sortable: true,
      className: 'whitespace-nowrap',
      format: (value) => formatTrendPeriod(String(value)),
    },
    {
      key: 'invoice_count',
      label: 'Orders',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => formatNumber(value),
    },
    {
      key: 'total_revenue',
      label: 'Revenue',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'avg_order_value',
      label: 'Avg Order Value',
      sortable: true,
      align: 'right',
      className: 'tabular-nums',
      format: (value) => formatCurrency(value),
    },
  ];

  // ---------------------------------------------------------------------------
  // Filter card — labelled controls (Period / Tag / Interval), matching the
  // accounting report pages instead of the old unlabelled pill soup.
  // ---------------------------------------------------------------------------
  // ── Full export datasets ────────────────────────────────────────────────
  // CSV/PDF/Print export every row, not just the page the detail table shows.
  const productExportRows = [
    ['Product', 'SKU', 'Qty Sold', 'Revenue', 'Cost', 'Profit', 'Margin %'],
    ...products.map((p) => [
      p.name,
      p.sku,
      formatNumber(p.total_quantity),
      formatCurrency(p.total_revenue),
      formatCurrency(p.total_cost),
      formatCurrency(p.total_profit),
      `${p.profit_margin.toFixed(2)}%`,
    ]),
  ];
  const customerExportRows = [
    ['Customer', 'Orders', 'Total Revenue', 'Avg Order Value', 'Last Purchase'],
    ...customers.map((c) => [
      c.name,
      formatNumber(c.total_orders),
      formatCurrency(c.total_revenue),
      formatCurrency(c.average_order_value),
      safeDate(c.last_purchase_date),
    ]),
  ];
  const trendExportRows = [
    ['Period', 'Orders', 'Revenue', 'Avg Order Value'],
    ...trends.map((t) => [
      formatTrendPeriod(t.period),
      formatNumber(t.invoice_count),
      formatCurrency(t.total_revenue),
      formatCurrency(t.avg_order_value),
    ]),
  ];
  const categoryExportRows = [
    ['Category', 'Products', 'Qty Sold', 'Revenue', 'Profit', 'Share'],
    ...categories.map((c) => [
      c.category_name,
      formatNumber(c.product_count),
      formatNumber(c.quantity_sold),
      formatCurrency(c.total_revenue),
      formatCurrency(c.total_profit),
      `${c.percentage.toFixed(1)}%`,
    ]),
  ];

  const filterCard = (
    <Card className="rounded-xl p-4">
      <div className="flex flex-wrap items-end gap-3">
        <div className="space-y-1.5">
          <Label className="text-xs font-medium text-muted-foreground">Period</Label>
          <div className="flex items-center gap-1.5">
            <Input
              type="date"
              aria-label="Start date"
              value={filters.start_date || ''}
              onChange={(e) => updateFilter('start_date', e.target.value)}
              className="h-9 w-[150px] rounded-full text-sm"
            />
            <span className="text-xs text-muted-foreground">→</span>
            <Input
              type="date"
              aria-label="End date"
              value={filters.end_date || ''}
              onChange={(e) => updateFilter('end_date', e.target.value)}
              className="h-9 w-[150px] rounded-full text-sm"
            />
          </div>
        </div>

        <div className="space-y-1.5">
          <Label className="text-xs font-medium text-muted-foreground">Tagged orders</Label>
          <TagFilterSelect
            className="h-9 w-[190px] rounded-full text-sm"
            value={filters.tag || ''}
            onChange={(value) => updateFilter('tag', value || undefined)}
            placeholder="All orders"
          />
        </div>

        <div className="space-y-1.5">
          <Label className="text-xs font-medium text-muted-foreground">Interval</Label>
          <Select
            value={filters.interval}
            onValueChange={(value) => updateFilter('interval', value as 'day' | 'week' | 'month' | 'year')}
          >
            <SelectTrigger className="h-9 w-[140px] rounded-full text-sm" aria-label="Interval">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="day">Daily</SelectItem>
              <SelectItem value="week">Weekly</SelectItem>
              <SelectItem value="month">Monthly</SelectItem>
              <SelectItem value="year">Yearly</SelectItem>
            </SelectContent>
          </Select>
        </div>

        {hasCustomFilters && (
          <HeaderActionButton
            variant="ghost"
            icon="solar:restart-bold"
            onClick={() => setFilters(defaultFilters())}
          >
            Reset
          </HeaderActionButton>
        )}
      </div>
    </Card>
  );

  const header = (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 className="text-3xl font-bold">
          {productOnly ? 'Product Sales Report' : 'Sales Analytics'}
        </h1>
        <p className="mt-1 text-muted-foreground">
          {productOnly
            ? 'Analyze sales performance by product'
            : 'Analyze sales performance by customer, category, and trends'}
        </p>
      </div>
      <HeaderActionButton
        variant="outline"
        icon="solar:refresh-bold"
        loading={activeFetching}
        onClick={handleRefresh}
      >
        Refresh
      </HeaderActionButton>
    </div>
  );

  // ---------------------------------------------------------------------------
  // Product-only view (used by /products/report)
  // ---------------------------------------------------------------------------
  if (productOnly) {
    return (
      <div className="space-y-6">
        {header}
        {filterCard}

        <div className="space-y-6">
          <ReportHeading
            title="Sales by Product"
            subtitle={`For the period ${periodLabel}`}
          >
            <ReportExportToolbar
              targetRef={productRef}
              title="Sales by Product"
              subtitle={`For the period ${periodLabel}`}
              filename={`sales-by-product-${exportRange}`}
              rows={productExportRows}
              columnAligns={['left','left','right','right','right','right','right']}
            />
          </ReportHeading>

          <ReportPanelState
            isLoading={isProductLoading}
            error={productError?.message || null}
            empty={products.length === 0}
          />

          {!isProductLoading && !productError && products.length > 0 && (
            <>
              <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <KPICard
                  title="Products Sold"
                  value={formatNumber(products.length)}
                  icon={Package}
                />
                <KPICard
                  title="Total Revenue"
                  value={formatCurrency(sumBy(products, (p) => p.total_revenue))}
                  icon={DollarSign}
                />
                <KPICard
                  title="Total Profit"
                  value={formatCurrency(sumBy(products, (p) => p.total_profit))}
                  icon={TrendingUp}
                />
                <KPICard
                  title="Avg Margin"
                  value={`${avgBy(products, (p) => p.profit_margin).toFixed(2)}%`}
                  icon={Percent}
                />
              </div>

              <Card className="rounded-xl">
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
                  <div>
                    <CardTitle className="text-base">Top 10 Products by Revenue</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                      Hover a bar for exact figures
                    </p>
                  </div>
                </CardHeader>
                <CardContent className="pt-6">
                  <BarChart
                    data={products.slice(0, 10).map((p) => ({
                      name: p.name,
                      Revenue: p.total_revenue,
                      Profit: p.total_profit,
                    }))}
                    xKey="name"
                    yKeys={['Revenue', 'Profit']}
                    colors={['#3b82f6', '#8b5cf6']}
                    height={360}
                    formatYAxis="currency"
                    truncateLabels={14}
                    xAxisAngle={-30}
                  />
                </CardContent>
              </Card>

              <Card className="rounded-xl">
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
                  <div>
                    <CardTitle className="text-base">All Products</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                      {products.length} products · {formatCurrency(sumBy(products, (p) => p.total_revenue))} revenue
                    </p>
                  </div>
                </CardHeader>
                <CardContent className="pt-6" ref={productRef}>
                  <ReportTable
                    columns={productColumns}
                    data={products}
                    showPagination
                    showSearch
                    pageSize={20}
                  />
                </CardContent>
              </Card>
            </>
          )}
        </div>
      </div>
    );
  }

  // ---------------------------------------------------------------------------
  // Tabbed view
  // ---------------------------------------------------------------------------
  return (
    <div className="space-y-6">
      {header}
      {filterCard}

      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="h-10 w-fit rounded-full">
          <TabsTrigger value="by-customer" className="gap-2 rounded-full px-4">
            <Icon icon="solar:users-group-rounded-bold" className="h-4 w-4" />
            By Customer
          </TabsTrigger>
          <TabsTrigger value="trends" className="gap-2 rounded-full px-4">
            <Icon icon="solar:chart-2-bold" className="h-4 w-4" />
            Trends
          </TabsTrigger>
          <TabsTrigger value="by-category" className="gap-2 rounded-full px-4">
            <Icon icon="solar:pie-chart-bold" className="h-4 w-4" />
            By Category
          </TabsTrigger>
        </TabsList>

        {/* ------------------------------- Customers ------------------------------ */}
        <TabsContent value="by-customer" className="space-y-6">
          <ReportHeading title="Sales by Customer" subtitle={`For the period ${periodLabel}`}>
            <ReportExportToolbar
              targetRef={customerRef}
              title="Sales by Customer"
              subtitle={`For the period ${periodLabel}`}
              filename={`sales-by-customer-${exportRange}`}
              rows={customerExportRows}
              columnAligns={['left','right','right','right','left']}
            />
          </ReportHeading>

          <ReportPanelState
            isLoading={isCustomerLoading}
            error={customerError?.message || null}
            empty={customers.length === 0}
          />

          {!isCustomerLoading && !customerError && customers.length > 0 && (
            <>
              <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <KPICard title="Customers" value={formatNumber(customers.length)} icon={Users} />
                <KPICard
                  title="Total Revenue"
                  value={formatCurrency(sumBy(customers, (c) => c.total_revenue))}
                  icon={DollarSign}
                />
                <KPICard
                  title="Total Orders"
                  value={formatNumber(sumBy(customers, (c) => c.total_orders))}
                  icon={ShoppingCart}
                />
                <KPICard
                  title="Avg Order Value"
                  value={formatCurrency(
                    aov(
                      sumBy(customers, (c) => c.total_revenue),
                      sumBy(customers, (c) => c.total_orders),
                    ),
                  )}
                  icon={TrendingUp}
                />
              </div>

              <Card className="rounded-xl">
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
                  <div>
                    <CardTitle className="text-base">Top 10 Customers by Revenue</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                      Hover a bar for the full name
                    </p>
                  </div>
                </CardHeader>
                <CardContent className="pt-6">
                  <BarChart
                    data={customers.slice(0, 10).map((c) => ({
                      name: c.name,
                      Revenue: c.total_revenue,
                    }))}
                    xKey="name"
                    yKeys={['Revenue']}
                    colors={['#3b82f6']}
                    height={Math.max(320, Math.min(10, customers.length) * 40)}
                    formatYAxis="currency"
                    horizontal
                    truncateLabels={28}
                    showLegend={false}
                  />
                </CardContent>
              </Card>

              <Card className="rounded-xl">
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
                  <div>
                    <CardTitle className="text-base">Customer Detail</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                      {customers.length} customers · {formatNumber(sumBy(customers, (c) => c.total_orders))} orders
                    </p>
                  </div>
                </CardHeader>
                <CardContent className="pt-6" ref={customerRef}>
                  <ReportTable
                    columns={customerColumns}
                    data={customers}
                    showPagination
                    showSearch
                    pageSize={20}
                  />
                </CardContent>
              </Card>
            </>
          )}
        </TabsContent>

        {/* -------------------------------- Trends -------------------------------- */}
        <TabsContent value="trends" className="space-y-6">
          <ReportHeading title="Sales Trends" subtitle={`For the period ${periodLabel}`}>
            <ReportExportToolbar
              targetRef={trendsRef}
              title="Sales Trends"
              subtitle={`For the period ${periodLabel}`}
              filename={`sales-trends-${exportRange}`}
              rows={trendExportRows}
              columnAligns={['left','right','right','right']}
            />
          </ReportHeading>

          <ReportPanelState
            isLoading={isTrendsLoading}
            error={trendsError?.message || null}
            empty={trends.length === 0}
          />

          {!isTrendsLoading && !trendsError && trends.length > 0 && (
            <>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <KPICard
                  title="Total Revenue"
                  value={formatCurrency(sumBy(trends, (t) => t.total_revenue))}
                  icon={DollarSign}
                />
                <KPICard
                  title="Total Orders"
                  value={formatNumber(sumBy(trends, (t) => t.invoice_count))}
                  icon={ShoppingCart}
                />
                <KPICard
                  title="Avg Order Value"
                  value={formatCurrency(
                    aov(
                      sumBy(trends, (t) => t.total_revenue),
                      sumBy(trends, (t) => t.invoice_count),
                    ),
                  )}
                  icon={TrendingUp}
                />
              </div>

              <Card className="rounded-xl">
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
                  <div>
                    <CardTitle className="text-base">Revenue Trend</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                      {formatNumber(trends.length)} period{trends.length === 1 ? '' : 's'}
                    </p>
                  </div>
                </CardHeader>
                <CardContent className="pt-6">
                  <LineChart
                    data={trends.map((t) => ({ period: formatTrendPeriod(t.period), Revenue: t.total_revenue }))}
                    xKey="period"
                    yKeys={['Revenue']}
                    colors={['#3b82f6']}
                    height={320}
                    formatYAxis="currency"
                    showLegend={false}
                    truncateLabels={14}
                    xAxisAngle={trends.length > 8 ? -30 : 0}
                  />
                </CardContent>
              </Card>

              <Card className="rounded-xl">
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
                  <div>
                    <CardTitle className="text-base">Order Volume</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">Orders per period</p>
                  </div>
                </CardHeader>
                <CardContent className="pt-6">
                  <LineChart
                    data={trends.map((t) => ({ period: formatTrendPeriod(t.period), Orders: t.invoice_count }))}
                    xKey="period"
                    yKeys={['Orders']}
                    colors={['#10b981']}
                    height={300}
                    formatYAxis="number"
                    showLegend={false}
                    truncateLabels={14}
                    xAxisAngle={trends.length > 8 ? -30 : 0}
                  />
                </CardContent>
              </Card>

              <Card className="rounded-xl">
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
                  <div>
                    <CardTitle className="text-base">Period Detail</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                      {trends.length} period{trends.length === 1 ? '' : 's'} ·{' '}
                      {formatNumber(sumBy(trends, (t) => t.invoice_count))} orders ·{' '}
                      {formatCurrency(sumBy(trends, (t) => t.total_revenue))} revenue
                    </p>
                  </div>
                </CardHeader>
                <CardContent className="pt-6" ref={trendsRef}>
                  <ReportTable
                    columns={trendColumns}
                    data={trends}
                    showPagination={trends.length > 20}
                    showSearch={false}
                    pageSize={20}
                  />
                </CardContent>
              </Card>
            </>
          )}
        </TabsContent>

        {/* ------------------------------ By Category ----------------------------- */}
        <TabsContent value="by-category" className="space-y-6">
          <ReportHeading title="Sales by Category" subtitle={`For the period ${periodLabel}`}>
            <ReportExportToolbar
              targetRef={categoryRef}
              title="Sales by Category"
              subtitle={`For the period ${periodLabel}`}
              filename={`sales-by-category-${exportRange}`}
              rows={categoryExportRows}
              columnAligns={['left','right','right','right','right','right']}
            />
          </ReportHeading>

          <ReportPanelState
            isLoading={isCategoryLoading}
            error={categoryError?.message || null}
            empty={categories.length === 0}
          />

          {!isCategoryLoading && !categoryError && categories.length > 0 && (
            <>
              <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <KPICard title="Categories" value={formatNumber(categories.length)} icon={Layers} />
                <KPICard
                  title="Total Revenue"
                  value={formatCurrency(sumBy(categories, (c) => c.total_revenue))}
                  icon={DollarSign}
                />
                <KPICard
                  title="Total Profit"
                  value={formatCurrency(sumBy(categories, (c) => c.total_profit))}
                  icon={TrendingUp}
                />
                <KPICard
                  title="Avg Margin"
                  value={`${avgBy(categories, (c) => c.profit_margin).toFixed(2)}%`}
                  icon={Percent}
                />
              </div>

              <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card className="rounded-xl">
                  <CardHeader className="border-b pb-4">
                    <CardTitle className="text-base">Revenue Distribution</CardTitle>
                  </CardHeader>
                  <CardContent className="pt-6">
                    <PieChart
                      data={categories.map((c) => ({
                        name: c.category_name,
                        value: c.total_revenue,
                      }))}
                      nameKey="name"
                      valueKey="value"
                      height={340}
                      formatValue="currency"
                      showLegend={categories.length <= 8}
                    />
                  </CardContent>
                </Card>

                <Card className="rounded-xl">
                  <CardHeader className="border-b pb-4">
                    <CardTitle className="text-base">Revenue by Category</CardTitle>
                  </CardHeader>
                  <CardContent className="pt-6">
                    <BarChart
                      data={categories.map((c) => ({
                        name: c.category_name,
                        Revenue: c.total_revenue,
                      }))}
                      xKey="name"
                      yKeys={['Revenue']}
                      colors={['#3b82f6']}
                      height={340}
                      formatYAxis="currency"
                      showLegend={false}
                      truncateLabels={14}
                      xAxisAngle={categories.length > 4 ? -25 : 0}
                    />
                  </CardContent>
                </Card>
              </div>

              <Card className="rounded-xl">
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
                  <div>
                    <CardTitle className="text-base">Category Detail</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                      {categories.length} categories ·{' '}
                      {formatCurrency(sumBy(categories, (c) => c.total_revenue))} revenue
                    </p>
                  </div>
                </CardHeader>
                <CardContent className="pt-6" ref={categoryRef}>
                  <ReportTable
                    columns={categoryColumns}
                    data={categories}
                    showPagination={categories.length > 20}
                    showSearch={false}
                    pageSize={20}
                  />
                </CardContent>
              </Card>
            </>
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
}

export default SalesAnalyticsPage;
