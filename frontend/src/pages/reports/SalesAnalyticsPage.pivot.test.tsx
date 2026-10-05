// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { SalesAnalyticsPage } from './SalesAnalyticsPage';
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

/**
 * Issue #601: the Pivot tab drives the real page. The API payload below is the
 * shape the new endpoint returns: a category → product hierarchy by month,
 * with measures per cell and aggregated parents.
 */
const pivotPayload = {
  title: 'Sales Analysis',
  row_dims: ['category', 'product'],
  col_dims: ['month'],
  measures: ['net_revenue', 'paid_qty'],
  columns: [
    { key: ['2026-08'], label: 'Aug 2026' },
    { key: ['2026-09'], label: 'Sep 2026' },
  ],
  rows: [
    {
      key: ['601 Category A'],
      label: '601 Category A',
      depth: 0,
      values: [
        { net_revenue: 500, paid_qty: 5 },
        { net_revenue: 300, paid_qty: 3 },
      ],
      totals: { net_revenue: 800, paid_qty: 8 },
      children: [
        {
          key: ['601 Category A', '601 Widget A'],
          label: '601 Widget A',
          depth: 1,
          values: [
            { net_revenue: 500, paid_qty: 5 },
            { net_revenue: 300, paid_qty: 3 },
          ],
          totals: { net_revenue: 800, paid_qty: 8 },
          children: [],
        },
      ],
    },
  ],
  totals: { net_revenue: 800, paid_qty: 8 },
  summary: { row_count: 2, column_count: 2, base_currency: 'USD' },
};

vi.mock('@/lib/reportApi', () => ({
  default: {
    getSalesByProduct: vi.fn(async () => ({ products: [] })),
    getSalesByCurrency: vi.fn(async () => ({ currencies: [], summary: {} })),
    getSalesByCategory: vi.fn(async () => ({ categories: [] })),
    getSalesTrends: vi.fn(async () => ({ trends: [] })),
    getSalesByCustomer: vi.fn(async () => ({ customers: [] })),
    getSalesPivot: vi.fn(async () => pivotPayload),
  },
  formatLocalDate: (date: Date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  },
}));

afterEach(cleanup);

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <SalesAnalyticsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('SalesAnalyticsPage pivot tab (#601)', () => {
  it('renders the pivot and toggles the row hierarchy', async () => {
    renderPage();

    fireEvent.mouseDown(screen.getByRole('tab', { name: /pivot/i }));

    // Parent row is rendered; its child starts collapsed.
    await screen.findByText('601 Category A');
    expect(screen.queryByText('601 Widget A')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Expand 601 Category A' }));
    await screen.findByText('601 Widget A');

    fireEvent.click(screen.getByRole('button', { name: 'Collapse 601 Category A' }));
    await waitFor(() => expect(screen.queryByText('601 Widget A')).toBeNull());

    // The request carried the default dimensions and every measure.
    expect(reportApi.getSalesPivot).toHaveBeenCalledWith(
      expect.any(Object),
      expect.objectContaining({
        row_dims: ['product'],
        col_dims: ['month'],
        measure: ['paid_qty', 'foc_qty', 'net_revenue', 'cost', 'margin'],
      }),
    );
  });

  it('re-queries when the row dimensions and measures change', async () => {
    renderPage();
    fireEvent.mouseDown(screen.getByRole('tab', { name: /pivot/i }));
    await screen.findByText('601 Category A');

    // Category / Product, two row group-bys.
    fireEvent.change(screen.getByLabelText('Row dimension 1'), { target: { value: 'category' } });
    fireEvent.click(screen.getByRole('button', { name: /add row/i }));
    fireEvent.change(screen.getByLabelText('Row dimension 2'), { target: { value: 'product' } });

    await waitFor(() => {
      const calls = (reportApi.getSalesPivot as any).mock.calls;
      const last = calls[calls.length - 1];
      expect(last[1]).toMatchObject({ row_dims: ['category', 'product'] });
    });

    // Dropping a measure re-queries without it.
    fireEvent.click(screen.getByLabelText('Cost'));

    await waitFor(() => {
      const calls = (reportApi.getSalesPivot as any).mock.calls;
      const last = calls[calls.length - 1];
      expect(last[1].measure).not.toContain('cost');
      expect(last[1].measure).toContain('foc_qty');
    });
  });
});
