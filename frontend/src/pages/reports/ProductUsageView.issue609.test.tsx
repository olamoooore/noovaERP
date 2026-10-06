// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ProductUsageView } from './ProductUsageView';

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }));

vi.mock('@/lib/api', () => ({
  default: { get: apiGet, post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

const performancePayload = {
  period: { from: '2026-03-01', to: '2026-03-31', date_field: 'procedure_date' },
  by_hospital: [],
  hospital_totals: [
    { customer_id: 7, customer_name: 'Hospital 609', case_count: 2, invoiced_value: 400, bonus_case_count: 1 },
  ],
  by_doctor: [
    // The same doctor contact row in two months rolls up to one line.
    {
      doctor_contact_id: 31, doctor_name: 'Dr. Alpha', customer_id: 7, customer_name: 'Hospital 609',
      month: '2026-02', case_count: 1, invoiced_value: 150, bonus_case_count: 0,
    },
    {
      doctor_contact_id: 31, doctor_name: 'Dr. Alpha', customer_id: 7, customer_name: 'Hospital 609',
      month: '2026-03', case_count: 1, invoiced_value: 250, bonus_case_count: 1,
    },
  ],
  product_mix: [{ product_id: 3, product_name: 'Stent 609', sku: 'ST-609', quantity: 4 }],
};

afterEach(() => {
  cleanup();
  apiGet.mockReset();
});

function renderView() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ProductUsageView
        filters={{ start_date: '2026-03-01', end_date: '2026-03-31' }}
        periodLabel="1 Mar 2026 → 31 Mar 2026"
      />
    </QueryClientProvider>,
  );
}

describe('ProductUsageView (#609)', () => {
  it('calls the hospital/doctor performance endpoint for the period and shows units used', async () => {
    apiGet.mockResolvedValue({ data: { data: performancePayload } });

    renderView();

    await screen.findByText('Stent 609');

    expect(apiGet).toHaveBeenCalledWith('/medical-cases/reports/performance', {
      params: { from_date: '2026-03-01', to_date: '2026-03-31', date_field: 'procedure_date' },
    });

    const products = screen.getByRole('region', { name: 'Products used' });
    expect(within(products).getByText('4')).toBeTruthy();

    const doctors = screen.getByRole('region', { name: 'By doctor' });
    // Header + one rolled-up doctor row.
    expect(within(doctors).getAllByRole('row')).toHaveLength(2);
    expect(within(doctors).getByText('Dr. Alpha')).toBeTruthy();
    expect(within(doctors).getByText('2')).toBeTruthy();

    const hospitals = screen.getByRole('region', { name: 'By hospital' });
    expect(within(hospitals).getByText('Hospital 609')).toBeTruthy();
  });

  it('explains a missing medical cases permission instead of a raw error', async () => {
    apiGet.mockRejectedValue(Object.assign(new Error('Forbidden'), { response: { status: 403, data: {} } }));

    renderView();

    await screen.findByText(/medical cases view permission/i);
  });
});
