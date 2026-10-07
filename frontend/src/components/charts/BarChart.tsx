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
import {
  getColorByIndex,
  makeAxisTickFormatter,
  maxAbsOf,
  truncateLabel,
} from '@/lib/chartUtils';
import { getChartTheme } from '@/styles/chartTheme';
import { ChartTooltip } from '@/components/charts/ChartTooltip';

interface BarChartProps {
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
  stacked?: boolean;
  horizontal?: boolean;
  /**
   * Category labels longer than this are shortened on the axis; the tooltip
   * always shows the full label. Pass 0 to disable truncation.
   */
  truncateLabels?: number;
  /** Rotate category ticks (e.g. -30) when names are long. */
  xAxisAngle?: number;
  isDark?: boolean;
}

/**
 * BarChart — modern bars with rounded corners, soft dashed grid, clean axes
 * and a floating tooltip. Single-series charts skip the legend.
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
  currency,
  stacked = false,
  horizontal = false,
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

  // Horizontal charts put the category names on the Y axis, so give that axis
  // enough width for the (truncated) names instead of the 60px default.
  const categoryAxisWidth = truncateLabels > 0 ? Math.min(200, truncateLabels * 7 + 14) : 160;

  return (
    <ResponsiveContainer width="100%" height={height}>
      <RechartsBarChart
        data={data}
        layout={horizontal ? 'horizontal' : 'vertical'}
        margin={
          horizontal
            ? { top: 16, right: 20, left: 4, bottom: 4 }
            : { top: 16, right: 12, left: 4, bottom: angled ? 72 : 4 }
        }
        barCategoryGap="24%"
      >
        {showGrid && (
          <CartesianGrid
            vertical={horizontal}
            horizontal={!horizontal}
            strokeDasharray="4 8"
            stroke={theme.colors.grid}
          />
        )}

        {horizontal ? (
          <>
            <XAxis
              type="number"
              axisLine={false}
              tickLine={false}
              tick={{ fill: theme.colors.text, fontSize: 11 }}
              tickFormatter={tickFormatter}
              tickMargin={8}
              domain={[(min: number) => Math.min(0, min), (max: number) => max * 1.08]}
            />
            <YAxis
              type="category"
              dataKey={xKey}
              width={categoryAxisWidth}
              axisLine={false}
              tickLine={false}
              tick={{ fill: theme.colors.text, fontSize: 11 }}
              tickFormatter={categoryTick}
              tickMargin={8}
            />
          </>
        ) : (
          <>
            <XAxis
              dataKey={xKey}
              axisLine={false}
              tickLine={false}
              tick={{ fill: theme.colors.text, fontSize: 11 }}
              tickFormatter={categoryTick}
              tickMargin={10}
              // Rotated labels fit every category; otherwise let Recharts thin
              // them so long names can never collide.
              interval={angled ? 0 : 'preserveStartEnd'}
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
              domain={[(min: number) => Math.min(0, min), (max: number) => max * 1.08]}
            />
          </>
        )}

        <Tooltip
          cursor={{ fill: 'rgba(148, 163, 184, 0.10)', radius: 8 }}
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
          const barColor = colors?.[index] || getColorByIndex(index);
          const radius: [number, number, number, number] = stacked
            ? [0, 0, 0, 0]
            : horizontal
              ? [0, 8, 8, 0]
              : [8, 8, 0, 0];

          return (
            <Bar
              key={key}
              dataKey={key}
              stackId={stacked ? 'stack' : undefined}
              radius={radius}
              maxBarSize={horizontal ? 22 : 48}
            >
              {data.map((_entry, idx) => (
                <Cell key={`cell-${idx}`} fill={barColor} />
              ))}
            </Bar>
          );
        })}
      </RechartsBarChart>
    </ResponsiveContainer>
  );
};
