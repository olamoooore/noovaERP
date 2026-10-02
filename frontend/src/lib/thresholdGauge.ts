/**
 * #749 (TU3): pure helpers for the registration-threshold gauge.
 *
 * The thresholds endpoint (#737/#743) returns one row per applicable
 * threshold — for MY that is one per service-tax category — with the trailing
 * 12-month value, the percentage of the threshold, and (given a forecast) a
 * forward flag. No rates are computed here; the gauge just classifies at the
 * 80% / 100% marks so the widget is testable and the API stays authoritative.
 */

export type GaugeTone = 'ok' | 'amber' | 'red';

export interface ThresholdLine {
  key: string;
  label: string;
  amount: number;
  currency: string;
  note?: string;
  category?: string | null;
  test?: string | null;
  trailing_value: number;
  percent_of_threshold: number | null;
  monitoring_flag?: boolean;
  forward_flag?: boolean | null;
}

export interface GaugeRow {
  key: string;
  label: string;
  category: string;
  amount: number;
  currency: string;
  trailingValue: number;
  percent: number;
  tone: GaugeTone;
  crossed: boolean;
  forwardPercent: number | null;
  forwardCrossed: boolean;
}

export function gaugeTone(percent: number): GaugeTone {
  if (!Number.isFinite(percent) || percent <= 0) {
    return 'ok';
  }
  if (percent >= 100) {
    return 'red';
  }
  if (percent >= 80) {
    return 'amber';
  }

  return 'ok';
}

export function buildGaugeRows(thresholds: ThresholdLine[], forecast: number | null): GaugeRow[] {
  return thresholds.map((line) => {
    const amount = Number(line.amount) || 0;
    const trailingValue = Number(line.trailing_value) || 0;
    const percent = line.percent_of_threshold != null
      ? Number(line.percent_of_threshold)
      : amount > 0
        ? Math.round((trailingValue / amount) * 1000) / 10
        : 0;
    const forwardPercent = forecast != null && amount > 0
      ? Math.round((forecast / amount) * 1000) / 10
      : null;

    return {
      key: line.key,
      label: line.label,
      category: line.category ?? 'all',
      amount,
      currency: line.currency,
      trailingValue,
      percent,
      tone: gaugeTone(percent),
      crossed: percent >= 100,
      forwardPercent,
      forwardCrossed: forwardPercent != null && forwardPercent >= 100,
    };
  });
}