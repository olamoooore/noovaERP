// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { SalesAnalyticsPage } from './SalesAnalyticsPage';
import reportApi from '@/lib/reportApi';

// jsdom polyfills Radix needs at render time (same as the other page suites).
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

const pivotPayload = {
  title: 'Sales Analysis',
  row_dims: ['product'],
  col_dims: ['month'],
  measures: ['qty_ordered', 'qty_delivered', 'qty_invoiced', 'qty_to_invoice'],
  columns: [{ key: ['2026-08'], label: 'Aug 2026' }],
  rows: [
    {
      key: ['602 Widget'],
      label: '602 Widget',
      depth: 0,
      values: [
        {
          qty_ordered: 10,
          qty_delivered: 6,
          qty_invoiced: 4,
          qty_to_invoice: 2,
        },
      ],
      totals: { qty_ordered: 10, qty_delivered: 6, qty_invoiced: 4, qty_to_invoice: 2 },
      children: [],
    },
  ],
  totals: { qty_ordered: 10, qty_delivered: 6, qty_invoiced: 4, qty_to_invoice: 2 },
  summary: { row_count: 1, column_count: 1, base_currency: 'USD' },
};

const unbilledPayload = {
  title: 'Unbilled Deliveries',
  deliveries: [
    {
      delivery_order_id: 41,
      delivery_order_number: 'DO-602-0001',
      delivery_order_line_id: 9001,
      delivery_date: '2026-08-06',
      sales_order_id: 11,
      customer_id: 7,
      customer_name: '602 Customer',
      product_id: 3,
      product_name: '602 Widget',
      sku: '602-A',
      quantity_ordered: 10,
      quantity_delivered: 6,
      quantity_invoiced: 4,
      quantity_to_invoice: 2,
      unit_price: 100,
      amount_to_invoice: 200,
      currency_code: 'USD',
    },
  ],
  summary: {
    line_count: 1,
    delivery_order_count: 1,
    total_quantity_delivered: 6,
    total_quantity_invoiced: 4,
    total_quantity_to_invoice: 2,
    total_amount_to_invoice: 200,
    base_currency: 'USD',
  },
};

vi.mock('@/lib/reportApi', () => ({
  default: {
    getSalesByProduct: vi.fn(async () => ({ products: [] })),
    getSalesByCurrency: vi.fn(async () => ({ currencies: [], summary: {} })),
    getSalesByCategory: vi.fn(async () => ({ categories: [] })),
    getSalesTrends: vi.fn(async () => ({ trends: [] })),
    getSalesByCustomer: vi.fn(async () => ({ customers: [] })),
    getSalesPivot: vi.fn(async () => pivotPayload),
    getUnbilledDeliveries: vi.fn(async () => unbilledPayload),
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

describe('SalesAnalyticsPage lifecycle measures and unbilled tab (#602)', () => {
  it('offers the ordered/delivered/invoiced/to-invoice measures in the pivot picker', async () => {
    renderPage();

    fireEvent.mouseDown(screen.getByRole('tab', { name: /pivot/i }));

    // The #602 measures are present in the picker, unchecked by default.
    const qtyOrdered = await screen.findByLabelText('Qty Ordered');
    expect((qtyOrdered as HTMLInputElement).checked).toBe(false);
    expect(screen.getByLabelText('Qty to Invoice')).toBeTruthy();
    expect(screen.getByLabelText('Amount Delivered')).toBeTruthy();

    // Selecting one asks the API for it.
    fireEvent.click(qtyOrdered);

    await waitFor(() => {
      const calls = (reportApi.getSalesPivot as any).mock.calls;
      const last = calls[calls.length - 1];
      expect(last[1].measure).toContain('qty_ordered');
    });

    // The default selection still carries the #601 measures.
    const firstCall = (reportApi.getSalesPivot as any).mock.calls[0];
    expect(firstCall[1].measure).toEqual(['paid_qty', 'foc_qty', 'net_revenue', 'cost', 'margin']);
  });

  it('renders the Unbilled Deliveries tab from the new endpoint', async () => {
    renderPage();

    fireEvent.mouseDown(screen.getByRole('tab', { name: /unbilled deliveries/i }));

    await screen.findByText('DO-602-0001');
    expect(reportApi.getUnbilledDeliveries).toHaveBeenCalled();
    expect(screen.getByText('602 Widget')).toBeTruthy();
  });
});
