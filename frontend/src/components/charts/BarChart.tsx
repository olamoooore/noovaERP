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
  formatCurrency,
  formatNumber,
  getColorByIndex,
  makeAxisTickFormatter,
  maxAbsOf,
  truncateLabel,
} from '@/lib/chartUtils';
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
 * Custom tooltip component — always renders the full category label and the
 * exact value (the axes use shortened forms for legibility).
 */
const CustomTooltip = ({
  active,
  payload,
  label,
  formatYAxis,
}: any) => {
  if (!active || !payload || !payload.length) return null;

  const formatValue = (value: number) => {
    if (!Number.isFinite(value)) return '-';
    if (formatYAxis === 'currency') return formatCurrency(value);
    if (formatYAxis === 'percent') return `${value.toFixed(1)}%`;
    return formatNumber(value, 2);
  };

  return (
    <div className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg p-3 max-w-xs">
      <p className="font-medium text-sm mb-2 break-words">{label}</p>
      {payload.map((entry: any, index: number) => (
        <div key={index} className="flex items-center gap-2 text-sm">
          <div
            className="w-3 h-3 rounded shrink-0"
            style={{ backgroundColor: entry.color }}
          />
          <span className="text-gray-600 dark:text-gray-400">{entry.name}:</span>
          <span className="font-medium ml-auto tabular-nums">{formatValue(entry.value)}</span>
        </div>
      ))}
    </div>
  );
};

/**
 * BarChart Component
 * Displays data as a bar chart with support for stacked and horizontal layouts.
 * Axis ticks adapt to magnitude (compact currency above 10k) and long category
 * labels are truncated so they never overlap.
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
  truncateLabels = 16,
  xAxisAngle = 0,
  isDark = false,
}) => {
  const theme = getChartTheme(isDark);
  const tickFormatter = makeAxisTickFormatter(formatYAxis, maxAbsOf(data, yKeys));
  const angled = xAxisAngle !== 0;
  const categoryTick = (value: any) =>
    truncateLabels > 0 ? truncateLabel(value, truncateLabels) : String(value ?? '');

  // Horizontal charts put the category names on the Y axis, so give that axis
  // enough width for the (truncated) names instead of the 60px default.
  const categoryAxisWidth = truncateLabels > 0 ? Math.min(200, truncateLabels * 7 + 14) : 160;

  const ChartComponent = RechartsBarChart;
  const layout = horizontal ? 'horizontal' : 'vertical';

  return (
    <ResponsiveContainer width="100%" height={height}>
      <ChartComponent
        data={data}
        layout={layout}
        margin={
          horizontal
            ? { top: 8, right: 28, left: 8, bottom: 8 }
            : { top: 8, right: 16, left: 8, bottom: angled ? 72 : 8 }
        }
      >
        {showGrid && <CartesianGrid strokeDasharray="3 3" stroke={theme.colors.grid} vertical={horizontal} />}
        {horizontal ? (
          <>
            <XAxis
              type="number"
              stroke={theme.colors.text}
              style={{ fontSize: theme.fonts.size.small }}
              tickFormatter={tickFormatter}
              tickMargin={8}
            />
            <YAxis
              type="category"
              dataKey={xKey}
              width={categoryAxisWidth}
              stroke={theme.colors.text}
              style={{ fontSize: theme.fonts.size.small }}
              tickFormatter={categoryTick}
              tickMargin={8}
            />
          </>
        ) : (
          <>
            <XAxis
              dataKey={xKey}
              stroke={theme.colors.text}
              style={{ fontSize: theme.fonts.size.small }}
              tickFormatter={categoryTick}
              tickMargin={8}
              // Rotated labels fit every category; otherwise let Recharts thin
              // them so long names can never collide.
              interval={angled ? 0 : 'preserveStartEnd'}
              angle={xAxisAngle}
              textAnchor={angled ? 'end' : 'middle'}
              height={angled ? 64 : undefined}
            />
            <YAxis
              stroke={theme.colors.text}
              style={{ fontSize: theme.fonts.size.small }}
              tickFormatter={tickFormatter}
              tickMargin={6}
              width={64}
            />
          </>
        )}
        <Tooltip
          content={<CustomTooltip formatYAxis={formatYAxis} />}
          cursor={{ fill: 'rgba(148, 163, 184, 0.12)' }}
        />
        {showLegend && (
          <Legend
            wrapperStyle={{ fontSize: theme.fonts.size.small, paddingTop: 8 }}
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
              radius={horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]}
            >
              {data.map((_entry, idx) => (
                <Cell key={`cell-${idx}`} fill={barColor} />
              ))}
            </Bar>
          );
        })}
      </ChartComponent>
    </ResponsiveContainer>
  );
};
