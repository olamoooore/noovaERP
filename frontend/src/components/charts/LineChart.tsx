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
import {
  getColorByIndex,
  makeAxisTickFormatter,
  maxAbsOf,
  truncateLabel,
} from '@/lib/chartUtils';
import { getChartTheme } from '@/styles/chartTheme';
import { ChartTooltip } from '@/components/charts/ChartTooltip';

interface LineChartProps {
  data: any[];
  xKey: string;
  yKeys: string[];
  colors?: string[];
  height?: number;
  showGrid?: boolean;
  showLegend?: boolean;
  formatYAxis?: 'currency' | 'number' | 'percent';
  /** ISO currency for 'currency' formatting (e.g. the report's base currency); defaults to the tenant setting. */
  currency?: string;
  /**
   * Category labels longer than this are shortened on the axis; the tooltip
   * always shows the full label. Pass 0 to disable truncation.
   */
  truncateLabels?: number;
  /** Rotate category ticks (e.g. -30) when labels are long. */
  xAxisAngle?: number;
  isDark?: boolean;
}

/**
 * LineChart — modern area/line hybrid: smooth gradient fill under each line,
 * hairline dashed grid, no axis lines and a floating tooltip. Single-series
 * charts skip the legend so the plot gets the full height.
 */
export const LineChart: React.FC<LineChartProps> = ({
  data,
  xKey,
  yKeys,
  colors,
  height = 300,
  showGrid = true,
  showLegend = true,
  formatYAxis = 'number',
  currency,
  truncateLabels = 16,
  xAxisAngle = 0,
  isDark = false,
}) => {
  const theme = getChartTheme(isDark);
  const tickFormatter = makeAxisTickFormatter(formatYAxis, maxAbsOf(data, yKeys), currency);
  const angled = xAxisAngle !== 0;
  const categoryTick = (value: any) =>
    truncateLabels > 0 ? truncateLabel(value, truncateLabels) : String(value ?? '');
  const legendVisible = showLegend && yKeys.length > 1;
  const gradientId = React.useId().replace(/[:]/g, '');

  return (
    <ResponsiveContainer width="100%" height={height}>
      <RechartsAreaChart
        data={data}
        margin={{ top: 8, right: 12, left: 4, bottom: angled ? 72 : 4 }}
      >
        <defs>
          {yKeys.map((key, index) => {
            const color = colors?.[index] || getColorByIndex(index);
            return (
              <linearGradient key={key} id={`${gradientId}-${index}`} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor={color} stopOpacity={0.35} />
                <stop offset="70%" stopColor={color} stopOpacity={0.05} />
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
          tickFormatter={categoryTick}
          tickMargin={10}
          interval="preserveStartEnd"
          angle={xAxisAngle}
          textAnchor={angled ? 'end' : 'middle'}
          height={angled ? 64 : undefined}
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
          content={<ChartTooltip valueFormat={formatYAxis} currency={currency} />}
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
              dot={false}
              activeDot={{ r: 5, strokeWidth: 2, stroke: theme.colors.background, fill: color }}
            />
          );
        })}
      </RechartsAreaChart>
    </ResponsiveContainer>
  );
};
