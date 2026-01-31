import React from 'react';
import { PieChart, Pie, Cell, ResponsiveContainer } from 'recharts';
import { formatNumber, formatPercent } from '@/lib/chartUtils';
import { getChartTheme } from '@/styles/chartTheme';

interface GaugeChartProps {
  value: number;
  max?: number;
  min?: number;
  height?: number;
  showValue?: boolean;
  showLabel?: boolean;
  label?: string;
  color?: string;
  isDark?: boolean;
}

/**
 * GaugeChart Component
 * Displays a single value as a gauge/semi-circle chart
 */
export const GaugeChart: React.FC<GaugeChartProps> = ({
  value,
  max = 100,
  min = 0,
  height = 200,
  showValue = true,
  showLabel = true,
  label,
  color,
  isDark = false,
}) => {
  const theme = getChartTheme(isDark);

  // Calculate percentage
  const percentage = ((value - min) / (max - min)) * 100;
  const clampedPercentage = Math.max(0, Math.min(100, percentage));

  // Determine color based on percentage if not provided
  const gaugeColor = color || (
    clampedPercentage >= 75 ? theme.colors.success :
    clampedPercentage >= 50 ? theme.colors.warning :
    theme.colors.danger
  );

  // Data for the gauge
  const data = [
    { name: 'value', value: clampedPercentage },
    { name: 'empty', value: 100 - clampedPercentage },
  ];

  return (
    <div className="relative w-full" style={{ height }}>
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Pie
            data={data}
            cx="50%"
            cy="70%"
            startAngle={180}
            endAngle={0}
            innerRadius="60%"
            outerRadius="80%"
            dataKey="value"
            stroke="none"
          >
            <Cell fill={gaugeColor} />
            <Cell fill={theme.colors.grid} />
          </Pie>
        </PieChart>
      </ResponsiveContainer>

      {/* Center text */}
      <div className="absolute inset-0 flex flex-col items-center justify-center" style={{ top: '40%' }}>
        {showValue && (
          <div className="text-3xl font-bold" style={{ color: gaugeColor }}>
            {formatNumber(value, 1)}
          </div>
        )}
        {showLabel && label && (
          <div className="text-sm text-gray-600 dark:text-gray-400 mt-1">
            {label}
          </div>
        )}
        <div className="text-xs text-gray-500 dark:text-gray-500 mt-1">
          {formatPercent(clampedPercentage, 0)}
        </div>
      </div>

      {/* Min/Max labels */}
      <div className="absolute bottom-0 left-0 right-0 flex justify-between px-8 text-xs text-gray-500 dark:text-gray-500">
        <span>{formatNumber(min)}</span>
        <span>{formatNumber(max)}</span>
      </div>
    </div>
  );
};
