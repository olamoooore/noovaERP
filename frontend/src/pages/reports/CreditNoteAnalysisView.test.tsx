// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { CreditNoteAnalysisView } from './CreditNoteAnalysisView';
import reportApi from '@/lib/reportApi';

const payload = (groupBy: string) => ({
  title: 'Credit Note Analysis',
  group_by: groupBy,
  rows:
    groupBy === 'customer'
      ? [
          {
            key: 7,
            label: '532 Hospital',
            credit_note_count: 2,
            credited_amount: 130,
            invoiced_amount: 500,
            credit_rate: 26,
            outcome: { applied: 2, partially_applied: 0, customer_credit: 0 },
          },
        ]
      : [
          {
            key: 'Damaged goods',
            label: 'Damaged goods',
            credit_note_count: 2,
            credited_amount: 130,
            invoiced_amount: 900,
            credit_rate: 14.44,
            outcome: { applied: 1, partially_applied: 0, customer_credit: 1 },
          },
        ],
  summary: {
    credit_note_count: 2,
    total_credited: 130,
    total_invoiced: 900,
    net_invoiced: 770,
    credit_rate: 14.44,
    outcome: {
      applied: 1,
      partially_applied: 0,
      customer_credit: 1,
      applied_amount: 50,
      refunded_amount: 0,
      remaining_credit: 80,
    },
    base_currency: 'MYR',
  },
});

vi.mock('@/lib/reportApi', () => ({
  default: {
    getCreditNoteAnalysis: vi.fn(async (_filters: unknown, groupBy: string) => payload(groupBy)),
  },
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function renderView(onDrilldown = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <CreditNoteAnalysisView
        filters={{ start_date: '2026-10-01', end_date: '2026-10-31' }}
        periodLabel="1 Oct 2026 → 31 Oct 2026"
        exportRange="2026-10-01_2026-10-31"
        onDrilldown={onDrilldown}
      />
    </QueryClientProvider>,
  );
  return onDrilldown;
}

describe('CreditNoteAnalysisView (#532)', () => {
  it('shows credited, invoiced and the credit rate per reason by default', async () => {
    renderView();

    await screen.findByText('Damaged goods');
    expect(reportApi.getCreditNoteAnalysis).toHaveBeenCalledWith(
      { start_date: '2026-10-01', end_date: '2026-10-31' },
      'reason',
    );
    expect(screen.getAllByText('14.44%').length).toBeGreaterThan(0);
  });

  it('regroups by customer', async () => {
    renderView();
    await screen.findByText('Damaged goods');

    fireEvent.click(screen.getByRole('button', { name: 'Customer' }));

    await screen.findByText('532 Hospital');
    await waitFor(() =>
      expect(reportApi.getCreditNoteAnalysis).toHaveBeenLastCalledWith(expect.anything(), 'customer'),
    );
    expect(screen.getAllByText('26.00%').length).toBeGreaterThan(0);
  });

  it('drills a row to its credit notes, matching the negative credited amount', async () => {
    const onDrilldown = renderView();

    fireEvent.click(await screen.findByRole('button', { name: 'Damaged goods' }));

    expect(onDrilldown).toHaveBeenCalledWith({
      dimension: 'reason',
      key: 'Damaged goods',
      document_type: 'credit_note',
      label: 'Credit notes · Damaged goods',
      rowValue: -130,
    });
  });
});
