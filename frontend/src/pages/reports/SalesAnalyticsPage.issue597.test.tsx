// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
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
    get: vi.fn(async (url: string) => {
      if (url === '/product-categories') return { data: [{ id: 4, name: 'Implants' }] };
      if (url === '/customers') {
        return {
          data: {
            data: [{ id: 1, name: 'Hospital Y', salesperson: { id: 3, name: 'Salesperson X' } }],
          },
        };
      }
      return { data: { data: [] } };
    }),
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}));

vi.mock('@/lib/pricingApi', () => ({
  default: { sellableCurrencies: vi.fn(async () => []) },
}));

vi.mock('@/lib/reportApi', () => ({
  default: {
    getSalesByProduct: vi.fn(async () => ({
      products: [
        {
          id: 7, name: 'Implant 596', sku: 'IMP-596', total_quantity: 3, paid_quantity: 3,
          foc_quantity: 0, foc_list_value: 0, average_price: 140, total_revenue: 420,
          total_cost: 300, total_profit: 120, profit_margin: 28.57,
        },
      ],
    })),
    getSalesByCustomer: vi.fn(async () => ({
      customers: [
        {
          id: 1, name: 'Hospital 596', total_orders: 2, total_revenue: 300,
          average_order_value: 150, paid_quantity: 3, foc_quantity: 0, foc_list_value: 0,
          last_purchase_date: '2026-10-02',
        },
        {
          id: null, name: 'Walk-in Customer', total_orders: 1, total_revenue: 120,
          average_order_value: 120, paid_quantity: 1, foc_quantity: 0, foc_list_value: 0,
          last_purchase_date: '2026-10-03',
        },
      ],
    })),
    getSalesByCategory: vi.fn(async () => ({
      categories: [
        {
          category_id: 4, category_name: 'Implants', product_count: 1, quantity_sold: 3,
          revenue: 420, profit: 120, profit_margin: 28.57, revenue_percentage: 100,
        },
      ],
    })),
    getSalesTrends: vi.fn(async () => ({
      trends: [{ period: '2026-10-02', order_count: 2, revenue: 420, average_order_value: 210 }],
    })),
    getSalesByCurrency: vi.fn(async () => ({ currencies: [], summary: {} })),
    getSalesReconciliation: vi.fn(async () => ({
      period: { start_date: '2026-10-01', end_date: '2026-10-31' },
      base_currency: 'MYR', report_net_revenue: 0, gl_revenue: 0, difference: 0, reconciled: true,
      report_breakdown: { invoice: 0, pos: 0, credit_note: 0, booking: 0 }, causes: [], notes: [],
    })),
    getSalesDrilldown: vi.fn(async () => ({
      dimension: 'customer',
      key: '1',
      documents: [
        {
          document_type: 'invoice', document_id: 42, document_number: 'INV-596-1',
          document_date: '2026-10-02', customer_id: 1, customer_name: 'Hospital 596',
          currency_code: 'MYR', document_amount: 350, net_amount: 350, channel: 'invoice',
        },
        {
          document_type: 'credit_note', document_id: 9, document_number: 'CN-596-1',
          document_date: '2026-10-03', customer_id: 1, customer_name: 'Hospital 596',
          currency_code: 'MYR', document_amount: -50, net_amount: -50, channel: 'credit_note',
        },
      ],
      summary: { document_count: 2, total_net_amount: 300, base_currency: 'MYR' },
    })),
  },
  formatLocalDate: (date: Date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  },
}));

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date(2026, 9, 15, 10, 0, 0));
  (reportApi.getSalesByCustomer as any).mockClear();
});

function renderPage() {
  // gcTime 0: a filter set seen earlier is refetched, so every change shows
  // up as an API call the assertions can read.
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/reports/sales-analytics?tab=by-customer']}>
        <SalesAnalyticsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const lastCustomerFilters = () => {
  const calls = (reportApi.getSalesByCustomer as any).mock.calls;
  return calls[calls.length - 1][0];
};

async function pickFromSelect(label: string, option: string) {
  fireEvent.click(screen.getByRole('combobox', { name: label }));
  fireEvent.click(await screen.findByRole('option', { name: option }));
}

/**
 * Issue #597 (RPT-UX2): one compact filter bar — shared customer/product
 * search pickers, chips for active filters, preset periods and "Clear all".
 */
describe('SalesAnalyticsPage compact filter bar (#597)', () => {
  it('offers the preset periods and applies them to the report query', async () => {
    renderPage();
    await waitFor(() => expect(reportApi.getSalesByCustomer).toHaveBeenCalled());

    const presets = screen.getByRole('group', { name: 'Preset periods' });
    for (const name of ['This month', 'Last month', 'QTD', 'YTD', 'Last 12 months']) {
      expect(within(presets).getByRole('button', { name })).toBeTruthy();
    }
    expect(within(presets).getByRole('button', { name: 'This month' }).getAttribute('aria-pressed')).toBe('true');

    fireEvent.click(within(presets).getByRole('button', { name: 'Last month' }));
    await waitFor(() => {
      expect(lastCustomerFilters().start_date).toBe('2026-09-01');
      expect(lastCustomerFilters().end_date).toBe('2026-09-30');
    });
    expect(within(presets).getByRole('button', { name: 'Last month' }).getAttribute('aria-pressed')).toBe('true');

    fireEvent.click(within(presets).getByRole('button', { name: 'YTD' }));
    await waitFor(() => expect(lastCustomerFilters().start_date).toBe('2026-01-01'));
  });

  it('uses the shared customer and product search pickers', async () => {
    renderPage();
    const bar = screen.getByRole('search', { name: 'Sales report filters' });
    expect(within(bar).getByText('Search customers...')).toBeTruthy();
    expect(within(bar).getByText(/Search name, SKU/)).toBeTruthy();
  });

  it('shows a removable chip per active filter and a Clear all', async () => {
    renderPage();
    await waitFor(() => expect(reportApi.getSalesByCustomer).toHaveBeenCalled());
    expect(screen.queryByRole('button', { name: 'Clear all' })).toBeNull();

    await pickFromSelect('Category', 'Implants');
    await pickFromSelect('Salesperson', 'Salesperson X');
    await pickFromSelect('Channel', 'POS');

    const chips = await screen.findByRole('list', { name: 'Active filters' });
    await within(chips).findByText('Category: Implants');
    expect(within(chips).getByText('Salesperson: Salesperson X')).toBeTruthy();
    expect(within(chips).getByText('Channel: POS')).toBeTruthy();
    await waitFor(() => {
      expect(lastCustomerFilters().category_id).toBe(4);
      expect(lastCustomerFilters().salesperson_id).toBe(3);
      expect(lastCustomerFilters().channel).toBe('pos');
    });

    // Removing one chip clears only that filter.
    fireEvent.click(within(chips).getByRole('button', { name: 'Remove Channel: POS' }));
    await waitFor(() => expect(lastCustomerFilters().channel).toBeUndefined());
    expect(lastCustomerFilters().category_id).toBe(4);

    // Clear all drops every filter and goes back to This month.
    fireEvent.click(within(screen.getByRole('search', { name: 'Sales report filters' })).getByRole('button', { name: 'Clear all' }));
    await waitFor(() => {
      expect(lastCustomerFilters().category_id).toBeUndefined();
      expect(lastCustomerFilters().salesperson_id).toBeUndefined();
    });
    expect(lastCustomerFilters().start_date).toBe('2026-10-01');
    expect(screen.queryByRole('list', { name: 'Active filters' })).toBeNull();
  });
});
