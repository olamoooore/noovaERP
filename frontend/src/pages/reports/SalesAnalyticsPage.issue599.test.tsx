// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ProductSalesReportRedirect, SalesAnalyticsPage } from './SalesAnalyticsPage';
import reportApi from '@/lib/reportApi';

// jsdom polyfills Radix needs at render time (same as the smoke suites).
if (!window.matchMedia) {
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia;
}
const testGlobals = globalThis as unknown as {
  ResizeObserver?: new () => { observe(): void; unobserve(): void; disconnect(): void };
};
if (!testGlobals.ResizeObserver) {
  testGlobals.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  };
}
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {};
}
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false;
  Element.prototype.setPointerCapture = () => {};
  Element.prototype.releasePointerCapture = () => {};
}

vi.mock('@/components/charts/LineChart', () => ({ LineChart: () => null }));
vi.mock('@/components/charts/BarChart', () => ({ BarChart: () => null }));
vi.mock('@/components/charts/PieChart', () => ({ PieChart: () => null }));

vi.mock('@/hooks/use-tags', () => ({
  useTags: () => ({ data: [] }),
  default: () => ({ data: [] }),
}));

vi.mock('@/lib/api', () => ({
  default: {
    get: vi.fn(async () => ({ data: { data: [] } })),
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}));

vi.mock('@/lib/pricingApi', () => ({
  default: {
    sellableCurrencies: vi.fn(async () => []),
  },
}));
vi.mock('@/lib/reportApi', () => ({
  default: {
    getSalesByProduct: vi.fn(async () => ({
      products: [
        {
          id: 7,
          name: 'Widget 599',
          sku: 'W-599',
          total_quantity: 3,
          total_revenue: 300,
          total_cost: 120,
          total_profit: 180,
          profit_margin: 60,
        },
      ],
    })),
    getSalesByCurrency: vi.fn(async () => ({ currencies: [], summary: {} })),
    getSalesByCategory: vi.fn(async () => ({ categories: [] })),
    getSalesTrends: vi.fn(async () => ({ trends: [] })),
    getSalesReconciliation: vi.fn(async () => ({
      period: { start_date: '2026-10-01', end_date: '2026-10-31' },
      base_currency: 'MYR',
      report_net_revenue: 0,
      gl_revenue: 0,
      difference: 0,
      reconciled: true,
      report_breakdown: { invoice: 0, pos: 0, credit_note: 0, booking: 0 },
      causes: [],
      notes: [],
    })),
    getSalesByCustomer: vi.fn(async () => ({
      customers: [
        {
          id: 1,
          name: 'Customer 599',
          total_orders: 1,
          total_revenue: 300,
          average_order_value: 300,
          last_purchase_date: '2026-10-02',
        },
      ],
    })),
  },
  formatLocalDate: (date: Date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  },
}));

afterEach(cleanup);

function renderAt(path: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/reports/sales-analytics" element={<SalesAnalyticsPage />} />
          <Route path="/products/report" element={<ProductSalesReportRedirect />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

/**
 * Issue #599 (RPT-UX4): /products/report rendered a separate product-only
 * layout while the main sales report had no product tab. There is now ONE
 * report with Customer / Product / Category / Trend tabs, and the old
 * /products/report URL deep-links to its Product tab so bookmarks keep working.
 */
describe('SalesAnalyticsPage single report with a Product tab (#599)', () => {
  it('offers Customer, Product, Category and Trend tabs on the one report', async () => {
    renderAt('/reports/sales-analytics');

    await screen.findByText('Customer 599');
    for (const name of [/by customer/i, /by product/i, /by category/i, /trends/i]) {
      expect(screen.getByRole('tab', { name })).toBeTruthy();
    }
    expect(screen.queryByText('Product Sales Report')).toBeNull();
  });

  it('opens the Product tab from ?tab=by-product', async () => {
    renderAt('/reports/sales-analytics?tab=by-product');

    await screen.findByText('Widget 599');
    expect(screen.getByRole('tab', { name: /by product/i }).getAttribute('aria-selected')).toBe('true');
    expect(reportApi.getSalesByProduct).toHaveBeenCalled();
  });

  it('redirects the old /products/report URL to the Product tab of the main report', async () => {
    renderAt('/products/report');

    await screen.findByText('Widget 599');
    expect(screen.getByText('Sales Analytics')).toBeTruthy();
    expect(screen.getByRole('tab', { name: /by product/i }).getAttribute('aria-selected')).toBe('true');
  });
});
