import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ReportViewer } from '@/components/reports/ReportViewer';
import { CompactReportFilters } from '@/components/reports/CompactReportFilters';
import { StatCard } from '@/components/ui/stat-card';
import { ReportTable, Column } from '@/components/reports/ReportTable';
import { ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox';
import { Label } from '@/components/ui/label';
import { useReportAutoRefresh } from '@/hooks/useReportAutoRefresh';
import reportApi from '@/lib/reportApi';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';

/** Quantities read as 50, 1,320, 0.5 — not 50.0000. */
const formatQty = (value: unknown): string => {
  const num = Number(value ?? 0)
  if (!Number.isFinite(num)) return '0'
  return num.toLocaleString(undefined, { maximumFractionDigits: 4 })
}

export function InventoryReportsPage() {
  const [activeTab, setActiveTab] = useState('valuation');
  useReportAutoRefresh();
  const [filters, setFilters] = useState<ReportFiltersType>({
    start_date: new Date(new Date().getFullYear(), new Date().getMonth(), 1)
      .toISOString()
      .split('T')[0],
    end_date: new Date().toISOString().split('T')[0],
  });

  // Stock Valuation Query
  const {
    data: valuationData,
    isLoading: isValuationLoading,
    error: valuationError,
    refetch: refetchValuation,
  } = useQuery({
    queryKey: ['stock-valuation', filters],
    queryFn: () => reportApi.getStockValuation(filters),
    enabled: activeTab === 'valuation',
    staleTime: 15 * 1000,
  });

  // Stock Movement Query
  const {
    data: movementData,
    isLoading: isMovementLoading,
    error: movementError,
    refetch: refetchMovement,
  } = useQuery({
    queryKey: ['stock-movement', filters],
    queryFn: () => reportApi.getStockMovement(filters),
    enabled: activeTab === 'movement',
    staleTime: 15 * 1000,
  });

  // Reorder Query
  const {
    data: reorderData,
    isLoading: isReorderLoading,
    error: reorderError,
    refetch: refetchReorder,
  } = useQuery({
    queryKey: ['reorder-report', filters],
    queryFn: () => reportApi.getReorderReport(filters),
    enabled: activeTab === 'reorder',
    staleTime: 15 * 1000,
  });

  // Inventory Aging Query
  const {
    data: agingData,
    isLoading: isAgingLoading,
    error: agingError,
    refetch: refetchAging,
  } = useQuery({
    queryKey: ['inventory-aging', filters],
    queryFn: () => reportApi.getInventoryAging(filters),
    enabled: activeTab === 'aging',
    staleTime: 15 * 1000,
  });

  // Inventory Turnover Query
  const {
    data: turnoverData,
    isLoading: isTurnoverLoading,
    error: turnoverError,
    refetch: refetchTurnover,
  } = useQuery({
    queryKey: ['inventory-turnover', filters],
    queryFn: () => reportApi.getInventoryTurnover(filters),
    enabled: activeTab === 'turnover',
    staleTime: 15 * 1000,
  });

  const handleFilterChange = (newFilters: ReportFiltersType) => {
    // #1020: keep the product filter when the date bar emits its filters.
    setFilters((current) => ({ ...current, ...newFilters, product_id: current.product_id }));
  };

  /**
   * #1020: product name + short description, used by every report table so a
   * product field reads the same everywhere (matches the invoice picker).
   */
  const renderProductCell = (value: any, row: any, description?: string | null) => (
    <div className="min-w-0">
      {row?.product_id
        ? <Link to={`/products/${row.product_id}`} className="font-medium hover:underline">{value}</Link>
        : value}
      {description
        ? <div className="text-xs text-muted-foreground">{description}</div>
        : null}
    </div>
  );

  const reportRef = useRef<HTMLDivElement>(null);

  // Stock Valuation Columns
  const valuationColumns: Column[] = [
    {
      key: 'product_name',
      label: 'Product',
      sortable: true,
      filterable: true,
      format: (value: any, row: any) => renderProductCell(value, row, row?.short_description ?? row?.product_short_description),
    },
    {
      key: 'sku',
      label: 'SKU',
      sortable: true,
      filterable: true,
    },
    {
      key: 'category',
      label: 'Category',
      sortable: true,
      filterable: true,
    },
    {
      key: 'quantity',
      label: 'Quantity',
      sortable: true,
      align: 'right',
    },
    {
      key: 'cost',
      label: 'Unit Cost',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'value',
      label: 'Total Value',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'location',
      label: 'Location',
      sortable: true,
      filterable: true,
    },
  ];

  // Stock Movement Columns
  const movementColumns: Column[] = [
    {
      key: 'date',
      label: 'Date',
      sortable: true,
      format: (value) => formatDate(value),
    },
    {
      key: 'product_name',
      label: 'Product',
      sortable: true,
      filterable: true,
      format: (value: any, row: any) => renderProductCell(value, row, row?.short_description ?? row?.product_short_description),
    },
    {
      key: 'sku',
      label: 'SKU',
      sortable: true,
      filterable: true,
    },
    {
      key: 'type',
      label: 'Type',
      sortable: true,
      filterable: true,
      format: (value) => (
        <span
          className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
            value === 'IN' || value === 'PURCHASE' || value === 'ADJUSTMENT_IN'
              ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
              : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
          }`}
        >
          {value}
        </span>
      ),
    },
    {
      key: 'quantity',
      label: 'Quantity',
      sortable: true,
      align: 'right',
      format: (value, row) => {
        // Store quantities are always positive; direction lives in the
        // movement's signed effect (type / adjustment delta).
        const delta = Number(row.effective_quantity ?? value ?? 0)
        return (
          <span className={delta >= 0 ? 'text-green-600 font-medium tabular-nums' : 'text-red-600 font-medium tabular-nums'}>
            {delta > 0 ? '+' : ''}{formatQty(delta)}
          </span>
        )
      },
    },
    {
      key: 'running_balance',
      label: 'Balance',
      sortable: true,
      align: 'right',
      format: (value) => (
        <span className="font-semibold tabular-nums">{formatQty(value)}</span>
      ),
    },
    {
      key: 'reference',
      label: 'Reference',
      sortable: true,
      filterable: true,
    },
    {
      key: 'notes',
      label: 'Notes',
      sortable: false,
      format: (value) => value || '-',
    },
  ];

  // Reorder Columns
  const reorderColumns: Column[] = [
    {
      key: 'product_name',
      label: 'Product',
      sortable: true,
      filterable: true,
      format: (value: any, row: any) => renderProductCell(value, row, row?.short_description ?? row?.product_short_description),
    },
    {
      key: 'sku',
      label: 'SKU',
      sortable: true,
      filterable: true,
    },
    {
      key: 'current_stock',
      label: 'Current Stock',
      sortable: true,
      align: 'right',
      format: (value, row) => {
        const threshold = Number(row.effective_threshold ?? row.reorder_level ?? 10)
        return (
          <span
            className={
              Number(value) <= threshold
                ? 'text-red-600 font-semibold tabular-nums'
                : 'text-gray-900 dark:text-gray-100 tabular-nums'
            }
          >
            {formatQty(value)}
          </span>
        )
      },
    },
    {
      key: 'effective_threshold',
      label: 'Reorder Level',
      sortable: true,
      align: 'right',
      format: (value, row) => (
        <span className="tabular-nums" title={row.reorder_level == null ? 'Platform default / stock-level minimum' : undefined}>
          {formatQty(value ?? row.reorder_level ?? 10)}
        </span>
      ),
    },
    {
      key: 'suggested_quantity',
      label: 'Suggested Order',
      sortable: true,
      align: 'right',
      format: (value) => (
        <span className="font-semibold text-blue-600 tabular-nums">{formatQty(value)}</span>
      ),
    },
    {
      key: 'supplier_name',
      label: 'Supplier',
      sortable: true,
      filterable: true,
    },
    {
      key: 'last_purchase_price',
      label: 'Last Price',
      sortable: true,
      align: 'right',
      format: (value) => (value ? formatCurrency(value) : '-'),
    },
  ];

  // Aging Columns
  const agingColumns: Column[] = [
    {
      key: 'product_name',
      label: 'Product',
      sortable: true,
      filterable: true,
      format: (value: any, row: any) => renderProductCell(value, row, row?.short_description ?? row?.product_short_description),
    },
    {
      key: 'sku',
      label: 'SKU',
      sortable: true,
      filterable: true,
    },
    {
      key: 'quantity',
      label: 'Quantity',
      sortable: true,
      align: 'right',
    },
    {
      key: 'age_days',
      label: 'Age (Days)',
      sortable: true,
      align: 'right',
      format: (value) => (
        <span
          className={
            value > 90
              ? 'text-rose-600 font-semibold dark:text-rose-400'
              : value > 60
                ? 'text-orange-600 font-semibold dark:text-orange-400'
                : 'text-foreground'
          }
        >
          {Math.round(Number(value))}
        </span>
      ),
    },
    {
      key: 'age_bucket',
      label: 'Age Bucket',
      sortable: true,
      filterable: true,
      format: (value) => {
        const colors: Record<string, string> = {
          '0-30': 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
          '31-60': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
          '61-90': 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
          '90+': 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        };
        return (
          <span className={`px-2 py-1 rounded text-xs font-medium ${colors[value] || ''}`}>
            {value} days
          </span>
        );
      },
    },
    {
      key: 'value',
      label: 'Value',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'carrying_cost',
      label: 'Carrying Cost',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
  ];

  // Turnover Columns
  const turnoverColumns: Column[] = [
    {
      key: 'product_name',
      label: 'Product',
      sortable: true,
      filterable: true,
      format: (value: any, row: any) => renderProductCell(value, row, row?.short_description ?? row?.product_short_description),
    },
    {
      key: 'category',
      label: 'Category',
      sortable: true,
      filterable: true,
    },
    {
      key: 'turnover_ratio',
      label: 'Turnover Ratio',
      sortable: true,
      align: 'right',
      format: (value) => <span className="tabular-nums">{Number(value).toFixed(2)}</span>,
    },
    {
      key: 'days_in_inventory',
      label: 'Days in Inventory',
      sortable: true,
      align: 'right',
      format: (value) => <span className="tabular-nums">{Math.round(Number(value))}</span>,
    },
    {
      key: 'classification',
      label: 'Classification',
      sortable: true,
      filterable: true,
      format: (value) => {
        const colors: Record<string, string> = {
          fast: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
          medium: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
          slow: 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300',
          no_movement: 'bg-muted text-muted-foreground',
        };
        return (
          <span className={`inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ${colors[value] || 'bg-muted text-muted-foreground'}`}>
            {String(value).replace(/_/g, ' ').toUpperCase()}
          </span>
        );
      },
    },
  ];

const valuationTotal = Number(valuationData?.total_value ?? 0);
  const valuationByCategory = Object.entries(valuationData?.by_category ?? {}).sort((a, b) => Number(b[1]) - Number(a[1]));
  const valuationByLocation = Object.entries(valuationData?.by_location ?? {}).sort((a, b) => Number(b[1]) - Number(a[1]));

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Inventory Reports</h1>
          <p className="text-muted-foreground mt-1">
            View comprehensive inventory valuation, movement, and analytics
          </p>
        </div>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-start gap-3">
        <div className="w-full max-w-xs space-y-1.5">
          <Label htmlFor="inventory-product-filter">Product</Label>
          {/* #1020: shared server-side product picker with "Show more" catalog. */}
          <ProductSearchCombobox
            value={filters.product_id ? String(filters.product_id) : ''}
            onValueChange={(value) => setFilters((current) => ({
              ...current,
              product_id: value ? Number(value) : undefined,
            }))}
            placeholder="All products"
          />
        </div>
        <div className="min-w-[280px] flex-1">
          <CompactReportFilters
            plain
            onFilterChange={handleFilterChange}
            initialFilters={filters}
            showDateRange
          />
        </div>
      </div>

      {/* Reports Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="w-fit max-w-full">
          <TabsTrigger value="valuation">
            <Icon icon="solar:dollar-minimalistic-bold" className="mr-2 h-4 w-4" />
            Valuation
          </TabsTrigger>
          <TabsTrigger value="movement">
            <Icon icon="solar:transfer-horizontal-bold" className="mr-2 h-4 w-4" />
            Movement
          </TabsTrigger>
          <TabsTrigger value="reorder">
            <Icon icon="solar:cart-plus-bold" className="mr-2 h-4 w-4" />
            Reorder
          </TabsTrigger>
          <TabsTrigger value="aging">
            <Icon icon="solar:clock-circle-bold" className="mr-2 h-4 w-4" />
            Aging
          </TabsTrigger>
          <TabsTrigger value="turnover">
            <Icon icon="solar:refresh-circle-bold" className="mr-2 h-4 w-4" />
            Turnover
          </TabsTrigger>
        </TabsList>

        {/* Stock Valuation Tab */}
        <TabsContent value="valuation">
          <ReportViewer
            title="Stock Valuation Report"
            description={`As of ${formatDate(filters.end_date || '')}`}
            isLoading={isValuationLoading}
            error={valuationError?.message || null}
            onRefresh={refetchValuation}
            exportTargetRef={reportRef}
            exportFilename={`inventory-${activeTab}-${filters.start_date || 'all'}-${filters.end_date || 'all'}`}
          >
            {valuationData && (
              <div className="space-y-6">
                {/* Valuation Table */}
                <ReportTable
                  columns={valuationColumns}
                  data={valuationData?.items ?? []}
                  showPagination
                  showSearch
                  pageSize={20}
                />

                {/* Summary Section */}
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                  {/* Total value tile */}
                  <Card className="overflow-hidden rounded-xl">
                    <CardContent className="flex items-center justify-between gap-4 p-5">
                      <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">Total inventory value</p>
                        <p className="mt-1 text-2xl font-bold tabular-nums">{formatCurrency(valuationTotal)}</p>
                        <p className="mt-1 text-xs text-muted-foreground">Valued at cost across all warehouses</p>
                      </div>
                      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400">
                        <Icon icon="solar:wallet-money-bold-duotone" className="h-5 w-5" />
                      </div>
                    </CardContent>
                  </Card>

                  {/* By Category Breakdown */}
                  {valuationByCategory.length > 0 && (
                    <Card className="overflow-hidden rounded-xl">
                      <CardContent className="p-5">
                        <div className="mb-3 flex items-center justify-between gap-2">
                          <p className="text-sm font-semibold">Value by category</p>
                          <Icon icon="solar:layers-minimalistic-bold-duotone" className="h-4 w-4 text-muted-foreground/60" />
                        </div>
                        <div className="space-y-3">
                          {valuationByCategory.map(([category, value]) => {
                            const share = valuationTotal > 0 ? (Number(value) / valuationTotal) * 100 : 0;
                            return (
                              <div key={category}>
                                <div className="flex items-center justify-between gap-3 text-sm">
                                  <span className="truncate text-muted-foreground">{category}</span>
                                  <span className="shrink-0 font-medium tabular-nums">{formatCurrency(Number(value))}</span>
                                </div>
                                <div className="mt-1 flex items-center gap-2">
                                  <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                    <div className="h-full rounded-full bg-primary/60" style={{ width: `${Math.min(100, share)}%` }} />
                                  </div>
                                  <span className="w-11 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{share.toFixed(1)}%</span>
                                </div>
                              </div>
                            );
                          })}
                        </div>
                      </CardContent>
                    </Card>
                  )}

                  {/* By Location Breakdown */}
                  {valuationByLocation.length > 0 && (
                    <Card className="overflow-hidden rounded-xl">
                      <CardContent className="p-5">
                        <div className="mb-3 flex items-center justify-between gap-2">
                          <p className="text-sm font-semibold">Value by location</p>
                          <Icon icon="solar:map-point-bold-duotone" className="h-4 w-4 text-muted-foreground/60" />
                        </div>
                        <div className="space-y-3">
                          {valuationByLocation.map(([location, value]) => {
                            const share = valuationTotal > 0 ? (Number(value) / valuationTotal) * 100 : 0;
                            return (
                              <div key={location}>
                                <div className="flex items-center justify-between gap-3 text-sm">
                                  <span className="truncate text-muted-foreground">{location}</span>
                                  <span className="shrink-0 font-medium tabular-nums">{formatCurrency(Number(value))}</span>
                                </div>
                                <div className="mt-1 flex items-center gap-2">
                                  <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                    <div className="h-full rounded-full bg-emerald-500/60" style={{ width: `${Math.min(100, share)}%` }} />
                                  </div>
                                  <span className="w-11 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{share.toFixed(1)}%</span>
                                </div>
                              </div>
                            );
                          })}
                        </div>
                      </CardContent>
                    </Card>
                  )}
                </div>
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Stock Movement Tab */}
        <TabsContent value="movement">
          <ReportViewer
            title="Stock Movement Report"
            description={`For the period ${formatDate(filters.start_date || '')} to ${formatDate(filters.end_date || '')}`}
            isLoading={isMovementLoading}
            error={movementError?.message || null}
            onRefresh={refetchMovement}
            exportTargetRef={reportRef}
            exportFilename={`inventory-${activeTab}-${filters.start_date || 'all'}-${filters.end_date || 'all'}`}
          >
            {movementData && (
              <div className="space-y-6">
                {/* Summary Cards */}
                {movementData?.summary && (
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
                  <StatCard
                    label="Opening Balance"
                    value={formatQty(movementData.summary.opening_balance ?? 0)}
                    hint="Start of period"
                    icon="solar:archive-minimalistic-bold-duotone"
                  />
                  <StatCard
                    label="Total In"
                    value={`+${formatQty(movementData.summary.total_in ?? 0)}`}
                    hint="Stock received"
                    icon="solar:download-minimalistic-bold-duotone"
                    tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
                  />
                  <StatCard
                    label="Total Out"
                    value={`-${formatQty(movementData.summary.total_out ?? 0)}`}
                    hint="Stock issued"
                    icon="solar:upload-minimalistic-bold-duotone"
                    tone="bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400"
                  />
                  <StatCard
                    label="Net Change"
                    value={`${(movementData.summary.net_change ?? 0) >= 0 ? '+' : ''}${formatQty(movementData.summary.net_change ?? 0)}`}
                    hint={(movementData.summary.net_change ?? 0) >= 0 ? 'Net increase' : 'Net decrease'}
                    icon={(movementData.summary.net_change ?? 0) >= 0 ? 'solar:graph-up-bold-duotone' : 'solar:graph-down-bold-duotone'}
                    tone={(movementData.summary.net_change ?? 0) >= 0
                      ? 'bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400'
                      : 'bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400'}
                  />
                  <StatCard
                    label="Closing Balance"
                    value={formatQty(movementData.summary.closing_balance ?? 0)}
                    hint="End of period"
                    icon="solar:clipboard-check-bold-duotone"
                  />
                </div>
                )}

                {/* Movement Table */}
                <ReportTable
                  columns={movementColumns}
                  data={movementData?.movements ?? []}
                  showPagination
                  showSearch
                  pageSize={20}
                />

                {/* Movement Count */}
                {movementData?.summary && (
                  <Card className="p-4 bg-gray-50 dark:bg-gray-800">
                    <div className="flex justify-between items-center">
                      <span className="text-sm text-gray-600 dark:text-gray-400">
                        Total Movements:
                      </span>
                      <span className="font-semibold">
                        {movementData.summary.movement_count ?? 0} transactions
                      </span>
                    </div>
                  </Card>
                )}
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Reorder Tab */}
        <TabsContent value="reorder">
          <ReportViewer
            title="Reorder Report"
            description="Products below reorder level requiring restocking"
            isLoading={isReorderLoading}
            error={reorderError?.message || null}
            onRefresh={refetchReorder}
            exportTargetRef={reportRef}
            exportFilename={`inventory-${activeTab}-${filters.start_date || 'all'}-${filters.end_date || 'all'}`}
          >
            {reorderData && (
              <div className="space-y-6">
                {(reorderData?.items ?? []).length === 0 ? (
                  <div className="flex flex-col items-center gap-2 rounded-xl border bg-card px-6 py-16 text-center">
                    <Icon icon="solar:check-circle-bold-duotone" className="h-12 w-12 text-emerald-500/70" />
                    <h3 className="mt-2 text-base font-semibold">All stock levels good</h3>
                    <p className="text-sm text-muted-foreground">
                      No products are currently below their reorder level.
                    </p>
                  </div>
                ) : (
                  <>
                    <div className="flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50/70 p-4 dark:border-amber-900 dark:bg-amber-950/40">
                      <Icon icon="solar:danger-triangle-bold-duotone" className="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                      <span className="text-sm font-medium text-amber-900 dark:text-amber-200">
                        {(reorderData?.items ?? []).length} product{(reorderData?.items ?? []).length === 1 ? '' : 's'} need reordering
                      </span>
                    </div>
                    <ReportTable
                      columns={reorderColumns}
                      data={reorderData?.items ?? []}
                      showPagination
                      showSearch
                      pageSize={20}
                    />
                  </>
                )}
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Aging Tab */}
        <TabsContent value="aging">
          <ReportViewer
            title="Inventory Aging Report"
            description={`As of ${formatDate(filters.end_date || '')}`}
            isLoading={isAgingLoading}
            error={agingError?.message || null}
            onRefresh={refetchAging}
            exportTargetRef={reportRef}
            exportFilename={`inventory-${activeTab}-${filters.start_date || 'all'}-${filters.end_date || 'all'}`}
          >
            {agingData && (
              <div className="space-y-6">
                {/* Age Bucket Summary */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                  {Object.entries(agingData?.by_age_bucket ?? {}).map(([bucket, data]) => {
                    const tones: Record<string, { tile: string; badge: string; value: string }> = {
                      '0-30': {
                        tile: 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900 dark:bg-emerald-950/30',
                        badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
                        value: 'text-emerald-700 dark:text-emerald-300',
                      },
                      '31-60': {
                        tile: 'border-amber-200 bg-amber-50/50 dark:border-amber-900 dark:bg-amber-950/30',
                        badge: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
                        value: 'text-amber-700 dark:text-amber-300',
                      },
                      '61-90': {
                        tile: 'border-orange-200 bg-orange-50/50 dark:border-orange-900 dark:bg-orange-950/30',
                        badge: 'bg-orange-100 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300',
                        value: 'text-orange-700 dark:text-orange-300',
                      },
                      '90+': {
                        tile: 'border-rose-200 bg-rose-50/50 dark:border-rose-900 dark:bg-rose-950/30',
                        badge: 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300',
                        value: 'text-rose-700 dark:text-rose-300',
                      },
                    };
                    const tone = tones[bucket] ?? {
                      tile: 'border-slate-200 bg-card dark:border-slate-800',
                      badge: 'bg-muted text-muted-foreground',
                      value: 'text-foreground',
                    };
                    return (
                      <div key={bucket} className={`rounded-xl border p-4 ${tone.tile}`}>
                        <div className="flex items-center justify-between gap-2">
                          <p className="text-sm text-muted-foreground">{bucket} days</p>
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium tabular-nums ${tone.badge}`}>
                            {formatQty((data as any).quantity)} units
                          </span>
                        </div>
                        <p className={`mt-2 text-2xl font-bold tabular-nums ${tone.value}`}>
                          {formatCurrency((data as any).value)}
                        </p>
                      </div>
                    );
                  })}
                </div>

                {/* Aging Table */}
                <ReportTable
                  columns={agingColumns}
                  data={agingData?.items ?? []}
                  showPagination
                  showSearch
                  pageSize={20}
                />

                {/* Total Carrying Cost */}
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-5 py-4">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400">
                      <Icon icon="solar:hourglass-bold-duotone" className="h-5 w-5" />
                    </div>
                    <div>
                      <p className="text-sm font-medium">Total carrying cost</p>
                      <p className="text-xs text-muted-foreground">Cost of holding the aged stock above</p>
                    </div>
                  </div>
                  <span className="text-2xl font-bold tabular-nums text-amber-600 dark:text-amber-400">
                    {formatCurrency(agingData?.total_carrying_cost ?? 0)}
                  </span>
                </div>
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Turnover Tab */}
        <TabsContent value="turnover">
          <ReportViewer
            title="Inventory Turnover Report"
            description={`For the period ${formatDate(filters.start_date || '')} to ${formatDate(filters.end_date || '')}`}
            isLoading={isTurnoverLoading}
            error={turnoverError?.message || null}
            onRefresh={refetchTurnover}
            exportTargetRef={reportRef}
            exportFilename={`inventory-${activeTab}-${filters.start_date || 'all'}-${filters.end_date || 'all'}`}
          >
            {turnoverData && (
              <div className="space-y-6">
                {/* Summary Cards */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <StatCard
                    label="Average Turnover Ratio"
                    value={`${(turnoverData?.average_turnover ?? 0).toFixed(2)}x`}
                    hint="Higher is faster-moving stock"
                    icon="solar:refresh-circle-bold-duotone"
                    tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400"
                  />
                  <StatCard
                    label="Average Days in Inventory"
                    value={`${Math.round(turnoverData?.average_days ?? 0)} days`}
                    hint="Lower is faster-moving stock"
                    icon="solar:calendar-minimalistic-bold-duotone"
                    tone="bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400"
                  />
                </div>

                {/* Turnover Table */}
                <ReportTable
                  columns={turnoverColumns}
                  data={turnoverData?.items ?? []}
                  showPagination
                  showSearch
                  pageSize={20}
                />
              </div>
            )}
          </ReportViewer>
        </TabsContent>
      </Tabs>
    </div>
  );
}
