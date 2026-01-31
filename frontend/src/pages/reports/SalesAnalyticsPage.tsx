import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ReportViewer } from '@/components/reports/ReportViewer';
import { ReportFilters } from '@/components/reports/ReportFilters';
import { ReportTable, Column } from '@/components/reports/ReportTable';
import { BarChart } from '@/components/charts/BarChart';
import { LineChart } from '@/components/charts/LineChart';
import { PieChart } from '@/components/charts/PieChart';
import reportApi from '@/lib/reportApi';
import { formatCurrency, formatNumber } from '@/lib/utils';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';

export function SalesAnalyticsPage() {
  const [activeTab, setActiveTab] = useState('by-product');
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
    enabled: activeTab === 'by-product',
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
    enabled: activeTab === 'by-customer',
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
    enabled: activeTab === 'trends',
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
    enabled: activeTab === 'by-category',
  });

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

  // Sales by Product Columns
  const productColumns: Column[] = [
    {
      key: 'name',
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
      key: 'total_quantity',
      label: 'Quantity Sold',
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

  // Sales by Customer Columns
  const customerColumns: Column[] = [
    {
      key: 'name',
      label: 'Customer',
      sortable: true,
      filterable: true,
    },
    {
      key: 'total_orders',
      label: 'Orders',
      sortable: true,
      align: 'right',
    },
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
      format: (value) => new Date(value).toLocaleDateString(),
    },
  ];

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Sales Analytics</h1>
          <p className="text-gray-600 dark:text-gray-400 mt-1">
            Analyze sales performance by product, customer, and trends
          </p>
        </div>
      </div>

      {/* Filters */}
      <ReportFilters
        onFilterChange={handleFilterChange}
        initialFilters={filters}
        showDateRange
        showInterval
      />

      {/* Analytics Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="grid w-full grid-cols-4">
          <TabsTrigger value="by-product">
            <Icon icon="solar:box-bold" className="mr-2 h-4 w-4" />
            By Product
          </TabsTrigger>
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

        {/* Sales by Product Tab */}
        <TabsContent value="by-product">
          <ReportViewer
            title="Sales by Product"
            description={`For the period ${filters.start_date} to ${filters.end_date}`}
            isLoading={isProductLoading}
            error={productError?.message || null}
            onRefresh={refetchProduct}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {productData && (
              <div className="space-y-6">
                {/* Top Products Chart */}
                <Card className="p-6">
                  <h3 className="text-lg font-semibold mb-4">Top 10 Products by Revenue</h3>
                  <BarChart
                    data={(productData?.products ?? []).slice(0, 10).map((p) => ({
                      name: p.name,
                      Revenue: p.total_revenue,
                      Profit: p.total_profit,
                    }))}
                    xKey="name"
                    yKeys={['Revenue', 'Profit']}
                    colors={['#3b82f6', '#8b5cf6']}
                    height={350}
                    formatYAxis="currency"
                  />
                </Card>

                {/* Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                  <Card className="p-4">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Products Sold
                    </div>
                    <div className="text-2xl font-bold">
                      {(productData?.products ?? []).length}
                    </div>
                  </Card>
                  <Card className="p-4 bg-blue-50 dark:bg-blue-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Revenue
                    </div>
                    <div className="text-2xl font-bold text-blue-600">
                      {formatCurrency(
                        (productData?.products ?? []).reduce((sum, p) => sum + p.total_revenue, 0)
                      )}
                    </div>
                  </Card>
                  <Card className="p-4 bg-green-50 dark:bg-green-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Profit
                    </div>
                    <div className="text-2xl font-bold text-green-600">
                      {formatCurrency(
                        (productData?.products ?? []).reduce((sum, p) => sum + p.total_profit, 0)
                      )}
                    </div>
                  </Card>
                  <Card className="p-4 bg-purple-50 dark:bg-purple-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Avg Profit Margin
                    </div>
                    <div className="text-2xl font-bold text-purple-600">
                      {(productData?.products ?? []).length > 0
                        ? (
                            (productData?.products ?? []).reduce((sum, p) => sum + p.profit_margin, 0) /
                            (productData?.products ?? []).length
                          ).toFixed(2)
                        : '0.00'}
                      %
                    </div>
                  </Card>
                </div>

                {/* Products Table */}
                <ReportTable
                  columns={productColumns}
                  data={productData?.products ?? []}
                  showPagination
                  showSearch
                  pageSize={20}
                />
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Sales by Customer Tab */}
        <TabsContent value="by-customer">
          <ReportViewer
            title="Sales by Customer"
            description={`For the period ${filters.start_date} to ${filters.end_date}`}
            isLoading={isCustomerLoading}
            error={customerError?.message || null}
            onRefresh={refetchCustomer}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {customerData && (
              <div className="space-y-6">
                {/* Top Customers Chart */}
                <Card className="p-6">
                  <h3 className="text-lg font-semibold mb-4">Top 10 Customers by Revenue</h3>
                  <BarChart
                    data={(customerData?.customers ?? []).slice(0, 10).map((c) => ({
                      name: c.name,
                      Revenue: c.total_revenue,
                    }))}
                    xKey="name"
                    yKeys={['Revenue']}
                    height={350}
                    formatYAxis="currency"
                    horizontal
                  />
                </Card>

                {/* Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                  <Card className="p-4">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Customers
                    </div>
                    <div className="text-2xl font-bold">
                      {(customerData?.customers ?? []).length}
                    </div>
                  </Card>
                  <Card className="p-4 bg-blue-50 dark:bg-blue-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Revenue
                    </div>
                    <div className="text-2xl font-bold text-blue-600">
                      {formatCurrency(
                        (customerData?.customers ?? []).reduce((sum, c) => sum + c.total_revenue, 0)
                      )}
                    </div>
                  </Card>
                  <Card className="p-4 bg-green-50 dark:bg-green-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Orders
                    </div>
                    <div className="text-2xl font-bold text-green-600">
                      {(customerData?.customers ?? []).reduce((sum, c) => sum + c.total_orders, 0)}
                    </div>
                  </Card>
                  <Card className="p-4 bg-purple-50 dark:bg-purple-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Avg Order Value
                    </div>
                    <div className="text-2xl font-bold text-purple-600">
                      {formatCurrency(
                        (customerData?.customers ?? []).length > 0
                          ? (customerData?.customers ?? []).reduce((sum, c) => sum + c.average_order_value, 0) /
                              (customerData?.customers ?? []).length
                          : 0
                      )}
                    </div>
                  </Card>
                </div>

                {/* Customers Table */}
                <ReportTable
                  columns={customerColumns}
                  data={customerData?.customers ?? []}
                  showPagination
                  showSearch
                  pageSize={20}
                />
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Sales Trends Tab */}
        <TabsContent value="trends">
          <ReportViewer
            title="Sales Trends"
            description={`For the period ${filters.start_date} to ${filters.end_date}`}
            isLoading={isTrendsLoading}
            error={trendsError?.message || null}
            onRefresh={refetchTrends}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {trendsData && (
              <div className="space-y-6">
                {/* Revenue Trend Chart */}
                <Card className="p-6">
                  <h3 className="text-lg font-semibold mb-4">Revenue Trend</h3>
                  <LineChart
                    data={(trendsData?.trends ?? []).map((t) => ({
                      period: t.period,
                      Revenue: t.total_revenue,
                      'Avg Order': t.avg_order_value,
                    }))}
                    xKey="period"
                    yKeys={['Revenue']}
                    height={350}
                    formatYAxis="currency"
                  />
                </Card>

                {/* Order Count Trend Chart */}
                <Card className="p-6">
                  <h3 className="text-lg font-semibold mb-4">Order Count Trend</h3>
                  <LineChart
                    data={(trendsData?.trends ?? []).map((t) => ({
                      period: t.period,
                      Orders: t.invoice_count,
                    }))}
                    xKey="period"
                    yKeys={['Orders']}
                    height={350}
                    formatYAxis="number"
                  />
                </Card>

                {/* Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <Card className="p-4 bg-blue-50 dark:bg-blue-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Revenue
                    </div>
                    <div className="text-2xl font-bold text-blue-600">
                      {formatCurrency(
                        (trendsData?.trends ?? []).reduce((sum, t) => sum + t.total_revenue, 0)
                      )}
                    </div>
                  </Card>
                  <Card className="p-4 bg-green-50 dark:bg-green-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Orders
                    </div>
                    <div className="text-2xl font-bold text-green-600">
                      {(trendsData?.trends ?? []).reduce((sum, t) => sum + t.invoice_count, 0)}
                    </div>
                  </Card>
                  <Card className="p-4 bg-purple-50 dark:bg-purple-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Avg Order Value
                    </div>
                    <div className="text-2xl font-bold text-purple-600">
                      {formatCurrency(
                        (trendsData?.trends ?? []).length > 0
                          ? (trendsData?.trends ?? []).reduce((sum, t) => sum + t.avg_order_value, 0) /
                              (trendsData?.trends ?? []).length
                          : 0
                      )}
                    </div>
                  </Card>
                </div>

                {/* Trends Table */}
                <ReportTable
                  columns={[
                    {
                      key: 'period',
                      label: 'Period',
                      sortable: true,
                    },
                    {
                      key: 'invoice_count',
                      label: 'Orders',
                      sortable: true,
                      align: 'right',
                    },
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
                  ]}
                  data={trendsData?.trends ?? []}
                  showPagination
                  showSearch={false}
                  pageSize={20}
                />
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Sales by Category Tab */}
        <TabsContent value="by-category">
          <ReportViewer
            title="Sales by Category"
            description={`For the period ${filters.start_date} to ${filters.end_date}`}
            isLoading={isCategoryLoading}
            error={categoryError?.message || null}
            onRefresh={refetchCategory}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {categoryData && (
              <div className="space-y-6">
                {/* Category Distribution Chart */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <Card className="p-6">
                    <h3 className="text-lg font-semibold mb-4">Revenue Distribution</h3>
                    <PieChart
                      data={(categoryData?.categories ?? []).map((c) => ({
                        name: c.category_name,
                        value: c.total_revenue,
                      }))}
                      height={350}
                      formatValue="currency"
                    />
                  </Card>

                  <Card className="p-6">
                    <h3 className="text-lg font-semibold mb-4">Revenue by Category</h3>
                    <BarChart
                      data={(categoryData?.categories ?? []).map((c) => ({
                        name: c.category_name,
                        Revenue: c.total_revenue,
                      }))}
                      xKey="name"
                      yKeys={['Revenue']}
                      height={350}
                      formatYAxis="currency"
                    />
                  </Card>
                </div>

                {/* Summary Card */}
                <Card className="p-6 bg-blue-50 dark:bg-blue-900/20">
                  <div className="flex justify-between items-center">
                    <span className="text-lg font-semibold">Total Revenue:</span>
                    <span className="text-3xl font-bold text-blue-600">
                      {formatCurrency(
                        (categoryData?.categories ?? []).reduce((sum, c) => sum + c.total_revenue, 0)
                      )}
                    </span>
                  </div>
                </Card>

                {/* Categories Table */}
                <ReportTable
                  columns={[
                    {
                      key: 'category_name',
                      label: 'Category',
                      sortable: true,
                      filterable: true,
                    },
                    {
                      key: 'total_revenue',
                      label: 'Revenue',
                      sortable: true,
                      align: 'right',
                      format: (value) => formatCurrency(value),
                    },
                    {
                      key: 'percentage',
                      label: 'Percentage',
                      sortable: true,
                      align: 'right',
                      format: (value) => (
                        <div className="flex items-center justify-end gap-2">
                          <div className="w-24 bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                            <div
                              className="bg-blue-600 h-2 rounded-full"
                              style={{ width: `${value}%` }}
                            />
                          </div>
                          <span className="font-semibold">{value.toFixed(1)}%</span>
                        </div>
                      ),
                    },
                  ]}
                  data={categoryData?.categories ?? []}
                  showPagination={false}
                  showSearch={false}
                />
              </div>
            )}
          </ReportViewer>
        </TabsContent>
      </Tabs>
    </div>
  );
}
