import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Package, AlertTriangle, TrendingUp, RefreshCw, XCircle } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { MetricCard, DashboardGrid } from '@/components/dashboards';
import { LineChart } from '@/components/charts/LineChart';
import { BarChart } from '@/components/charts/BarChart';
import { GaugeChart } from '@/components/charts/GaugeChart';
import reportApi from '@/lib/reportApi';
import type { InventoryDashboardData } from '@/types/reports';

/**
 * Inventory Dashboard Page
 * Comprehensive inventory analytics and metrics
 */
export const InventoryDashboard: React.FC = () => {
  // Add month/year selectors - default to December 2025 to show test data
  const now = new Date();
  const [selectedMonth, setSelectedMonth] = useState(11); // December (0-11)
  const [selectedYear, setSelectedYear] = useState(2025);

  // Calculate date range based on selected month/year
  const getDateFilters = () => {
    const startDate = new Date(selectedYear, selectedMonth, 1);
    const endDate = new Date(selectedYear, selectedMonth + 1, 0);
    return {
      start_date: startDate.toISOString().split('T')[0],
      end_date: endDate.toISOString().split('T')[0],
    };
  };

  const currentMonthFilters = getDateFilters();

  // Fetch dashboard data
  const { data, isLoading, error, refetch } = useQuery<InventoryDashboardData>({
    queryKey: ['inventory-dashboard', selectedMonth, selectedYear],
    queryFn: async () => {
      // Since we don't have a dedicated dashboard endpoint yet,
      // we'll aggregate data from multiple endpoints
      const [stockValuation, reorderReport, stockMovement, inventoryTurnover] =
        await Promise.all([
          reportApi.getStockValuation(currentMonthFilters),
          reportApi.getReorderReport(),
          reportApi.getStockMovement(currentMonthFilters),
          reportApi.getInventoryTurnover(currentMonthFilters),
        ]);

      // Count low stock and out of stock items
      const lowStockCount = reorderReport?.items?.length ?? 0;
      const outOfStockCount = (reorderReport?.items ?? []).filter(
        (item) => item.current_stock === 0
      ).length;

      // Transform stock movement data for chart
      const movementsByPeriod = (stockMovement?.movements ?? []).reduce((acc, movement) => {
        const period = movement.date;
        if (!acc[period]) {
          acc[period] = { period, in: 0, out: 0 };
        }
        if (movement.type === 'in' || movement.type === 'purchase' || movement.type === 'adjustment_in') {
          acc[period].in += movement.quantity;
        } else {
          acc[period].out += Math.abs(movement.quantity);
        }
        return acc;
      }, {} as Record<string, { period: string; in: number; out: number }>);

      const stockMovementsChart = Object.values(movementsByPeriod).slice(-30); // Last 30 periods

      // Get top moving products (by total movement quantity)
      const productMovements = (stockMovement?.movements ?? []).reduce((acc, movement) => {
        const key = movement.product_name;
        if (!acc[key]) {
          acc[key] = 0;
        }
        acc[key] += Math.abs(movement.quantity);
        return acc;
      }, {} as Record<string, number>);

      const topMovingProducts = Object.entries(productMovements)
        .map(([product, quantity]) => ({ product, quantity }))
        .sort((a, b) => b.quantity - a.quantity)
        .slice(0, 10);

      return {
        metrics: {
          total_value: stockValuation?.total_value ?? 0,
          low_stock_count: lowStockCount,
          out_of_stock_count: outOfStockCount,
        },
        low_stock_alerts: (reorderReport?.items ?? []).slice(0, 10).map((item) => ({
          product: item.product_name,
          current_stock: item.current_stock,
          reorder_level: item.reorder_level,
        })),
        stock_movements: stockMovementsChart,
        inventory_turnover: inventoryTurnover?.average_turnover ?? 0,
        top_moving_products: topMovingProducts,
        reorder_recommendations: (reorderReport?.items ?? []).slice(0, 10).map((item) => ({
          product: item.product_name,
          suggested_quantity: item.suggested_quantity,
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
            {error instanceof Error ? error.message : 'Failed to load inventory dashboard data'}
          </p>
        </div>
      </div>
    );
  }

  const formatCurrency = (value: number) => {
    return `${value.toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })}`;
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Inventory Dashboard</h1>
          <p className="text-muted-foreground mt-1">
            Real-time inventory metrics and stock management
          </p>
        </div>
        <div className="flex items-center gap-4">
          {/* Month/Year Selector */}
          <div className="flex items-center gap-2">
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
          <button
            onClick={handleRefresh}
            className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
            disabled={isLoading}
          >
            <RefreshCw className={`h-4 w-4 ${isLoading ? 'animate-spin' : ''}`} />
            Refresh
          </button>
        </div>
      </div>

      {/* Stock Value Metrics */}
      <DashboardGrid columns={3} gap="md">
        <MetricCard
          label="Total Stock Value"
          value={isLoading ? '...' : formatCurrency(data?.metrics.total_value || 0)}
          icon={Package}
          iconColor="text-blue-600"
          loading={isLoading}
        />
        <MetricCard
          label="Low Stock Items"
          value={isLoading ? '...' : String(data?.metrics.low_stock_count || 0)}
          icon={AlertTriangle}
          iconColor="text-orange-600"
          loading={isLoading}
        />
        <MetricCard
          label="Out of Stock"
          value={isLoading ? '...' : String(data?.metrics.out_of_stock_count || 0)}
          icon={XCircle}
          iconColor="text-red-600"
          loading={isLoading}
        />
      </DashboardGrid>

      {/* Charts Row 1 */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Low Stock Alerts */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <AlertTriangle className="h-5 w-5 text-orange-600" />
              Low Stock Alerts
            </CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="space-y-3">
                {[1, 2, 3, 4, 5].map((i) => (
                  <div key={i} className="flex items-center justify-between">
                    <div className="h-4 w-40 bg-gray-200 animate-pulse rounded" />
                    <div className="h-4 w-24 bg-gray-200 animate-pulse rounded" />
                  </div>
                ))}
              </div>
            ) : data && data.low_stock_alerts.length > 0 ? (
              <div className="space-y-2 max-h-[300px] overflow-y-auto">
                {data.low_stock_alerts.map((alert, index) => (
                  <div
                    key={index}
                    className="flex items-center justify-between p-3 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg"
                  >
                    <div className="flex-1">
                      <p className="font-medium text-gray-900 dark:text-white">
                        {alert.product}
                      </p>
                      <p className="text-sm text-gray-600 dark:text-gray-400">
                        Reorder level: {alert.reorder_level}
                      </p>
                    </div>
                    <div className="text-right">
                      <p className="text-lg font-bold text-orange-600">
                        {alert.current_stock}
                      </p>
                      <p className="text-xs text-gray-500">in stock</p>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="h-[300px] flex flex-col items-center justify-center text-muted-foreground">
                <Package className="h-12 w-12 mb-2 text-gray-300" />
                <p>All stock levels are healthy</p>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Inventory Turnover Gauge */}
        <Card>
          <CardHeader>
            <CardTitle>Inventory Turnover</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="h-[300px] flex items-center justify-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
              </div>
            ) : data ? (
              <GaugeChart
                value={data.inventory_turnover}
                max={20}
                min={0}
                height={300}
                label="Turnover Ratio"
                showValue={true}
                showLabel={true}
              />
            ) : (
              <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                No turnover data available
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Charts Row 2 */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Stock Movements Chart */}
        <Card>
          <CardHeader>
            <CardTitle>Stock Movements (Last 30 Days)</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="h-[300px] flex items-center justify-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
              </div>
            ) : data && data.stock_movements.length > 0 ? (
              <LineChart
                data={data.stock_movements}
                xKey="period"
                yKeys={['in', 'out']}
                height={300}
                colors={['#10b981', '#ef4444']}
              />
            ) : (
              <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                No stock movement data available
              </div>
            )}
          </CardContent>
        </Card>

        {/* Top Moving Products Chart */}
        <Card>
          <CardHeader>
            <CardTitle>Top Moving Products</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="h-[300px] flex items-center justify-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
              </div>
            ) : data && data.top_moving_products.length > 0 ? (
              <BarChart
                data={data.top_moving_products}
                xKey="product"
                yKeys={['quantity']}
                height={300}
                horizontal={true}
                colors={['#3b82f6']}
              />
            ) : (
              <div className="h-[300px] flex items-center justify-center text-muted-foreground">
                No product movement data available
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Reorder Recommendations Table */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <TrendingUp className="h-5 w-5 text-blue-600" />
            Reorder Recommendations
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-3">
              {[1, 2, 3, 4, 5].map((i) => (
                <div key={i} className="flex items-center justify-between">
                  <div className="h-4 w-48 bg-gray-200 animate-pulse rounded" />
                  <div className="h-4 w-32 bg-gray-200 animate-pulse rounded" />
                </div>
              ))}
            </div>
          ) : data && data.reorder_recommendations.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-gray-200 dark:border-gray-700">
                    <th className="text-left py-3 px-4 font-medium text-gray-700 dark:text-gray-300">
                      #
                    </th>
                    <th className="text-left py-3 px-4 font-medium text-gray-700 dark:text-gray-300">
                      Product
                    </th>
                    <th className="text-right py-3 px-4 font-medium text-gray-700 dark:text-gray-300">
                      Suggested Quantity
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {data.reorder_recommendations.map((item, index) => (
                    <tr
                      key={index}
                      className="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
                    >
                      <td className="py-3 px-4 text-gray-600 dark:text-gray-400">
                        {index + 1}
                      </td>
                      <td className="py-3 px-4 font-medium text-gray-900 dark:text-white">
                        {item.product}
                      </td>
                      <td className="py-3 px-4 text-right">
                        <span className="inline-flex items-center px-3 py-1 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 rounded-full font-medium">
                          {item.suggested_quantity} units
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="h-32 flex flex-col items-center justify-center text-muted-foreground">
              <Package className="h-12 w-12 mb-2 text-gray-300" />
              <p>No reorder recommendations at this time</p>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default InventoryDashboard;
