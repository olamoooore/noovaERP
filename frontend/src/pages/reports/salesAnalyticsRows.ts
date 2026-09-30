/**
 * Normalisers for the sales-analytics API payloads.
 *
 * The analytics endpoints have renamed fields over time and the frontend used
 * to read names the API does not send (`invoice_count`, `avg_order_value`,
 * `percentage`), which rendered `$NaN` and crashed a `value.toFixed()`
 * formatter. Everything that maps a raw API row to a render row lives here so
 * the mapping is unit-testable and only has to be right in one place.
 */

export interface ProductRow {
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

export interface CustomerRow {
  /** Kept for traceability links (customer detail page). */
  id: number | null;
  name: string;
  total_orders: number;
  total_revenue: number;
  average_order_value: number;
  last_purchase_date: string | null;
}

export interface TrendRow {
  period: string;
  invoice_count: number;
  total_revenue: number;
  avg_order_value: number;
}

export interface CategoryRow {
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
  }));

export const normalizeCustomers = (payload: any): CustomerRow[] =>
  asArray(payload?.customers).map((c: any) => ({
    id: num(pick(c, 'id', 'customer_id')) || null,
    name: c.name ?? c.customer_name ?? 'Walk-in Customer',
    total_orders: num(pick(c, 'total_orders', 'order_count')),
    total_revenue: num(pick(c, 'total_revenue', 'total_sales', 'revenue')),
    average_order_value: num(pick(c, 'average_order_value', 'avg_order_value')),
    last_purchase_date: c.last_purchase_date ?? null,
  }));

export const normalizeTrends = (payload: any): TrendRow[] =>
  asArray(payload?.trends).map((t: any) => ({
    period: t.period ?? '-',
    invoice_count: num(pick(t, 'invoice_count', 'order_count')),
    total_revenue: num(pick(t, 'total_revenue', 'total_sales', 'revenue')),
    avg_order_value: num(pick(t, 'avg_order_value', 'average_order_value')),
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
