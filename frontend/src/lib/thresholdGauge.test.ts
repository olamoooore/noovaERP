import { describe, expect, it } from 'vitest';
import { buildGaugeRows, gaugeTone, type ThresholdLine } from './thresholdGauge';

const line = (over: Partial<ThresholdLine> = {}): ThresholdLine => ({
  key: 'taxable_services',
  label: 'RMCD service tax registration',
  amount: 500000,
  currency: 'MYR',
  category: 'services',
  trailing_value: 0,
  percent_of_threshold: 0,
  ...over,
});

describe('#749 TU3 — threshold gauge helpers', () => {
  it('classifies green below 80%, amber at/above 80%, red at/above 100%', () => {
    expect(gaugeTone(0)).toBe('ok');
    expect(gaugeTone(79.9)).toBe('ok');
    expect(gaugeTone(80)).toBe('amber');
    expect(gaugeTone(99.9)).toBe('amber');
    expect(gaugeTone(100)).toBe('red');
    expect(gaugeTone(220)).toBe('red');
  });

  it('builds one gauge row per sector threshold (MY services + goods)', () => {
    const rows = buildGaugeRows([
      line({ percent_of_threshold: 92, trailing_value: 460000 }),
      line({ key: 'taxable_goods', label: 'Sales tax', category: 'goods', percent_of_threshold: 40, trailing_value: 200000 }),
    ], null);

    expect(rows).toHaveLength(2);
    expect(rows[0].category).toBe('services');
    expect(rows[0].tone).toBe('amber');
    expect(rows[0].crossed).toBe(false);
    expect(rows[1].category).toBe('goods');
    expect(rows[1].tone).toBe('ok');
  });

  it('derives the percentage from the values when the API omits it', () => {
    const [row] = buildGaugeRows([line({ trailing_value: 250000, percent_of_threshold: null })], null);
    expect(row.percent).toBe(50);
    expect(row.tone).toBe('ok');
  });

  it('marks the forward projection against the same threshold', () => {
    const [row] = buildGaugeRows([line({ percent_of_threshold: 60 })], 520000);
    expect(row.forwardPercent).toBe(104);
    expect(row.forwardCrossed).toBe(true);
  });
});