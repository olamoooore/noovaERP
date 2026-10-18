// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest'
import { render, screen, cleanup, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import React from 'react'
import { SalesDashboard } from './SalesDashboard'

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
  })) as unknown as typeof window.matchMedia
}
const testGlobals = globalThis as unknown as {
  ResizeObserver?: new () => { observe(): void; unobserve(): void; disconnect(): void }
}
if (!testGlobals.ResizeObserver) {
  testGlobals.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
}

vi.mock('@/components/charts/LineChart', () => ({ LineChart: () => null }))
vi.mock('@/components/charts/BarChart', () => ({ BarChart: () => null }))
vi.mock('@/components/charts/PieChart', () => ({ PieChart: () => null }))
vi.mock('./SavedViewWidgets', () => ({ SavedViewWidgets: () => null }))
vi.mock('./SalesActionQueues', () => ({ SalesActionQueues: () => null }))

const totals = (revenue: number, units: number) => ({
  total_revenue: revenue,
  invoiced_revenue: revenue,
  bookings_revenue: 0,
  total_quantity: units,
  order_count: 1,
  average_order_value: revenue,
  base_currency: 'MYR',
  revenue_basis: 'net',
  includes_tax: false,
})

const reportApiMock = vi.hoisted(() => ({
  getSalesSummary: vi.fn(),
  // The product widget (Top-N) still reads by-product; its rows must never
  // feed the KPI tiles.
  getSalesByProduct: vi.fn(async () => ({
    products: [{ name: 'Widget', total_revenue: 7, total_quantity: 1 }],
  })),
  getSalesByCustomer: vi.fn(async () => ({ customers: [] })),
  getSalesByCategory: vi.fn(async () => ({ categories: [] })),
  getSalesTrends: vi.fn(async () => ({ trends: [] })),
}))

vi.mock('@/lib/reportApi', () => ({
  default: reportApiMock,
  formatLocalDate: (date: Date) => {
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')
    return `${year}-${month}-${day}`
  },
}))

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(2026, 9, 14, 10, 0, 0))
  reportApiMock.getSalesSummary.mockImplementation(async () => ({
    period: { start_date: '2026-10-01', end_date: '2026-10-14' },
    summary: totals(1000, 12),
    comparison: {
      mode: 'custom',
      period: { start_date: '2026-09-01', end_date: '2026-09-14' },
      summary: { total_revenue: { previous: 800, delta: 200, delta_percent: 25 } },
    },
    today: { ...totals(500, 3), date: '2026-10-14' },
  }))
})

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.clearAllMocks()
})

describe('SalesDashboard KPI tiles read the summary endpoint (#361)', () => {
  it('makes one summary call for period + comparison + today and no by-product call for KPIs', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={client}>
        <SalesDashboard />
      </QueryClientProvider>,
    )

    const selected = await screen.findByTestId('kpi-selected')
    await waitFor(() => expect(selected.textContent).toContain('1,000.00'), { timeout: 3000 })
    expect(selected.textContent).toContain('12 units sold')
    await waitFor(() =>
      expect((screen.getByTestId('kpi-previous')).textContent).toContain('800.00'),
    )
    expect(screen.getByTestId('kpi-change').textContent).toContain('+25.0%')
    await waitFor(() =>
      expect(screen.getByTestId('kpi-today').textContent).toContain('500.00'),
    )

    // Exactly one summary call: the period, its comparison window and today.
    expect(reportApiMock.getSalesSummary).toHaveBeenCalledTimes(1)
    expect(reportApiMock.getSalesSummary).toHaveBeenCalledWith({
      start_date: '2026-10-01',
      end_date: '2026-10-14',
      comparison: 'custom',
      compare_start_date: '2026-09-01',
      compare_end_date: '2026-09-14',
      include_today: true,
    })

    // by-product is only the Top-N widget, never a KPI window.
    await waitFor(() => expect(reportApiMock.getSalesByProduct).toHaveBeenCalled())
    for (const [filters] of reportApiMock.getSalesByProduct.mock.calls as unknown as Array<
      [{ top?: number }]
    >) {
      expect(filters.top).toBe(10)
    }
    expect(reportApiMock.getSalesByProduct).toHaveBeenCalledTimes(1)
  })
})
