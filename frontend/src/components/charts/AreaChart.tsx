import React from 'react';
import {
  AreaChart as RechartsAreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from 'recharts';
import { formatCurrency, formatNumber, getColorByIndex } from '@/lib/chartUtils';
import { getChartTheme } from '@/styles/chartTheme';

interface AreaChartProps {
  data: any[];
  xKey: string;
  yKeys: string[];
  colors?: string[];
  height?: number;
  showGrid?: boolean;
  showLegend?: boolean;
  formatYAxis?: 'currency' | 'number' | 'percent';
  stacked?: boolean;
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
            className="w-3 h-3 rounded-full"
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
 * AreaChart Component
 * Displays data as an area chart with support for stacked areas
 */
export const AreaChart: React.FC<AreaChartProps> = ({
  data,
  xKey,
  yKeys,
  colors,
  height = 300,
  showGrid = true,
  showLegend = true,
  formatYAxis = 'number',
  stacked = false,
  isDark = false,
}) => {
  const theme = getChartTheme(isDark);

  const formatYAxisTick = (value: number) => {
    if (formatYAxis === 'currency') return formatCurrency(value);
    if (formatYAxis === 'percent') return `${value}%`;
    return formatNumber(value);
  };

  return (
    <ResponsiveContainer width="100%" height={height}>
      <RechartsAreaChart data={data} margin={{ top: 5, right: 30, left: 20, bottom: 5 }}>
        {showGrid && <CartesianGrid strokeDasharray="3 3" stroke={theme.colors.grid} />}
        <XAxis
          dataKey={xKey}
          stroke={theme.colors.text}
          style={{ fontSize: theme.fonts.size.small }}
        />
        <YAxis
          stroke={theme.colors.text}
          style={{ fontSize: theme.fonts.size.small }}
          tickFormatter={formatYAxisTick}
        />
        <Tooltip content={<CustomTooltip formatYAxis={formatYAxis} />} />
        {showLegend && (
          <Legend
            wrapperStyle={{ fontSize: theme.fonts.size.small }}
            iconType="rect"
          />
        )}
        {yKeys.map((key, index) => (
          <Area
            key={key}
            type="monotone"
            dataKey={key}
            stroke={colors?.[index] || getColorByIndex(index)}
            fill={colors?.[index] || getColorByIndex(index)}
            fillOpacity={0.6}
            stackId={stacked ? 'stack' : undefined}
          />
        ))}
      </RechartsAreaChart>
    </ResponsiveContainer>
  );
};
