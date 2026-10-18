// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
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

const { apiGet, apiPost } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const draftOrder = {
  id: 5,
  sales_order_number: 'SO-2026-0005',
  customer: { id: 1, name: 'Klinik Ali' },
  channel: 'admin',
  order_date: '2026-01-10',
  created_at: '2026-01-10T00:00:00Z',
  status: 'draft',
  warehouse_id: 3,
  total: 100,
  invoice_links_count: 0,
  delivery_orders_count: 0,
  lines: [
    {
      id: 11,
      line_type: 'product',
      product_id: 8,
      product: { id: 8, name: 'Gadget', sku: 'G-1' },
      description: 'Gadget',
      quantity: 4,
      unit_price: 20,
      line_total: 80,
    },
  ],
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders') {
      return {
        data: {
          data: [draftOrder],
          meta: { total: 1, last_page: 1, current_page: 1, per_page: 20 },
          total: 1,
          last_page: 1,
        },
      }
    }
    return { data: { data: [] } }
  })

  apiPost.mockReset()
  apiPost.mockImplementation(async (url: string) => {
    if (url === '/sales/delivery-orders/stock-check') {
      return {
        data: {
          warehouse_id: 3,
          lines: [
            {
              product_id: 8,
              requested: 4,
              on_hand: 1,
              reserved: 0,
              available: 1,
              incoming: [],
              incoming_quantity: 0,
              shortage: 3,
              sufficient: false,
            },
          ],
          summary: { line_count: 1, lines_short: 1 },
          incoming_note: 'No open purchase orders or inbound transfers found for the requested products in this warehouse.',
        },
      }
    }
    if (url === '/sales/orders/5/confirm') {
      return { data: { message: 'Order confirmed' } }
    }
    return { data: {} }
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

describe('SalesOrdersPage — confirm shortage summary (#687 UX1)', () => {
  it('intercepts the list Confirm action with the shortage summary instead of confirming silently', async () => {
    renderPage()

    await screen.findByText('SO-2026-0005')
    fireEvent.click(screen.getByRole('button', { name: /^confirm$/i }))

    // The count appears in both the dialog title and its description.
    await waitFor(() => expect(screen.getAllByText(/1 line short/).length).toBeGreaterThan(0))
    expect(screen.getByRole('button', { name: /backorder & confirm/i })).toBeTruthy()
    expect(apiPost.mock.calls.map((call) => call[0])).not.toContain('/sales/orders/5/confirm')

    fireEvent.click(screen.getByRole('button', { name: /backorder & confirm/i }))
    await waitFor(() =>
      expect(apiPost.mock.calls.map((call) => call[0])).toContain('/sales/orders/5/confirm'),
    )
  })
})
