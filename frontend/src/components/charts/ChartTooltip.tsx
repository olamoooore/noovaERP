import React from 'react';
import { formatCurrency, formatNumber, formatPercent } from '@/lib/chartUtils';

export interface ChartTooltipProps {
  active?: boolean;
  payload?: any[];
  label?: any;
  valueFormat?: 'currency' | 'number' | 'percent';
  /** Shorten the label shown on top (full label is used by default). */
  labelFormat?: (label: any) => string;
}

/**
 * Shared modern chart tooltip: floating card with soft shadow, muted label and
 * tabular figures. Used by every chart wrapper so hover styling is identical.
 */
export const ChartTooltip: React.FC<ChartTooltipProps> = ({
  active,
  payload,
  label,
  valueFormat = 'number',
  labelFormat,
}) => {
  if (!active || !payload || payload.length === 0) return null;

  const formatValue = (value: number) => {
    if (!Number.isFinite(value)) return '—';
    if (valueFormat === 'currency') return formatCurrency(value);
    if (valueFormat === 'percent') return formatPercent(value);
    return formatNumber(value, 2);
  };

  return (
    <div className="pointer-events-none rounded-xl border border-border/70 bg-background/95 px-3 py-2 shadow-lg backdrop-blur">
      {label !== undefined && label !== null && label !== '' && (
        <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
          {labelFormat ? labelFormat(label) : String(label)}
        </p>
      )}
      <div className="space-y-1">
        {payload.map((entry: any, index: number) => (
          <div key={index} className="flex items-center gap-2 text-sm">
            <span
              className="h-2.5 w-2.5 shrink-0 rounded-full"
              style={{ backgroundColor: entry.color || entry.payload?.fill }}
            />
            <span className="text-muted-foreground">{entry.name}</span>
            <span className="ml-auto pl-4 font-semibold tabular-nums">{formatValue(entry.value)}</span>
          </div>
        ))}
      </div>
    </div>
  );
};
