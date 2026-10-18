// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SavedViewWidgets } from './SavedViewWidgets';

const apiMocks = vi.hoisted(() => ({
  dashboardWidgets: vi.fn(),
  widgetData: vi.fn(),
  removeWidget: vi.fn(),
  reorderWidgets: vi.fn(),
}));

vi.mock('@/lib/reportViewsApi', () => ({ default: apiMocks }));

const viewA = {
  id: 1,
  user_id: 7,
  name: 'Cockpit customers',
  report_key: 'sales-analytics',
  tab: 'by-customer' as const,
  filters: { start_date: '2026-10-01', end_date: '2026-10-05' },
  grouping: null,
  visibility: 'personal' as const,
  is_default: false,
  is_favourite: false,
  is_pinned: true,
  is_owner: true,
};

const viewB = { ...viewA, id: 2, name: 'Cockpit trends', tab: 'trends' as const };

const widgetA = { id: 11, view_id: 1, position: 0, view: viewA };
const widgetB = { id: 22, view_id: 2, position: 1, view: viewB };

const dataA = {
  widget_id: 11,
  view: viewA,
  title: 'Sales by Customer',
  tab: 'by-customer' as const,
  period: { start_date: '2026-10-01', end_date: '2026-10-05' },
  columns: ['Customer', 'Orders'],
  rows: [{ Customer: 'Cockpit Customer', Orders: 1 }],
  row_count: 1,
  generated_at: '2026-10-05T09:00:00Z',
};

beforeEach(() => {
  apiMocks.dashboardWidgets.mockResolvedValue([widgetA]);
  apiMocks.widgetData.mockResolvedValue(dataA);
  apiMocks.removeWidget.mockResolvedValue(undefined);
  apiMocks.reorderWidgets.mockResolvedValue([widgetB, widgetA]);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function renderWidgets() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <SavedViewWidgets />
    </QueryClientProvider>,
  );
}

describe('SavedViewWidgets (#603)', () => {
  it("renders each pinned saved view's data on the Sales cockpit", async () => {
    renderWidgets();

    expect(await screen.findByText('Cockpit customers')).toBeTruthy();
    expect(await screen.findByText('Cockpit Customer')).toBeTruthy();
    expect(screen.getByText(/1 row/i)).toBeTruthy();
    expect(apiMocks.widgetData).toHaveBeenCalledWith(11);
  });

  it('renders an access message when the saved view can no longer be read', async () => {
    apiMocks.widgetData.mockRejectedValue(new Error('Forbidden'));

    renderWidgets();

    expect(await screen.findByText(/do not have access/i)).toBeTruthy();
  });

  it('unpins a widget through the API', async () => {
    renderWidgets();
    await screen.findByText('Cockpit customers');

    fireEvent.click(
      screen.getByRole('button', { name: /remove Cockpit customers from dashboard/i }),
    );

    await waitFor(() => expect(apiMocks.removeWidget).toHaveBeenCalledWith(11));
  });

  it('reorders widgets with the move controls', async () => {
    apiMocks.dashboardWidgets.mockResolvedValue([widgetA, widgetB]);

    renderWidgets();
    await screen.findByText('Cockpit customers');
    await screen.findByText('Cockpit trends');

    fireEvent.click(screen.getByRole('button', { name: /move Cockpit customers down/i }));

    await waitFor(() => expect(apiMocks.reorderWidgets).toHaveBeenCalledWith([22, 11]));
  });

  it('renders nothing when the user has no pinned views', async () => {
    apiMocks.dashboardWidgets.mockResolvedValue([]);

    renderWidgets();

    await waitFor(() =>
      expect(screen.queryByText(/saved view widgets/i)).toBeNull(),
    );
  });
});
