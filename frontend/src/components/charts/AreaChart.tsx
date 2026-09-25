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
import { getColorByIndex, makeAxisTickFormatter, maxAbsOf } from '@/lib/chartUtils';
import { getChartTheme } from '@/styles/chartTheme';
import { ChartTooltip } from '@/components/charts/ChartTooltip';

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
 * AreaChart — modern gradient areas with a soft dashed grid, hairline-free
 * axes and a floating tooltip.
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
  const tickFormatter = makeAxisTickFormatter(formatYAxis, maxAbsOf(data, yKeys));
  const legendVisible = showLegend && (yKeys.length > 1 || stacked);
  const gradientId = React.useId().replace(/[:]/g, '');

  return (
    <ResponsiveContainer width="100%" height={height}>
      <RechartsAreaChart data={data} margin={{ top: 8, right: 12, left: 4, bottom: 4 }}>
        <defs>
          {yKeys.map((key, index) => {
            const color = colors?.[index] || getColorByIndex(index);
            return (
              <linearGradient key={key} id={`${gradientId}-${index}`} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor={color} stopOpacity={stacked ? 0.6 : 0.35} />
                <stop offset="70%" stopColor={color} stopOpacity={stacked ? 0.25 : 0.05} />
                <stop offset="100%" stopColor={color} stopOpacity={0} />
              </linearGradient>
            );
          })}
        </defs>

        {showGrid && (
          <CartesianGrid vertical={false} strokeDasharray="4 8" stroke={theme.colors.grid} />
        )}
        <XAxis
          dataKey={xKey}
          axisLine={false}
          tickLine={false}
          tick={{ fill: theme.colors.text, fontSize: 11 }}
          tickMargin={10}
        />
        <YAxis
          axisLine={false}
          tickLine={false}
          tick={{ fill: theme.colors.text, fontSize: 11 }}
          tickFormatter={tickFormatter}
          tickMargin={8}
          width={60}
        />
        <Tooltip
          cursor={{ stroke: theme.colors.grid, strokeWidth: 1, strokeDasharray: '4 4' }}
          content={<ChartTooltip valueFormat={formatYAxis} />}
        />
        {legendVisible && (
          <Legend
            wrapperStyle={{ fontSize: 12, paddingTop: 12 }}
            iconType="circle"
            iconSize={8}
          />
        )}

        {yKeys.map((key, index) => {
          const color = colors?.[index] || getColorByIndex(index);
          return (
            <Area
              key={key}
              type="monotone"
              dataKey={key}
              stroke={color}
              strokeWidth={2.5}
              fill={`url(#${gradientId}-${index})`}
              fillOpacity={1}
              stackId={stacked ? 'stack' : undefined}
              dot={false}
              activeDot={{ r: 5, strokeWidth: 2, stroke: theme.colors.background, fill: color }}
            />
          );
        })}
      </RechartsAreaChart>
    </ResponsiveContainer>
  );
};
