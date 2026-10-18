// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { downloadReportXlsx } from '@/lib/reportFiles';
import {
  buildPivotExportRows,
  flattenPivotLeaves,
  formatPivotMeasure,
  pivotNodeKey,
} from './salesPivotRows';
import type { SalesPivotData } from '@/types/reports';

/**
 * Issue #601: the pivot's XLSX export must cover every leaf row and the
 * totals, not only what happens to be expanded on screen, and it must reuse
 * the repo's existing report-file writer (`xlsx` via downloadReportXlsx).
 */
const pivot: SalesPivotData = {
  title: 'Sales Analysis',
  row_dims: ['product'],
  col_dims: ['month'],
  measures: ['net_revenue', 'paid_qty'],
  columns: [
    { key: ['2026-08'], label: 'Aug 2026' },
    { key: ['2026-09'], label: 'Sep 2026' },
  ],
  rows: [
    {
      key: ['601 Widget A'],
      label: '601 Widget A',
      depth: 0,
      values: [
        { net_revenue: 500, paid_qty: 5 },
        { net_revenue: 300, paid_qty: 3 },
      ],
      totals: { net_revenue: 800, paid_qty: 8 },
      children: [],
    },
    {
      key: ['601 Widget B'],
      label: '601 Widget B',
      depth: 0,
      values: [
        { net_revenue: 0, paid_qty: 0 },
        { net_revenue: 400, paid_qty: 2 },
      ],
      totals: { net_revenue: 400, paid_qty: 2 },
      children: [],
    },
  ],
  totals: { net_revenue: 1200, paid_qty: 10 },
  summary: { row_count: 2, column_count: 2, base_currency: 'USD' },
};

describe('sales pivot rows and export (#601)', () => {
  it('flattens the hierarchy to its leaves and keys nodes by their path', () => {
    const nested: SalesPivotData = {
      ...pivot,
      rows: [
        {
          key: ['601 Category A'],
          label: '601 Category A',
          depth: 0,
          values: [{ net_revenue: 500, paid_qty: 5 }],
          totals: { net_revenue: 500, paid_qty: 5 },
          children: [
            {
              key: ['601 Category A', '601 Widget A'],
              label: '601 Widget A',
              depth: 1,
              values: [{ net_revenue: 500, paid_qty: 5 }],
              totals: { net_revenue: 500, paid_qty: 5 },
              children: [],
            },
          ],
        },
      ],
    };

    expect(flattenPivotLeaves(nested.rows).map((row) => row.label)).toEqual(['601 Widget A']);
    expect(pivotNodeKey(nested.rows[0])).toBe('["601 Category A"]');
    expect(pivotNodeKey(nested.rows[0].children[0])).toBe(
      '["601 Category A","601 Widget A"]',
    );
  });

  it('builds one export column per (column × measure) plus totals', () => {
    const rows = buildPivotExportRows(pivot);

    expect(rows[0]).toEqual([
      'Product',
      'Aug 2026 — Net Revenue',
      'Aug 2026 — Paid Qty',
      'Sep 2026 — Net Revenue',
      'Sep 2026 — Paid Qty',
      'Total — Net Revenue',
      'Total — Paid Qty',
    ]);

    expect(rows[1][0]).toBe('601 Widget A');
    expect(rows[2][0]).toBe('601 Widget B');

    // Totals row: per-column sums first, then the API's grand totals.
    expect(rows[3][0]).toBe('Total');
    expect(rows[3][rows[0].length - 2]).toBe(formatPivotMeasure('net_revenue', 1200));
    expect(rows[3][rows[0].length - 1]).toBe(formatPivotMeasure('paid_qty', 10));
  });

  it('exports a real xlsx (zip) file from the pivot grid', async () => {
    const blob = await downloadReportXlsx(buildPivotExportRows(pivot), {
      filename: 'sales-pivot',
      title: 'Sales Analysis',
    });

    expect(blob).toBeTruthy();
    expect(blob!.type).toContain('spreadsheetml');
    const bytes = new Uint8Array(await blob!.arrayBuffer());
    expect([bytes[0], bytes[1], bytes[2], bytes[3]]).toEqual([0x50, 0x4b, 0x03, 0x04]);
  });
});
