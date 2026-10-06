import { Icon } from '@iconify/react';
import { formatCurrency } from '@/lib/utils';
import type { GaugeRow } from '@/lib/thresholdGauge';

/**
 * #749 (TU3): compact registration-threshold gauge.
 *
 * One gauge per threshold row (MY is per service-tax category). Green below
 * 80%, amber at/above 80%, red when crossed. The trailing value is compared
 * against the authority threshold; an optional forward projection is shown
 * beneath it. Advisory only — registration is a human/legal decision.
 */
const TONE_BAR: Record<GaugeRow['tone'], string> = {
  ok: 'bg-emerald-500',
  amber: 'bg-amber-500',
  red: 'bg-rose-500',
};

const TONE_TEXT: Record<GaugeRow['tone'], string> = {
  ok: 'text-emerald-700 dark:text-emerald-400',
  amber: 'text-amber-700 dark:text-amber-400',
  red: 'text-rose-700 dark:text-rose-400',
};

function formatAmount(value: number, currency: string): string {
  return formatCurrency(Number(value) || 0, currency || undefined);
}

export function TaxThresholdGauge({ row }: { row: GaugeRow }) {
  const width = Math.min(Math.max(row.percent, 0), 100);

  return (
    <div className="rounded-xl border p-3 text-sm">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-medium">{row.label}</p>
          <p className="text-xs text-muted-foreground">
            {row.category} · threshold {formatAmount(row.amount, row.currency)}
          </p>
        </div>
        <div className="text-right">
          <p className={`font-mono tabular-nums ${TONE_TEXT[row.tone]}`}>{row.percent}%</p>
          <p className="text-xs text-muted-foreground">{formatAmount(row.trailingValue, row.currency)} / 12m</p>
        </div>
      </div>

      <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-muted" role="meter" aria-valuenow={row.percent} aria-valuemin={0} aria-valuemax={100} aria-label={`${row.label} ${row.percent}% of threshold`}>
        <div className={`h-full ${TONE_BAR[row.tone]}`} style={{ width: `${width}%` }} />
      </div>

      <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs">
        {row.forwardPercent != null ? (
          <span className={row.forwardCrossed ? TONE_TEXT.red : 'text-muted-foreground'}>
            Forward 12m projection: {row.forwardPercent}%{row.forwardCrossed ? ' — projected to cross' : ''}
          </span>
        ) : (
          <span className="text-muted-foreground">Enter a forward projection to check the next 12 months.</span>
        )}
        {row.crossed && (
          <span className={`inline-flex items-center gap-1 ${TONE_TEXT.red}`}>
            <Icon icon="solar:danger-triangle-bold-duotone" className="h-4 w-4" />
            Threshold crossed — consider registering
          </span>
        )}
      </div>
    </div>
  );
}