import React, { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { DollarSign, TrendingUp, Wallet, RefreshCw } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { KPICard, DashboardGrid } from '@/components/dashboards';
import { LineChart } from '@/components/charts/LineChart';
import { PieChart } from '@/components/charts/PieChart';
import { BarChart } from '@/components/charts/BarChart';
import reportApi from '@/lib/reportApi';
import type { ExecutiveDashboardData } from '@/types/reports';

/**
 * Executive Dashboard Page
 * High-level overview of business performance with key metrics and charts
 * Updated: 2026-01-02
 */
export const ExecutiveDashboard: React.FC = () => {
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [lastRefresh, setLastRefresh] = useState(new Date());
  
  // Initialize with current month
  const now = new Date();
  const [selectedMonth, setSelectedMonth] = useState(now.getMonth()); // 0-11
  const [selectedYear, setSelectedYear] = useState(now.getFullYear());

  // Calculate date range based on selected month/year
  const getDateFilters = () => {
    const startDate = new Date(selectedYear, selectedMonth, 1);
    const endDate = new Date(selectedYear, selectedMonth + 1, 0); // Last day of month
    
    return {
      start_date: startDate.toISOString().split('T')[0],
      end_date: endDate.toISOString().split('T')[0],
    };
  };

  const currentMonthFilters = getDateFilters();

  // Fetch dashboard data
  const { data, isLoading, error, refetch } = useQuery<ExecutiveDashboardData>({
    queryKey: ['executive-dashboard', selectedMonth, selectedYear],
    queryFn: async () => {
      try {
        // Since we don't have a dedicated dashboard endpoint yet,
        // we'll aggregate data from multiple endpoints
        const [profitLoss, salesTrends, salesByCategory, expensesByCategory, salesByProduct] =
          await Promise.all([
            reportApi.getProfitLoss(currentMonthFilters).catch(() => null),
            reportApi.getSalesTrends({ ...currentMonthFilters, interval: 'day' }).catch(() => null),
            reportApi.getSalesByCategory(currentMonthFilters).catch(() => null),
            reportApi.getExpensesByCategory(currentMonthFilters).catch(() => null),
            reportApi.getSalesByProduct(currentMonthFilters).catch(() => null),
          ]);

        // Transform data into dashboard format
        console.log('Dashboard API Responses:', {
          profitLoss,
          salesTrends,
          salesByCategory,
          expensesByCategory,
          salesByProduct
        });

        // Safely extract data with proper null checks
        const trends = Array.isArray(salesTrends?.trends) ? salesTrends.trends : [];
        const categories = Array.isArray(salesByCategory?.categories) ? salesByCategory.categories : [];
        const expenses = Array.isArray(expensesByCategory?.categories) ? expensesByCategory.categories : [];
        const products = Array.isArray(salesByProduct?.products) ? salesByProduct.products : [];

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
              current: profitLoss?.net_profit ?? 0,
              previous: profitLoss?.comparison?.profit_change
                ? (profitLoss?.net_profit ?? 0) / (1 + profitLoss.comparison.profit_change / 100)
                : 0,
              change_percentage: profitLoss?.comparison?.profit_change ?? 0,
            },
            cash: {
              current: (profitLoss?.revenue?.total ?? 0) - (profitLoss?.expenses?.total ?? 0),
              previous: 0,
              change_percentage: 0,
            },
          },
          sales_trends: trends
            .filter((trend) => trend && typeof trend === 'object' && trend.total_sales != null)
            .map((trend) => ({
              period: String(trend.period || ''),
              revenue: Number(trend.total_sales) || 0,
            })),
          revenue_by_category: categories
            .filter((cat) => cat && typeof cat === 'object' && cat.total_sales != null)
            .map((cat) => ({
              category: String(cat.category_name || 'Unknown'),
              amount: Number(cat.total_sales) || 0,
            })),
          expense_breakdown: expenses
            .filter((cat) => cat && typeof cat === 'object' && cat.total_amount != null)
            .map((cat) => ({
              category: String(cat.account_name || 'Unknown'),
              amount: Number(cat.total_amount) || 0,
            })),
          top_products: products
            .filter((prod) => prod && typeof prod === 'object' && prod.total_sales != null)
            .slice(0, 5)
            .map((prod) => ({
              name: String(prod.product_name || prod.name || 'Unknown Product'),
              revenue: Number(prod.total_sales || prod.total_revenue) || 0,
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

  // Month names for dropdown
  const monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
  ];

  // Generate year options (current year and 2 years back)
  const currentYear = new Date().getFullYear();
  const yearOptions = [currentYear, currentYear - 1, currentYear - 2];

  const handleMonthChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    setSelectedMonth(parseInt(e.target.value));
  };

  const handleYearChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    setSelectedYear(parseInt(e.target.value));
  };

  if (error) {
    return (
      <div className="p-6">
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <h3 className="text-red-800 font-medium">Error Loading Dashboard</h3>
          <p className="text-red-600 text-sm mt-1">
            {error instanceof Error ? error.message : 'Failed to load dashboard data'}
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Executive Dashboard</h1>
          <p className="text-muted-foreground mt-1">
            High-level overview of business performance
          </p>
        </div>
        <div className="flex items-center gap-4">
          {/* Month/Year Selector */}
          <div className="flex items-center gap-2">
            <select
              value={selectedMonth}
              onChange={handleMonthChange}
              className="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              {monthNames.map((month, index) => (
                <option key={index} value={index}>
                  {month}
                </option>
              ))}
            </select>
            <select
              value={selectedYear}
              onChange={handleYearChange}
              className="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              {yearOptions.map((year) => (
                <option key={year} value={year}>
                  {year}
                </option>
              ))}
            </select>
          </div>
          
          <div className="text-sm text-muted-foreground">
            Last updated: {lastRefresh.toLocaleTimeString()}
          </div>
          <button
            onClick={handleManualRefresh}
            className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
            disabled={isLoading}
          >
            <RefreshCw className={`h-4 w-4 ${isLoading ? 'animate-spin' : ''}`} />
            Refresh
          </button>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={autoRefresh}
              onChange={(e) => setAutoRefresh(e.target.checked)}
              className="rounded"
            />
            Auto-refresh (5 min)
          </label>
        </div>
      </div>

      {/* KPI Cards */}
      <DashboardGrid columns={3} gap="md">
        <KPICard
          title="Total Revenue"
          value={
            isLoading
              ? '...'
              : `$${(data?.kpis.revenue.current || 0).toLocaleString('en-US', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2,
                })}`
          }
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
          subtitle={`${monthNames[selectedMonth]} ${selectedYear}`}
          loading={isLoading}
        />
        <KPICard
          title="Net Profit"
          value={
            isLoading
              ? '...'
              : `$${(data?.kpis.profit.current || 0).toLocaleString('en-US', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2,
                })}`
          }
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
          subtitle={`${monthNames[selectedMonth]} ${selectedYear}`}
          loading={isLoading}
        />
        <KPICard
          title="Cash Position"
          value={
            isLoading
              ? '...'
              : `$${(data?.kpis.cash.current || 0).toLocaleString('en-US', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2,
                })}`
          }
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
          subtitle="Available cash"
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
            ) : data && Array.isArray(data.sales_trends) && data.sales_trends.length > 0 ? (
              <LineChart
                data={data.sales_trends}
                xKey="period"
                yKeys={['revenue']}
                formatYAxis="currency"
                height={300}
              />
            ) : (
              <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                No sales data available
              </div>
            )}
          </CardContent>
        </Card>

        {/* Revenue by Category Chart */}
        <Card>
          <CardHeader>
            <CardTitle>Revenue by Category</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="h-[300px] flex items-center justify-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
              </div>
            ) : data && Array.isArray(data.revenue_by_category) && data.revenue_by_category.length > 0 ? (
              <PieChart
                data={data.revenue_by_category}
                nameKey="category"
                valueKey="amount"
                formatValue="currency"
                height={300}
                innerRadius={60}
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
        {/* Expense Breakdown Chart */}
        <Card>
          <CardHeader>
            <CardTitle>Expense Breakdown</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="h-[300px] flex items-center justify-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
              </div>
            ) : data && Array.isArray(data.expense_breakdown) && data.expense_breakdown.length > 0 ? (
              <BarChart
                data={data.expense_breakdown}
                xKey="category"
                yKeys={['amount']}
                formatYAxis="currency"
                height={300}
                colors={['#ef4444']}
              />
            ) : (
              <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                No expense data available
              </div>
            )}
          </CardContent>
        </Card>

        {/* Top Products List */}
        <Card>
          <CardHeader>
            <CardTitle>Top Products</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="space-y-3">
                {[1, 2, 3, 4, 5].map((i) => (
                  <div key={i} className="flex items-center justify-between">
                    <div className="h-4 w-32 bg-gray-200 animate-pulse rounded" />
                    <div className="h-4 w-20 bg-gray-200 animate-pulse rounded" />
                  </div>
                ))}
              </div>
            ) : data && Array.isArray(data.top_products) && data.top_products.length > 0 ? (
              <div className="space-y-3">
                {data.top_products
                  .filter((product) => product && typeof product === 'object' && product.revenue != null)
                  .map((product, index) => (
                    <div
                      key={index}
                      className="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg"
                    >
                      <div className="flex items-center gap-3">
                        <div className="flex items-center justify-center w-8 h-8 bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300 rounded-full font-medium text-sm">
                          {index + 1}
                        </div>
                        <span className="font-medium">{product.name || 'Unknown'}</span>
                      </div>
                      <span className="text-green-600 font-medium">
                        ${Number(product.revenue || 0).toLocaleString('en-US', {
                          minimumFractionDigits: 2,
                          maximumFractionDigits: 2,
                        })}
                      </span>
                    </div>
                  ))}
              </div>
            ) : (
              <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                No product data available
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
};

export default ExecutiveDashboard;
