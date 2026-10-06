// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { SalesAnalyticsPage } from './SalesAnalyticsPage';
import { formatCurrency } from '@/lib/utils';

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
          name: 'Implant 600',
          sku: 'IMP-600',
          total_quantity: 4,
          paid_quantity: 4,
          foc_quantity: 1,
          foc_list_value: 100,
          average_price: 100,
          total_revenue: 400,
          total_cost: 160,
          total_profit: 240,
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
          name: 'Hospital 600',
          total_orders: 1,
          total_revenue: 400,
          average_order_value: 400,
          paid_quantity: 4,
          foc_quantity: 1,
          foc_list_value: 100,
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

function renderPage(productOnly = false) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <SalesAnalyticsPage productOnly={productOnly} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

/** The cell texts of the table row that contains `label`. */
function rowCells(label: string): string[] {
  const row = screen.getByText(label).closest('tr');
  expect(row).not.toBeNull();
  return Array.from(row!.querySelectorAll('td')).map((cell) => cell.textContent?.trim() ?? '');
}

/**
 * Issue #600 (RPT-UX5): free-of-charge (bonus scheme) units counted in
 * quantity sold at zero revenue. The product and customer tabs show paid qty,
 * FOC qty and FOC list value as separate columns; average price and margin
 * are on paid units (the API computes them that way).
 */
describe('SalesAnalyticsPage FOC columns (#600)', () => {
  it('shows paid qty, FOC qty and FOC value on the customer tab', async () => {
    renderPage();

    await screen.findByText('Hospital 600');
    expect(screen.getByText('Paid Qty')).toBeTruthy();
    expect(screen.getByText('FOC Qty')).toBeTruthy();
    expect(screen.getByText('FOC Value')).toBeTruthy();

    const cells = rowCells('Hospital 600');
    expect(cells).toContain('4');
    expect(cells).toContain('1');
    expect(cells).toContain(formatCurrency(100));
  });

  it('shows paid qty, FOC qty, FOC value and average price on the product tab', async () => {
    renderPage(true);

    await screen.findByText('Implant 600');
    expect(screen.getByText('Paid Qty')).toBeTruthy();
    expect(screen.getByText('FOC Qty')).toBeTruthy();
    expect(screen.getByText('FOC Value')).toBeTruthy();
    expect(screen.getByText('Avg Price')).toBeTruthy();

    const cells = rowCells('Implant 600');
    expect(cells).toContain('4');
    expect(cells).toContain('1');
    expect(cells).toContain(formatCurrency(100));
    expect(cells).toContain('60.00%');
  });
});
