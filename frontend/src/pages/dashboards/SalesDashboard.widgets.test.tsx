// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SalesDashboard } from './SalesDashboard';

// jsdom polyfills Radix needs at render time (same as the smoke suites)
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

vi.mock('@/components/charts/LineChart', () => ({ LineChart: () => null }));
vi.mock('@/components/charts/BarChart', () => ({ BarChart: () => null }));
vi.mock('@/components/charts/PieChart', () => ({ PieChart: () => null }));

const reportApiMock = vi.hoisted(() => ({
  getDateRangePresets: () => ({
    today: { start_date: '2026-10-14', end_date: '2026-10-14' },
    thisWeek: { start_date: '2026-10-11', end_date: '2026-10-14' },
    thisMonth: { start_date: '2026-10-01', end_date: '2026-10-31' },
    thisYear: { start_date: '2026-01-01', end_date: '2026-10-14' },
  }),
  // #361: the KPI tiles read the summary endpoint (period, comparison, today).
  getSalesSummary: vi.fn(async () => ({
    period: { start_date: '2026-10-01', end_date: '2026-10-31' },
    summary: { total_revenue: 500, total_quantity: 5 },
    today: { total_revenue: 500, total_quantity: 5, date: '2026-10-14' },
  })),
  getSalesByProduct: vi.fn(async () => ({
    products: [{ name: 'Widget', total_revenue: 500, total_quantity: 5 }],
  })),
  getSalesByCustomer: vi.fn(async () => ({ customers: [] })),
  getSalesByCategory: vi.fn(async () => ({ categories: [] })),
  getSalesTrends: vi.fn(async () => ({ trends: [] })),
}));

vi.mock('@/lib/reportApi', () => ({
  default: reportApiMock,
  formatLocalDate: (date: Date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  },
}));

const widgetView = {
  id: 1,
  user_id: 7,
  name: 'Cockpit customers',
  report_key: 'sales-analytics',
  tab: 'by-customer' as const,
  filters: { start_date: '2026-10-01', end_date: '2026-10-05' },
  grouping: null,
  visibility: 'personal' as const,
  is_default: false,
  is_favourite: true,
  is_pinned: true,
  is_owner: true,
};

const reportViewsApiMock = vi.hoisted(() => ({
  dashboardWidgets: vi.fn(),
  widgetData: vi.fn(),
  removeWidget: vi.fn(),
  reorderWidgets: vi.fn(),
}));

vi.mock('@/lib/reportViewsApi', () => ({ default: reportViewsApiMock }));

beforeEach(() => {
  reportViewsApiMock.dashboardWidgets.mockResolvedValue([
    { id: 11, view_id: 1, position: 0, view: widgetView },
  ]);
  reportViewsApiMock.widgetData.mockResolvedValue({
    widget_id: 11,
    view: widgetView,
    title: 'Sales by Customer',
    tab: 'by-customer',
    period: { start_date: '2026-10-01', end_date: '2026-10-05' },
    columns: ['Customer', 'Orders'],
    rows: [{ Customer: 'Cockpit Customer', Orders: 1 }],
    row_count: 1,
    generated_at: '2026-10-05T09:00:00Z',
  });
});

afterEach(cleanup);

describe('Sales cockpit saved-view widgets (#603)', () => {
  it('renders the pinned saved view as a widget with its data', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={client}>
        <SalesDashboard />
      </QueryClientProvider>,
    );

    expect(await screen.findByText('Cockpit customers')).toBeTruthy();
    await waitFor(() => expect(screen.getByText('Cockpit Customer')).toBeTruthy());
  });
});
