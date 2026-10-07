// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, cleanup, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import SalesRecordsPage from './SalesRecordsPage'

// #1020: the Sales Records default end_date is the LOCAL business day.
// toISOString() is the UTC day, so before 08:00 at +0800 the report ended
// on yesterday and dropped today's invoices.

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

const { getSalesRecords } = vi.hoisted(() => ({ getSalesRecords: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: { get: vi.fn(async () => ({ data: { data: [] } })), post: vi.fn(async () => ({ data: {} })) },
}))
vi.mock('@/lib/reportApi', () => ({ default: { getSalesRecords } }))
vi.mock('@/components/ui/CustomerSearchCombobox', () => ({ CustomerSearchCombobox: () => null }))
vi.mock('@/components/inventory/ProductSearchCombobox', () => ({ ProductSearchCombobox: () => null }))

const originalTz = process.env.TZ

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  if (originalTz === undefined) delete process.env.TZ
  else process.env.TZ = originalTz
})

describe('#1020 — Sales Records default period', () => {
  it('ends on the local day at 07:00 Asia/Singapore, not the UTC day', async () => {
    process.env.TZ = 'Asia/Singapore'
    vi.useFakeTimers({ toFake: ['Date'] })
    // 07:00 SGT on 7 Oct 2026 === 23:00 UTC on 6 Oct.
    vi.setSystemTime(new Date('2026-10-06T23:00:00Z'))
    getSalesRecords.mockResolvedValue({ data: [], columns: [], totals: {} })

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <SalesRecordsPage />
      </QueryClientProvider>,
    )

    await waitFor(() => expect(getSalesRecords).toHaveBeenCalled())
    expect(getSalesRecords.mock.calls[0][0]).toMatchObject({ start_date: '2026-01-01', end_date: '2026-10-07' })
  })
})
