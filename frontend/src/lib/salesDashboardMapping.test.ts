import { describe, expect, it } from 'vitest';
import { mapCategoryRevenueSlices, mapRevenueTrendPoints } from './salesDashboardMapping';

/**
 * #350 (S2): fixture mirrors the real /analytics/sales/by-category payload —
 * items carry `revenue` (and `total_sales`), no `total_revenue`.
 */
describe('mapCategoryRevenueSlices', () => {
  it('reads revenue from the API rows', () => {
    const slices = mapCategoryRevenueSlices([
      {
        category_id: 1,
        category_name: 'Beverages',
        product_count: 4,
        quantity_sold: 20,
        revenue: 1250.5,
        total_sales: 1250.5,
        cost: 600,
        profit: 650,
        profit_margin: 52,
        revenue_percentage: 100,
      },
      {
        category_id: 2,
        category_name: 'Snacks',
        product_count: 2,
        quantity_sold: 5,
        revenue: '349.5',
        total_sales: '349.5',
        cost: 100,
        profit: 249.5,
        profit_margin: 71.4,
        revenue_percentage: 21.8,
      },
    ]);

    expect(slices).toEqual([
      { category: 'Beverages', amount: 1250.5 },
      { category: 'Snacks', amount: 349.5 },
    ]);
  });

  it('falls back for a missing name and a missing revenue', () => {
    const slices = mapCategoryRevenueSlices([
      { category_id: null, category_name: null, revenue: null },
      { category_id: 3, category_name: 'Stationery' },
    ]);

    expect(slices).toEqual([
      { category: 'Uncategorised', amount: 0 },
      { category: 'Stationery', amount: 0 },
    ]);
  });

  it('handles an absent payload', () => {
    expect(mapCategoryRevenueSlices(undefined)).toEqual([]);
    expect(mapCategoryRevenueSlices(null)).toEqual([]);
  });
});

/**
 * #349 (S1): fixture mirrors the real /analytics/sales/trends payload (the
 * controller wraps the service's `data` as `trends`); each point carries
 * `revenue`, no `total_revenue`.
 */
describe('mapRevenueTrendPoints', () => {
  it('reads revenue from the API points', () => {
    const points = mapRevenueTrendPoints([
      {
        period: '2026-10-01',
        order_count: 3,
        total_sales: 750,
        revenue: 750,
        average_order_value: 250,
        growth_rate: null,
      },
      {
        period: '2026-10-02',
        order_count: 1,
        total_sales: '120.25',
        revenue: '120.25',
        average_order_value: 120.25,
        growth_rate: -84,
      },
    ]);

    expect(points).toEqual([
      { period: '2026-10-01', amount: 750 },
      { period: '2026-10-02', amount: 120.25 },
    ]);
  });

  it('tolerates missing values and an absent payload', () => {
    expect(mapRevenueTrendPoints([{ period: null, revenue: null }])).toEqual([
      { period: '', amount: 0 },
    ]);
    expect(mapRevenueTrendPoints(undefined)).toEqual([]);
  });
});
