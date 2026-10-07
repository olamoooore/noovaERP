import { describe, it, expect } from 'vitest'
import { buildSalesRecordsDisplayItems, buildSalesRecordsExportRows } from './salesRecordsView'
import type { SalesRecordsData, SalesRecordsMonthTotal, SalesRecordsRow } from '@/types/reports'

const row = (overrides: Partial<SalesRecordsRow>): SalesRecordsRow => ({
  invoice_id: 1,
  customer_name: 'Dr Beh Suan Tiong',
  date: '2026-05-06',
  month: '2026-05',
  document_number: '2026/05/020',
  lot_number: '2601022',
  bonus_scheme: '5 + 1',
  due_date: '2026-06-05',
  status: '30 Jun 2026, Completed',
  values: {
    F240115150: { quantity: 6, value: 5000 },
    F02306: { quantity: 0, value: 0 },
  },
  total: 5000,
  ...overrides,
})

const monthTotal = (overrides: Partial<SalesRecordsMonthTotal>): SalesRecordsMonthTotal => ({
  month: '2026-05',
  label: 'May 2026',
  values: {
    F240115150: { quantity: 6, value: 5000 },
    F02306: { quantity: 2, value: 700 },
  },
  total: 5700,
  ...overrides,
})

const data: SalesRecordsData = {
  title: 'Sales Records',
  period: { start_date: '2026-01-01', end_date: '2026-12-31' },
  group_by: 'item_code',
  columns: [
    { key: 'F02306', label: 'Disposable Retrieval Bag', code: 'F02306' },
    { key: 'F240115150', label: 'Front Morcellator', code: 'F240115150' },
  ],
  rows: [
    row({}),
    row({ invoice_id: 2, date: '2026-06-29', month: '2026-06', document_number: '2026/06/154' }),
  ],
  month_totals: [
    monthTotal({}),
    monthTotal({
      month: '2026-06',
      label: 'June 2026',
      values: {
        F240115150: { quantity: 6, value: 5000 },
        F02306: { quantity: 0, value: 0 },
      },
      total: 5000,
    }),
  ],
  summary: { total_quantity: 8, total_value: 5700, invoice_count: 2 },
  generated_at: '2026-10-07T00:00:00Z',
}

describe('buildSalesRecordsDisplayItems (#1020)', () => {
  it('inserts each month total after its rows', () => {
    const items = buildSalesRecordsDisplayItems(data.rows, data.month_totals)
    expect(items.map((item) => (item.kind === 'row' ? item.row.month : `${item.total.month} TOTAL`))).toEqual([
      '2026-05',
      '2026-05 TOTAL',
      '2026-06',
      '2026-06 TOTAL',
    ])
  })

  it('handles an empty report', () => {
    expect(buildSalesRecordsDisplayItems([], [])).toEqual([])
  })
})

describe('buildSalesRecordsExportRows (#1020)', () => {
  it('emits the sheet header with a Qty/S$ pair per item-code column', () => {
    const [header] = buildSalesRecordsExportRows(data)
    expect(header).toEqual([
      'Customer Name',
      'Date of Invoice/DO',
      'Invoice/DO Number',
      'Lot Number',
      'Disposable Retrieval Bag Qty',
      'Disposable Retrieval Bag S$',
      'Front Morcellator Qty',
      'Front Morcellator S$',
      'Total S$',
      'Bonusing Scheme',
      'Due Date',
      'Status',
    ])
  })

  it('exports rows and month totals with the sheet values', () => {
    const rows = buildSalesRecordsExportRows(data)
    // header + 2 data rows + 2 monthly totals
    expect(rows).toHaveLength(5)
    const firstData = rows[1]
    expect(firstData[0]).toBe('Dr Beh Suan Tiong')
    expect(firstData[6]).toBe('6') // Morcellator qty (paid 5 + FOC 1)
    expect(Number(firstData[7].replace(/,/g, ''))).toBe(5000) // net of tax
    expect(firstData[9]).toBe('5 + 1')
    const mayTotal = rows[2]
    expect(mayTotal[0]).toBe('May 2026 Total')
    expect(Number(mayTotal[8].replace(/,/g, ''))).toBe(5700)
  })

  it('returns nothing without data', () => {
    expect(buildSalesRecordsExportRows(undefined)).toEqual([])
  })
})
