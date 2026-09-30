import { describe, expect, it } from 'vitest';
import {
  avgBy,
  normalizeCategories,
  normalizeCustomers,
  normalizeProducts,
  normalizeTrends,
  num,
  safeDate,
  sumBy,
} from './salesAnalyticsRows';

/**
 * These payloads are captured verbatim from the live analytics endpoints
 * (GET /api/v1/analytics/sales/*). They are the regression guard for the
 * report page rendering `$NaN` and crashing on `value.toFixed()` because the
 * frontend read field names the API does not send.
 */

const TRENDS_PAYLOAD = {
  trends: [
    {
      period: '2026-09',
      order_count: 2793,
      total_sales: 3743830.04,
      revenue: 3743830.04,
      average_order_value: 1340.43,
      growth_rate: null,
    },
  ],
};

const CATEGORY_PAYLOAD = {
  categories: [
    {
      category_id: null,
      category_name: 'Uncategorized',
      product_count: 33,
      quantity_sold: 12344,
      revenue: 3458379.02,
      total_sales: 3458379.02,
      cost: 486296,
      profit: 2972083.02,
      profit_margin: 85.94,
      revenue_percentage: 100,
    },
  ],
};

const CUSTOMER_PAYLOAD = {
  customers: [
    {
      id: 85,
      name: 'Singapore General Hospital',
      customer_name: 'Singapore General Hospital',
      total_orders: 35,
      total_revenue: 320806.6,
      total_sales: 320806.6,
      average_order_value: 9165.9,
      last_purchase_date: '2026-09-16 00:00:00',
    },
  ],
};

const PRODUCT_PAYLOAD = {
  products: [
    {
      id: 12,
      name: 'Surgical Gloves',
      sku: 'SG-100',
      total_quantity: 480,
      quantity_sold: 480,
      total_revenue: 2400,
      total_sales: 2400,
      total_cost: 1200,
      total_profit: 1200,
      profit_margin: 50,
    },
  ],
};

describe('traceability ids survive normalisation', () => {
  it('carries category ids for filtered product links', () => {
    const rows = normalizeCategories({ categories: [{ category_id: 12, category_name: 'Sutures' }] });
    expect(rows[0].category_id).toBe(12);
  });

  it('carries product ids for detail links', () => {
    const rows = normalizeProducts({ products: [{ id: 42, name: 'Widget', sku: 'W-1' }] });
    expect(rows[0].id).toBe(42);
    expect(normalizeProducts({ products: [{ product_id: 7, name: 'Legacy' }] })[0].id).toBe(7);
  });

  it('carries customer ids for detail links', () => {
    const rows = normalizeCustomers({ customers: [{ id: 103, name: 'Asc Clinic' }] });
    expect(rows[0].id).toBe(103);
    expect(normalizeCustomers({ customers: [{ customer_id: 9, customer_name: 'Legacy' }] })[0].id).toBe(9);
  });
});

describe('trends', () => {
  it('maps the API names (order_count / total_sales / average_order_value)', () => {
    const [row] = normalizeTrends(TRENDS_PAYLOAD);
    expect(row.period).toBe('2026-09');
    expect(row.invoice_count).toBe(2793);
    expect(row.total_revenue).toBe(3743830.04);
    expect(row.avg_order_value).toBe(1340.43);
  });

  it('still accepts the legacy names if the API ever emits them', () => {
    const [row] = normalizeTrends({
      trends: [{ period: 'x', invoice_count: 3, total_revenue: 9, avg_order_value: 3 }],
    });
    expect(row.invoice_count).toBe(3);
    expect(row.total_revenue).toBe(9);
    expect(row.avg_order_value).toBe(3);
  });
});

describe('categories', () => {
  it('maps revenue/total_sales and revenue_percentage', () => {
    const [row] = normalizeCategories(CATEGORY_PAYLOAD);
    expect(row.category_name).toBe('Uncategorized');
    expect(row.total_revenue).toBe(3458379.02);
    expect(row.percentage).toBe(100);
    expect(row.product_count).toBe(33);
  });

  it('produces a finite percentage so `percentage.toFixed()` cannot throw', () => {
    const [row] = normalizeCategories({ categories: [{ category_name: 'X' }] });
    expect(Number.isFinite(row.percentage)).toBe(true);
    expect(row.percentage.toFixed(1)).toBe('0.0');
  });
});

describe('customers', () => {
  it('keeps name/total_revenue and falls back to customer_name', () => {
    const [row] = normalizeCustomers(CUSTOMER_PAYLOAD);
    expect(row.name).toBe('Singapore General Hospital');
    expect(row.total_revenue).toBe(320806.6);
    expect(row.total_orders).toBe(35);
    expect(row.average_order_value).toBe(9165.9);

    const [fallback] = normalizeCustomers({ customers: [{ customer_name: 'Only Alias' }] });
    expect(fallback.name).toBe('Only Alias');
  });

  it('falls back to "Walk-in Customer" for a null customer', () => {
    const [row] = normalizeCustomers({ customers: [{ id: null, name: null }] });
    expect(row.name).toBe('Walk-in Customer');
  });
});

describe('products', () => {
  it('maps quantity_sold/total_cost aliases', () => {
    const [row] = normalizeProducts(PRODUCT_PAYLOAD);
    expect(row.total_quantity).toBe(480);
    expect(row.total_revenue).toBe(2400);
    expect(row.total_cost).toBe(1200);
    expect(row.total_profit).toBe(1200);
  });
});

describe('missing / malformed payloads never produce NaN', () => {
  it('returns empty arrays for absent envelopes', () => {
    for (const normalise of [normalizeProducts, normalizeCustomers, normalizeTrends, normalizeCategories]) {
      expect(normalise(undefined)).toEqual([]);
      expect(normalise({})).toEqual([]);
    }
  });

  it('treats non-numeric and missing values as 0', () => {
    expect(num(undefined)).toBe(0);
    expect(num(null)).toBe(0);
    expect(num('abc')).toBe(0);
    expect(num(NaN)).toBe(0);
    expect(num('42.5')).toBe(42.5);
  });

  it('sums and averages without poisoning the result', () => {
    const rows = [{ v: 10 }, { v: undefined }, { v: 20 }] as any[];
    expect(sumBy(rows, (r) => r.v)).toBe(30);
    // A missing value counts as 0 for the mean, matching the API's AVG(...)
    // over the same row set (30 / 3).
    expect(avgBy(rows, (r) => r.v)).toBe(10);
    expect(avgBy([], (r: any) => r.v)).toBe(0);
  });

  it('renders an unparseable date as "-"', () => {
    expect(safeDate(null)).toBe('-');
    expect(safeDate('not a date')).toBe('-');
    expect(safeDate('2026-09-16 00:00:00')).not.toBe('-');
  });
});
