// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ProductUsageView } from './ProductUsageView';

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }));

vi.mock('@/lib/api', () => ({
  default: { get: apiGet, post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

const base = {
  legal_entity_id: 1,
  legal_entity_name: 'Selling Co A',
  hospital_group_id: 10,
  hospital_group_name: 'Chain 608',
  hospital_id: 11,
  hospital_name: 'Hospital 608',
  product_id: 3,
  product_name: 'Stent 608',
  sku: 'ST-608',
  category_id: 5,
  category_name: 'Stents',
};

const payload = {
  period: { from: '2026-09-01', to: '2026-09-30', date_field: 'procedure_date' },
  by_hospital: [],
  hospital_totals: [
    { customer_id: 11, customer_name: 'Hospital 608', case_count: 2, invoiced_value: 604, bonus_case_count: 0 },
  ],
  by_doctor: [],
  product_mix: [{ product_id: 3, product_name: 'Stent 608', sku: 'ST-608', quantity: 9 }],
  usage: {
    rows: [
      {
        ...base,
        doctor_contact_id: 31,
        doctor_name: 'Dr. Usage',
        used_units: 6,
        paid_units: 4,
        foc_units: 2,
        net_value: 304,
        case_count: 1,
        case_ids: [101],
      },
      {
        ...base,
        doctor_contact_id: null,
        doctor_name: 'Unassigned doctor',
        used_units: 3,
        paid_units: 3,
        foc_units: 0,
        net_value: 300,
        case_count: 1,
        case_ids: [102],
      },
    ],
    cases: [
      {
        id: 101, case_no: 'CASE-2026-0101', status: 'invoiced', case_date: '2026-08-25', procedure_date: '2026-09-10',
        hospital_id: 11, hospital_name: 'Hospital 608', doctor_contact_id: 31, doctor_name: 'Dr. Usage',
        patient_name: 'J********t', patient_reference: 'M*********1',
      },
      {
        id: 102, case_no: 'CASE-2026-0102', status: 'invoiced', case_date: '2026-08-25', procedure_date: '2026-09-11',
        hospital_id: 11, hospital_name: 'Hospital 608', doctor_contact_id: null, doctor_name: 'Unassigned doctor',
        patient_name: 'J******e', patient_reference: null,
      },
    ],
    phi_masked: true,
  },
};

afterEach(() => {
  cleanup();
  apiGet.mockReset();
});

function renderView() {
  apiGet.mockResolvedValue({ data: { data: payload } });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ProductUsageView
        filters={{ start_date: '2026-09-01', end_date: '2026-09-30' }}
        periodLabel="1 Sep 2026 → 30 Sep 2026"
      />
    </QueryClientProvider>,
  );
}

function rowTexts(pivot: HTMLElement) {
  return within(pivot)
    .getAllByTestId('usage-row')
    .map((row) => within(row).getAllByRole('cell').map((cell) => cell.textContent?.trim()));
}

describe('ProductUsageView pivot (#608)', () => {
  it('nests legal entity → hospital group → hospital → doctor × product with subtotals and a grand total', async () => {
    renderView();
    const pivot = await screen.findByRole('region', { name: 'Usage pivot' });

    const rows = rowTexts(pivot);
    // Entity, group and hospital subtotals carry both doctors: 9 used, 7 paid, 2 FOC, 2 cases.
    expect(rows[0]).toEqual(['Selling Co A', '9', '7', '2', '2', expect.stringContaining('604')]);
    expect(rows[1][0]).toBe('Chain 608');
    expect(rows[2][0]).toBe('Hospital 608');
    const labels = rows.map((row) => row[0]);
    expect(labels).toContain('Dr. Usage');
    expect(labels).toContain('Unassigned doctor');

    const total = within(pivot).getByTestId('usage-grand-total');
    expect(within(total).getAllByRole('cell').map((cell) => cell.textContent?.trim()).slice(0, 5)).toEqual([
      'Grand total', '9', '7', '2', '2',
    ]);
  });

  it('each grouping works on its own', async () => {
    renderView();
    const pivot = await screen.findByRole('region', { name: 'Usage pivot' });

    for (const level of ['Legal entity', 'Hospital group', 'Hospital']) {
      fireEvent.click(within(pivot).getByRole('button', { name: level }));
    }

    const rows = rowTexts(pivot);
    // Doctor only, × product.
    expect(rows.map((row) => row[0])).toEqual(['Dr. Usage', 'Stent 608', 'Unassigned doctor', 'Stent 608']);
    expect(rows[0].slice(1, 5)).toEqual(['6', '4', '2', '1']);
  });

  it('drills down from a cell to its cases with masked patient names', async () => {
    renderView();
    const pivot = await screen.findByRole('region', { name: 'Usage pivot' });

    fireEvent.click(within(pivot).getByRole('button', { name: 'Show 1 cases for Unassigned doctor' }));

    const drill = screen.getByRole('region', { name: 'Usage cases' });
    expect(within(drill).getByText('CASE-2026-0102')).toBeTruthy();
    expect(within(drill).queryByText('CASE-2026-0101')).toBeNull();
    expect(within(drill).getByText('J******e')).toBeTruthy();
    expect(within(drill).getByText(/PHI permission/i)).toBeTruthy();
  });

  it('filters the pivot by doctor', async () => {
    renderView();
    const pivot = await screen.findByRole('region', { name: 'Usage pivot' });

    fireEvent.change(within(pivot).getByLabelText('Filter by doctor'), { target: { value: '31' } });

    const total = within(pivot).getByTestId('usage-grand-total');
    expect(within(total).getAllByRole('cell')[1].textContent).toBe('6');
    expect(rowTexts(pivot).map((row) => row[0])).not.toContain('Unassigned doctor');
  });
});
