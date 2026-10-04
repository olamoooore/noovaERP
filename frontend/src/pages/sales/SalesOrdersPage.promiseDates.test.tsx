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

const lateOrder = {
  id: 5,
  sales_order_number: 'SO-2026-0005',
  customer: { id: 1, name: 'Klinik Ali' },
  channel: 'admin',
  order_date: '2026-09-01',
  commitment_date: '2026-09-20',
  expected_date: '2026-09-25',
  is_late: true,
  created_at: '2026-09-01T00:00:00Z',
  status: 'confirmed',
  total: 100,
  invoice_links_count: 0,
  delivery_orders_count: 0,
  lines: [],
}

const onTimeOrder = {
  id: 6,
  sales_order_number: 'SO-2026-0006',
  customer: { id: 2, name: 'Klinik Siti' },
  channel: 'admin',
  order_date: '2026-10-01',
  commitment_date: '2026-11-15',
  expected_date: '2026-10-09',
  is_late: false,
  created_at: '2026-10-01T00:00:00Z',
  status: 'confirmed',
  total: 200,
  invoice_links_count: 0,
  delivery_orders_count: 0,
  lines: [],
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders') {
      return {
        data: {
          data: [lateOrder, onTimeOrder],
          meta: { total: 2, last_page: 1, current_page: 1, per_page: 20 },
          total: 2,
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

describe('SalesOrdersPage — commitment / expected date + late indicator (#408)', () => {
  it('shows the commitment date, the computed expected date and the late badge on the list', async () => {
    renderPage()

    await screen.findByText('SO-2026-0005')

    // Commitment and expected columns.
    expect(screen.getByText('Commitment')).toBeTruthy()
    expect(screen.getByText('Expected')).toBeTruthy()
    expect(screen.getByText('Sep 20, 2026')).toBeTruthy()
    expect(screen.getByText('Sep 25, 2026')).toBeTruthy()
    expect(screen.getByText('Nov 15, 2026')).toBeTruthy()
    expect(screen.getByText('Oct 9, 2026')).toBeTruthy()

    // Only the late order carries the badge.
    expect(screen.getAllByText('Late')).toHaveLength(1)
  })
})
