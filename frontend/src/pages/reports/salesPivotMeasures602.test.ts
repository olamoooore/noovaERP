// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
  ALL_PIVOT_MEASURES,
  DEFAULT_PIVOT_MEASURES,
  PIVOT_MEASURE_LABELS,
  formatPivotMeasure,
} from './salesPivotRows';

/**
 * Issue #602: the ordered / delivered / invoiced / to-invoice measures are
 * part of the pivot picker, labelled and formatted (quantities vs money),
 * without changing the #601 default selection.
 */
describe('sales pivot lifecycle measures (#602)', () => {
  it('exposes the eight lifecycle measures with human labels', () => {
    expect(ALL_PIVOT_MEASURES).toEqual(
      expect.arrayContaining([
        'qty_ordered',
        'qty_delivered',
        'qty_invoiced',
        'qty_to_invoice',
        'amount_ordered',
        'amount_delivered',
        'amount_invoiced',
        'amount_to_invoice',
      ]),
    );

    expect(PIVOT_MEASURE_LABELS.qty_ordered).toBe('Qty Ordered');
    expect(PIVOT_MEASURE_LABELS.qty_to_invoice).toBe('Qty to Invoice');
    expect(PIVOT_MEASURE_LABELS.amount_invoiced).toBe('Amount Invoiced');
    expect(PIVOT_MEASURE_LABELS.amount_to_invoice).toBe('Amount to Invoice');
  });

  it('keeps the #601 default selection unchanged', () => {
    expect(DEFAULT_PIVOT_MEASURES).toEqual(['paid_qty', 'foc_qty', 'net_revenue', 'cost', 'margin']);
  });

  it('formats lifecycle quantities as numbers and amounts as currency', () => {
    expect(formatPivotMeasure('qty_delivered', 6)).toBe('6');
    expect(formatPivotMeasure('qty_to_invoice', 2)).toBe('2');
    expect(formatPivotMeasure('amount_invoiced', 400)).toMatch(/400/);
    expect(formatPivotMeasure('amount_invoiced', 400)).toMatch(/\$/);
  });
});
