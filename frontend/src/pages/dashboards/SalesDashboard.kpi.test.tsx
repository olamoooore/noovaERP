// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
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

const productPayload = {
  products: [{ name: 'Widget', total_revenue: 500, total_quantity: 5 }],
}

vi.mock('@/lib/reportApi', () => ({
  default: {
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
    getSalesByProduct: vi.fn(async () => productPayload),
    getSalesByCustomer: vi.fn(async () => ({ customers: [] })),
    getSalesByCategory: vi.fn(async () => ({ categories: [] })),
    getSalesTrends: vi.fn(async () => ({ trends: [] })),
  },
  formatLocalDate: (date: Date) => {
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')
    return `${year}-${month}-${day}`
  },
}))

afterEach(cleanup)

describe('SalesDashboard KPI tiles (#351)', () => {
  it('shows the metrics once the metrics query resolves, though the dashboard query captured it as undefined', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <QueryClientProvider client={client}>
        <SalesDashboard />
      </QueryClientProvider>,
    )

    const label = await screen.findByText("Today's sales")
    const card = label.closest('.rounded-xl') as HTMLElement | null

    expect(card).toBeTruthy()

    // The bug cached `allMetrics?.today ?? 0` (0, because the parallel metrics
    // query had not resolved) and never recomputed it: the tile stayed at 0.00.
    await waitFor(() => expect(card!.textContent).toContain('500.00'), { timeout: 3000 })
  })
})
