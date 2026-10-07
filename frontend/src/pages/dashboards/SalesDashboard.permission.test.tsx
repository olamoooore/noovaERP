// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { useAuthStore } from '@/store/authStore';
import { SalesDashboard } from './SalesDashboard';

/**
 * #356 — decision 2026-10-07 ("Revert, keep blocked"): sales analytics stay
 * behind reports.view, which the Sales role lacks. The Sales landing page
 * shows a clear permission message instead of "Error loading dashboard", and
 * the operational action queues (sales.view) keep rendering.
 */

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

vi.mock('@/components/charts/LineChart', () => ({ LineChart: () => null }));
vi.mock('@/components/charts/BarChart', () => ({ BarChart: () => null }));
vi.mock('@/components/charts/PieChart', () => ({ PieChart: () => null }));

const forbidden = () =>
  Object.assign(new Error('Request failed with status code 403'), {
    response: { status: 403, data: { required_permission: 'reports.view' } },
  });

const reportApiMock = vi.hoisted(() => ({
  getSalesSummary: vi.fn(),
  getSalesByProduct: vi.fn(),
  getSalesByCustomer: vi.fn(),
  getSalesByCategory: vi.fn(),
  getSalesTrends: vi.fn(),
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

const reportViewsApiMock = vi.hoisted(() => ({
  dashboardWidgets: vi.fn(),
  widgetData: vi.fn(),
  removeWidget: vi.fn(),
  reorderWidgets: vi.fn(),
}));
vi.mock('@/lib/reportViewsApi', () => ({ default: reportViewsApiMock }));

const queuesMock = vi.hoisted(() => ({ fetchSalesActionQueues: vi.fn() }));
vi.mock('@/lib/salesActionQueues', () => queuesMock);

beforeEach(() => {
  for (const fn of Object.values(reportApiMock)) {
    fn.mockReset().mockRejectedValue(forbidden());
  }
  reportViewsApiMock.dashboardWidgets.mockRejectedValue(forbidden());
  queuesMock.fetchSalesActionQueues.mockResolvedValue({
    mine: false,
    expiring_window_days: 7,
    queues: [
      {
        key: 'orders_to_deliver',
        label: 'Orders to deliver',
        document: 'sales_order',
        count: 2,
        link: '/sales/orders?action_queue=orders_to_deliver',
        items: [],
      },
    ],
  });
});

afterEach(() => {
  cleanup();
  useAuthStore.setState({ role: null, permissions: [] });
});

function renderDashboard() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <SalesDashboard />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Sales landing without reports.view (#356)', () => {
  it('shows the permission message, not the error card, when analytics answer 403', async () => {
    renderDashboard();

    expect(
      await screen.findByText('Sales analytics need the Reports permission — ask an administrator'),
    ).toBeTruthy();
    expect(screen.queryByText('Error loading dashboard')).toBeNull();
    // The operational cockpit still loads.
    expect(await screen.findByText('Orders to deliver')).toBeTruthy();
  });

  it('does not call the analytics endpoints when the role is known to lack reports.view', async () => {
    useAuthStore.setState({ role: 'Sales', permissions: ['sales.view', 'dashboard.view'] });

    renderDashboard();

    expect(
      await screen.findByText('Sales analytics need the Reports permission — ask an administrator'),
    ).toBeTruthy();
    expect(await screen.findByText('Orders to deliver')).toBeTruthy();
    await waitFor(() => expect(queuesMock.fetchSalesActionQueues).toHaveBeenCalled());
    expect(reportApiMock.getSalesSummary).not.toHaveBeenCalled();
    expect(reportApiMock.getSalesTrends).not.toHaveBeenCalled();
    expect(reportApiMock.getSalesByProduct).not.toHaveBeenCalled();
    expect(screen.queryByText('Error loading dashboard')).toBeNull();
  });
});
