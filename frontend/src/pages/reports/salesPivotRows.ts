/**
 * Issue #601: presentation helpers for the sales pivot.
 *
 * The pivot API returns a whitelisted dimension/measure matrix with a row
 * hierarchy. Everything that maps that payload to display strings or export
 * rows lives here so it is unit-testable without rendering the page.
 */

import { formatCurrency, formatNumber } from '@/lib/utils';
import type {
  PivotDimension,
  PivotMeasure,
  PivotRowNode,
  SalesPivotData,
} from '@/types/reports';

export const ALL_PIVOT_DIMENSIONS: PivotDimension[] = [
  'salesperson',
  'team',
  'customer',
  'product',
  'category',
  'country',
  'month',
];

export const PIVOT_DIMENSION_LABELS: Record<PivotDimension, string> = {
  salesperson: 'Salesperson',
  team: 'Team',
  customer: 'Customer',
  product: 'Product',
  category: 'Category',
  country: 'Country',
  month: 'Month',
};

export const ALL_PIVOT_MEASURES: PivotMeasure[] = [
  'paid_qty',
  'foc_qty',
  'net_revenue',
  'cost',
  'margin',
];

export const PIVOT_MEASURE_LABELS: Record<PivotMeasure, string> = {
  paid_qty: 'Paid Qty',
  foc_qty: 'FOC Qty',
  net_revenue: 'Net Revenue',
  cost: 'Cost',
  margin: 'Margin',
};

/** Measures the issue lists; all selected by default. */
export const DEFAULT_PIVOT_MEASURES: PivotMeasure[] = [...ALL_PIVOT_MEASURES];

/** Measures rendered as money; the rest are quantities. */
const MONEY_MEASURES: PivotMeasure[] = ['net_revenue', 'cost', 'margin'];

export const formatPivotMeasure = (measure: PivotMeasure, value: number): string =>
  MONEY_MEASURES.includes(measure) ? formatCurrency(value) : formatNumber(value);

/** Unique key for a row node's expand/collapse state and React lists. */
export const pivotNodeKey = (node: Pick<PivotRowNode, 'key'>): string => JSON.stringify(node.key);

/** Every leaf row of the hierarchy, in display order. */
export const flattenPivotLeaves = (rows: PivotRowNode[]): PivotRowNode[] =>
  rows.flatMap((row) => (row.children.length > 0 ? flattenPivotLeaves(row.children) : [row]));

/**
 * Complete export grid for the pivot: one column per (column group × measure),
 * one row per leaf, plus a totals row whose per-column cells are summed over
 * the leaves and whose final cell is the API's grand total.
 */
export const buildPivotExportRows = (data: SalesPivotData): string[][] => {
  const { measures, columns } = data;
  const leaves = flattenPivotLeaves(data.rows);
  const multiMeasure = measures.length > 1;

  const header = [
    data.row_dims.map((dimension) => PIVOT_DIMENSION_LABELS[dimension]).join(' / ') || 'Row',
    ...columns.flatMap((column) =>
      measures.map((measure) =>
        multiMeasure ? `${column.label} — ${PIVOT_MEASURE_LABELS[measure]}` : column.label,
      ),
    ),
    ...measures.map((measure) =>
      multiMeasure ? `Total — ${PIVOT_MEASURE_LABELS[measure]}` : 'Total',
    ),
  ];

  const body = leaves.map((node) => [
    node.key.map((value) => value || '—').join(' / '),
    ...node.values.flatMap((cell) =>
      measures.map((measure) => formatPivotMeasure(measure, cell[measure] ?? 0)),
    ),
    ...measures.map((measure) => formatPivotMeasure(measure, node.totals[measure] ?? 0)),
  ]);

  const total = [
    'Total',
    ...columns.flatMap((_, index) =>
      measures.map((measure) =>
        formatPivotMeasure(
          measure,
          leaves.reduce((sum, node) => sum + (node.values[index]?.[measure] ?? 0), 0),
        ),
      ),
    ),
    ...measures.map((measure) => formatPivotMeasure(measure, data.totals[measure] ?? 0)),
  ];

  return [header, ...body, total];
};
