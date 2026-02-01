import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { DollarSign, TrendingUp, Calendar, RefreshCw } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { MetricCard, DashboardGrid } from '@/components/dashboards';
import { LineChart } from '@/components/charts/LineChart';
import { BarChart } from '@/components/charts/BarChart';
import { PieChart } from '@/components/charts/PieChart';
import reportApi from '@/lib/reportApi';
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
        start_date: startDate.toISOString().split('T')[0],
        end_date: endDate.toISOString().split('T')[0],
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
  const { data: allMetrics } = useQuery({
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
      
      return {
        today: (todayData?.products ?? []).reduce((sum, p) => sum + (Number(p.total_sales) || 0), 0),
        week: (weekData?.products ?? []).reduce((sum, p) => sum + (Number(p.total_sales) || 0), 0),
        month: (monthData?.products ?? []).reduce((sum, p) => sum + (Number(p.total_sales) || 0), 0),
        year: (yearData?.products ?? []).reduce((sum, p) => sum + (Number(p.total_sales) || 0), 0),
      };
    },
    staleTime: 5 * 60 * 1000, // Cache for 5 minutes
  });

  // Fetch dashboard data for selected period
  const { data, isLoading, error, refetch } = useQuery<SalesDashboardData>({
    queryKey: ['sales-dashboard', selectedPeriod, selectedMonth, selectedYear],
    queryFn: async () => {
      // Aggregate data from multiple endpoints
      const [salesTrends, salesByProduct, salesByCustomer, salesByCategory] = await Promise.all([
        reportApi.getSalesTrends({ ...currentFilters, interval: 'day' }),
        reportApi.getSalesByProduct(currentFilters),
        reportApi.getSalesByCustomer(currentFilters),
        reportApi.getSalesByCategory(currentFilters),
      ]);

      // Calculate metrics for selected period
      const totalSales = (salesByProduct?.products ?? []).reduce(
        (sum, p) => sum + (Number(p.total_sales) || 0),
        0
      );

      return {
        metrics: {
          today: allMetrics?.today ?? 0,
          week: allMetrics?.week ?? 0,
          month: allMetrics?.month ?? 0,
          year: allMetrics?.year ?? 0,
          selected: totalSales,
        },
        sales_by_product: (salesByProduct?.products ?? []).map((p) => ({
          product: p.product_name || p.name || 'Unknown',
          amount: Number(p.total_sales || p.total_revenue) || 0,
        })),
        sales_by_customer: (salesByCustomer?.customers ?? []).map((c) => ({
          customer: c.customer_name || c.name || 'Unknown',
          amount: Number(c.total_sales || c.total_revenue) || 0,
        })),
        sales_trends: (salesTrends?.trends ?? []).map((t) => ({
          period: t.period || '',
          amount: Number(t.total_sales || t.revenue) || 0,
        })),
        top_selling_products: (salesByProduct?.products ?? [])
          .slice(0, 10)
          .map((p) => ({
            name: p.product_name || p.name || 'Unknown',
            quantity: Number(p.quantity_sold || p.total_quantity) || 0,
          })),
        sales_by_category: (salesByCategory?.categories ?? []).map((c) => ({
          category: c.category_name || 'Unknown',
          amount: Number(c.total_sales || c.revenue) || 0,
        })),
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
            {error instanceof Error ? error.message : 'Failed to load sales dashboard data'}
          </p>
        </div>
      </div>
    );
  }

  const formatCurrency = (value: number | undefined | null) => {
    const numValue = Number(value) || 0;
    return `${numValue.toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })}`;
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Sales Dashboard</h1>
          <p className="text-muted-foreground mt-1">
            Comprehensive sales analytics and performance metrics
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
      <div className="flex items-center gap-4">
        <div className="flex gap-2">
          {(['today', 'week', 'month', 'year'] as const).map((period) => (
            <button
              key={period}
              onClick={() => setSelectedPeriod(period)}
              className={`px-4 py-2 rounded-lg font-medium transition-colors ${
                selectedPeriod === period
                  ? 'bg-blue-600 text-white'
                  : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700'
              }`}
            >
              {period.charAt(0).toUpperCase() + period.slice(1)}
            </button>
          ))}
        </div>
        
        {/* Month/Year Selector - Only show when "Month" is selected */}
        {selectedPeriod === 'month' && (
          <div className="flex items-center gap-2 ml-auto">
            <select
              value={selectedMonth}
              onChange={(e) => setSelectedMonth(parseInt(e.target.value))}
              className="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              {[
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'
              ].map((month, index) => (
                <option key={index} value={index}>
                  {month}
                </option>
              ))}
            </select>
            <select
              value={selectedYear}
              onChange={(e) => setSelectedYear(parseInt(e.target.value))}
              className="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              {[now.getFullYear(), now.getFullYear() - 1, now.getFullYear() - 2].map((year) => (
                <option key={year} value={year}>
                  {year}
                </option>
              ))}
            </select>
          </div>
        )}
      </div>

      {/* Sales Metrics */}
      <DashboardGrid columns={4} gap="md">
        <MetricCard
          label="Today's Sales"
          value={isLoading ? '...' : formatCurrency(data?.metrics.today || 0)}
          icon={DollarSign}
          iconColor="text-green-600"
          loading={isLoading}
        />
        <MetricCard
          label="This Week"
          value={isLoading ? '...' : formatCurrency(data?.metrics.week || 0)}
          icon={Calendar}
          iconColor="text-blue-600"
          loading={isLoading}
        />
        <MetricCard
          label="This Month"
          value={isLoading ? '...' : formatCurrency(data?.metrics.month || 0)}
          icon={TrendingUp}
          iconColor="text-purple-600"
          loading={isLoading}
        />
        <MetricCard
          label="This Year"
          value={isLoading ? '...' : formatCurrency(data?.metrics.year || 0)}
          icon={TrendingUp}
          iconColor="text-orange-600"
          loading={isLoading}
        />
      </DashboardGrid>

      {/* Charts Row 1 */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Sales Trends Chart */}
        <Card>
          <CardHeader>
            <CardTitle>Sales Trends</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="h-[300px] flex items-center justify-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
              </div>
            ) : data && data.sales_trends.length > 0 ? (
              <LineChart
                data={data.sales_trends}
                xKey="period"
                yKeys={['amount']}
                formatYAxis="currency"
                height={300}
                colors={['#3b82f6']}
              />
            ) : (
              <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                No sales trends data available
              </div>
            )}
          </CardContent>
        </Card>

        {/* Sales by Category Chart */}
        <Card>
          <CardHeader>
            <CardTitle>Sales by Category</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="h-[300px] flex items-center justify-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
              </div>
            ) : data && data.sales_by_category.length > 0 ? (
              <PieChart
                data={data.sales_by_category}
                nameKey="category"
                valueKey="amount"
                formatValue="currency"
                height={300}
              />
            ) : (
              <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                No category data available
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Charts Row 2 */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Sales by Product Chart */}
        <Card>
          <CardHeader>
            <CardTitle>Sales by Product (Top 10)</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="h-[350px] flex items-center justify-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
              </div>
            ) : data && data.sales_by_product.length > 0 ? (
              <BarChart
                data={data.sales_by_product.slice(0, 10)}
                xKey="product"
                yKeys={['amount']}
                formatYAxis="currency"
                height={350}
                horizontal={true}
                colors={['#10b981']}
              />
            ) : (
              <div className="h-[350px] flex items-center justify-center text-muted-foreground">
                No product data available
              </div>
            )}
          </CardContent>
        </Card>

        {/* Sales by Customer Table */}
        <Card>
          <CardHeader>
            <CardTitle>Top Customers</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="space-y-3">
                {[1, 2, 3, 4, 5, 6, 7, 8].map((i) => (
                  <div key={i} className="flex items-center justify-between">
                    <div className="h-4 w-40 bg-gray-200 animate-pulse rounded" />
                    <div className="h-4 w-24 bg-gray-200 animate-pulse rounded" />
                  </div>
                ))}
              </div>
            ) : data && data.sales_by_customer.length > 0 ? (
              <div className="space-y-2 max-h-[350px] overflow-y-auto">
                {data.sales_by_customer.slice(0, 15).map((customer, index) => (
                  <div
                    key={index}
                    className="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                  >
                    <div className="flex items-center gap-3">
                      <div className="flex items-center justify-center w-8 h-8 bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300 rounded-full font-medium text-sm">
                        {index + 1}
                      </div>
                      <span className="font-medium">{customer.customer}</span>
                    </div>
                    <span className="text-green-600 font-medium">
                      {formatCurrency(customer.amount)}
                    </span>
                  </div>
                ))}
              </div>
            ) : (
              <div className="h-[350px] flex items-center justify-center text-muted-foreground">
                No customer data available
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Top Selling Products List */}
      <Card>
        <CardHeader>
          <CardTitle>Top Selling Products by Quantity</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
              {[1, 2, 3, 4, 5].map((i) => (
                <div key={i} className="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                  <div className="h-4 w-24 bg-gray-200 animate-pulse rounded mb-2" />
                  <div className="h-6 w-16 bg-gray-200 animate-pulse rounded" />
                </div>
              ))}
            </div>
          ) : data && data.top_selling_products.length > 0 ? (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
              {data.top_selling_products.map((product, index) => (
                <div
                  key={index}
                  className="p-4 bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900 dark:to-blue-800 rounded-lg border border-blue-200 dark:border-blue-700"
                >
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-sm text-gray-600 dark:text-gray-300 font-medium">
                      #{index + 1}
                    </span>
                    <div className="px-2 py-1 bg-blue-600 text-white text-xs rounded-full font-medium">
                      {product.quantity} sold
                    </div>
                  </div>
                  <p className="font-medium text-gray-900 dark:text-white line-clamp-2">
                    {product.name}
                  </p>
                </div>
              ))}
            </div>
          ) : (
            <div className="h-32 flex items-center justify-center text-muted-foreground">
              No product data available
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default SalesDashboard;
