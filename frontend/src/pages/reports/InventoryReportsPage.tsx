import { useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ReportViewer } from '@/components/reports/ReportViewer';
import { CompactReportFilters } from '@/components/reports/CompactReportFilters';
import { ReportTable, Column } from '@/components/reports/ReportTable';
import { useReportAutoRefresh } from '@/hooks/useReportAutoRefresh';
import reportApi from '@/lib/reportApi';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';

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
    setFilters(newFilters);
  };

  const reportRef = useRef<HTMLDivElement>(null);

  // Stock Valuation Columns
  const valuationColumns: Column[] = [
    {
      key: 'product_name',
      label: 'Product',
      sortable: true,
      filterable: true,
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
          className={`px-2 py-1 rounded text-xs font-medium ${
            value === 'IN' || value === 'PURCHASE' || value === 'ADJUSTMENT_IN'
              ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
              : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
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
      format: (value) => (
        <span className={value > 0 ? 'text-green-600 font-medium' : 'text-red-600 font-medium'}>
          {value > 0 ? '+' : ''}{value}
        </span>
      ),
    },
    {
      key: 'running_balance',
      label: 'Balance',
      sortable: true,
      align: 'right',
      format: (value) => (
        <span className="font-semibold">{value}</span>
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
      format: (value, row) => (
        <span
          className={
            value <= row.reorder_level
              ? 'text-red-600 font-semibold'
              : 'text-gray-900 dark:text-gray-100'
          }
        >
          {value}
        </span>
      ),
    },
    {
      key: 'reorder_level',
      label: 'Reorder Level',
      sortable: true,
      align: 'right',
    },
    {
      key: 'suggested_quantity',
      label: 'Suggested Order',
      sortable: true,
      align: 'right',
      format: (value) => (
        <span className="font-semibold text-blue-600">{value}</span>
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
              ? 'text-red-600 font-semibold'
              : value > 60
                ? 'text-orange-600 font-semibold'
                : 'text-gray-900 dark:text-gray-100'
          }
        >
          {value}
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
      format: (value) => value.toFixed(2),
    },
    {
      key: 'days_in_inventory',
      label: 'Days in Inventory',
      sortable: true,
      align: 'right',
      format: (value) => Math.round(value),
    },
    {
      key: 'classification',
      label: 'Classification',
      sortable: true,
      filterable: true,
      format: (value) => {
        const colors: Record<string, string> = {
          fast: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
          medium: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
          slow: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        };
        return (
          <span className={`px-2 py-1 rounded text-xs font-medium ${colors[value] || ''}`}>
            {value.toUpperCase()}
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
      <CompactReportFilters
        plain
        onFilterChange={handleFilterChange}
        initialFilters={filters}
        showDateRange
      />

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
                  <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <Card className="p-4">
                      <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                        Opening Balance
                      </div>
                      <div className="text-2xl font-bold">
                        {movementData.summary.opening_balance ?? 0}
                      </div>
                    </Card>
                    <Card className="p-4 bg-green-50 dark:bg-green-900/20">
                      <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                        Total In
                      </div>
                      <div className="text-2xl font-bold text-green-600">
                        +{movementData.summary.total_in ?? 0}
                      </div>
                    </Card>
                    <Card className="p-4 bg-red-50 dark:bg-red-900/20">
                      <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                        Total Out
                      </div>
                      <div className="text-2xl font-bold text-red-600">
                        -{movementData.summary.total_out ?? 0}
                      </div>
                    </Card>
                    <Card className="p-4 bg-blue-50 dark:bg-blue-900/20">
                      <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                        Net Change
                      </div>
                      <div className={`text-2xl font-bold ${
                        (movementData.summary.net_change ?? 0) >= 0 
                          ? 'text-green-600' 
                          : 'text-red-600'
                      }`}>
                        {(movementData.summary.net_change ?? 0) >= 0 ? '+' : ''}
                        {movementData.summary.net_change ?? 0}
                      </div>
                    </Card>
                    <Card className="p-4">
                      <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                        Closing Balance
                      </div>
                      <div className="text-2xl font-bold">
                        {movementData.summary.closing_balance ?? 0}
                      </div>
                    </Card>
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
                  <Card className="p-8 text-center">
                    <Icon
                      icon="solar:check-circle-bold"
                      className="h-16 w-16 mx-auto mb-4 text-green-500"
                    />
                    <h3 className="text-lg font-semibold mb-2">All Stock Levels Good</h3>
                    <p className="text-gray-600 dark:text-gray-400">
                      No products are currently below their reorder level
                    </p>
                  </Card>
                ) : (
                  <>
                    <div className="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                      <div className="flex items-center gap-2">
                        <Icon icon="solar:danger-triangle-bold" className="h-5 w-5 text-yellow-600" />
                        <span className="font-semibold text-yellow-800 dark:text-yellow-200">
                          {(reorderData?.items ?? []).length} product(s) need reordering
                        </span>
                      </div>
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
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                  {Object.entries(agingData?.by_age_bucket ?? {}).map(([bucket, data]) => (
                    <Card key={bucket} className="p-4">
                      <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                        {bucket} days
                      </div>
                      <div className="text-2xl font-bold mb-1">
                        {(data as any).quantity} units
                      </div>
                      <div className="text-sm font-medium text-blue-600">
                        {formatCurrency((data as any).value)}
                      </div>
                    </Card>
                  ))}
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
                <Card className="p-6 bg-gray-50 dark:bg-gray-800">
                  <div className="flex justify-between items-center">
                    <span className="text-xl font-bold">Total Carrying Cost:</span>
                    <span className="text-2xl font-bold text-orange-600">
                      {formatCurrency(agingData?.total_carrying_cost ?? 0)}
                    </span>
                  </div>
                </Card>
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
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <Card className="p-6">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Average Turnover Ratio
                    </div>
                    <div className="text-3xl font-bold text-blue-600">
                      {(turnoverData?.average_turnover ?? 0).toFixed(2)}x
                    </div>
                  </Card>
                  <Card className="p-6">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Average Days in Inventory
                    </div>
                    <div className="text-3xl font-bold text-purple-600">
                      {Math.round(turnoverData?.average_days ?? 0)} days
                    </div>
                  </Card>
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
