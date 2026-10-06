// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { SalesOrdersPage } from './SalesOrdersPage'

// jsdom polyfills Radix needs at render time
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

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const STATUSES: [string, string][] = [
  ['draft', 'Draft'],
  ['confirmed', 'Confirmed'],
  ['allocated', 'Allocated'],
  ['fulfilled', 'Fulfilled'],
  ['invoiced', 'Invoiced'],
  ['cancelled', 'Cancelled'],
]

const orders = STATUSES.map(([status], index) => ({
  id: 100 + index,
  sales_order_number: `SO-2026-01${index}`,
  customer: { id: 1, name: 'Klinik Ali' },
  channel: 'admin',
  order_date: '2026-09-01',
  created_at: '2026-09-01T00:00:00Z',
  status,
  total: 100,
  invoice_links_count: 0,
  delivery_orders_count: 0,
  lines: [],
}))

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders') {
      return {
        data: {
          data: orders,
          meta: { total: orders.length, last_page: 1, current_page: 1, per_page: 20 },
          total: orders.length,
          last_page: 1,
        },
      }
    }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/sales/orders']}>
        <SalesOrdersPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('SalesOrdersPage — status badge uses the shared lifecycle mapping (#399 / SU1)', () => {
  it('labels every real order status instead of printing the raw value', async () => {
    renderPage()
    await screen.findByText('SO-2026-010')

    for (const [status, label] of STATUSES) {
      expect(screen.queryByText(status)).toBeNull()
      expect(screen.getByText(label)).toBeTruthy()
    }
    const badges = screen.getAllByTestId('sales-order-status-badge')
    expect(badges.map((b) => b.textContent)).toEqual(STATUSES.map(([, label]) => label))
  })

  it('renders the same badge colours as the order detail page mapping', async () => {
    renderPage()
    await screen.findByText('SO-2026-010')

    const classes = screen.getAllByTestId('sales-order-status-badge').map((b) => b.className)
    expect(new Set(classes).size).toBe(STATUSES.length)
  })
})
