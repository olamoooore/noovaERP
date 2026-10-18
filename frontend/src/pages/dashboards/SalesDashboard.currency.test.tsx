// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest'
import { render, screen, cleanup, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import React from 'react'
import { SalesDashboard } from './SalesDashboard'
import { formatCurrency } from '@/lib/utils'
import { useSettingsStore } from '@/store/settingsStore'

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

const chartProps: Record<string, Record<string, unknown>> = {}
vi.mock('@/components/charts/LineChart', () => ({
  LineChart: (props: Record<string, unknown>) => {
    chartProps.line = props
    return null
  },
}))
vi.mock('@/components/charts/BarChart', () => ({
  BarChart: (props: Record<string, unknown>) => {
    chartProps.bar = props
    return null
  },
}))
vi.mock('@/components/charts/PieChart', () => ({
  PieChart: (props: Record<string, unknown>) => {
    chartProps.pie = props
    return null
  },
}))

// The analytics endpoints report every amount in the tenant base currency and
// say which one in `summary.base_currency` (SalesAnalyticsService::baseSummary).
const summary = { base_currency: 'MYR', revenue_basis: 'net', includes_tax: false }

vi.mock('@/lib/reportApi', () => ({
  default: {
    // #361: the KPI tiles read the summary endpoint (period, comparison, today).
    getSalesSummary: vi.fn(async () => ({
      period: { start_date: '2026-10-01', end_date: '2026-10-31' },
      summary: { total_revenue: 12400, total_quantity: 5, ...summary },
      today: { total_revenue: 12400, total_quantity: 5, ...summary, date: '2026-10-14' },
    })),
    getSalesByProduct: vi.fn(async () => ({
      products: [{ name: 'Widget', total_revenue: 12400, total_quantity: 5 }],
      summary,
    })),
    getSalesByCustomer: vi.fn(async () => ({
      customers: [{ customer_name: 'Acme Sdn Bhd', total_revenue: 12400 }],
      summary,
    })),
    getSalesByCategory: vi.fn(async () => ({
      categories: [{ category_name: 'Gadgets', total_revenue: 12400 }],
      summary,
    })),
    getSalesTrends: vi.fn(async () => ({
      trends: [{ period: '2026-10-14', revenue: 12400 }],
      summary,
    })),
  },
  formatLocalDate: (date: Date) => {
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')
    return `${year}-${month}-${day}`
  },
}))

afterEach(cleanup)

const renderDashboard = () => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <SalesDashboard />
    </QueryClientProvider>,
  )
}

const tile = async (label: string) => {
  const el = await screen.findByText(label)
  const card = el.closest('.rounded-xl') as HTMLElement | null
  expect(card).toBeTruthy()
  return card!
}

// Formatted exactly as the shared @/lib/utils formatter renders the base currency.
const expected = formatCurrency(12400, 'MYR')

describe('SalesDashboard amounts carry the tenant base currency (#363)', () => {
  beforeEach(() => {
    // The display-settings currency differs from the analytics base currency:
    // the dashboard must label amounts with the currency they are measured in.
    useSettingsStore.setState({ currencyCode: 'SGD', currencyDisplayFormat: 'symbol' })
    for (const key of Object.keys(chartProps)) delete chartProps[key]
  })

  it('KPI tiles show the base-currency symbol, not a bare en-US number', async () => {
    renderDashboard()

    const today = await tile("Today's sales")
    await waitFor(() => expect(today.textContent).toContain(expected), { timeout: 3000 })

    const net = await tile('Net revenue')
    await waitFor(() => expect(net.textContent).toContain(expected), { timeout: 3000 })
    expect(net.textContent).not.toContain('S$')
  })

  it('top customers list amounts in the base currency', async () => {
    renderDashboard()

    const name = await screen.findByText('Acme Sdn Bhd', undefined, { timeout: 3000 })
    const row = name.closest('div.flex.items-center.justify-between') as HTMLElement | null
    expect(row).toBeTruthy()
    expect(row!.textContent).toContain(expected)
  })

  it('charts are told to format money in the base currency', async () => {
    renderDashboard()

    await waitFor(() => expect(chartProps.line?.currency).toBe('MYR'), { timeout: 3000 })
    expect(chartProps.bar?.currency).toBe('MYR')
    expect(chartProps.pie?.currency).toBe('MYR')
  })
})
