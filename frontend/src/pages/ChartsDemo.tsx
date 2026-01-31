import React from 'react';
import {
  ChartContainer,
  LineChart,
  BarChart,
  PieChart,
  AreaChart,
  GaugeChart,
} from '@/components/charts';

/**
 * ChartsDemo Page
 * Demonstrates all available chart components
 */
const ChartsDemo: React.FC = () => {
  // Sample data for line chart
  const lineData = [
    { month: 'Jan', revenue: 45000, expenses: 32000 },
    { month: 'Feb', revenue: 52000, expenses: 35000 },
    { month: 'Mar', revenue: 48000, expenses: 33000 },
    { month: 'Apr', revenue: 61000, expenses: 38000 },
    { month: 'May', revenue: 55000, expenses: 36000 },
    { month: 'Jun', revenue: 67000, expenses: 40000 },
  ];

  // Sample data for bar chart
  const barData = [
    { product: 'Product A', sales: 4000, profit: 2400 },
    { product: 'Product B', sales: 3000, profit: 1398 },
    { product: 'Product C', sales: 2000, profit: 9800 },
    { product: 'Product D', sales: 2780, profit: 3908 },
    { product: 'Product E', sales: 1890, profit: 4800 },
  ];

  // Sample data for pie chart
  const pieData = [
    { name: 'Electronics', value: 45000 },
    { name: 'Clothing', value: 32000 },
    { name: 'Food', value: 28000 },
    { name: 'Books', value: 15000 },
    { name: 'Other', value: 10000 },
  ];

  // Sample data for area chart
  const areaData = [
    { date: 'Week 1', sales: 4000, orders: 240 },
    { date: 'Week 2', sales: 3000, orders: 139 },
    { date: 'Week 3', sales: 2000, orders: 980 },
    { date: 'Week 4', sales: 2780, orders: 390 },
  ];

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-3xl font-bold mb-2">Charts Demo</h1>
        <p className="text-gray-600 dark:text-gray-400">
          Demonstration of all available chart components
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Line Chart */}
        <ChartContainer
          title="Revenue vs Expenses"
          description="Monthly comparison"
        >
          <LineChart
            data={lineData}
            xKey="month"
            yKeys={['revenue', 'expenses']}
            formatYAxis="currency"
            height={300}
          />
        </ChartContainer>

        {/* Bar Chart */}
        <ChartContainer
          title="Sales by Product"
          description="Top 5 products"
        >
          <BarChart
            data={barData}
            xKey="product"
            yKeys={['sales', 'profit']}
            formatYAxis="currency"
            height={300}
          />
        </ChartContainer>

        {/* Pie Chart */}
        <ChartContainer
          title="Sales by Category"
          description="Distribution"
        >
          <PieChart
            data={pieData}
            nameKey="name"
            valueKey="value"
            formatValue="currency"
            height={300}
          />
        </ChartContainer>

        {/* Area Chart */}
        <ChartContainer
          title="Weekly Trends"
          description="Sales and orders"
        >
          <AreaChart
            data={areaData}
            xKey="date"
            yKeys={['sales', 'orders']}
            formatYAxis="number"
            height={300}
          />
        </ChartContainer>

        {/* Gauge Charts */}
        <ChartContainer
          title="Performance Metrics"
          description="Current status"
        >
          <div className="grid grid-cols-2 gap-4">
            <GaugeChart
              value={75}
              max={100}
              label="Sales Target"
              height={180}
            />
            <GaugeChart
              value={42}
              max={100}
              label="Inventory Level"
              height={180}
            />
          </div>
        </ChartContainer>

        {/* Stacked Bar Chart */}
        <ChartContainer
          title="Stacked Sales"
          description="Combined view"
        >
          <BarChart
            data={barData}
            xKey="product"
            yKeys={['sales', 'profit']}
            formatYAxis="currency"
            stacked
            height={300}
          />
        </ChartContainer>
      </div>
    </div>
  );
};

export default ChartsDemo;
