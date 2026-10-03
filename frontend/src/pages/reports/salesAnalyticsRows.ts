/**
 * Normalisers for the sales-analytics API payloads.
 *
 * The analytics endpoints have renamed fields over time and the frontend used
 * to read names the API does not send (`invoice_count`, `avg_order_value`,
 * `percentage`), which rendered `$NaN` and crashed a `value.toFixed()`
 * formatter. Everything that maps a raw API row to a render row lives here so
 * the mapping is unit-testable and only has to be right in one place.
 */

import { addDays, format, isValid, parse, startOfISOWeek } from 'date-fns';

/**
 * Issue #595: flat comparison fields the API adds to a row when a comparison
 * period was requested — `${metric}_previous`, `${metric}_delta` and
 * `${metric}_delta_percent` (null when the previous value is 0). Kept flat
 * because ReportTable looks cell values up by column key.
 */
export interface ComparisonFields {
  [key: string]: unknown;
}

export interface ProductRow extends ComparisonFields {
  /** Kept for traceability links (product detail page). */
  id: number | null;
  name: string;
  sku: string;
  total_quantity: number;
  total_revenue: number;
  total_cost: number;
  total_profit: number;
  profit_margin: number;
}

export interface CustomerRow extends ComparisonFields {
  /** Kept for traceability links (customer detail page). */
  id: number | null;
  name: string;
  total_orders: number;
  total_revenue: number;
  average_order_value: number;
  last_purchase_date: string | null;
}

export interface TrendRow extends ComparisonFields {
  period: string;
  invoice_count: number;
  total_revenue: number;
  avg_order_value: number;
}

export interface CategoryRow extends ComparisonFields {
  /** Kept for traceability links (products filtered by category). */
  category_id: number | null;
  category_name: string;
  product_count: number;
  quantity_sold: number;
  total_revenue: number;
  total_profit: number;
  profit_margin: number;
  percentage: number;
}

/** Coerce an API value to a finite number (missing/renamed fields become 0). */
export const num = (value: unknown): number => {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
};

/** First present (non-null) value among several candidate field names. */
export const pick = (row: any, ...keys: string[]): unknown => {
  for (const key of keys) {
    const value = row?.[key];
    if (value !== undefined && value !== null) return value;
  }
  return undefined;
};

const asArray = (value: unknown): any[] => (Array.isArray(value) ? value : []);

/**
 * Issue #595: copy every `${metric}_previous|_delta|_delta_percent` triple the
 * API sent into flat row fields, so a delta column can key straight into the
 * row. A missing triple leaves the row untouched (columns render '-'), which
 * is what keeps responses without a comparison unchanged.
 */
export const comparisonFields = (raw: any): Record<string, number | null> => {
  const fields: Record<string, number | null> = {};
  for (const key of Object.keys(raw ?? {})) {
    if (!key.endsWith('_delta')) continue;
    const metric = key.slice(0, -'_delta'.length);
    const percent = raw[`${metric}_delta_percent`];
    fields[`${metric}_previous`] = num(raw[`${metric}_previous`]);
    fields[`${metric}_delta`] = num(raw[key]);
    fields[`${metric}_delta_percent`] =
      percent === null || percent === undefined ? null : num(percent);
  }
  return fields;
};

export const normalizeProducts = (payload: any): ProductRow[] =>
  asArray(payload?.products).map((p: any) => ({
    id: num(pick(p, 'id', 'product_id')) || null,
    name: p.name ?? p.product_name ?? '-',
    sku: p.sku ?? '-',
    total_quantity: num(pick(p, 'total_quantity', 'quantity_sold')),
    total_revenue: num(pick(p, 'total_revenue', 'total_sales', 'revenue')),
    total_cost: num(pick(p, 'total_cost', 'cost')),
    total_profit: num(pick(p, 'total_profit', 'profit')),
    profit_margin: num(p.profit_margin),
    ...comparisonFields(p),
  }));

export const normalizeCustomers = (payload: any): CustomerRow[] =>
  asArray(payload?.customers).map((c: any) => ({
    id: num(pick(c, 'id', 'customer_id')) || null,
    name: c.name ?? c.customer_name ?? 'Walk-in Customer',
    total_orders: num(pick(c, 'total_orders', 'order_count')),
    total_revenue: num(pick(c, 'total_revenue', 'total_sales', 'revenue')),
    average_order_value: num(pick(c, 'average_order_value', 'avg_order_value')),
    last_purchase_date: c.last_purchase_date ?? null,
    ...comparisonFields(c),
  }));

export const normalizeTrends = (payload: any): TrendRow[] =>
  asArray(payload?.trends).map((t: any) => ({
    period: t.period ?? '-',
    invoice_count: num(pick(t, 'invoice_count', 'order_count')),
    total_revenue: num(pick(t, 'total_revenue', 'total_sales', 'revenue')),
    avg_order_value: num(pick(t, 'avg_order_value', 'average_order_value')),
    ...comparisonFields(t),
  }));

export const normalizeCategories = (payload: any): CategoryRow[] =>
  asArray(payload?.categories).map((c: any) => ({
    category_id: num(pick(c, 'category_id', 'id')) || null,
    category_name: c.category_name ?? 'Uncategorized',
    product_count: num(pick(c, 'product_count')),
    quantity_sold: num(pick(c, 'quantity_sold', 'total_quantity')),
    total_revenue: num(pick(c, 'total_revenue', 'total_sales', 'revenue')),
    total_profit: num(pick(c, 'total_profit', 'profit')),
    profit_margin: num(c.profit_margin),
    percentage: num(pick(c, 'percentage', 'revenue_percentage')),
    ...comparisonFields(c),
  }));

/** Sum of a numeric field across rows — never NaN. */
export const sumBy = <T,>(rows: T[], get: (row: T) => number): number =>
  rows.reduce((total, row) => total + num(get(row)), 0);

/** Mean of a numeric field across rows (0 for an empty set) — never NaN. */
export const avgBy = <T,>(rows: T[], get: (row: T) => number): number =>
  rows.length > 0 ? sumBy(rows, get) / rows.length : 0;

/** Parse an API date for display; invalid or missing values render as '-'. */
export const safeDate = (value: unknown): string => {
  if (!value) return '-';
  const date = new Date(value as string);
  return Number.isNaN(date.getTime()) ? '-' : date.toLocaleDateString();
};

/** `2026-08-31` → `31 Aug 2026` (falls back to the raw value). */
export const formatPeriodDate = (value?: string | null) => {
  if (!value) return '…';
  const date = parse(value, 'yyyy-MM-dd', new Date());
  return isValid(date) ? format(date, 'dd MMM yyyy') : value;
};

/**
 * `2026-39` (ISO week year + ISO week) → `Week 39 (21–27 Sep 2026)`.
 *
 * ISO weeks run Monday–Sunday. A week that crosses a month or year boundary
 * names both sides (`Week 1 (29 Dec 2025 – 4 Jan 2026)`) instead of printing
 * one month that would be wrong.
 */
const formatISOWeekPeriod = (period: string) => {
  const [yearPart, weekPart] = period.split('-');
  const year = Number(yearPart);
  const week = Number(weekPart);
  if (!Number.isInteger(year) || !Number.isInteger(week) || week < 1 || week > 53) {
    return period;
  }

  const monday = addDays(startOfISOWeek(new Date(year, 0, 4)), (week - 1) * 7);
  const sunday = addDays(monday, 6);
  const sameMonth =
    monday.getMonth() === sunday.getMonth() && monday.getFullYear() === sunday.getFullYear();

  return sameMonth
    ? `Week ${week} (${format(monday, 'd')}–${format(sunday, 'd MMM yyyy')})`
    : `Week ${week} (${format(monday, 'd MMM yyyy')} – ${format(sunday, 'd MMM yyyy')})`;
};

/**
 * Human period label for trend rows: day → `22 Sep 2026`, week → `Week 39
 * (21–27 Sep 2026)`, month → `Sep 2026`, year → the raw year.
 *
 * `interval` resolves the one ambiguous shape: `YYYY-MM` is a month key and
 * `IYYY-IW` is a week key, and both look like `2026-39`. Without it, a second
 * component above 12 can only be a week.
 */
export const formatTrendPeriod = (period: string, interval?: string) => {
  if (/^\d{4}-\d{2}-\d{2}$/.test(period)) {
    return formatPeriodDate(period);
  }
  const yearMonth = /^(\d{4})-(\d{2})$/.exec(period);
  if (yearMonth) {
    const second = Number(yearMonth[2]);
    if (interval === 'week' || (interval === undefined && second > 12)) {
      return formatISOWeekPeriod(period);
    }
    const date = parse(`${period}-01`, 'yyyy-MM-dd', new Date());
    return isValid(date) ? format(date, 'MMM yyyy') : period;
  }
  return period;
};
