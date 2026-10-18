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
}
if (!Element.prototype.setPointerCapture) {
  Element.prototype.setPointerCapture = () => {};
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

const reconciledPayload = {
  period: { start_date: '2026-10-01', end_date: '2026-10-31' },
  base_currency: 'MYR',
  report_net_revenue: 1204330,
  gl_revenue: 1204330,
  difference: 0,
  reconciled: true,
  report_breakdown: { invoice: 1204330, pos: 0, credit_note: 0, booking: 0 },
  causes: [],
  notes: [],
};

const differingPayload = {
  ...reconciledPayload,
  report_net_revenue: 1204330,
  gl_revenue: 1180000,
  difference: 24330,
  reconciled: false,
  causes: [
    {
      code: 'unposted_invoice',
      label: 'Unposted invoice — in the report, not yet in the GL',
      direction: 'report_only',
      amount: 30000,
      document_count: 1,
      documents: [
        { type: 'invoice', id: 42, label: 'INV-2026-0042', url: '/invoices/42' },
      ],
    },
    {
      code: 'manual_journal',
      label: 'Manual journal — in the GL, not in the report',
      direction: 'gl_only',
      amount: -5670,
      document_count: 1,
      documents: [
        { type: 'journal_entry', id: 7, label: 'JE-2026-0007', url: '/accounting/core/journal-entries?entry_id=7' },
      ],
    },
  ],
  notes: [],
};

let reconciliationPayload: unknown = reconciledPayload;

vi.mock('@/lib/reportApi', () => ({
  default: {
    getSalesByProduct: vi.fn(async () => ({ products: [] })),
    getSalesByCurrency: vi.fn(async () => ({ currencies: [], summary: {} })),
    getSalesByCategory: vi.fn(async () => ({ categories: [] })),
    getSalesTrends: vi.fn(async () => ({ trends: [] })),
    getSalesByCustomer: vi.fn(async () => ({ customers: [] })),
    getSalesReconciliation: vi.fn(async () => reconciliationPayload),
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
  reconciliationPayload = reconciledPayload;
});

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

/**
 * Issue #604 (RPT-D1): the sales reports had no "ties to GL" footer — a
 * reader could not tell whether the report's net revenue matched the posted
 * ledger, and when it did not, nothing named the difference or the documents
 * behind it.
 *
 * The footer is one shared component rendered on every sales report surface
 * (the tabbed /reports/sales-analytics page and the product-only
 * /products/report view), fed by GET /analytics/sales/reconciliation with
 * the SAME filters the report itself sends.
 */
describe('Sales report ties-to-GL footer (#604)', () => {
  it('shows both totals with a tick when the report ties to the GL', async () => {
    renderPage();

    const footer = await screen.findByTestId('gl-reconciliation-footer');
    expect(footer.textContent).toContain('Net revenue');
    expect(footer.textContent).toContain(formatCurrency(1204330));
    expect(footer.textContent).toContain('GL revenue accounts');
    expect(footer.textContent).toContain('✓');
    expect(footer.textContent).not.toContain('Difference');
  });

  it('shows the difference and links the documents that explain it', async () => {
    reconciliationPayload = differingPayload;
    renderPage();

    const footer = await screen.findByTestId('gl-reconciliation-footer');
    expect(footer.textContent).toContain(formatCurrency(24330));

    // Every difference is listed by cause …
    expect(footer.textContent).toContain('Unposted invoice');
    expect(footer.textContent).toContain('Manual journal');
    expect(footer.textContent).toContain(formatCurrency(30000));
    expect(footer.textContent).toContain(formatCurrency(-5670));

    // … and each cause links to its documents.
    const invoiceLink = screen.getByText('INV-2026-0042');
    expect(invoiceLink.getAttribute('href')).toBe('/invoices/42');
    const journalLink = screen.getByText('JE-2026-0007');
    expect(journalLink.getAttribute('href')).toBe('/accounting/core/journal-entries?entry_id=7');
  });

  it('renders the footer on the product-only sales report too', async () => {
    renderPage(true);

    const footer = await screen.findByTestId('gl-reconciliation-footer');
    expect(footer.textContent).toContain('Net revenue');
    expect(footer.textContent).toContain('GL revenue accounts');
  });
});
