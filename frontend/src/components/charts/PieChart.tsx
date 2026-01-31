import React from 'react';
import {
  PieChart as RechartsPieChart,
  Pie,
  Cell,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from 'recharts';
import { formatCurrency, formatNumber, formatPercent, getColorByIndex } from '@/lib/chartUtils';
import { getChartTheme } from '@/styles/chartTheme';

interface PieChartProps {
  data: any[];
  nameKey: string;
  valueKey: string;
  colors?: string[];
  height?: number;
  showLegend?: boolean;
  showLabels?: boolean;
  formatValue?: 'currency' | 'number' | 'percent';
  innerRadius?: number; // For donut chart
  isDark?: boolean;
}

/**
 * Custom tooltip component
 */
const CustomTooltip = ({
  active,
  payload,
  formatValue,
}: any) => {
  if (!active || !payload || !payload.length) return null;

  const entry = payload[0];
  const value = entry.value;
  const name = entry.name;

  const formattedValue = () => {
    if (formatValue === 'currency') return formatCurrency(value);
    if (formatValue === 'percent') return formatPercent(value);
    return formatNumber(value, 2);
  };

  return (
    <div className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg p-3">
      <div className="flex items-center gap-2">
        <div
          className="w-3 h-3 rounded-full"
          style={{ backgroundColor: entry.payload.fill }}
        />
        <span className="font-medium text-sm">{name}</span>
      </div>
      <p className="text-sm mt-1 ml-5">{formattedValue()}</p>
    </div>
  );
};

/**
 * Custom label component
 */
const renderCustomLabel = (props: any) => {
  const { cx, cy, midAngle, innerRadius, outerRadius, percent } = props;
  const RADIAN = Math.PI / 180;
  const radius = innerRadius + (outerRadius - innerRadius) * 0.5;
  const x = cx + radius * Math.cos(-midAngle * RADIAN);
  const y = cy + radius * Math.sin(-midAngle * RADIAN);

  if (percent < 0.05) return null; // Don't show label for small slices

  return (
    <text
      x={x}
      y={y}
      fill="white"
      textAnchor={x > cx ? 'start' : 'end'}
      dominantBaseline="central"
      className="text-xs font-medium"
    >
      {formatPercent(percent * 100, 0)}
    </text>
  );
};

/**
 * PieChart Component
 * Displays data as a pie or donut chart
 */
export const PieChart: React.FC<PieChartProps> = ({
  data,
  nameKey,
  valueKey,
  colors,
  height = 300,
  showLegend = true,
  showLabels = true,
  formatValue = 'number',
  innerRadius = 0,
  isDark = false,
}) => {
  const theme = getChartTheme(isDark);

  return (
    <ResponsiveContainer width="100%" height={height}>
      <RechartsPieChart>
        <Pie
          data={data}
          dataKey={valueKey}
          nameKey={nameKey}
          cx="50%"
          cy="50%"
          innerRadius={innerRadius}
          outerRadius={height / 3}
          label={showLabels ? renderCustomLabel : false}
          labelLine={false}
        >
          {data.map((_entry, index) => (
            <Cell
              key={`cell-${index}`}
              fill={colors?.[index] || getColorByIndex(index)}
            />
          ))}
        </Pie>
        <Tooltip content={<CustomTooltip formatValue={formatValue} />} />
        {showLegend && (
          <Legend
            wrapperStyle={{ fontSize: theme.fonts.size.small }}
            iconType="circle"
          />
        )}
      </RechartsPieChart>
    </ResponsiveContainer>
  );
};
