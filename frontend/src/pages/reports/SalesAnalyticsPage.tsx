import { useState } from 'react';
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
import { Card } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ReportViewer } from '@/components/reports/ReportViewer';
import { CompactReportFilters } from '@/components/reports/CompactReportFilters';
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

export function SalesAnalyticsPage({ productOnly = false }: SalesAnalyticsPageProps) {
  const [activeTab, setActiveTab] = useState('by-customer');
  const queryClient = useQueryClient();
  useReportAutoRefresh();
  const [filters, setFilters] = useState<ReportFiltersType>({
    start_date: new Date(new Date().getFullYear(), new Date().getMonth(), 1)
      .toISOString()
      .split('T')[0],
    end_date: new Date().toISOString().split('T')[0],
    interval: 'day',
  });

  // Sales by Product Query
  const {
    data: productData,
    isLoading: isProductLoading,
    error: productError,
    refetch: refetchProduct,
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
    error: customerError,
    refetch: refetchCustomer,
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
    error: trendsError,
    refetch: refetchTrends,
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
    error: categoryError,
    refetch: refetchCategory,
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

  const handleFilterChange = (newFilters: ReportFiltersType) => {
    setFilters(newFilters);
  };

  const handleExport = () => {
    // TODO: Implement export functionality
    console.log('Export report:', activeTab);
  };

  const handlePrint = () => {
    window.print();
  };

  const handleRefresh = () => {
    queryClient.invalidateQueries({ queryKey: ['sales-by-product'] });
    queryClient.invalidateQueries({ queryKey: ['sales-by-customer'] });
    queryClient.invalidateQueries({ queryKey: ['sales-trends'] });
    queryClient.invalidateQueries({ queryKey: ['sales-by-category'] });
  };

  const periodLabel = `${filters.start_date} → ${filters.end_date}`;

  // ---------------------------------------------------------------------------
  // Columns
  // ---------------------------------------------------------------------------
  const productColumns: Column[] = [
    { key: 'name', label: 'Product', sortable: true, filterable: true },
    { key: 'sku', label: 'SKU', sortable: true, filterable: true },
    {
      key: 'total_quantity',
      label: 'Qty Sold',
      sortable: true,
      align: 'right',
      format: (value) => formatNumber(value),
    },
    {
      key: 'total_revenue',
      label: 'Revenue',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'total_cost',
      label: 'Cost',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'total_profit',
      label: 'Profit',
      sortable: true,
      align: 'right',
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
      format: (value) => (
        <span className={value >= 0 ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold'}>
          {value.toFixed(2)}%
        </span>
      ),
    },
  ];

  const customerColumns: Column[] = [
    { key: 'name', label: 'Customer', sortable: true, filterable: true },
    { key: 'total_orders', label: 'Orders', sortable: true, align: 'right' },
    {
      key: 'total_revenue',
      label: 'Total Revenue',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'average_order_value',
      label: 'Avg Order Value',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'last_purchase_date',
      label: 'Last Purchase',
      sortable: true,
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
      format: (value) => formatNumber(value),
    },
    {
      key: 'quantity_sold',
      label: 'Qty Sold',
      sortable: true,
      align: 'right',
      format: (value) => formatNumber(value),
    },
    {
      key: 'total_revenue',
      label: 'Revenue',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'total_profit',
      label: 'Profit',
      sortable: true,
      align: 'right',
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
    { key: 'period', label: 'Period', sortable: true },
    { key: 'invoice_count', label: 'Orders', sortable: true, align: 'right', format: (value) => formatNumber(value) },
    {
      key: 'total_revenue',
      label: 'Revenue',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
    {
      key: 'avg_order_value',
      label: 'Avg Order Value',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
  ];

  const tabFilters = (
    <CompactReportFilters
      onFilterChange={handleFilterChange}
      initialFilters={filters}
      showDateRange
      showInterval
      showTag
      tagLabel="Tagged orders"
      showRefresh
      onRefresh={handleRefresh}
    />
  );

  // ---------------------------------------------------------------------------
  // Product-only view (used by /products/report)
  // ---------------------------------------------------------------------------
  if (productOnly) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-3xl font-bold">Product Sales Report</h1>
          <p className="text-gray-600 dark:text-gray-400 mt-1">
            Analyze sales performance by product
          </p>
        </div>

        {tabFilters}

        <ReportViewer
          title="Sales by Product"
          description={`For the period ${periodLabel}`}
          isLoading={isProductLoading}
          error={productError?.message || null}
          onRefresh={refetchProduct}
          onExport={handleExport}
          onPrint={handlePrint}
        >
          {productData && (
            <div className="space-y-6">
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

              <Card className="p-6">
                <div className="mb-4 flex items-baseline justify-between gap-4">
                  <h3 className="text-base font-semibold">Top 10 Products by Revenue</h3>
                  <span className="text-xs text-muted-foreground">Hover a bar for exact figures</span>
                </div>
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
              </Card>

              <Card className="p-6">
                <h3 className="mb-4 text-base font-semibold">All Products</h3>
                <ReportTable
                  columns={productColumns}
                  data={products}
                  showPagination
                  showSearch
                  pageSize={20}
                />
              </Card>
            </div>
          )}
        </ReportViewer>
      </div>
    );
  }

  // ---------------------------------------------------------------------------
  // Tabbed view
  // ---------------------------------------------------------------------------
  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Sales Analytics</h1>
          <p className="text-gray-600 dark:text-gray-400 mt-1">
            Analyze sales performance by customer, category, and trends
          </p>
        </div>
      </div>

      {tabFilters}

      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="grid w-full grid-cols-3">
          <TabsTrigger value="by-customer">
            <Icon icon="solar:users-group-rounded-bold" className="mr-2 h-4 w-4" />
            By Customer
          </TabsTrigger>
          <TabsTrigger value="trends">
            <Icon icon="solar:chart-2-bold" className="mr-2 h-4 w-4" />
            Trends
          </TabsTrigger>
          <TabsTrigger value="by-category">
            <Icon icon="solar:pie-chart-bold" className="mr-2 h-4 w-4" />
            By Category
          </TabsTrigger>
        </TabsList>

        {/* ------------------------------- Customers ------------------------------ */}
        <TabsContent value="by-customer">
          <ReportViewer
            title="Sales by Customer"
            description={`For the period ${periodLabel}`}
            isLoading={isCustomerLoading}
            error={customerError?.message || null}
            onRefresh={refetchCustomer}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {customerData && (
              <div className="space-y-6">
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
                    value={formatCurrency(avgBy(customers, (c) => c.average_order_value))}
                    icon={TrendingUp}
                  />
                </div>

                <Card className="p-6">
                  <div className="mb-4 flex items-baseline justify-between gap-4">
                    <h3 className="text-base font-semibold">Top 10 Customers by Revenue</h3>
                    <span className="text-xs text-muted-foreground">Hover a bar for the full name</span>
                  </div>
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
                </Card>

                <Card className="p-6">
                  <h3 className="mb-4 text-base font-semibold">Customer Detail</h3>
                  <ReportTable
                    columns={customerColumns}
                    data={customers}
                    showPagination
                    showSearch
                    pageSize={20}
                  />
                </Card>
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* -------------------------------- Trends -------------------------------- */}
        <TabsContent value="trends">
          <ReportViewer
            title="Sales Trends"
            description={`For the period ${periodLabel}`}
            isLoading={isTrendsLoading}
            error={trendsError?.message || null}
            onRefresh={refetchTrends}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {trendsData && (
              <div className="space-y-6">
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
                    value={formatCurrency(avgBy(trends, (t) => t.avg_order_value))}
                    icon={TrendingUp}
                  />
                </div>

                <Card className="p-6">
                  <div className="mb-4 flex items-baseline justify-between gap-4">
                    <h3 className="text-base font-semibold">Revenue Trend</h3>
                    <span className="text-xs text-muted-foreground">
                      {formatNumber(trends.length)} period{trends.length === 1 ? '' : 's'}
                    </span>
                  </div>
                  <LineChart
                    data={trends.map((t) => ({ period: t.period, Revenue: t.total_revenue }))}
                    xKey="period"
                    yKeys={['Revenue']}
                    colors={['#3b82f6']}
                    height={320}
                    formatYAxis="currency"
                    showLegend={false}
                    truncateLabels={14}
                    xAxisAngle={trends.length > 8 ? -30 : 0}
                  />
                </Card>

                <Card className="p-6">
                  <div className="mb-4 flex items-baseline justify-between gap-4">
                    <h3 className="text-base font-semibold">Order Volume</h3>
                    <span className="text-xs text-muted-foreground">
                      Orders per period
                    </span>
                  </div>
                  <LineChart
                    data={trends.map((t) => ({ period: t.period, Orders: t.invoice_count }))}
                    xKey="period"
                    yKeys={['Orders']}
                    colors={['#10b981']}
                    height={300}
                    formatYAxis="number"
                    showLegend={false}
                    truncateLabels={14}
                    xAxisAngle={trends.length > 8 ? -30 : 0}
                  />
                </Card>

                <Card className="p-6">
                  <h3 className="mb-4 text-base font-semibold">Period Detail</h3>
                  <ReportTable
                    columns={trendColumns}
                    data={trends}
                    showPagination={trends.length > 20}
                    showSearch={false}
                    pageSize={20}
                  />
                </Card>
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* ------------------------------ By Category ----------------------------- */}
        <TabsContent value="by-category">
          <ReportViewer
            title="Sales by Category"
            description={`For the period ${periodLabel}`}
            isLoading={isCategoryLoading}
            error={categoryError?.message || null}
            onRefresh={refetchCategory}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {categoryData && (
              <div className="space-y-6">
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
                  <Card className="p-6">
                    <h3 className="mb-4 text-base font-semibold">Revenue Distribution</h3>
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
                  </Card>

                  <Card className="p-6">
                    <h3 className="mb-4 text-base font-semibold">Revenue by Category</h3>
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
                  </Card>
                </div>

                <Card className="p-6">
                  <h3 className="mb-4 text-base font-semibold">Category Detail</h3>
                  <ReportTable
                    columns={categoryColumns}
                    data={categories}
                    showPagination={categories.length > 20}
                    showSearch={false}
                    pageSize={20}
                  />
                </Card>
              </div>
            )}
          </ReportViewer>
        </TabsContent>
      </Tabs>
    </div>
  );
}
