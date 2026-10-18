// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { UnbilledDeliveriesView } from './UnbilledDeliveriesView';
import reportApi from '@/lib/reportApi';

vi.mock('@/hooks/use-tags', () => ({
  useTags: () => ({ data: [] }),
  default: () => ({ data: [] }),
}));

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
    {
      delivery_order_id: 42,
      delivery_order_number: 'DO-602-0002',
      delivery_order_line_id: 9002,
      delivery_date: '2026-08-07',
      sales_order_id: 12,
      customer_id: 7,
      customer_name: '602 Customer',
      product_id: 3,
      product_name: '602 Widget',
      sku: '602-A',
      quantity_ordered: 8,
      quantity_delivered: 5,
      quantity_invoiced: 0,
      quantity_to_invoice: 5,
      unit_price: 100,
      amount_to_invoice: 500,
      currency_code: 'USD',
    },
  ],
  summary: {
    line_count: 2,
    delivery_order_count: 2,
    total_quantity_delivered: 11,
    total_quantity_invoiced: 4,
    total_quantity_to_invoice: 7,
    total_amount_to_invoice: 700,
    base_currency: 'USD',
  },
  period: { start_date: '2026-08-01', end_date: '2026-09-30' },
};

vi.mock('@/lib/reportApi', () => ({
  default: {
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

function renderView() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <UnbilledDeliveriesView
        filters={{ start_date: '2026-08-01', end_date: '2026-09-30' }}
        periodLabel="1 Aug 2026 → 30 Sep 2026"
        exportRange="2026-08-01_2026-09-30"
      />
    </QueryClientProvider>,
  );
}

describe('UnbilledDeliveriesView (#602)', () => {
  it('lists only unbilled DO lines with their to-invoice quantities and amounts', async () => {
    renderView();

    await screen.findByText('DO-602-0001');
    await screen.findByText('DO-602-0002');

    const rows = screen.getAllByRole('row');
    // Header + 2 delivery rows + totals footer.
    expect(rows).toHaveLength(4);

    // 6 delivered, 4 invoiced, 2 to invoice on the first line.
    expect(screen.getByText('DO-602-0001')).toBeTruthy();
    expect(screen.getByText('DO-602-0002')).toBeTruthy();

    // Amount totals reach the summary bar and the footer.
    expect(screen.getAllByText(/700/).length).toBeGreaterThan(0);
  });

  it('shows the empty state when nothing is awaiting an invoice', async () => {
    (reportApi.getUnbilledDeliveries as any).mockResolvedValueOnce({
      ...unbilledPayload,
      deliveries: [],
      summary: { ...unbilledPayload.summary, line_count: 0, total_quantity_to_invoice: 0 },
    });

    renderView();

    await screen.findByText(/Everything delivered has been invoiced/i);
  });
});
