import React from 'react';
import {
  BarChart as RechartsBarChart,
  Bar,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from 'recharts';
import { formatCurrency, formatNumber, getColorByIndex } from '@/lib/chartUtils';
import { getChartTheme } from '@/styles/chartTheme';

interface BarChartProps {
  data: any[];
  xKey: string;
  yKeys: string[];
  colors?: string[];
  height?: number;
  showGrid?: boolean;
  showLegend?: boolean;
  formatYAxis?: 'currency' | 'number' | 'percent';
  stacked?: boolean;
  horizontal?: boolean;
  isDark?: boolean;
}

/**
 * Custom tooltip component
 */
const CustomTooltip = ({
  active,
  payload,
  label,
  formatYAxis,
}: any) => {
  if (!active || !payload || !payload.length) return null;

  const formatValue = (value: number) => {
    if (formatYAxis === 'currency') return formatCurrency(value);
    if (formatYAxis === 'percent') return `${value.toFixed(1)}%`;
    return formatNumber(value, 2);
  };

  return (
    <div className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg p-3">
      <p className="font-medium text-sm mb-2">{label}</p>
      {payload.map((entry: any, index: number) => (
        <div key={index} className="flex items-center gap-2 text-sm">
          <div
            className="w-3 h-3 rounded"
            style={{ backgroundColor: entry.color }}
          />
          <span className="text-gray-600 dark:text-gray-400">{entry.name}:</span>
          <span className="font-medium">{formatValue(entry.value)}</span>
        </div>
      ))}
    </div>
  );
};

/**
 * BarChart Component
 * Displays data as a bar chart with support for stacked and horizontal layouts
 */
export const BarChart: React.FC<BarChartProps> = ({
  data,
  xKey,
  yKeys,
  colors,
  height = 300,
  showGrid = true,
  showLegend = true,
  formatYAxis = 'number',
  stacked = false,
  horizontal = false,
  isDark = false,
}) => {
  const theme = getChartTheme(isDark);

  const formatAxisTick = (value: number) => {
    if (formatYAxis === 'currency') return formatCurrency(value);
    if (formatYAxis === 'percent') return `${value}%`;
    return formatNumber(value);
  };

  const ChartComponent = RechartsBarChart;
  const layout = horizontal ? 'horizontal' : 'vertical';

  return (
    <ResponsiveContainer width="100%" height={height}>
      <ChartComponent
        data={data}
        layout={layout}
        margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
      >
        {showGrid && <CartesianGrid strokeDasharray="3 3" stroke={theme.colors.grid} />}
        {horizontal ? (
          <>
            <XAxis
              type="number"
              stroke={theme.colors.text}
              style={{ fontSize: theme.fonts.size.small }}
              tickFormatter={formatAxisTick}
            />
            <YAxis
              type="category"
              dataKey={xKey}
              stroke={theme.colors.text}
              style={{ fontSize: theme.fonts.size.small }}
            />
          </>
        ) : (
          <>
            <XAxis
              dataKey={xKey}
              stroke={theme.colors.text}
              style={{ fontSize: theme.fonts.size.small }}
            />
            <YAxis
              stroke={theme.colors.text}
              style={{ fontSize: theme.fonts.size.small }}
              tickFormatter={formatAxisTick}
            />
          </>
        )}
        <Tooltip content={<CustomTooltip formatYAxis={formatYAxis} />} />
        {showLegend && (
          <Legend
            wrapperStyle={{ fontSize: theme.fonts.size.small }}
            iconType="rect"
          />
        )}
        {yKeys.map((key, index) => {
          const barColor = colors?.[index] || getColorByIndex(index);
          return (
            <Bar
              key={key}
              dataKey={key}
              stackId={stacked ? 'stack' : undefined}
              radius={[4, 4, 0, 0]}
            >
              {data.map((entry, idx) => (
                <Cell key={`cell-${idx}`} fill={barColor} />
              ))}
            </Bar>
          );
        })}
      </ChartComponent>
    </ResponsiveContainer>
  );
};
