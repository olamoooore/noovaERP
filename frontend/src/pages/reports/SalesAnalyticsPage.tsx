import { useMemo, useRef, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
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
import api from '@/lib/api';
import pricingApi from '@/lib/pricingApi';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar';
import { GlReconciliationFooter } from '@/components/reports/GlReconciliationFooter';
import { ReportTable, Column } from '@/components/reports/ReportTable';
import { StatCard } from '@/components/ui/stat-card';
import { BarChart } from '@/components/charts/BarChart';
import { LineChart } from '@/components/charts/LineChart';
import { PieChart } from '@/components/charts/PieChart';
import { useReportAutoRefresh } from '@/hooks/useReportAutoRefresh';
import reportApi, { formatLocalDate } from '@/lib/reportApi';
import { formatCurrency, formatNumber } from '@/lib/utils';
import type {
  ReportComparison,
  ReportFilters as ReportFiltersType,
  SalesViewGrouping,
  SalesViewTab,
} from '@/types/reports';
import {
  avgBy,
  formatPeriodDate,
  formatTrendPeriod,
  normalizeCategories,
  normalizeCustomers,
  normalizeProducts,
  normalizeTrends,
  safeDate,
  sumBy,
} from './salesAnalyticsRows';
import { SalesPivotView } from './SalesPivotView';
import { DEFAULT_PIVOT_GROUPING } from './salesPivotRows';
import { UnbilledDeliveriesView } from './UnbilledDeliveriesView';
import { ProductUsageView } from './ProductUsageView';
import { SavedViewBar, type SavedViewApplied } from './SavedViewBar';

interface SalesAnalyticsPageProps {
  productOnly?: boolean;
}

export const defaultFilters = (): ReportFiltersType => {
  const now = new Date();
  return {
    start_date: formatLocalDate(new Date(now.getFullYear(), now.getMonth(), 1)),
    end_date: formatLocalDate(now),
    interval: 'day',
  };
};

interface FilterSelectOption {
  value: string;
  label: string;
}

/**
 * One labelled dropdown in the sales filter bar (#593). `undefined` from
 * `onChange` clears the filter; the 'all' item is what carries that value.
 */
const FilterSelect = ({
  label,
  value,
  onChange,
  options,
  allLabel,
  width = 'w-[160px]',
}: {
  label: string;
  value: string;
  onChange: (value: string | undefined) => void;
  options: FilterSelectOption[];
  allLabel: string;
  width?: string;
}) => (
  <div className="space-y-1.5">
    <Label className="text-xs font-medium text-muted-foreground">{label}</Label>
    <Select value={value} onValueChange={(next) => onChange(next === 'all' ? undefined : next)}>
      <SelectTrigger className={`h-9 ${width} rounded-full text-sm`} aria-label={label}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">{allLabel}</SelectItem>
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  </div>
);

/** True revenue-weighted AOV; averaging per-row AOVs skews large order counts. */
const aov = (revenue: number, orders: number) => (orders > 0 ? revenue / orders : 0);

/** Issue #595: absolute comparison delta, signed and coloured. */
function DeltaCell({ delta, currency = true }: { delta: number; currency?: boolean }) {
  const tone =
    delta > 0 ? 'text-emerald-600' : delta < 0 ? 'text-red-600' : 'text-muted-foreground';
  return (
    <span className={`font-semibold tabular-nums ${tone}`}>
      {delta > 0 ? '+' : ''}
      {currency ? formatCurrency(delta) : formatNumber(delta)}
    </span>
  );
}

/** Issue #595: Δ% — the API sends null when the previous value is 0. */
function DeltaPercentCell({ percent }: { percent: number }) {
  const tone =
    percent > 0 ? 'text-emerald-600' : percent < 0 ? 'text-red-600' : 'text-muted-foreground';
  return (
    <span className={`font-semibold tabular-nums ${tone}`}>
      {percent > 0 ? '+' : ''}
      {percent.toFixed(2)}%
    </span>
  );
}

/** Issue #595: " vs 1 Sep 2026 → 30 Sep 2026" for a report heading. */
function comparisonRangeLabel(comparison?: ReportComparison) {
  return comparison
    ? ` vs ${formatPeriodDate(comparison.period.start_date)} → ${formatPeriodDate(comparison.period.end_date)}`
    : '';
}

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
  // Issue #598: the pivot's grouping is page state so a saved view can carry
  // and restore it (row/column dimensions and measures).
  const [pivotGrouping, setPivotGrouping] = useState<SalesViewGrouping>(DEFAULT_PIVOT_GROUPING);

  const productRef = useRef<HTMLDivElement>(null);
  const customerRef = useRef<HTMLDivElement>(null);
  const trendsRef = useRef<HTMLDivElement>(null);
  const categoryRef = useRef<HTMLDivElement>(null);

  // ---------------------------------------------------------------------------
  // Issue #595: a custom comparison only reaches the API once both bounds are
  // chosen — the endpoint rejects an incomplete custom range with a 422, so
  // the page neither asks nor shows an error while the user is still picking.
  // ---------------------------------------------------------------------------
  const queryFilters = useMemo<ReportFiltersType>(() => {
    if (filters.comparison !== 'custom') return filters;
    if (filters.compare_start_date && filters.compare_end_date) return filters;

    const incomplete = { ...filters };
    delete incomplete.comparison;
    delete incomplete.compare_start_date;
    delete incomplete.compare_end_date;
    return incomplete;
  }, [filters]);

  // Issue #601: a pivot reports one period — the comparison window is
  // meaningless there and the endpoint rejects it outright (422), so the
  // pivot query never carries the comparison keys.
  const pivotFilters = useMemo<ReportFiltersType>(() => {
    const rest = { ...queryFilters };
    delete rest.comparison;
    delete rest.compare_start_date;
    delete rest.compare_end_date;
    return rest;
  }, [queryFilters]);

  // Sales by Product Query
  const {
    data: productData,
    isLoading: isProductLoading,
    isFetching: isProductFetching,
    error: productError,
  } = useQuery({
    queryKey: ['sales-by-product', queryFilters],
    queryFn: () => reportApi.getSalesByProduct(queryFilters),
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
    queryKey: ['sales-by-customer', queryFilters],
    queryFn: () => reportApi.getSalesByCustomer(queryFilters),
    enabled: !productOnly && activeTab === 'by-customer',
    staleTime: 15 * 1000,
  });

  // Sales Trends Query
  const { data: sellableCurrencies = [] } = useQuery({
    queryKey: ['currencies', 'sellable'],
    queryFn: () => pricingApi.sellableCurrencies(),
  });

  // ---------------------------------------------------------------------------
  // Issue #593: filter option lists. Kept separate from the report queries so a
  // missing permission on one list only empties its dropdown, never the report.
  // ---------------------------------------------------------------------------
  const { data: filterCustomers = [] } = useQuery({
    queryKey: ['sales-filters', 'customers'],
    queryFn: async () => {
      const res = await api.get('/customers', { params: { per_page: 500 } });
      return (res.data?.data?.data ?? res.data?.data ?? []) as Array<{
        id: number;
        name: string;
        salesperson?: { id: number; name: string } | null;
      }>;
    },
    staleTime: 60 * 1000,
    retry: false,
  });

  const { data: filterProducts = [] } = useQuery({
    queryKey: ['sales-filters', 'products'],
    queryFn: async () => {
      const res = await api.get('/products', { params: { per_page: 500 } });
      return (res.data?.data ?? []) as Array<{ id: number; name: string }>;
    },
    staleTime: 60 * 1000,
    retry: false,
  });

  const { data: filterCategories = [] } = useQuery({
    queryKey: ['product-categories'],
    queryFn: async () => {
      const res = await api.get('/product-categories');
      return (Array.isArray(res.data) ? res.data : res.data?.data || []) as Array<{
        id: number;
        name: string;
      }>;
    },
    staleTime: 60 * 1000,
    retry: false,
  });

  const { data: filterEntities = [] } = useQuery({
    queryKey: ['sales-filters', 'legal-entities'],
    queryFn: async () => {
      const res = await api.get('/accounting/legal-entities');
      return (Array.isArray(res.data) ? res.data : res.data?.data || []) as Array<{
        id: number;
        name: string;
      }>;
    },
    staleTime: 60 * 1000,
    retry: false,
  });

  // The salesperson filter matches customers.salesperson_id, so list the
  // salespeople actually assigned to a customer rather than tenant users —
  // those are different id spaces and the report would silently return 0 rows.
  const filterSalespersons = useMemo(() => {
    const byId = new Map<number, string>();
    for (const customer of filterCustomers) {
      const salesperson = customer.salesperson;
      if (salesperson?.id && !byId.has(salesperson.id)) {
        byId.set(salesperson.id, salesperson.name);
      }
    }
    return [...byId].map(([id, name]) => ({ id, name }));
  }, [filterCustomers]);

  const { data: byCurrencyData } = useQuery({
    queryKey: ['sales-by-currency', queryFilters],
    queryFn: () => reportApi.getSalesByCurrency(queryFilters),
  });

  const {
    data: trendsData,
    isLoading: isTrendsLoading,
    isFetching: isTrendsFetching,
    error: trendsError,
  } = useQuery({
    queryKey: ['sales-trends', queryFilters],
    queryFn: () => reportApi.getSalesTrends(queryFilters),
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
    queryKey: ['sales-by-category', queryFilters],
    queryFn: () => reportApi.getSalesByCategory(queryFilters),
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
    queryClient.invalidateQueries({ queryKey: ['sales-pivot'] });
    queryClient.invalidateQueries({ queryKey: ['sales-unbilled-deliveries'] });
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

  // Issue #595: switching away from custom drops the (now meaningless) range.
  const updateComparison = (mode: string) =>
    setFilters((prev) => ({
      ...prev,
      comparison: mode === 'none' ? undefined : (mode as ReportFiltersType['comparison']),
      compare_start_date: mode === 'custom' ? prev.compare_start_date : undefined,
      compare_end_date: mode === 'custom' ? prev.compare_end_date : undefined,
    }));

  // Issue #598: applying a saved view restores its tab, filters and (pivot)
  // grouping. The product-only page hosts no tabs, so it only restores the
  // filters there.
  const applySavedView = ({ tab: savedTab, filters: savedFilters, grouping }: SavedViewApplied) => {
    if (!productOnly) setActiveTab(savedTab);
    setFilters(savedFilters);
    if (grouping) setPivotGrouping(grouping);
  };

  const savedViewTab = (productOnly ? 'by-product' : activeTab) as SalesViewTab;

  const hasCustomFilters =
    !!filters.tag ||
    !!filters.currency_code ||
    !!filters.customer_id ||
    !!filters.product_id ||
    !!filters.category_id ||
    !!filters.salesperson_id ||
    !!filters.channel ||
    !!filters.legal_entity_id ||
    !!filters.comparison ||
    filters.interval !== 'day' ||
    filters.start_date !== defaultFilters().start_date ||
    filters.end_date !== defaultFilters().end_date;

  const periodLabel = `${formatPeriodDate(filters.start_date)} → ${formatPeriodDate(filters.end_date)}`;
  const exportRange = `${filters.start_date}_${filters.end_date}`;

  // ---------------------------------------------------------------------------
  // Issue #595: Δ + Δ% columns for one metric. Columns key straight into the
  // flat `{metric}_delta[_percent]` fields the API adds to each row, so a row
  // without a comparison renders '-'. Hidden entirely without a comparison.
  // ---------------------------------------------------------------------------
  const hasComparison = !!queryFilters.comparison;

  const comparisonColumns = (metric: string, label: string): Column[] =>
    hasComparison
      ? [
          {
            key: `${metric}_delta`,
            label: `Δ ${label}`,
            sortable: true,
            align: 'right',
            className: 'tabular-nums',
            format: (value: number) => <DeltaCell delta={value} />,
          },
          {
            key: `${metric}_delta_percent`,
            label: `Δ% ${label}`,
            sortable: true,
            align: 'right',
            className: 'tabular-nums',
            format: (value: number) => <DeltaPercentCell percent={value} />,
          },
        ]
      : [];

  // ---------------------------------------------------------------------------
  // Columns — numeric columns are tabular so figures line up vertically.
  // ---------------------------------------------------------------------------
  const productColumns: Column[] = [
    {
      key: 'name',
      label: 'Product',
      sortable: true,
      filterable: true,
      format: (value: any, row: any) =>
        row?.id
          ? <Link to={`/products/${row.id}`} className="font-medium hover:underline">{value}</Link>
          : value,
    },
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
    ...comparisonColumns('total_revenue', 'Revenue'),
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
    {
      key: 'name',
      label: 'Customer',
      sortable: true,
      filterable: true,
      format: (value: any, row: any) =>
        row?.id
          ? <Link to={`/customers/${row.id}`} className="font-medium hover:underline">{value}</Link>
          : value,
    },
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
    ...comparisonColumns('total_revenue', 'Revenue'),
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
    {
      key: 'category_name',
      label: 'Category',
      sortable: true,
      filterable: true,
      format: (value: any, row: any) =>
        row?.category_id
          ? <Link to={`/products?category=${row.category_id}`} className="font-medium hover:underline">{value}</Link>
          : value,
    },
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
    ...comparisonColumns('revenue', 'Revenue'),
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
      format: (value) => formatTrendPeriod(String(value), filters.interval),
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
    ...comparisonColumns('revenue', 'Revenue'),
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
      formatTrendPeriod(t.period, filters.interval),
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
          <Label className="text-xs font-medium text-muted-foreground">Currency</Label>
          <Select
            value={filters.currency_code || 'all'}
            onValueChange={(value) => updateFilter('currency_code', value === 'all' ? undefined : value)}
          >
            <SelectTrigger className="h-9 w-[140px] rounded-full text-sm" aria-label="Currency">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All currencies</SelectItem>
              {(sellableCurrencies as any[]).map((currency: any) => (
                <SelectItem key={currency.code} value={currency.code}>{currency.code}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <FilterSelect
          label="Customer"
          allLabel="All customers"
          value={filters.customer_id ? String(filters.customer_id) : 'all'}
          onChange={(value) => updateFilter('customer_id', value === undefined ? undefined : Number(value))}
          options={filterCustomers.map((customer) => ({ value: String(customer.id), label: customer.name }))}
        />

        <FilterSelect
          label="Product"
          allLabel="All products"
          value={filters.product_id ? String(filters.product_id) : 'all'}
          onChange={(value) => updateFilter('product_id', value === undefined ? undefined : Number(value))}
          options={filterProducts.map((product) => ({ value: String(product.id), label: product.name }))}
        />

        <FilterSelect
          label="Category"
          allLabel="All categories"
          value={filters.category_id ? String(filters.category_id) : 'all'}
          onChange={(value) => updateFilter('category_id', value === undefined ? undefined : Number(value))}
          options={filterCategories.map((category) => ({ value: String(category.id), label: category.name }))}
        />

        <FilterSelect
          label="Salesperson"
          allLabel="All salespeople"
          value={filters.salesperson_id ? String(filters.salesperson_id) : 'all'}
          onChange={(value) => updateFilter('salesperson_id', value === undefined ? undefined : Number(value))}
          options={filterSalespersons.map((person) => ({ value: String(person.id), label: person.name }))}
        />

        <FilterSelect
          label="Channel"
          allLabel="All channels"
          value={filters.channel || 'all'}
          onChange={(value) => updateFilter('channel', value as ReportFiltersType['channel'])}
          options={[
            { value: 'pos', label: 'POS' },
            { value: 'admin', label: 'Admin' },
            { value: 'ecommerce', label: 'E-commerce' },
            { value: 'storefront', label: 'Storefront' },
          ]}
        />

        <FilterSelect
          label="Legal entity"
          allLabel="All entities"
          width="w-[170px]"
          value={filters.legal_entity_id ? String(filters.legal_entity_id) : 'all'}
          onChange={(value) => updateFilter('legal_entity_id', value === undefined ? undefined : Number(value))}
          options={filterEntities.map((entity) => ({ value: String(entity.id), label: entity.name }))}
        />

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

        {/* Issue #595: comparison period — derived (previous period / same
            period last year) or an explicit custom range. Every tab then shows
            Δ and Δ% columns for its headline metric. */}
        <div className="space-y-1.5">
          <Label className="text-xs font-medium text-muted-foreground">Compare</Label>
          <Select value={filters.comparison ?? 'none'} onValueChange={updateComparison}>
            <SelectTrigger className="h-9 w-[200px] rounded-full text-sm" aria-label="Comparison period">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="none">No comparison</SelectItem>
              <SelectItem value="previous_period">Previous period</SelectItem>
              <SelectItem value="same_period_last_year">Same period last year</SelectItem>
              <SelectItem value="custom">Custom range</SelectItem>
            </SelectContent>
          </Select>
        </div>

        {filters.comparison === 'custom' && (
          <>
            <div className="space-y-1.5">
              <Label htmlFor="sales-compare-from" className="text-xs font-medium text-muted-foreground">
                Compare from
              </Label>
              <Input
                id="sales-compare-from"
                type="date"
                aria-label="Compare from"
                value={filters.compare_start_date || ''}
                onChange={(e) => updateFilter('compare_start_date', e.target.value)}
                className="h-9 w-[150px] rounded-full text-sm"
              />
            </div>
            <span className="pb-2 text-xs text-muted-foreground">→</span>
            <div className="space-y-1.5">
              <Label htmlFor="sales-compare-to" className="text-xs font-medium text-muted-foreground">
                Compare to
              </Label>
              <Input
                id="sales-compare-to"
                type="date"
                aria-label="Compare to"
                value={filters.compare_end_date || ''}
                onChange={(e) => updateFilter('compare_end_date', e.target.value)}
                className="h-9 w-[150px] rounded-full text-sm"
              />
            </div>
          </>
        )}

        {filters.comparison === 'custom' &&
          (!filters.compare_start_date || !filters.compare_end_date) && (
            <span className="pb-2 text-xs text-amber-600">Select a complete custom range</span>
          )}

        {hasCustomFilters && (
          <HeaderActionButton
            variant="ghost"
            icon="solar:restart-bold"
            onClick={() => setFilters(defaultFilters())}
          >
            Reset
          </HeaderActionButton>
        )}
        {filters.tag && (
          <p className="mt-3 flex w-full items-center gap-2 rounded-lg bg-amber-500/10 px-3 py-2 text-xs font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
            <Icon icon="solar:info-circle-bold" className="h-4 w-4 shrink-0" />
            {"POS sales excluded (POS orders can't be tagged)"}
          </p>
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
        <SavedViewBar
          tab={savedViewTab}
          filters={filters}
          grouping={pivotGrouping}
          onApply={applySavedView}
        />
        {filterCard}

        <div className="space-y-6">
          <ReportHeading
            title="Sales by Product"
            subtitle={`For the period ${periodLabel}${comparisonRangeLabel(productData?.comparison)}`}
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
                <StatCard
                  label="Products Sold"
                  value={formatNumber(products.length)}
                  icon="solar:box-bold-duotone"
                  tone="bg-primary/10 text-primary"
                />
                <StatCard
                  label="Total Revenue"
                  value={formatCurrency(sumBy(products, (p) => p.total_revenue))}
                  icon="solar:wallet-money-bold-duotone"
                  tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
                />
                <StatCard
                  label="Total Profit"
                  value={formatCurrency(sumBy(products, (p) => p.total_profit))}
                  icon="solar:graph-up-bold-duotone"
                  tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
                />
                <StatCard
                  label="Avg Margin"
                  value={`${avgBy(products, (p) => p.profit_margin).toFixed(2)}%`}
                  icon="solar:tag-price-bold-duotone"
                  tone="bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400"
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

          <GlReconciliationFooter filters={queryFilters} />
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
      {/* Issue #609: saved views (ReportSavedView::TABS) do not cover the
          Product Usage tab yet, so the bar is not offered there. */}
      {activeTab !== 'product-usage' && (
        <SavedViewBar
          tab={savedViewTab}
          filters={filters}
          grouping={pivotGrouping}
          onApply={applySavedView}
        />
      )}
      {filterCard}

      {/* MRD FR-8.1: order-currency dimension — document revenue with the
          snapshot-rate base equivalent per currency. */}
      {byCurrencyData?.currencies?.length ? (
        <Card className="rounded-xl">
          <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
            <div>
              <CardTitle className="text-base">Revenue by Currency</CardTitle>
              <p className="mt-0.5 text-sm text-muted-foreground">
                Base-equivalent total: {formatCurrency(byCurrencyData.summary?.total_base_revenue ?? 0)}
              </p>
            </div>
          </CardHeader>
          <CardContent className="pt-6">
            <div className="flex flex-wrap gap-3">
              {byCurrencyData.currencies.map((bucket) => (
                <div key={bucket.currency_code} className="rounded-xl border px-4 py-3">
                  <div className="flex items-center gap-2">
                    <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium">{bucket.currency_code}</span>
                    <span className="text-sm font-semibold">{formatCurrency(bucket.total_revenue, bucket.currency_code)}</span>
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">
                    ≈ {formatCurrency(bucket.base_revenue)} · {bucket.order_count} order(s)
                  </p>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      ) : null}

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
          <TabsTrigger value="pivot" className="gap-2 rounded-full px-4">
            <Icon icon="solar:widget-5-bold" className="h-4 w-4" />
            Pivot
          </TabsTrigger>
          <TabsTrigger value="unbilled" className="gap-2 rounded-full px-4">
            <Icon icon="solar:delivery-bold" className="h-4 w-4" />
            Unbilled Deliveries
          </TabsTrigger>
          <TabsTrigger value="product-usage" className="gap-2 rounded-full px-4">
            <Icon icon="solar:medical-kit-bold" className="h-4 w-4" />
            Product Usage
          </TabsTrigger>
        </TabsList>

        {/* ------------------------------- Customers ------------------------------ */}
        <TabsContent value="by-customer" className="space-y-6">
          <ReportHeading
            title="Sales by Customer"
            subtitle={`For the period ${periodLabel}${comparisonRangeLabel(customerData?.comparison)}`}
          >
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
                <StatCard
                  label="Customers"
                  value={formatNumber(customers.length)}
                  icon="solar:users-group-rounded-bold-duotone"
                  tone="bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400"
                />
                <StatCard
                  label="Total Revenue"
                  value={formatCurrency(sumBy(customers, (c) => c.total_revenue))}
                  icon="solar:wallet-money-bold-duotone"
                  tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
                />
                <StatCard
                  label="Total Orders"
                  value={formatNumber(sumBy(customers, (c) => c.total_orders))}
                  icon="solar:cart-large-2-bold-duotone"
                  tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400"
                />
                <StatCard
                  label="Avg Order Value"
                  value={formatCurrency(
                    aov(
                      sumBy(customers, (c) => c.total_revenue),
                      sumBy(customers, (c) => c.total_orders),
                    ),
                  )}
                  icon="solar:graph-up-bold-duotone"
                  tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
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
          <ReportHeading
            title="Sales Trends"
            subtitle={`For the period ${periodLabel}${comparisonRangeLabel(trendsData?.comparison)}`}
          >
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
                <StatCard
                  label="Total Revenue"
                  value={formatCurrency(sumBy(trends, (t) => t.total_revenue))}
                  icon="solar:wallet-money-bold-duotone"
                  tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
                />
                <StatCard
                  label="Total Orders"
                  value={formatNumber(sumBy(trends, (t) => t.invoice_count))}
                  icon="solar:cart-large-2-bold-duotone"
                  tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400"
                />
                <StatCard
                  label="Avg Order Value"
                  value={formatCurrency(
                    aov(
                      sumBy(trends, (t) => t.total_revenue),
                      sumBy(trends, (t) => t.invoice_count),
                    ),
                  )}
                  icon="solar:graph-up-bold-duotone"
                  tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
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
                    data={trends.map((t) => ({
                      period: formatTrendPeriod(t.period, filters.interval),
                      Revenue: t.total_revenue,
                    }))}
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
                    data={trends.map((t) => ({
                      period: formatTrendPeriod(t.period, filters.interval),
                      Orders: t.invoice_count,
                    }))}
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
          <ReportHeading
            title="Sales by Category"
            subtitle={`For the period ${periodLabel}${comparisonRangeLabel(categoryData?.comparison)}`}
          >
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
                <StatCard
                  label="Categories"
                  value={formatNumber(categories.length)}
                  icon="solar:layers-minimalistic-bold-duotone"
                  tone="bg-indigo-100 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400"
                />
                <StatCard
                  label="Total Revenue"
                  value={formatCurrency(sumBy(categories, (c) => c.total_revenue))}
                  icon="solar:wallet-money-bold-duotone"
                  tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
                />
                <StatCard
                  label="Total Profit"
                  value={formatCurrency(sumBy(categories, (c) => c.total_profit))}
                  icon="solar:graph-up-bold-duotone"
                  tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
                />
                <StatCard
                  label="Avg Margin"
                  value={`${avgBy(categories, (c) => c.profit_margin).toFixed(2)}%`}
                  icon="solar:tag-price-bold-duotone"
                  tone="bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400"
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

        {/* -------------------------------- Pivot --------------------------------- */}
        <TabsContent value="pivot" className="space-y-6">
          <SalesPivotView
            filters={pivotFilters}
            periodLabel={periodLabel}
            exportRange={exportRange}
            config={pivotGrouping}
            onConfigChange={setPivotGrouping}
          />
        </TabsContent>

        {/* --------------------------- Unbilled Deliveries ------------------------ */}
        <TabsContent value="unbilled" className="space-y-6">
          <UnbilledDeliveriesView
            filters={pivotFilters}
            periodLabel={periodLabel}
            exportRange={exportRange}
          />
        </TabsContent>

        {/* ---------------------- Product Usage (medical cases) ------------------- */}
        <TabsContent value="product-usage" className="space-y-6">
          <ProductUsageView filters={pivotFilters} periodLabel={periodLabel} />
        </TabsContent>
      </Tabs>

      <GlReconciliationFooter filters={queryFilters} />
    </div>
  );
}

export default SalesAnalyticsPage;
