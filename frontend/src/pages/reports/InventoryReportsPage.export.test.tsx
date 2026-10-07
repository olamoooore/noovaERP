// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { InventoryReportsPage } from './InventoryReportsPage'

// #1020 (VC-012): inventory report exports must carry every row of the
// report, not the 20 rows of the visible ReportTable page (the toolbar used to
// scrape the paginated DOM table). Stock movements are server-paginated
// (50 per page, max 100), so the report fetches every page.

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

const { apiGet, downloadReportXlsx } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  downloadReportXlsx: vi.fn(async (_rows: string[][], _options?: unknown) => {}),
}))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))
vi.mock('@/lib/reportFiles', () => ({
  downloadReportXlsx,
  downloadReportDocx: vi.fn(async () => {}),
}))
vi.mock('@/components/inventory/ProductSearchCombobox', () => ({ ProductSearchCombobox: () => null }))

const valuationItems = Array.from({ length: 25 }, (_, i) => ({
  product_id: i + 1,
  product_name: `Product ${i + 1}`,
  sku: `SKU-${i + 1}`,
  category: 'Implants',
  quantity: 2,
  cost: 10,
  value: 20,
  location: 'Main',
}))

const movement = (i: number) => ({
  id: i,
  date: '2026-10-01T00:00:00Z',
  product_id: 1,
  product_name: `Product ${i}`,
  sku: `SKU-${i}`,
  type: 'IN',
  quantity: 1,
  effective_quantity: 1,
  running_balance: i,
  reference: `REF-${i}`,
  notes: null,
})

beforeEach(() => {
  apiGet.mockReset()
  downloadReportXlsx.mockClear()
  apiGet.mockImplementation(async (url: string, config?: { params?: Record<string, unknown> }) => {
    if (url === '/reports/inventory/valuation') {
      return { data: { success: true, data: { items: valuationItems, total_value: 500, by_category: {}, by_location: {} } } }
    }
    if (url === '/reports/inventory/movement') {
      const page = Number(config?.params?.page ?? 1)
      // 120 movements: page 1 = 1..100, page 2 = 101..120.
      const ids = page === 1
        ? Array.from({ length: 100 }, (_, i) => i + 1)
        : Array.from({ length: 20 }, (_, i) => i + 101)
      return {
        data: {
          success: true,
          data: {
            movements: ids.map(movement),
            summary: { opening_balance: 0, total_in: 120, total_out: 0, net_change: 120, closing_balance: 120, movement_count: 120 },
            pagination: { current_page: page, per_page: 100, total: 120, last_page: 2 },
          },
        },
      }
    }
    return { data: { data: {} } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <InventoryReportsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('#1020 — inventory report exports', () => {
  it('exports the header plus all 25 valuation rows, not the 20 on the visible page', async () => {
    renderPage()
    await screen.findByText('Product 1')
    // The table itself still paginates at 20.
    expect(screen.queryByText('Product 25')).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: /excel/i }))

    await waitFor(() => expect(downloadReportXlsx).toHaveBeenCalled())
    const rows = downloadReportXlsx.mock.calls[0][0]
    expect(rows).toHaveLength(26)
    expect(rows[0]).toEqual(['Product', 'SKU', 'Category', 'Quantity', 'Unit Cost', 'Total Value', 'Location'])
    expect(rows[25][0]).toBe('Product 25')
    expect(rows[25][1]).toBe('SKU-25')
  })

  it('fetches every server page of stock movements and exports all of them', async () => {
    renderPage()
    await screen.findByText('Product 1')

    fireEvent.mouseDown(screen.getByRole('tab', { name: /movement/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Stock Movement Report')

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        '/reports/inventory/movement',
        expect.objectContaining({ params: expect.objectContaining({ page: 2 }) }),
      )
    })
    await screen.findByText('REF-1')

    fireEvent.click(screen.getByRole('button', { name: /excel/i }))

    await waitFor(() => expect(downloadReportXlsx).toHaveBeenCalled())
    const rows = downloadReportXlsx.mock.calls[0][0]
    expect(rows).toHaveLength(121)
    expect(rows[0][0]).toBe('Date')
    expect(rows.map((row) => row[6])).toContain('REF-120')
  })

  it('defaults the period to local dates at +0800 (not the UTC day)', async () => {
    const originalTz = process.env.TZ
    process.env.TZ = 'Asia/Singapore'
    vi.useFakeTimers({ toFake: ['Date'] })
    // 07:00 SGT on 7 Oct 2026 === 23:00 UTC on 6 Oct.
    vi.setSystemTime(new Date('2026-10-06T23:00:00Z'))
    try {
      renderPage()
      await waitFor(() => {
        expect(apiGet).toHaveBeenCalledWith(
          '/reports/inventory/valuation',
          expect.objectContaining({ params: expect.objectContaining({ start_date: '2026-10-01', end_date: '2026-10-07' }) }),
        )
      })
    } finally {
      vi.useRealTimers()
      if (originalTz === undefined) delete process.env.TZ
      else process.env.TZ = originalTz
    }
  })
})
