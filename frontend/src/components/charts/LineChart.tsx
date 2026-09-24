import React from 'react';
import {
  LineChart as RechartsLineChart,
  Line,
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

interface LineChartProps {
  data: any[];
  xKey: string;
  yKeys: string[];
  colors?: string[];
  height?: number;
  showGrid?: boolean;
  showLegend?: boolean;
  formatYAxis?: 'currency' | 'number' | 'percent';
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
 * Custom tooltip component — full category label, exact value.
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
            className="w-3 h-3 rounded-full shrink-0"
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
 * LineChart Component
 * Displays data as a line chart with multiple series support. Axis ticks adapt
 * to magnitude and long category labels are truncated to avoid collisions.
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
  truncateLabels = 16,
  xAxisAngle = 0,
  isDark = false,
}) => {
  const theme = getChartTheme(isDark);
  const tickFormatter = makeAxisTickFormatter(formatYAxis, maxAbsOf(data, yKeys));
  const angled = xAxisAngle !== 0;
  const categoryTick = (value: any) =>
    truncateLabels > 0 ? truncateLabel(value, truncateLabels) : String(value ?? '');
  // Dots only help when there are few points; otherwise they clutter the line.
  const showDots = (data?.length ?? 0) <= 24;

  return (
    <ResponsiveContainer width="100%" height={height}>
      <RechartsLineChart
        data={data}
        margin={{ top: 8, right: 16, left: 8, bottom: angled ? 72 : 8 }}
      >
        {showGrid && <CartesianGrid strokeDasharray="3 3" stroke={theme.colors.grid} />}
        <XAxis
          dataKey={xKey}
          stroke={theme.colors.text}
          style={{ fontSize: theme.fonts.size.small }}
          tickFormatter={categoryTick}
          tickMargin={8}
          interval="preserveStartEnd"
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
        <Tooltip content={<CustomTooltip formatYAxis={formatYAxis} />} />
        {showLegend && (
          <Legend
            wrapperStyle={{ fontSize: theme.fonts.size.small, paddingTop: 8 }}
            iconType="line"
          />
        )}
        {yKeys.map((key, index) => (
          <Line
            key={key}
            type="monotone"
            dataKey={key}
            stroke={colors?.[index] || getColorByIndex(index)}
            strokeWidth={2}
            dot={showDots ? { r: 3 } : false}
            activeDot={{ r: 5 }}
          />
        ))}
      </RechartsLineChart>
    </ResponsiveContainer>
  );
};
