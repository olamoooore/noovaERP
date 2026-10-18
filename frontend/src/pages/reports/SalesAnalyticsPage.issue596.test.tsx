// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { SalesAnalyticsPage } from './SalesAnalyticsPage';
import reportApi from '@/lib/reportApi';
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

afterEach(cleanup);
beforeEach(() => {
  (reportApi.getSalesDrilldown as any).mockClear();
});

function renderAt(tab: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[`/reports/sales-analytics?tab=${tab}`]}>
        <SalesAnalyticsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const lastDrillCall = () => {
  const calls = (reportApi.getSalesDrilldown as any).mock.calls;
  return calls[calls.length - 1];
};

/**
 * Issue #596 (RPT-UX1): clicking a customer, product, category or period row
 * opens a side panel listing its source documents (number, date, net amount)
 * with links, and the panel total equals the row.
 */
describe('SalesAnalyticsPage drill-down (#596)', () => {
  it('opens a side panel of the customer row source documents with links and a total equal to the row', async () => {
    renderAt('by-customer');

    fireEvent.click(await screen.findByRole('button', { name: 'Show documents for Hospital 596' }));

    const panel = await screen.findByRole('dialog');
    await within(panel).findByText('INV-596-1');

    expect(lastDrillCall()[1]).toEqual({ dimension: 'customer', key: '1' });
    expect(lastDrillCall()[0].start_date).toBeTruthy();

    const invoiceLink = within(panel).getByRole('link', { name: 'INV-596-1' });
    expect(invoiceLink.getAttribute('href')).toBe('/invoices/42');
    const creditLink = within(panel).getByRole('link', { name: 'CN-596-1' });
    expect(creditLink.getAttribute('href')).toBe('/accounting/customers/credit-notes/9');

    expect(within(panel).getByText(formatCurrency(350))).toBeTruthy();
    expect(within(panel).getByText(formatCurrency(-50))).toBeTruthy();

    const total = within(panel).getByTestId('drilldown-total');
    expect(total.textContent).toContain(formatCurrency(300));
    // Panel total equals the row: no mismatch warning.
    expect(within(panel).queryByText(/does not match the row/i)).toBeNull();
  });

  it('drills the walk-in row with key "none"', async () => {
    renderAt('by-customer');

    fireEvent.click(await screen.findByRole('button', { name: 'Show documents for Walk-in Customer' }));
    await screen.findByRole('dialog');

    await waitFor(() => expect(lastDrillCall()[1]).toEqual({ dimension: 'customer', key: 'none' }));
  });

  it('drills product and category rows', async () => {
    renderAt('by-product');
    fireEvent.click(await screen.findByRole('button', { name: 'Show documents for Implant 596' }));
    await screen.findByRole('dialog');
    await waitFor(() => expect(lastDrillCall()[1]).toEqual({ dimension: 'product', key: '7' }));
    cleanup();

    renderAt('by-category');
    fireEvent.click(await screen.findByRole('button', { name: 'Show documents for Implants' }));
    await screen.findByRole('dialog');
    await waitFor(() => expect(lastDrillCall()[1]).toEqual({ dimension: 'category', key: '4' }));
  });

  it('drills a period row with its interval', async () => {
    renderAt('trends');
    fireEvent.click(await screen.findByRole('button', { name: /^Show documents for 02 Oct 2026/ }));
    await screen.findByRole('dialog');
    await waitFor(() =>
      expect(lastDrillCall()[1]).toEqual({ dimension: 'period', key: '2026-10-02', interval: 'day' }),
    );
  });
});
