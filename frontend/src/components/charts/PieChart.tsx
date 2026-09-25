import React from 'react';
import {
  PieChart as RechartsPieChart,
  Pie,
  Cell,
  Tooltip,
  Label,
  ResponsiveContainer,
} from 'recharts';
import { formatNumber, formatPercent, getColorByIndex, makeAxisTickFormatter, truncateLabel } from '@/lib/chartUtils';
import { ChartTooltip } from '@/components/charts/ChartTooltip';

interface PieChartProps {
  data: any[];
  nameKey: string;
  valueKey: string;
  colors?: string[];
  height?: number;
  showLegend?: boolean;
  showLabels?: boolean;
  formatValue?: 'currency' | 'number' | 'percent';
  innerRadius?: number; // For donut chart — also enables the centre total.
}

/** Centre label for donuts: total value + caption. */
const DonutCenter: React.FC<{
  viewBox?: { cx?: number; cy?: number };
  total: number;
  formatValue: 'currency' | 'number' | 'percent';
}> = ({ viewBox, total, formatValue }) => {
  const cx = viewBox?.cx ?? 0;
  const cy = viewBox?.cy ?? 0;
  const value =
    formatValue === 'currency'
      ? // Compact (e.g. $3.46M) so long totals never overflow the donut hole.
        makeAxisTickFormatter('currency', total)(total)
      : formatValue === 'percent'
        ? formatPercent(total)
        : formatNumber(total, 2);

  return (
    <g>
      <text
        x={cx}
        y={cy - 10}
        textAnchor="middle"
        style={{ fontSize: 10, letterSpacing: 1, textTransform: 'uppercase' }}
        className="fill-muted-foreground"
      >
        Total
      </text>
      <text x={cx} y={cy + 12} textAnchor="middle" style={{ fontSize: 16, fontWeight: 700 }} className="fill-foreground">
        {value}
      </text>
    </g>
  );
};

/**
 * Custom label component (optional) — percentage inside a slice.
 */
const renderCustomLabel = (props: any) => {
  const { cx, cy, midAngle, innerRadius, outerRadius, percent } = props;
  const RADIAN = Math.PI / 180;
  const radius = innerRadius + (outerRadius - innerRadius) * 0.5;
  const x = cx + radius * Math.cos(-midAngle * RADIAN);
  const y = cy + radius * Math.sin(-midAngle * RADIAN);

  if (percent < 0.07) return null; // no room for text on small slices

  return (
    <text
      x={x}
      y={y}
      fill="white"
      textAnchor="middle"
      dominantBaseline="central"
      style={{ fontSize: 11, fontWeight: 600 }}
    >
      {formatPercent(percent * 100, 0)}
    </text>
  );
};

/**
 * PieChart — modern donut/pie: rounded slices with small gaps, a centre total
 * for donuts and a compact chip legend (name + share). Slice labels are off by
 * default; the tooltip always shows the exact value.
 */
export const PieChart: React.FC<PieChartProps> = ({
  data,
  nameKey,
  valueKey,
  colors,
  height = 300,
  showLegend = true,
  showLabels = false,
  formatValue = 'number',
  innerRadius = 0,
}) => {
  const total = (data ?? []).reduce((sum, entry) => sum + Number(entry?.[valueKey] ?? 0), 0);

  const colorFor = (index: number) => colors?.[index] || getColorByIndex(index);

  return (
    <div>
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
            paddingAngle={2}
            cornerRadius={innerRadius > 0 ? 6 : 4}
            stroke="none"
          >
            {data.map((_entry, index) => (
              <Cell key={`cell-${index}`} fill={colorFor(index)} />
            ))}
            {innerRadius > 0 && (
              <Label
                position="center"
                content={<DonutCenter total={total} formatValue={formatValue} /> as any}
              />
            )}
          </Pie>
          <Tooltip content={<ChartTooltip valueFormat={formatValue} />} />
        </RechartsPieChart>
      </ResponsiveContainer>

      {showLegend && data?.length > 0 && (
        <div className="mt-3 flex flex-wrap items-center justify-center gap-x-4 gap-y-1.5">
          {data.map((entry: any, index: number) => {
            const value = Number(entry?.[valueKey] ?? 0);
            const share = total > 0 ? (value / total) * 100 : 0;
            const name = String(entry?.[nameKey] ?? '');

            return (
              <div key={`legend-${index}`} className="flex items-center gap-1.5 text-xs">
                <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: colorFor(index) }} />
                <span className="max-w-[170px] truncate text-muted-foreground" title={name}>
                  {truncateLabel(name, 24)}
                </span>
                <span className="font-medium tabular-nums">{share.toFixed(0)}%</span>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
};
