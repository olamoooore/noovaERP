/**
 * #350 (S2): pure mappers from the sales-analytics API payloads to the
 * dashboard chart series. Kept out of the page component so the mapping can be
 * unit tested against fixtures of the real API shape.
 */

export interface SalesByCategoryApiRow {
  category_id?: number | null;
  category_name?: string | null;
  product_count?: number;
  quantity_sold?: number;
  revenue?: number | string | null;
  total_sales?: number | string | null;
  cost?: number;
  profit?: number;
  profit_margin?: number;
  revenue_percentage?: number;
}

export interface CategoryRevenueSlice {
  category: string;
  amount: number;
}

/**
 * The by-category endpoint returns `revenue` (see SalesAnalyticsService and
 * SalesByCategoryData); it has no `total_revenue` field, which the dashboard
 * used to read — every slice came out as 0.
 */
export function mapCategoryRevenueSlices(
  rows: SalesByCategoryApiRow[] | null | undefined,
): CategoryRevenueSlice[] {
  return (rows ?? []).map((row) => ({
    category: row.category_name || 'Uncategorised',
    amount: Number(row.revenue) || 0,
  }));
}
