// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent, within } from '@testing-library/react'
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
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {}
}
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
}

vi.mock('@/components/charts/LineChart', () => ({ LineChart: () => null }))
vi.mock('@/components/charts/BarChart', () => ({ BarChart: () => null }))
vi.mock('@/components/charts/PieChart', () => ({ PieChart: () => null }))
vi.mock('./SavedViewWidgets', () => ({ SavedViewWidgets: () => null }))

// Net revenue the analytics API returns for an exact date range. "Today" is
// pinned to Wednesday 2026-10-14 below; any range not listed returns 0.
const REVENUE_BY_RANGE: Record<string, number> = {
  // today / yesterday
  '2026-10-14_2026-10-14': 500,
  '2026-10-13_2026-10-13': 400,
  // current month to date vs the same days of the previous month
  '2026-10-01_2026-10-14': 1000,
  '2026-09-01_2026-09-14': 800,
  // a closed month (March 2025) vs the month before it
  '2025-03-01_2025-03-31': 300,
  '2025-02-01_2025-02-28': 200,
  // current year to date vs the same span of the previous year
  '2026-01-01_2026-10-14': 9000,
  '2025-01-01_2025-10-14': 6000,
  // a custom 10-day range vs the 10 days immediately before it
  '2026-09-01_2026-09-10': 450,
  '2026-08-22_2026-08-31': 150,
}

const reportApiMock = vi.hoisted(() => ({
  getDateRangePresets: () => ({
    today: { start_date: '2026-10-14', end_date: '2026-10-14' },
    thisWeek: { start_date: '2026-10-11', end_date: '2026-10-14' },
    thisMonth: { start_date: '2026-10-01', end_date: '2026-10-31' },
    thisYear: { start_date: '2026-01-01', end_date: '2026-10-14' },
  }),
  getSalesByProduct: vi.fn(),
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
  reportApiMock.getSalesByProduct.mockImplementation(
    async (filters: { start_date: string; end_date: string }) => {
      const revenue = REVENUE_BY_RANGE[`${filters.start_date}_${filters.end_date}`] ?? 0
      return { products: [{ name: 'Widget', total_revenue: revenue, total_quantity: 1 }] }
    },
  )
})

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  reportApiMock.getSalesByProduct.mockReset()
})

function renderDashboard() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <SalesDashboard />
    </QueryClientProvider>,
  )
}

const tile = (id: string) => screen.findByTestId(id)

async function expectTile(id: string, text: string) {
  const el = await tile(id)
  await waitFor(() => expect(el.textContent).toContain(text), { timeout: 3000 })
}

async function chooseOption(triggerLabel: string, option: string) {
  const trigger = screen.getByRole('combobox', { name: triggerLabel })
  fireEvent.pointerDown(trigger, { button: 0, ctrlKey: false, pointerType: 'mouse' })
  const listbox = await screen.findByRole('listbox')
  fireEvent.click(within(listbox).getByRole('option', { name: option }))
}

describe('SalesDashboard KPI row follows the selected period (#358)', () => {
  it('shows the selected month to date against the same days of the previous month', async () => {
    renderDashboard()

    await expectTile('kpi-selected', '1,000.00')
    await expectTile('kpi-previous', '800.00')
    await expectTile('kpi-change', '+25.0%')
  })

  it('reflects a past month chosen in the month picker, not the current month', async () => {
    renderDashboard()
    await expectTile('kpi-selected', '1,000.00')

    await chooseOption('Month', 'March')
    await chooseOption('Year', '2025')

    await expectTile('kpi-selected', '300.00')
    await expectTile('kpi-previous', '200.00')
    await expectTile('kpi-change', '+50.0%')
    expect((await tile('kpi-selected')).textContent).toContain('March 2025')
  })

  it('switches the KPI row to the year when the Year button is pressed', async () => {
    renderDashboard()
    await expectTile('kpi-selected', '1,000.00')

    fireEvent.click(screen.getByRole('button', { name: /^year$/i }))

    await expectTile('kpi-selected', '9,000.00')
    await expectTile('kpi-previous', '6,000.00')
    await expectTile('kpi-change', '+50.0%')
  })

  it('switches the KPI row to today against yesterday', async () => {
    renderDashboard()

    fireEvent.click(screen.getByRole('button', { name: /^today$/i }))

    await expectTile('kpi-selected', '500.00')
    await expectTile('kpi-previous', '400.00')
    await expectTile('kpi-change', '+25.0%')
  })

  it('supports a custom date range compared with the same-length range before it', async () => {
    renderDashboard()

    fireEvent.click(screen.getByRole('button', { name: /^custom$/i }))
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-09-01' } })
    fireEvent.change(screen.getByLabelText('To'), { target: { value: '2026-09-10' } })

    await expectTile('kpi-selected', '450.00')
    await expectTile('kpi-previous', '150.00')
    await expectTile('kpi-change', '+200.0%')
  })

  it('does not query an inverted custom range and says why', async () => {
    renderDashboard()

    fireEvent.click(screen.getByRole('button', { name: /^custom$/i }))
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-09-10' } })
    fireEvent.change(screen.getByLabelText('To'), { target: { value: '2026-09-01' } })

    expect(await screen.findByText(/start date must be on or before the end date/i)).toBeTruthy()
    const calls = reportApiMock.getSalesByProduct.mock.calls.map(
      ([f]) => `${f.start_date}_${f.end_date}`,
    )
    expect(calls).not.toContain('2026-09-10_2026-09-01')
  })
})
