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

vi.mock('@/lib/reportApi', () => ({
  default: {
    getSalesByProduct: vi.fn(async () => ({ products: [] })),
    getSalesByCurrency: vi.fn(async () => ({ currencies: [], summary: {} })),
    getSalesByCategory: vi.fn(async () => ({ categories: [] })),
    getSalesTrends: vi.fn(async () => ({ trends: [] })),
    getSalesByCustomer: vi.fn(async (filters: any) => ({
      customers: [
        {
          id: 1,
          name: 'Acme Clinic',
          total_orders: 2,
          total_revenue: 200,
          average_order_value: 100,
          last_purchase_date: '2026-10-02',
          total_revenue_previous: 100,
          total_revenue_delta: 100,
          total_revenue_delta_percent: 100,
        },
      ],
      ...(filters?.comparison
        ? {
            comparison: {
              mode: filters.comparison,
              period: { start_date: '2026-09-01', end_date: '2026-09-30' },
            },
          }
        : {}),
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

/**
 * Issue #595 (SALES-RPT, P2, RP6): the sales analytics report had no
 * comparison period — ReportFilter's compareStartDate/compareEndDate were
 * never used and no tab offered a "vs previous period" mode. This drives the
 * real page: the Compare control selects the mode, the API request carries it,
 * and the table grows Δ and Δ% columns sourced from the API's comparison
 * fields.
 */
describe('SalesAnalyticsPage comparison period (#595)', () => {
  it('adds Δ and Δ% columns once a comparison mode is selected', async () => {
    const { container } = renderPage();

    await screen.findByText('Acme Clinic');
    expect(screen.queryByText('Δ Revenue')).toBeNull();
    expect(screen.queryByText('Δ% Revenue')).toBeNull();

    const trigger = await screen.findByRole('combobox', { name: /comparison period/i });
    fireEvent.click(trigger);
    fireEvent.click(await screen.findByText('Previous period'));

    await waitFor(() => expect(screen.getByText('Δ Revenue')).toBeTruthy());
    expect(screen.getByText('Δ% Revenue')).toBeTruthy();

    expect(reportApi.getSalesByCustomer).toHaveBeenCalledWith(
      expect.objectContaining({ comparison: 'previous_period' }),
    );

    // The delta cells render the API comparison values (previous 100 → +100,
    // +100%).
    await waitFor(() => expect(container.textContent).toContain('+$100.00'));
    expect(container.textContent).toContain('+100.00%');
  });

  it('offers a custom range and waits for both dates before asking for a custom comparison', async () => {
    renderPage();
    await screen.findByText('Acme Clinic');

    const trigger = await screen.findByRole('combobox', { name: /comparison period/i });
    fireEvent.click(trigger);
    fireEvent.click(await screen.findByText('Custom range'));

    await screen.findByLabelText('Compare from');
    // Neither date chosen yet: the page must not send an incomplete custom
    // comparison (the API answers 422 for that).
    await waitFor(() => {
      const calls = (reportApi.getSalesByCustomer as any).mock.calls;
      const last = calls[calls.length - 1]?.[0] ?? {};
      expect(last.comparison).toBeUndefined();
    });

    fireEvent.change(screen.getByLabelText('Compare from'), { target: { value: '2026-08-01' } });
    fireEvent.change(screen.getByLabelText('Compare to'), { target: { value: '2026-08-31' } });

    await waitFor(() => {
      expect(reportApi.getSalesByCustomer).toHaveBeenCalledWith(
        expect.objectContaining({
          comparison: 'custom',
          compare_start_date: '2026-08-01',
          compare_end_date: '2026-08-31',
        }),
      );
    });
  });
});
